<?php

declare(strict_types=1);

namespace RabbitmqSms\Consumer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Consumer de mensajes SMS.
 *
 * Escucha la cola `sms.directo` (mensajes listos para enviar, tanto
 * directos como liberados del delayed) y por cada ID recibido:
 *
 *   1. Consulta la BD para obtener los datos actualizados del mensaje.
 *   2. Llama al proveedor SMS.
 *   3. Si el envío es exitoso: marca el mensaje como `enviado` y actualiza
 *      `ultimo_envio` en la tabla de usuarios.
 *   4. Si el envío falla: marca el mensaje como `error` con backoff calculado.
 */
final class Consumer
{
    private const COLA_DIRECTA = 'sms.directo';

    public function __construct(
        private readonly MensajeRepository  $mensajeRepo,
        private readonly UsuarioRepository  $usuarioRepo,
        private readonly SmsProviderInterface $smsProvider,
        private readonly AMQPChannel        $channel,
        private readonly LoggerInterface    $logger,
        /** Base del backoff exponencial en minutos (leída de configuracion). */
        private readonly int $backoffBaseMinutos = 2,
    ) {}

    /**
     * Declara la cola directa si aún no existe.
     *
     * Idempotente respecto al intermediario: ambos pueden declararla.
     */
    public function declararCola(): void
    {
        $this->channel->queue_declare(
            queue: self::COLA_DIRECTA, passive: false,
            durable: true, exclusive: false, auto_delete: false,
            arguments: new AMQPTable(['x-max-priority' => 10])
        );
    }

    /**
     * Inicia el consumo de la cola `sms.directo`.
     *
     * Bloquea el proceso. Los mensajes urgentes (priority=10) se procesan
     * antes que los normales (priority=0) gracias a x-max-priority.
     */
    public function escuchar(): void
    {
        $this->channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

        $this->channel->basic_consume(
            queue:    self::COLA_DIRECTA,
            callback: [$this, 'procesarMensaje']
        );

        $this->logger->info(sprintf("Consumer escuchando cola '%s'...", self::COLA_DIRECTA));

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * Callback invocado por php-amqplib para cada mensaje de la cola.
     *
     * @param AMQPMessage $msg Mensaje recibido con el ID en el body.
     */
    public function procesarMensaje(AMQPMessage $msg): void
    {
        $id = (int) $msg->getBody();

        $this->logger->info('Mensaje recibido para envío', ['id' => $id]);

        try {
            $mensaje = $this->mensajeRepo->buscarPorId($id);

            if ($mensaje === null) {
                $this->logger->warning('Mensaje no encontrado en BD, descartado.', ['id' => $id]);
                $msg->ack();
                return;
            }

            // si ya fue enviado (p.ej. duplicado por el reintentador), ignorar
            if ($mensaje->estado === 'enviado') {
                $this->logger->info('Mensaje ya enviado previamente, ignorado.', ['id' => $id]);
                $msg->ack();
                return;
            }

            $exito = $this->smsProvider->enviar($mensaje->telefono, $mensaje->contenido);

            if ($exito) {
                $this->onEnvioExitoso($id, $mensaje->usuarioId);
                $msg->ack();
            } else {
                $this->onEnvioFallido($id, $mensaje->reintentos);
                // nack sin requeue: el reintentador lo recuperará según el backoff
                $msg->nack(requeue: false);
            }

        } catch (\PDOException $e) {
            $this->logger->warning(
                'Error de BD en consumer, mensaje se reencola.',
                ['id' => $id, 'error' => $e->getMessage()]
            );
            $msg->nack(requeue: true);

        } catch (\Exception $e) {
            $this->logger->error(
                'Error inesperado en consumer, mensaje descartado.',
                ['id' => $id, 'error' => $e->getMessage()]
            );
            $msg->nack(requeue: false);
        }
    }

    /**
     * Acciones tras un envío exitoso:
     *   - Marca el mensaje como `enviado`.
     *   - Actualiza `ultimo_envio` en la tabla usuarios.
     *
     * @param int    $id        ID del mensaje.
     * @param string $usuarioId FK del usuario.
     */
    private function onEnvioExitoso(int $id, string $usuarioId): void
    {
        $this->mensajeRepo->marcarComoEnviado($id);
        $this->usuarioRepo->actualizarUltimoEnvio($usuarioId);

        $this->logger->info('Envío confirmado y registrado en BD.', [
            'id'         => $id,
            'usuario_id' => $usuarioId,
        ]);
    }

    /**
     * Acciones tras un envío fallido:
     *   - Marca el mensaje como `error` con backoff exponencial.
     *
     * Backoff: base * 2^reintentos (minutos).
     * Ejemplo con base=2: 2, 4, 8, 16, 32 minutos...
     *
     * @param int $id         ID del mensaje.
     * @param int $reintentos Número de reintentos ya realizados.
     */
    private function onEnvioFallido(int $id, int $reintentos): void
    {
        $backoff = $this->backoffBaseMinutos * (2 ** $reintentos);

        $this->mensajeRepo->marcarComoError($id, $backoff);

        $this->logger->warning('Envío fallido, marcado como error con backoff.', [
            'id'             => $id,
            'reintentos'     => $reintentos,
            'backoff_minutos' => $backoff,
        ]);
    }
}
