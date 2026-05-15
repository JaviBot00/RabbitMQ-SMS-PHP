<?php

declare(strict_types=1);

/**
 * run.php — Punto de entrada del Consumer.
 *
 * Proceso de larga duración que escucha la cola `sms.directo`
 * y gestiona el envío real de cada SMS.
 *
 * Variables de entorno requeridas: ver .env en la raíz del proyecto.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RabbitmqSms\Shared\Connection\AmqpConnection;
use RabbitmqSms\Shared\Connection\DatabaseConnection;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;
use RabbitmqSms\Consumer\Consumer;
use RabbitmqSms\Consumer\SimuladoSmsProvider;

$logger = new Logger('CONSUMER');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

while (true) {
    try {
        $pdo     = DatabaseConnection::get();
        $channel = AmqpConnection::channel();

        // leer backoff base desde la BD
        $stmt = $pdo->query(
            "SELECT valor FROM configuracion WHERE clave = 'backoff_base_minutos'"
        );
        $row              = $stmt->fetch();
        $backoffBase      = $row !== false ? (int) $row['valor'] : 2;

        $consumer = new Consumer(
            mensajeRepo:        new MensajeRepository($pdo),
            usuarioRepo:        new UsuarioRepository($pdo),
            smsProvider:        new SimuladoSmsProvider($logger),
            channel:            $channel,
            logger:             $logger,
            backoffBaseMinutos: $backoffBase,
        );

        $consumer->declararCola();
        $consumer->escuchar();

    } catch (\Exception $e) {
        $logger->error('Error de conexión, reintentando en 5s...', ['error' => $e->getMessage()]);
        AmqpConnection::close();
        DatabaseConnection::reset();
        sleep(5);
    }
}