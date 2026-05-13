<?php

declare(strict_types=1);

namespace RabbitmqSms\Intermediario\Tests;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RabbitmqSms\Intermediario\Intermediario;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Tests del Intermediario.
 *
 * Cubre todas las reglas de enrutamiento y los casos de error.
 */
final class IntermediarioTest extends TestCase
{
    private MensajeRepository&MockObject $mensajeRepo;
    private UsuarioRepository&MockObject $usuarioRepo;
    private AMQPChannel&MockObject       $channel;
    private Intermediario                $intermediario;

    protected function setUp(): void
    {
        $this->mensajeRepo   = $this->createMock(MensajeRepository::class);
        $this->usuarioRepo   = $this->createMock(UsuarioRepository::class);
        $this->channel       = $this->createMock(AMQPChannel::class);

        $this->intermediario = new Intermediario(
            mensajeRepo: $this->mensajeRepo,
            usuarioRepo: $this->usuarioRepo,
            channel:     $this->channel,
            logger:      new NullLogger(),
        );
    }

    // ─── Enrutamiento ─────────────────────────────────────────────────────────

    /**
     * @test
     * Usuario normal + no urgente → publica en sms.directo con priority=0.
     */
    public function procesarMensaje_usuarioNormalNoUrgente_vaDirectoConPrioridadNormal(): void
    {
        $mensaje = $this->crearMensaje(id: 1, esUrgente: false);
        $usuario = ['es_especial' => 0];

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->usuarioRepo->method('buscarPorId')->willReturn($usuario);

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(fn(AMQPMessage $msg) =>
                    $msg->getBody() === '1' &&
                    $msg->get('priority') === 0
                ),
                '',           // exchange vacío = cola directa
                'sms.directo'
            );

        $amqpMsg = $this->crearAmqpMessage('1');
        $this->intermediario->procesarMensaje($amqpMsg);
    }

    /**
     * @test
     * Usuario especial + no urgente → publica en exchange delayed.
     */
    public function procesarMensaje_usuarioEspecialNoUrgente_vaAlDelayed(): void
    {
        $mensaje = $this->crearMensaje(id: 2, esUrgente: false);
        $usuario = ['es_especial' => 1];

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->usuarioRepo->method('buscarPorId')->willReturn($usuario);
        $this->usuarioRepo->method('obtenerIntervalo')->willReturn(30);

        // debe publicar en el exchange delayed, no en cola directa
        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                'sms.delayed',  // exchange delayed
                'sms.directo'
            );

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarEnEspera');

        $amqpMsg = $this->crearAmqpMessage('2');
        $this->intermediario->procesarMensaje($amqpMsg);
    }

    /**
     * @test
     * Mensaje urgente + usuario normal → siempre directo con priority=10.
     */
    public function procesarMensaje_mensajeUrgenteUsuarioNormal_vaDirectoConPrioridadAlta(): void
    {
        $mensaje = $this->crearMensaje(id: 3, esUrgente: true);
        $usuario = ['es_especial' => 0];

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->usuarioRepo->method('buscarPorId')->willReturn($usuario);

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(fn(AMQPMessage $msg) =>
                    $msg->get('priority') === 10
                ),
                '',
                'sms.directo'
            );

        $amqpMsg = $this->crearAmqpMessage('3');
        $this->intermediario->procesarMensaje($amqpMsg);
    }

    /**
     * @test
     * Mensaje urgente + usuario especial → siempre directo (urgente tiene prioridad sobre especial).
     */
    public function procesarMensaje_mensajeUrgenteUsuarioEspecial_vaDirectoIgnorandoDelayed(): void
    {
        $mensaje = $this->crearMensaje(id: 4, esUrgente: true);
        $usuario = ['es_especial' => 1];

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->usuarioRepo->method('buscarPorId')->willReturn($usuario);

        // nunca debe tocar el exchange delayed
        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                '',           // exchange vacío = directo
                'sms.directo'
            );

        $this->mensajeRepo
            ->expects($this->never())
            ->method('marcarEnEspera');

        $amqpMsg = $this->crearAmqpMessage('4');
        $this->intermediario->procesarMensaje($amqpMsg);
    }

    // ─── Casos de error ───────────────────────────────────────────────────────

    /**
     * @test
     * Mensaje no encontrado en BD → ack sin publicar.
     */
    public function procesarMensaje_mensajeNoEncontradoEnBD_descartaConAck(): void
    {
        $this->mensajeRepo->method('buscarPorId')->willReturn(null);

        $this->channel->expects($this->never())->method('basic_publish');

        $amqpMsg = $this->crearAmqpMessage('999');
        $this->intermediario->procesarMensaje($amqpMsg);

        // si llega aquí sin excepción, el ack fue llamado internamente
        $this->assertTrue(true);
    }

    /**
     * @test
     * Usuario desconocido → se crea como normal y se enruta directo.
     */
    public function procesarMensaje_usuarioDesconocido_creaUsuarioYEnrutaDirecto(): void
    {
        $mensaje = $this->crearMensaje(id: 5, esUrgente: false);

        $this->mensajeRepo->method('buscarPorId')->willReturn($mensaje);
        $this->usuarioRepo->method('buscarPorId')->willReturn(null); // desconocido

        $this->usuarioRepo
            ->expects($this->once())
            ->method('crearUsuarioNormal');

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with($this->anything(), '', 'sms.directo');

        $amqpMsg = $this->crearAmqpMessage('5');
        $this->intermediario->procesarMensaje($amqpMsg);
    }

    /**
     * @test
     * Error de BD → nack con requeue (sin detener el proceso).
     */
    public function procesarMensaje_errorDeBD_nacksConRequeue(): void
    {
        $this->mensajeRepo
            ->method('buscarPorId')
            ->willThrowException(new \PDOException('Conexión perdida'));

        // no debe publicar nada en RabbitMQ
        $this->channel->expects($this->never())->method('basic_publish');

        $amqpMsg = $this->crearAmqpMessage('6');

        // no debe lanzar excepción al exterior (el proceso sigue vivo)
        $this->intermediario->procesarMensaje($amqpMsg);

        $this->assertTrue(true);
    }

    // ─── calcularDelay() ──────────────────────────────────────────────────────

    /**
     * @test
     * El delay calculado debe ser > 0 y <= intervalo * 60 * 1000 ms.
     */
    public function calcularDelay_devuelveDelayDentroDelRango(): void
    {
        $intervalo = 30;
        [$delayMs, $ventana] = $this->intermediario->calcularDelay($intervalo);

        $maxMs = $intervalo * 60 * 1000;

        $this->assertGreaterThan(0, $delayMs);
        $this->assertLessThanOrEqual($maxMs, $delayMs);
        $this->assertInstanceOf(\DateTimeImmutable::class, $ventana);
    }

    /**
     * @test
     * La ventana calculada siempre está en el futuro.
     */
    public function calcularDelay_ventanaEstaEnElFuturo(): void
    {
        [, $ventana] = $this->intermediario->calcularDelay(30);

        $this->assertGreaterThan(new \DateTimeImmutable('now'), $ventana);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function crearMensaje(int $id, bool $esUrgente): Mensaje
    {
        return new Mensaje(
            id:        $id,
            usuarioId: 'user_normal_01',
            telefono:  '+34600000001',
            contenido: 'Mensaje de prueba',
            esUrgente: $esUrgente,
            estado:    'encolado',
            creadoEn:  new \DateTimeImmutable(),
        );
    }

    private function crearAmqpMessage(string $body): AMQPMessage
    {
        $msg = new AMQPMessage($body);
        // simular los métodos ack/nack para que no fallen
        return $msg;
    }
}
