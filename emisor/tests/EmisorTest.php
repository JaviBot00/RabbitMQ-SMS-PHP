<?php

declare(strict_types=1);

namespace RabbitmqSms\Emisor\Tests;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RabbitmqSms\Emisor\Emisor;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Tests del Emisor.
 *
 * Cubre todas las casuísticas de negocio sin tocar BD ni RabbitMQ reales.
 * Todos los colaboradores se mockean para aislar la lógica del Emisor.
 */
final class EmisorTest extends TestCase
{
    private MensajeRepository&MockObject $mensajeRepo;
    private UsuarioRepository&MockObject $usuarioRepo;
    private AMQPChannel&MockObject       $channel;
    private Emisor                       $emisor;

    protected function setUp(): void
    {
        $this->mensajeRepo = $this->createMock(MensajeRepository::class);
        $this->usuarioRepo = $this->createMock(UsuarioRepository::class);
        $this->channel     = $this->createMock(AMQPChannel::class);

        $this->emisor = new Emisor(
            mensajeRepo: $this->mensajeRepo,
            usuarioRepo: $this->usuarioRepo,
            channel:     $this->channel,
            logger:      new NullLogger(),
        );
    }

    // ─── emitir() ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Caso feliz: usuario existe, INSERT correcto, ID publicado y marcado encolado.
     */
    public function emitir_usuarioExiste_insertaPublicaYMarcaEncolado(): void
    {
        $this->usuarioRepo
            ->method('buscarPorId')
            ->with('user_normal_01')
            ->willReturn(['usuario_id' => 'user_normal_01', 'telefono' => '+34600000001']);

        $this->mensajeRepo
            ->method('insertar')
            ->willReturn(42);

        // se espera una publicación en RabbitMQ
        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(fn(AMQPMessage $msg) => $msg->getBody() === '42'),
                '',
                'sms.entrada'
            );

        // se espera la actualización de estado
        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoEncolado')
            ->with(42);

        $this->emisor->emitir('user_normal_01', 'Hola', false);
    }

    /**
     * @test
     * Si el usuario no existe en BD, se lanza RuntimeException y no se publica nada.
     */
    public function emitir_usuarioNoExiste_lanzaExcepcion(): void
    {
        $this->usuarioRepo
            ->method('buscarPorId')
            ->willReturn(null);

        $this->channel
            ->expects($this->never())
            ->method('basic_publish');

        $this->expectException(\RuntimeException::class);

        $this->emisor->emitir('usuario_inexistente', 'Hola', false);
    }

    /**
     * @test
     * Mensaje urgente: se publica igual que uno normal (la prioridad la pone el intermediario).
     */
    public function emitir_mensajeUrgente_sePublicaCorrectamente(): void
    {
        $this->usuarioRepo
            ->method('buscarPorId')
            ->willReturn(['usuario_id' => 'user_normal_01', 'telefono' => '+34600000001']);

        $this->mensajeRepo
            ->method('insertar')
            ->with('user_normal_01', '+34600000001', 'URGENTE', true)
            ->willReturn(99);

        $this->channel
            ->expects($this->once())
            ->method('basic_publish');

        $this->mensajeRepo
            ->expects($this->once())
            ->method('marcarComoEncolado')
            ->with(99);

        $this->emisor->emitir('user_normal_01', 'URGENTE', true);
    }

    /**
     * @test
     * Si basic_publish lanza excepción, marcarComoEncolado NO se llama.
     */
    public function emitir_fallaDurantePublicacion_noMarcaEncolado(): void
    {
        $this->usuarioRepo
            ->method('buscarPorId')
            ->willReturn(['usuario_id' => 'user_normal_01', 'telefono' => '+34600000001']);

        $this->mensajeRepo
            ->method('insertar')
            ->willReturn(7);

        $this->channel
            ->method('basic_publish')
            ->willThrowException(new \Exception('RabbitMQ no disponible'));

        $this->mensajeRepo
            ->expects($this->never())
            ->method('marcarComoEncolado');

        $this->expectException(\Exception::class);

        $this->emisor->emitir('user_normal_01', 'Hola', false);
    }

    // ─── procesarPendientes() ─────────────────────────────────────────────────

    /**
     * @test
     * Sin mensajes pendientes: no se publica nada.
     */
    public function procesarPendientes_sinPendientes_noPublicaNada(): void
    {
        $this->mensajeRepo
            ->method('obtenerPendientes')
            ->willReturn([]);

        $this->channel
            ->expects($this->never())
            ->method('basic_publish');

        $this->emisor->procesarPendientes();
    }

    /**
     * @test
     * Con N pendientes: se publican N mensajes y se marcan N como encolados.
     */
    public function procesarPendientes_conPendientes_publicaTodos(): void
    {
        $pendientes = [
            $this->crearMensaje(1),
            $this->crearMensaje(2),
            $this->crearMensaje(3),
        ];

        $this->mensajeRepo
            ->method('obtenerPendientes')
            ->willReturn($pendientes);

        $this->channel
            ->expects($this->exactly(3))
            ->method('basic_publish');

        $this->mensajeRepo
            ->expects($this->exactly(3))
            ->method('marcarComoEncolado');

        $this->emisor->procesarPendientes();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Crea un Mensaje mínimo para usar en tests de procesarPendientes.
     */
    private function crearMensaje(int $id): Mensaje
    {
        return new Mensaje(
            id:        $id,
            usuarioId: 'user_normal_01',
            telefono:  '+34600000001',
            contenido: 'Mensaje de prueba',
            esUrgente: false,
            estado:    'pendiente',
            creadoEn:  new \DateTimeImmutable(),
        );
    }
}
