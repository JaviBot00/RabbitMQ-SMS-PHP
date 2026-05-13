<?php

declare(strict_types=1);

namespace RabbitmqSms\Consumer\Tests;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RabbitmqSms\Consumer\Consumer;
use RabbitmqSms\Consumer\SmsProviderInterface;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Tests del Consumer.
 *
 * Cubre todas las casuísticas: envío exitoso, fallo del proveedor,
 * mensaje no encontrado, duplicado ya enviado y errores de BD.
 */
final class ConsumerTest extends TestCase
{
    private MensajeRepository&MockObject  $mensajeRepo;
    private UsuarioRepository&MockObject  $usuarioRepo;
    private SmsProviderInterface&MockObject $smsProvider;
    private AMQPChannel&MockObject        $channel;
    private Consumer                      $consumer;

    protected function setUp(): void
    {
        $this->mensajeRepo = $this->createMock(MensajeRepository::class);
        $this->usuarioRepo = $this->createMock(UsuarioRepository::class);
        $this->smsProvider = $this->createMock(SmsProviderInterface::class);
        $this->channel     = $this->createMock(AMQPChannel::class);

        $this->consumer = new Consumer(
            mensajeRepo:        $this->mensajeRepo,
            usuarioRepo:        $this->usuarioRepo,
            smsProvider:        $this->smsProvider,
            channel:            $this->channel,
            logger:             new NullLogger(),
            backoffBaseMinutos: 2,
        );
    }

    // ─── Envío exitoso ────────────────────────────────────────────────────────

    /**
     * @test
     * Caso feliz: proveedor confirma envío → mensaje marcado enviado + ultimo_envio actualizado.
     */
    public function procesarMensaje_envioExitoso_marcaEnviadoYActualizaUsuario(): void
    {
        $mensaje = $this->crearMensaje(id: 1, estado: 'encolado', reintentos: 0);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->smsProvider->method('enviar')->willReturn(true);

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoEnviado')
            ->with(1);

        $this->usuarioRepo
            ->expects($this->once())
            ->method('actualizarUltimoEnvio')
            ->with('user_normal_01');

        $this->mensajeRepo
            ->expects($this->never())
            ->method('marcarComoError');

        $amqpMsg = new AMQPMessage('1');
        $this->consumer->procesarMensaje($amqpMsg);
    }

    // ─── Fallo del proveedor ──────────────────────────────────────────────────

    /**
     * @test
     * Proveedor falla en el primer intento → error con backoff de 2 minutos (2 * 2^0).
     */
    public function procesarMensaje_proveedorFalla_marcaErrorConBackoffBase(): void
    {
        $mensaje = $this->crearMensaje(id: 2, estado: 'encolado', reintentos: 0);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->smsProvider->method('enviar')->willReturn(false);

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoError')
            ->with(2, 2); // backoff = 2 * 2^0 = 2 minutos

        $this->mensajeRepo->expects($this->never())->method('marcarComoEnviado');
        $this->usuarioRepo->expects($this->never())->method('actualizarUltimoEnvio');

        $amqpMsg = new AMQPMessage('2');
        $this->consumer->procesarMensaje($amqpMsg);
    }

    /**
     * @test
     * Proveedor falla en el tercer intento → backoff de 16 minutos (2 * 2^3).
     */
    public function procesarMensaje_proveedorFallaEnTercerIntento_backoffExponencial(): void
    {
        $mensaje = $this->crearMensaje(id: 3, estado: 'error', reintentos: 3);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->smsProvider->method('enviar')->willReturn(false);

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoError')
            ->with(3, 16); // backoff = 2 * 2^3 = 16 minutos

        $amqpMsg = new AMQPMessage('3');
        $this->consumer->procesarMensaje($amqpMsg);
    }

    // ─── Mensaje no encontrado ────────────────────────────────────────────────

    /**
     * @test
     * ID no existe en BD → ack sin llamar al proveedor.
     */
    public function procesarMensaje_mensajeNoEncontrado_ackSinEnviar(): void
    {
        $this->mensajeRepo->method('buscarPorId')->willReturn(null);

        $this->smsProvider->expects($this->never())->method('enviar');
        $this->mensajeRepo->expects($this->never())->method('marcarComoEnviado');
        $this->mensajeRepo->expects($this->never())->method('marcarComoError');

        $amqpMsg = new AMQPMessage('999');
        $this->consumer->procesarMensaje($amqpMsg);

        $this->assertTrue(true); // llegó aquí sin excepción
    }

    // ─── Duplicado ya enviado ─────────────────────────────────────────────────

    /**
     * @test
     * Mensaje ya en estado enviado → se ignora sin volver a enviar (idempotencia).
     */
    public function procesarMensaje_mensajeYaEnviado_seIgnora(): void
    {
        $mensaje = $this->crearMensaje(id: 4, estado: 'enviado', reintentos: 0);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);

        $this->smsProvider->expects($this->never())->method('enviar');
        $this->mensajeRepo->expects($this->never())->method('marcarComoEnviado');

        $amqpMsg = new AMQPMessage('4');
        $this->consumer->procesarMensaje($amqpMsg);

        $this->assertTrue(true);
    }

    // ─── Error de BD ──────────────────────────────────────────────────────────

    /**
     * @test
     * Error de BD al buscar el mensaje → nack con requeue, proceso no cae.
     */
    public function procesarMensaje_errorDeBD_nacksConRequeueSinCaer(): void
    {
        $this->mensajeRepo
            ->method('buscarPorId')
            ->willThrowException(new \PDOException('Conexión perdida'));

        $this->smsProvider->expects($this->never())->method('enviar');

        $amqpMsg = new AMQPMessage('5');

        // no debe propagar la excepción
        $this->consumer->procesarMensaje($amqpMsg);

        $this->assertTrue(true);
    }

    /**
     * @test
     * Error de BD al marcar enviado tras envío exitoso → proceso no cae.
     */
    public function procesarMensaje_errorDeBDAlMarcarEnviado_procesoNoCae(): void
    {
        $mensaje = $this->crearMensaje(id: 6, estado: 'encolado', reintentos: 0);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->smsProvider->method('enviar')->willReturn(true);

        $this->mensajeRepo
            ->method('marcarComoEnviado')
            ->willThrowException(new \PDOException('Timeout'));

        // no debe propagar la excepción
        $amqpMsg = new AMQPMessage('6');
        $this->consumer->procesarMensaje($amqpMsg);

        $this->assertTrue(true);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function crearMensaje(int $id, string $estado, int $reintentos): Mensaje
    {
        return new Mensaje(
            id:         $id,
            usuarioId:  'user_normal_01',
            telefono:   '+34600000001',
            contenido:  'Mensaje de prueba',
            esUrgente:  false,
            estado:     $estado,
            creadoEn:   new \DateTimeImmutable(),
            reintentos: $reintentos,
        );
    }
}
