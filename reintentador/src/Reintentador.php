<?php

declare(strict_types=1);

namespace RabbitmqSms\Reintentador;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use RabbitmqSms\Shared\Model\Mensaje;
use RabbitmqSms\Shared\Repository\MensajeRepository;

/**
 * Reintentador de mensajes SMS.
 *
 * Proceso que se ejecuta periódicamente (como un cron) y recupera
 * todos los mensajes que no están en estado `enviado` y que cumplen
 * las condiciones para ser reencolados:
 *
 *   - `pendiente`  → nunca llegó a publicarse; se reencola siempre.
 *   - `encolado`   → lleva más de X minutos sin avanzar (atascado); se reencola.
 *   - `en_espera`  → el delayed ya debería haber expirado pero no se procesó; se reencola.
 *   - `error`      → falló el envío; se reencola respetando el backoff exponencial.
 *
 * El reintentador publica en `sms.entrada` (no directamente en `sms.directo`)
 * para que el intermediario vuelva a aplicar la lógica de enrutamiento.
 *
 * Límite de reintentos configurable; 0 = infinito (no recomendado en producción).
 */
final class Reintentador
{
    private const COLA_ENTRADA = 'sms.entrada';

    public function __construct(
        private readonly MensajeRepository $mensajeRepo,
        private readonly AMQPChannel       $channel,
        private readonly LoggerInterface   $logger,
        /** Minutos que un mensaje puede estar en `encolado` antes de considerarse atascado. */
        private readonly int $encoladoTimeoutMinutos,
        /** Máximo de reintentos permitidos. 0 = infinito. */
        private readonly int $reintentosMaximos,
    ) {}

    /**
     * Ejecuta un ciclo completo de revisión y reencola de mensajes.
     *
     * Llamar periódicamente desde el bucle principal de run.php.
     */
    public function ejecutar(): void
    {
        $this->logger->info('Reintentador: iniciando ciclo de revisión.');

        $candidatos = $this->mensajeRepo->obtenerParaReintento($this->encoladoTimeoutMinutos);

        if (empty($candidatos)) {
            $this->logger->info('Reintentador: no hay mensajes que procesar.');
            return;
        }

        $this->logger->info(sprintf(
            'Reintentador: %d mensaje(s) candidatos.',
            count($candidatos)
        ));

        foreach ($candidatos as $mensaje) {
            $this->procesarCandidato($mensaje);
        }

        $this->logger->info('Reintentador: ciclo completado.');
    }

    /**
     * Evalúa un mensaje candidato y decide si reencolarlo o descartarlo.
     *
     * @param Mensaje $mensaje Mensaje candidato a reintento.
     */
    private function procesarCandidato(Mensaje $mensaje): void
    {
        // comprobar límite de reintentos (0 = infinito)
        if ($this->reintentosMaximos > 0 && $mensaje->reintentos >= $this->reintentosMaximos) {
            $this->logger->warning(
                'Mensaje alcanzó el límite de reintentos, se descarta.',
                [
                    'id'          => $mensaje->id,
                    'reintentos'  => $mensaje->reintentos,
                    'max'         => $this->reintentosMaximos,
                ]
            );
            // marcamos como error definitivo para que no vuelva a aparecer
            $this->mensajeRepo->marcarComoError($mensaje->id, backoffMinutos: 99999);
            return;
        }

        $this->reencolar($mensaje->id);
    }

    /**
     * Publica el ID del mensaje en `sms.entrada` y actualiza su estado a `encolado`.
     *
     * @param int $id ID del mensaje a reencolar.
     */
    private function reencolar(int $id): void
    {
        $amqpMsg = new AMQPMessage((string) $id, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type'  => 'text/plain',
        ]);

        $this->channel->basic_publish($amqpMsg, '', self::COLA_ENTRADA);

        // actualizamos el estado para que no vuelva a ser candidato
        // hasta que el intermediario lo procese
        $this->mensajeRepo->marcarComoEncolado($id);

        $this->logger->info('Mensaje reencolado en sms.entrada.', ['id' => $id]);
    }

    /**
     * Declara la cola de entrada si aún no existe.
     *
     * Llamar una vez al iniciar el proceso.
     */
    public function declararCola(): void
    {
        $this->channel->queue_declare(
            queue:       self::COLA_ENTRADA,
            passive:     false,
            durable:     true,
            exclusive:   false,
            auto_delete: false
        );
    }
}
