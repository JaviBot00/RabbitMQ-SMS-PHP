<?php

declare(strict_types=1);

namespace RabbitmqSms\Shared\Connection;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Factoría de conexiones AMQP a RabbitMQ mediante php-amqplib.
 *
 * Gestiona una única conexión y un único canal por proceso (singleton).
 * El canal se puede cerrar y reabrir sin tirar la conexión TCP subyacente.
 *
 * Uso:
 * ```php
 * $channel = AmqpConnection::channel();
 * ```
 */
final class AmqpConnection
{
    /** @var AMQPStreamConnection|null Conexión TCP al broker. */
    private static ?AMQPStreamConnection $connection = null;

    /** @var AMQPChannel|null Canal AMQP activo. */
    private static ?AMQPChannel $channel = null;

    /** No se permiten instancias directas; usar ::channel(). */
    private function __construct() {}

    /**
     * Devuelve el canal AMQP, abriendo conexión y canal si es necesario.
     *
     * Lee las credenciales desde variables de entorno:
     *   - RABBITMQ_HOST, RABBITMQ_PORT, RABBITMQ_USER, RABBITMQ_PASS
     *
     * @throws \Exception Si la conexión o el canal no pueden abrirse.
     */
    public static function channel(): AMQPChannel
    {
        if (self::$connection === null || !self::$connection->isConnected()) {
            self::$connection = self::createConnection();
        }

        if (self::$channel === null || !self::$channel->is_open()) {
            self::$channel = self::$connection->channel();
        }

        return self::$channel;
    }

    /**
     * Cierra canal y conexión de forma ordenada.
     *
     * Llamar siempre al final del proceso o ante errores irrecuperables.
     */
    public static function close(): void
    {
        if (self::$channel !== null && self::$channel->is_open()) {
            self::$channel->close();
        }

        if (self::$connection !== null && self::$connection->isConnected()) {
            self::$connection->close();
        }

        self::$channel    = null;
        self::$connection = null;
    }

    /**
     * Crea la conexión TCP al broker con los parámetros de entorno.
     *
     * @throws \Exception Si el broker no está disponible.
     */
    private static function createConnection(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host:     (string) getenv('RABBITMQ_HOST'),
            port:     (int)    getenv('RABBITMQ_PORT'),
            user:     (string) getenv('RABBITMQ_USER'),
            password: (string) getenv('RABBITMQ_PASS'),
            heartbeat: 60   // mantiene viva la conexión en periodos sin mensajes
        );
    }
}
