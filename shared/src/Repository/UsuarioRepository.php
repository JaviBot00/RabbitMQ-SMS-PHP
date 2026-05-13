<?php

declare(strict_types=1);

namespace RabbitmqSms\Shared\Repository;

use PDO;

/**
 * Acceso a datos de la tabla `usuarios`.
 *
 * Centraliza las queries sobre usuarios para que el intermediario
 * y el consumer no repitan SQL.
 */
final class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Busca un usuario por su ID.
     *
     * @return array<string, mixed>|null null si el usuario no existe.
     */
    public function buscarPorId(string $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM usuarios WHERE usuario_id = :id'
        );
        $stmt->execute(['id' => $usuarioId]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Devuelve el intervalo de agrupación en minutos para un usuario especial.
     *
     * Prioridad:
     *   1. Intervalo propio del usuario (intervalo_minutos IS NOT NULL).
     *   2. Intervalo global de la tabla configuracion.
     *   3. Valor por defecto de 30 minutos si no hay config.
     *
     * @param string $usuarioId FK del usuario.
     * @return int Minutos de intervalo.
     */
    public function obtenerIntervalo(string $usuarioId): int
    {
        // intervalo propio
        $stmt = $this->pdo->prepare(
            'SELECT intervalo_minutos FROM usuarios WHERE usuario_id = :id'
        );
        $stmt->execute(['id' => $usuarioId]);
        $row = $stmt->fetch();

        if ($row !== false && $row['intervalo_minutos'] !== null) {
            return (int) $row['intervalo_minutos'];
        }

        // intervalo global
        $stmt = $this->pdo->query(
            "SELECT valor FROM configuracion WHERE clave = 'intervalo_minutos'"
        );
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['valor'] : 30;
    }

    /**
     * Actualiza `ultimo_envio` al momento actual.
     *
     * Lo llama el consumer tras confirmar un envío exitoso.
     *
     * @param string $usuarioId FK del usuario.
     */
    public function actualizarUltimoEnvio(string $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET ultimo_envio = NOW() WHERE usuario_id = :id'
        );
        $stmt->execute(['id' => $usuarioId]);
    }

    /**
     * Crea un usuario desconocido como normal (es_especial = 0).
     *
     * El intermediario lo llama cuando llega un mensaje de un usuario
     * que aún no existe en la BD.
     *
     * @param string $usuarioId  ID del nuevo usuario.
     * @param string $telefono   Número de teléfono.
     */
    public function crearUsuarioNormal(string $usuarioId, string $telefono): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (usuario_id, telefono, es_especial, intervalo_minutos)
             VALUES (:usuario_id, :telefono, 0, NULL)'
        );
        $stmt->execute([
            'usuario_id' => $usuarioId,
            'telefono'   => $telefono,
        ]);
    }
}
