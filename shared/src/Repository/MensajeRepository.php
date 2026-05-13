<?php

declare(strict_types=1);

namespace RabbitmqSms\Shared\Repository;

use PDO;
use RabbitmqSms\Shared\Model\Mensaje;

/**
 * Acceso a datos de la tabla `sms_mensajes`.
 *
 * Centraliza todas las queries relacionadas con mensajes para que
 * ningún actor repita SQL. Cada método es atómico; las transacciones
 * multi-paso se gestionan en el actor correspondiente.
 */
final class MensajeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Inserta un nuevo mensaje en estado `pendiente` y devuelve el ID generado.
     *
     * @param string $usuarioId FK a usuarios.
     * @param string $telefono  Número de destino.
     * @param string $contenido Texto del SMS.
     * @param bool   $esUrgente true = prioridad alta.
     * @return int ID del mensaje insertado.
     */
    public function insertar(
        string $usuarioId,
        string $telefono,
        string $contenido,
        bool   $esUrgente
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sms_mensajes (usuario_id, telefono, contenido, es_urgente, estado, creado_en)
             VALUES (:usuario_id, :telefono, :contenido, :es_urgente, \'pendiente\', NOW())'
        );

        $stmt->execute([
            'usuario_id' => $usuarioId,
            'telefono'   => $telefono,
            'contenido'  => $contenido,
            'es_urgente' => (int) $esUrgente,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Busca un mensaje por su ID.
     *
     * @return Mensaje|null null si no existe.
     */
    public function buscarPorId(int $id): ?Mensaje
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sms_mensajes WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? Mensaje::fromRow($row) : null;
    }

    /**
     * Devuelve todos los mensajes en estado `pendiente`.
     *
     * El emisor los usa para publicar en la cola tras confirmar que
     * el INSERT se realizó correctamente.
     *
     * @return Mensaje[]
     */
    public function obtenerPendientes(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM sms_mensajes WHERE estado = 'pendiente' ORDER BY id ASC"
        );

        return array_map(
            fn(array $row) => Mensaje::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Devuelve mensajes candidatos a reintento.
     *
     * Se excluyen los mensajes en estado `enviado` (ya terminados) y los que
     * están en `en_espera` con proximo_reintento en el futuro (aún dentro de
     * su ventana delayed).
     *
     * @param int $encoladoTimeoutMinutos Minutos tras los que un mensaje en
     *                                    estado `encolado` se considera atascado.
     * @return Mensaje[]
     */
    public function obtenerParaReintento(int $encoladoTimeoutMinutos): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sms_mensajes
             WHERE estado != 'enviado'
               AND (
                   -- pendiente: nunca llegó a publicarse
                   estado = 'pendiente'

                   -- encolado pero atascado: lleva demasiado tiempo sin avanzar
                   OR (estado = 'encolado'
                       AND encolado_en <= NOW() - INTERVAL :timeout MINUTE)

                   -- en_espera: el delayed ya debería haber expirado pero no se envió
                   OR (estado = 'en_espera'
                       AND proximo_reintento IS NOT NULL
                       AND proximo_reintento <= NOW())

                   -- error: respetando el backoff calculado
                   OR (estado = 'error'
                       AND (proximo_reintento IS NULL OR proximo_reintento <= NOW()))
               )
             ORDER BY es_urgente DESC, id ASC"
        );

        $stmt->execute(['timeout' => $encoladoTimeoutMinutos]);

        return array_map(
            fn(array $row) => Mensaje::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Actualiza el estado de un mensaje a `encolado` y registra el momento.
     *
     * Lo llaman el emisor y el reintentador justo después de publicar el ID.
     */
    public function marcarComoEncolado(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_mensajes
             SET estado = 'encolado', encolado_en = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Actualiza el estado a `en_espera` cuando el intermediario lo manda al delayed.
     *
     * @param int             $id             ID del mensaje.
     * @param \DateTimeInterface $ventana      Momento en que expirará el delay (próxima ventana).
     */
    public function marcarEnEspera(int $id, \DateTimeInterface $ventana): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_mensajes
             SET estado = 'en_espera', proximo_reintento = :ventana
             WHERE id = :id"
        );
        $stmt->execute([
            'id'      => $id,
            'ventana' => $ventana->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Marca el mensaje como `enviado` y registra el timestamp de confirmación.
     *
     * Lo llama el consumer tras recibir confirmación del proveedor.
     */
    public function marcarComoEnviado(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_mensajes
             SET estado = 'enviado', enviado_en = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Marca el mensaje como `error` y actualiza los contadores de reintento.
     *
     * @param int $id              ID del mensaje.
     * @param int $backoffMinutos  Minutos hasta el próximo intento (backoff exponencial).
     */
    public function marcarComoError(int $id, int $backoffMinutos): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_mensajes
             SET estado            = 'error',
                 reintentos        = reintentos + 1,
                 proximo_reintento = NOW() + INTERVAL :backoff MINUTE
             WHERE id = :id"
        );
        $stmt->execute([
            'id'      => $id,
            'backoff' => $backoffMinutos,
        ]);
    }
}
