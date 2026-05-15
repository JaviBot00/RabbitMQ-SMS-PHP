<?php

declare(strict_types=1);

/**
 * run.php — Punto de entrada del Emisor.
 *
 * Script de prueba que simula el sistema externo generando mensajes
 * para los usuarios de prueba definidos en init.sql.
 *
 * En producción este script no existe: el emisor real será el código
 * de la aplicación que llame a Emisor::emitir() directamente.
 *
 * Uso:
 *   php run.php
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
use RabbitmqSms\Emisor\Emisor;

// ─── LOGGER ──────────────────────────────────────────────────────────────────
$logger = new Logger('EMISOR');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// ─── MENSAJES DE PRUEBA ───────────────────────────────────────────────────────
// Coinciden con los usuarios insertados en init.sql.
$mensajesPrueba = [
    ['usuario_id' => 'user_normal_01',   'contenido' => 'Tienes una tarea pendiente', 'es_urgente' => false],
    ['usuario_id' => 'user_normal_02',   'contenido' => 'Tienes una tarea pendiente', 'es_urgente' => false],
    ['usuario_id' => 'user_especial_01', 'contenido' => 'Tienes una tarea pendiente', 'es_urgente' => false],
    ['usuario_id' => 'user_especial_02', 'contenido' => 'Tienes una tarea pendiente', 'es_urgente' => false],
    ['usuario_id' => 'user_normal_01',   'contenido' => 'URGENTE: servidor caído',    'es_urgente' => true],
];

$pausaEntreRondas = 10; // segundos entre rondas de prueba

// ─── BUCLE PRINCIPAL ─────────────────────────────────────────────────────────
$ronda = 1;

while (true) {
    try {
        $pdo     = DatabaseConnection::get();
        $channel = AmqpConnection::channel();

        $emisor = new Emisor(
            mensajeRepo: new MensajeRepository($pdo),
            usuarioRepo: new UsuarioRepository($pdo),
            channel:     $channel,
            logger:      $logger,
        );

        $emisor->declararCola();

        // // al arrancar, procesar cualquier pendiente que quedara de antes
        // if ($ronda === 1) {
        //     $emisor->procesarPendientes();
        // }

        $logger->info(sprintf('── Ronda %d ─────────────────────────────────', $ronda));

        foreach ($mensajesPrueba as $datos) {
            try {
                $emisor->emitir(
                    usuarioId:  $datos['usuario_id'],
                    contenido:  $datos['contenido'],
                    esUrgente:  $datos['es_urgente'],
                );
                sleep(1); // pequeña pausa entre mensajes de la misma ronda
            } catch (\RuntimeException $e) {
                // usuario no encontrado: logueamos y seguimos con el siguiente
                $logger->warning('Mensaje omitido: ' . $e->getMessage());
            }
        }

        $logger->info(sprintf('Ronda %d completada. Esperando %ds...', $ronda, $pausaEntreRondas));
        $ronda++;
        sleep($pausaEntreRondas);

    } catch (\Exception $e) {
        $logger->error('Error de conexión, reintentando en 5s...', ['error' => $e->getMessage()]);
        AmqpConnection::close();
        DatabaseConnection::reset();
        sleep(5);
    }
}
