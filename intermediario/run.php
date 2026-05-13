<?php

declare(strict_types=1);

/**
 * run.php — Punto de entrada del Intermediario.
 *
 * Proceso de larga duración que escucha la cola `sms.entrada`
 * y enruta cada mensaje a su destino correspondiente.
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
use RabbitmqSms\Intermediario\Intermediario;

$logger = new Logger('INTERMEDIARIO');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

while (true) {
    try {
        $pdo     = DatabaseConnection::get();
        $channel = AmqpConnection::channel();

        $intermediario = new Intermediario(
            mensajeRepo: new MensajeRepository($pdo),
            usuarioRepo: new UsuarioRepository($pdo),
            channel:     $channel,
            logger:      $logger,
        );

        $intermediario->declararTopologia();
        $intermediario->escuchar();

    } catch (\Exception $e) {
        $logger->error('Error de conexión, reintentando en 5s...', ['error' => $e->getMessage()]);
        AmqpConnection::close();
        DatabaseConnection::reset();
        sleep(5);
    }
}
