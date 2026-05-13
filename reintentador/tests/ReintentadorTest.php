<?php

declare(strict_types=1);

namespace RabbitmqSms\Reintentador\Tests;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RabbitmqSms\Reintentador\Reintentador;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;

/**
 * Tests del Reintentador.
 *
 * Cubre la lógica de selección de candidatos, límite de reintentos
 * y el backoff exponencial.
 */
final class ReintentadorTest extends TestCase
{
    private MensajeRepository&MockObject $mensajeRepo;
    private AMQPChannel&MockObject       $channel;

    protected function setUp(): void
    {
        $this->mensajeRepo = $this->createMock(MensajeRepository::class);
        $this->channel     = $this->createMock(AMQPChannel::class);
    }

    private function crearReintentador(int $reintentosMaximos = 5): Reintentador
    {
        return new Reintentador(
            mensajeRepo:            $this->mensajeRepo,
            channel:                $this->channel,
            logger:                 new NullLogger(),
            encoladoTimeoutMinutos: 5,
            reintentosMaximos:      $reintentosMaximos,
        );
    }

    // ─── Sin candidatos ───────────────────────────────────────────────────────

    /**
     * @test
     * Sin mensajes candidatos → no se publica nada.
     */
    public function ejecutar_sinCandidatos_noPublicaNada(): void
    {
        $this->mensajeRepo->method('obtenerParaReintento')->willReturn([]);

        $this->channel->expects($this->never())->method('basic_publish');

        $this->crearReintentador()->ejecutar();
    }

    // ─── Reencolar candidatos ─────────────────────────────────────────────────

    /**
     * @test
     * Mensaje en estado error dentro del límite → se reencola.
     */
    public function ejecutar_mensajeErrorDentroDelLimite_reencola(): void
    {
        $mensaje = $this->crearMensaje(id: 1, estado: 'error', reintentos: 2);

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn([$mensaje]);

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(fn(AMQPMessage $msg) => $msg->getBody() === '1'),
                '',
                'sms.entrada'
            );

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoEncolado')
            ->with(1);

        $this->crearReintentador(reintentosMaximos: 5)->ejecutar();
    }

    /**
     * @test
     * Mensaje en estado pendiente → siempre se reencola independientemente de reintentos.
     */
    public function ejecutar_mensajePendiente_siempreReencola(): void
    {
        $mensaje = $this->crearMensaje(id: 2, estado: 'pendiente', reintentos: 0);

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn([$mensaje]);

        $this->channel->expects($this->once())->method('basic_publish');
        $this->mensajeRepo->expects($this->once())->method('marcarComoEncolado')->with(2);

        $this->crearReintentador()->ejecutar();
    }

    /**
     * @test
     * Múltiples candidatos → todos se reencolán.
     */
    public function ejecutar_multiplescandidatos_reencolaTodos(): void
    {
        $candidatos = [
            $this->crearMensaje(id: 1, estado: 'error',    reintentos: 1),
            $this->crearMensaje(id: 2, estado: 'pendiente', reintentos: 0),
            $this->crearMensaje(id: 3, estado: 'encolado', reintentos: 0),
        ];

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn($candidatos);

        $this->channel->expects($this->exactly(3))->method('basic_publish');
        $this->mensajeRepo->expects($this->exactly(3))->method('marcarComoEncolado');

        $this->crearReintentador()->ejecutar();
    }

    // ─── Límite de reintentos ─────────────────────────────────────────────────

    /**
     * @test
     * Mensaje que alcanzó el límite → se descarta (marcarComoError), no se reencola.
     */
    public function ejecutar_mensajeAlcanzoLimite_seDescartaSinReencolar(): void
    {
        $mensaje = $this->crearMensaje(id: 10, estado: 'error', reintentos: 5);

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn([$mensaje]);

        $this->channel->expects($this->never())->method('basic_publish');
        $this->mensajeRepo->expects($this->never())->method('marcarComoEncolado');

        // debe marcar como error definitivo
        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoError')
            ->with(10, 99999);

        $this->crearReintentador(reintentosMaximos: 5)->ejecutar();
    }

    /**
     * @test
     * Con reintentos_maximos = 0 (infinito), nunca se descarta ningún mensaje.
     */
    public function ejecutar_reintentosMaximosInfinito_nuncaDescarta(): void
    {
        // aunque lleve 1000 reintentos, con límite 0 siempre se reencola
        $mensaje = $this->crearMensaje(id: 11, estado: 'error', reintentos: 1000);

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn([$mensaje]);

        $this->channel->expects($this->once())->method('basic_publish');
        $this->mensajeRepo->expects($this->once())->method('marcarComoEncolado');
        $this->mensajeRepo->expects($this->never())->method('marcarComoError');

        $this->crearReintentador(reintentosMaximos: 0)->ejecutar(); // 0 = infinito
    }

    /**
     * @test
     * Mezcla de mensajes: unos dentro del límite y otros fuera.
     * Los que superan el límite se descartan; los demás se reencolán.
     */
    public function ejecutar_mezclaDeLimites_procesaCadaUnoCorrectamente(): void
    {
        $candidatos = [
            $this->crearMensaje(id: 20, estado: 'error', reintentos: 3), // dentro del límite
            $this->crearMensaje(id: 21, estado: 'error', reintentos: 5), // límite alcanzado
        ];

        $this->mensajeRepo->method('obtenerParaReintento')->willReturn($candidatos);

        // solo el id=20 se reencola
        $this->channel->expects($this->once())->method('basic_publish');
        $this->mensajeRepo->expects($this->once())->method('marcarComoEncolado')->with(20);

        // solo el id=21 se descarta
        $this->mensajeRepo->expects($this->once())->method('marcarComoError')->with(21, 99999);

        $this->crearReintentador(reintentosMaximos: 5)->ejecutar();
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
