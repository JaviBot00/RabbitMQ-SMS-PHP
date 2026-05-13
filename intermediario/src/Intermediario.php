<?php

declare(strict_types=1);

namespace RabbitmqSms\Intermediario;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

/**
 * Intermediario de mensajes SMS.
 *
 * Escucha la cola `sms.entrada`, consulta la BD para cada ID recibido
 * y decide el destino del mensaje:
 *
 *   - Urgente              → cola `sms.directo` con priority=10 (siempre directo)
 *   - Normal + especial    → exchange `sms.delayed` (espera la próxima ventana)
 *   - Normal + no especial → cola `sms.directo` con priority=0
 *   - Usuario desconocido  → se crea como normal y se envía directo
 *
 * El intermediario publica el ID del mensaje (no el payload completo)
 * para que el consumer siempre lea datos frescos de la BD.
 *
 * Ante errores de BD lanza warnings pero no detiene el proceso;
 * el mensaje se rechaza con nack+requeue para ser reintentado.
 */
final class Intermediario
{
    private const COLA_ENTRADA   = 'sms.entrada';
    private const COLA_DIRECTA   = 'sms.directo';
    private const EXCHANGE_DELAY = 'sms.delayed';

    /** Prioridad para mensajes urgentes en la cola con x-max-priority. */
    private const PRIORIDAD_URGENTE = 10;
    private const PRIORIDAD_NORMAL  = 0;

    public function __construct(
        private readonly MensajeRepository $mensajeRepo,
        private readonly UsuarioRepository $usuarioRepo,
        private readonly AMQPChannel       $channel,
        private readonly LoggerInterface   $logger,
    ) {}

    /**
     * Declara colas, exchange delayed y bindings necesarios.
     *
     * Idempotente: si ya existen con los mismos parámetros, no hace nada.
     */
    public function declararTopologia(): void
    {
        // cola de entrada
        $this->channel->queue_declare(
            queue: self::COLA_ENTRADA, passive: false,
            durable: true, exclusive: false, auto_delete: false
        );

        // cola directa con soporte de prioridad (0–10)
        $this->channel->queue_declare(
            queue: self::COLA_DIRECTA, passive: false,
            durable: true, exclusive: false, auto_delete: false,
            arguments: new AMQPTable(['x-max-priority' => 10])
        );

        // exchange delayed (requiere el plugin rabbitmq_delayed_message_exchange)
        $this->channel->exchange_declare(
            exchange: self::EXCHANGE_DELAY,
            type: 'x-delayed-message',
            passive: false, durable: true, auto_delete: false,
            internal: false, nowait: false,
            arguments: new AMQPTable(['x-delayed-type' => 'direct'])
        );

        // cuando el delay expira, el mensaje llega a la cola directa
        $this->channel->queue_bind(
            queue: self::COLA_DIRECTA,
            exchange: self::EXCHANGE_DELAY,
            routing_key: self::COLA_DIRECTA
        );

        $this->logger->info('Topología de colas declarada correctamente.');
    }

    /**
     * Inicia el consumo de la cola de entrada.
     *
     * Bloquea el proceso hasta que se llame a stop_consuming()
     * o se interrumpa con SIGINT/SIGTERM.
     */
    public function escuchar(): void
    {
        // un mensaje a la vez: no coger el siguiente hasta terminar el actual
        $this->channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

        $this->channel->basic_consume(
            queue:    self::COLA_ENTRADA,
            callback: [$this, 'procesarMensaje']
        );

        $this->logger->info(sprintf("Intermediario escuchando cola '%s'...", self::COLA_ENTRADA));

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

        $this->logger->info('Mensaje recibido', ['id' => $id]);

        try {
            $mensaje = $this->mensajeRepo->buscarPorId($id);

            if ($mensaje === null) {
                $this->logger->warning('Mensaje no encontrado en BD, descartado.', ['id' => $id]);
                $msg->ack();
                return;
            }

            $usuario = $this->usuarioRepo->buscarPorId($mensaje->usuarioId);

            // usuario desconocido: se crea como normal y se trata como tal
            if ($usuario === null) {
                $this->logger->warning(
                    "Usuario desconocido '{$mensaje->usuarioId}', creado como normal.",
                    ['id' => $id]
                );
                $this->usuarioRepo->crearUsuarioNormal($mensaje->usuarioId, $mensaje->telefono);
                $usuario = ['es_especial' => 0];
            }

            $this->enrutar($mensaje, (bool) $usuario['es_especial']);

            $msg->ack();

        } catch (\PDOException $e) {
            // error de BD: warning + nack con requeue (el reintentador lo recuperará)
            $this->logger->warning(
                'Error de BD al procesar mensaje, se reencola.',
                ['id' => $id, 'error' => $e->getMessage()]
            );
            $msg->nack(requeue: true);

        } catch (\Exception $e) {
            $this->logger->error(
                'Error inesperado, mensaje descartado sin requeue.',
                ['id' => $id, 'error' => $e->getMessage()]
            );
            $msg->nack(requeue: false);
        }
    }

