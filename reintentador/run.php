<?php

declare(strict_types=1);

/**
 * run.php — Punto de entrada del Reintentador.
 *
 * Proceso de larga duración que ejecuta un ciclo de revisión cada
 * N segundos (configurable en .env como REINTENTADOR_INTERVALO_SEG).
 *
 * Variables de entorno requeridas: ver .env en la raíz del proyecto.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RabbitmqSms\Shared\Connection\AmqpConnection;
use RabbitmqSms\Shared\Connection\DatabaseConnection;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Reintentador\Reintentador;

$logger = new Logger('REINTENTADOR');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

while (true) {
    try {
        $pdo     = DatabaseConnection::get();
        $channel = AmqpConnection::channel();

        // leer parámetros de configuración desde la BD
        $stmt = $pdo->query(
            "SELECT clave, valor FROM configuracion
             WHERE clave IN ('reintentador_intervalo_seg','encolado_timeout_minutos','reintentos_maximos')"
        );
        $config = array_column($stmt->fetchAll(), 'valor', 'clave');

        $intervaloSeg           = (int) ($config['reintentador_intervalo_seg']   ?? 120);
        $encoladoTimeoutMinutos = (int) ($config['encolado_timeout_minutos']     ?? 5);
        $reintentosMaximos      = (int) ($config['reintentos_maximos']           ?? 5);

        $reintentador = new Reintentador(
            mensajeRepo:             new MensajeRepository($pdo),
            channel:                 $channel,
            logger:                  $logger,
            encoladoTimeoutMinutos:  $encoladoTimeoutMinutos,
            reintentosMaximos:       $reintentosMaximos,
        );

        $reintentador->declararCola();

        $logger->info(sprintf(
            'Reintentador activo. Ciclo cada %ds | timeout encolado: %dmin | max reintentos: %s',
            $intervaloSeg,
            $encoladoTimeoutMinutos,
            $reintentosMaximos === 0 ? '∞' : $reintentosMaximos
        ));

        while (true) {
            $reintentador->ejecutar();
            sleep($intervaloSeg);
        }

    } catch (\Exception $e) {
        $logger->error('Error de conexión, reintentando en 10s...', ['error' => $e->getMessage()]);
        AmqpConnection::close();
        DatabaseConnection::reset();
        sleep(10);
    }
}
