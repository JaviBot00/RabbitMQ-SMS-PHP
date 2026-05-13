<?php

declare(strict_types=1);

namespace RabbitmqSms\Shared\Connection;

use PDO;
use PDOException;

/**
 * Factoría de conexiones PDO a MySQL.
 *
 * Proporciona una única instancia de PDO (singleton por proceso) configurada
 * con el charset utf8mb4, modo de errores por excepción y sin emulación de
 * prepared statements para mayor seguridad.
 *
 * Uso:
 * ```php
 * $pdo = DatabaseConnection::get();
 * ```
 */
final class DatabaseConnection
{
    /** @var PDO|null Instancia compartida dentro del mismo proceso PHP. */
    private static ?PDO $instance = null;

    /** No se permiten instancias directas; usar ::get(). */
    private function __construct() {}

    /**
     * Devuelve la conexión PDO, creándola si aún no existe.
     *
     * Lee las credenciales desde variables de entorno:
     *   - MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD
     *
     * @throws PDOException Si la conexión falla.
     */
    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::create();
        }

        return self::$instance;
    }

    /**
     * Cierra la conexión activa (útil en procesos de larga duración
     * que necesiten reconectar tras un error de red).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Construye y configura la instancia PDO.
     *
     * @throws PDOException Si los parámetros son inválidos o el servidor no responde.
     */
    private static function create(): PDO
    {
        $host     = (string) getenv('MYSQL_HOST');
        $port     = (string) getenv('MYSQL_PORT');
        $database = (string) getenv('MYSQL_DATABASE');
        $user     = (string) getenv('MYSQL_USER');
        $password = (string) getenv('MYSQL_PASSWORD');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lanza PDOException en errores
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // filas como arrays asociativos
            PDO::ATTR_EMULATE_PREPARES   => false,                     // prepared statements reales
        ]);
    }
}