    /**
     * Decide la ruta del mensaje y lo publica en la cola correspondiente.
     *
     * Reglas de enrutamiento:
     *   1. Si es_urgente → directo con prioridad alta (siempre).
     *   2. Si es_especial y NO urgente → delayed hasta próxima ventana.
     *   3. En cualquier otro caso → directo con prioridad normal.
     *
     * @param Mensaje $mensaje   Datos del mensaje desde BD.
     * @param bool    $esEspecial Si el usuario es de tipo especial.
     */
    private function enrutar(Mensaje $mensaje, bool $esEspecial): void
    {
        if ($mensaje->esUrgente) {
            $this->publicarDirecto($mensaje, self::PRIORIDAD_URGENTE);
            $this->logger->info('Enrutado: URGENTE → directo con prioridad alta', ['id' => $mensaje->id]);
            return;
        }

        if ($esEspecial) {
            $this->publicarDelayed($mensaje);
            $this->logger->info('Enrutado: especial → delayed', ['id' => $mensaje->id]);
            return;
        }

        $this->publicarDirecto($mensaje, self::PRIORIDAD_NORMAL);
        $this->logger->info('Enrutado: normal → directo', ['id' => $mensaje->id]);
    }

    /**
     * Publica el ID del mensaje en la cola directa con la prioridad indicada.
     *
     * @param Mensaje $mensaje  Mensaje a publicar.
     * @param int     $prioridad 0 (normal) o 10 (urgente).
     */
    private function publicarDirecto(Mensaje $mensaje, int $prioridad): void
    {
        $amqpMsg = new AMQPMessage((string) $mensaje->id, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority'      => $prioridad,
        ]);

        $this->channel->basic_publish($amqpMsg, '', self::COLA_DIRECTA);
    }

    /**
     * Publica el ID en el exchange delayed y actualiza el estado en BD.
     *
     * Calcula cuántos ms faltan para la próxima ventana del usuario
     * (múltiplo del intervalo desde la hora en punto) y lo pone en
     * el header `x-delay` que lee el plugin.
     *
     * @param Mensaje $mensaje Mensaje a encolar en delayed.
     */
    private function publicarDelayed(Mensaje $mensaje): void
    {
        $intervalo = $this->usuarioRepo->obtenerIntervalo($mensaje->usuarioId);
        [$delayMs, $ventana] = $this->calcularDelay($intervalo);

        $amqpMsg = new AMQPMessage((string) $mensaje->id, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable(['x-delay' => $delayMs]),
        ]);

        $this->channel->basic_publish($amqpMsg, self::EXCHANGE_DELAY, self::COLA_DIRECTA);

        // actualizar estado y guardar cuándo expirará el delay
        $this->mensajeRepo->marcarEnEspera($mensaje->id, $ventana);
    }

    /**
     * Calcula el delay en milisegundos hasta la próxima ventana global.
     *
     * La ventana se basa en múltiplos del intervalo desde la hora en punto.
     * Ejemplo con intervalo=30: son las 10:17 → próxima ventana: 10:30 → 13 min.
     *
     * @param int $intervaloMinutos Intervalo de agrupación del usuario.
     * @return array{int, \DateTimeImmutable} [delay en ms, timestamp de la ventana]
     */
    public function calcularDelay(int $intervaloMinutos): array
    {
        $ahora          = new \DateTimeImmutable('now');
        $minutoActual   = (int) $ahora->format('i');
        $minutosRestantes = $intervaloMinutos - ($minutoActual % $intervaloMinutos);

        $ventana  = $ahora->modify("+{$minutosRestantes} minutes");
        $delayMs  = $minutosRestantes * 60 * 1000;

        return [$delayMs, $ventana];
    }
}
