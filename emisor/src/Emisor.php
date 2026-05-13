<?php

declare(strict_types=1);

namespace RabbitmqSms\Emisor;

use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Emisor de mensajes SMS.
 *
 * Responsabilidades (y solo estas):
 *   1. Insertar mensajes en `sms_mensajes` con estado `pendiente`.
 *   2. Consultar los mensajes `pendiente` recién insertados.
 *   3. Publicar sus IDs en la cola `sms.entrada`.
 *   4. Actualizar el estado a `encolado` tras la publicación.
 *
 * No gestiona reintentos ni recuperación de caídas; esa responsabilidad
 * recae en el Reintentador.
 */
final class Emisor
{
    /** Nombre de la cola donde se publican los IDs. */
    private const COLA_ENTRADA = 'sms.entrada';

    public function __construct(
        private readonly MensajeRepository $mensajeRepo,
        private readonly UsuarioRepository $usuarioRepo,
        private readonly AMQPChannel       $channel,
        private readonly LoggerInterface   $logger,
    ) {}

    /**
     * Crea un nuevo mensaje para un usuario existente y lo encola.
     *
     * Flujo:
     *   INSERT → obtener pendientes → publicar ID → marcar encolado.
     *
     * El SELECT de pendientes se hace sobre BD (no en RAM) para garantizar
     * que solo se publican mensajes correctamente persistidos.
     *
     * @param string $usuarioId FK del usuario destinatario.
     * @param string $contenido Texto del SMS.
     * @param bool   $esUrgente true = prioridad alta en la cola del intermediario.
     *
     * @throws \RuntimeException Si el usuario no existe en la BD.
     * @throws \PDOException     Si falla la BD.
     * @throws \Exception        Si falla la publicación en RabbitMQ.
     */
    public function emitir(string $usuarioId, string $contenido, bool $esUrgente = false): void
    {
        // verificar que el usuario existe y obtener su teléfono
        $usuario = $this->usuarioRepo->buscarPorId($usuarioId);

        if ($usuario === null) {
            throw new \RuntimeException(
                sprintf("El usuario '%s' no existe en la BD. El emisor solo trabaja con usuarios registrados.", $usuarioId)
            );
        }

        $telefono = $usuario['telefono'];

        // INSERT: el mensaje nace en estado pendiente
        $id = $this->mensajeRepo->insertar($usuarioId, $telefono, $contenido, $esUrgente);

        $this->logger->info('Mensaje insertado en BD', [
            'id'         => $id,
            'usuario_id' => $usuarioId,
            'es_urgente' => $esUrgente,
        ]);

        // publicar y marcar como encolado
        $this->publicarYEncolar($id);
    }

    /**
     * Procesa todos los mensajes en estado `pendiente` que existan en BD.
     *
     * Útil al arrancar el proceso para no perder mensajes insertados
     * antes de que la cola estuviera disponible.
     */
    public function procesarPendientes(): void
    {
        $pendientes = $this->mensajeRepo->obtenerPendientes();

        if (empty($pendientes)) {
            $this->logger->info('No hay mensajes pendientes en BD.');
            return;
        }

        $this->logger->info(sprintf('Procesando %d mensajes pendientes.', count($pendientes)));

        foreach ($pendientes as $mensaje) {
            $this->publicarYEncolar($mensaje->id);
        }
    }

    /**
     * Publica el ID de un mensaje en `sms.entrada` y actualiza su estado a `encolado`.
     *
     * @param int $id ID del mensaje a publicar.
     *
     * @throws \Exception Si la publicación en RabbitMQ falla.
     */
    private function publicarYEncolar(int $id): void
    {
        $body = (string) $id;

        $msg = new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // sobrevive reinicios
            'content_type'  => 'text/plain',
        ]);

        $this->channel->basic_publish($msg, '', self::COLA_ENTRADA);

        // solo marcamos encolado si la publicación no lanzó excepción
        $this->mensajeRepo->marcarComoEncolado($id);

        $this->logger->info('ID publicado en cola y marcado como encolado', ['id' => $id]);
    }

    /**
     * Declara la cola de entrada si aún no existe.
     *
     * Llamar una vez al iniciar el proceso, antes de emitir.
     */
    public function declararCola(): void
    {
        $this->channel->queue_declare(
            queue:       self::COLA_ENTRADA,
            passive:     false,
            durable:     true,   // la cola sobrevive reinicios de RabbitMQ
            exclusive:   false,
            auto_delete: false
        );
    }
}
