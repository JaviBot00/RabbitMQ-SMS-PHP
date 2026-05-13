<?php

declare(strict_types=1);

namespace RabbitmqSms\Shared\Model;

/**
 * Representa un mensaje SMS en cualquier punto de su ciclo de vida.
 *
 * Es un objeto de solo lectura (value object) que transporta el estado
 * de una fila de `sms_mensajes` entre los distintos actores del sistema.
 * No contiene lógica de negocio; esa responsabilidad recae en los repositorios
 * y en cada actor.
 */
final class Mensaje
{
    /**
     * @param int         $id               Identificador único (AUTO_INCREMENT).
     * @param string      $usuarioId        FK a la tabla usuarios.
     * @param string      $telefono         Número de destino en el momento del INSERT.
     * @param string      $contenido        Texto del SMS.
     * @param bool        $esUrgente        true = prioridad alta en la cola sms.directo.
     * @param string      $estado           Uno de: pendiente|encolado|en_espera|enviado|error.
     * @param \DateTimeImmutable $creadoEn  Momento en que el emisor hizo el INSERT.
     * @param \DateTimeImmutable|null $encoladoEn    Cuando el emisor publicó el ID en la cola.
     * @param \DateTimeImmutable|null $enviadoEn     Cuando el consumer confirmó el envío.
     * @param int         $reintentos       Número de reintentos realizados hasta ahora.
     * @param \DateTimeImmutable|null $proximoReintento  Cuándo puede volver a intentarse.
     */
    public function __construct(
        public readonly int                  $id,
        public readonly string               $usuarioId,
        public readonly string               $telefono,
        public readonly string               $contenido,
        public readonly bool                 $esUrgente,
        public readonly string               $estado,
        public readonly \DateTimeImmutable   $creadoEn,
        public readonly ?\DateTimeImmutable  $encoladoEn       = null,
        public readonly ?\DateTimeImmutable  $enviadoEn        = null,
        public readonly int                  $reintentos       = 0,
        public readonly ?\DateTimeImmutable  $proximoReintento = null,
    ) {}

    /**
     * Construye un Mensaje a partir de una fila de BD (array asociativo).
     *
     * @param array<string, mixed> $row Fila devuelta por PDO en modo FETCH_ASSOC.
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:               (int)  $row['id'],
            usuarioId:        (string) $row['usuario_id'],
            telefono:         (string) $row['telefono'],
            contenido:        (string) $row['contenido'],
            esUrgente:        (bool)   $row['es_urgente'],
            estado:           (string) $row['estado'],
            creadoEn:         new \DateTimeImmutable($row['creado_en']),
            encoladoEn:       isset($row['encolado_en'])
                                ? new \DateTimeImmutable($row['encolado_en'])
                                : null,
            enviadoEn:        isset($row['enviado_en'])
                                ? new \DateTimeImmutable($row['enviado_en'])
                                : null,
            reintentos:       (int) $row['reintentos'],
            proximoReintento: isset($row['proximo_reintento'])
                                ? new \DateTimeImmutable($row['proximo_reintento'])
                                : null,
        );
    }

    /**
     * Indica si el mensaje puede ser procesado por el reintentador ahora mismo.
     *
     * Un mensaje con proximo_reintento en el futuro debe esperar.
     */
    public function listoParareintentar(): bool
    {
        if ($this->proximoReintento === null) {
            return true;
        }

        return $this->proximoReintento <= new \DateTimeImmutable('now');
    }
}
