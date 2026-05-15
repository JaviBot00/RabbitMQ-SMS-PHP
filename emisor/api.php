<?php

declare(strict_types=1);

/**
 * api.php — Endpoint JSON para el panel de pruebas de carga.
 *
 * Acepta POST con:
 *   - usuario_id  (string, requerido)
 *   - contenido   (string, requerido)
 *   - es_urgente  (string "1" | "0", opcional, default "0")
 *
 * Devuelve JSON:
 *   { "ok": true,  "id": 42 }          → éxito
 *   { "error": "mensaje de error" }    → fallo (HTTP 4xx/5xx)
 *
 * Uso:
 *   Coloca este archivo en la raíz del proyecto (junto a run.php).
 *   Necesita un servidor HTTP mínimo; puedes levantarlo con:
 *     php -S 0.0.0.0:8080
 *
 * CORS: permite cualquier origen para que el HTML pueda llamarlo
 * desde file:// o cualquier host durante pruebas.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RabbitmqSms\Emisor\Emisor;
use RabbitmqSms\Shared\Connection\AmqpConnection;
use RabbitmqSms\Shared\Connection\DatabaseConnection;
use RabbitmqSms\Shared\Repository\MensajeRepository;
use RabbitmqSms\Shared\Repository\UsuarioRepository;

// ── HEADERS ──────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.']);
    exit;
}

// ── INPUT ─────────────────────────────────────────────────────────────────────
$usuarioId = trim($_POST['usuario_id'] ?? '');
$contenido = trim($_POST['contenido']  ?? '');
$esUrgente = ($_POST['es_urgente'] ?? '0') === '1';

if ($usuarioId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'El campo usuario_id es obligatorio.']);
    exit;
}

if ($contenido === '') {
    http_response_code(400);
    echo json_encode(['error' => 'El campo contenido es obligatorio.']);
    exit;
}

// ── LOGGER (silencioso en API; escribe a stderr para no ensuciar la respuesta) ─
$logger = new Logger('API');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

// ── EMISOR ────────────────────────────────────────────────────────────────────
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
    $emisor->emitir($usuarioId, $contenido, $esUrgente);

    // Obtenemos el ID del último INSERT para devolvérselo al panel.
    // Si MensajeRepository::insertar() devuelve el ID, ajusta esta parte.
    $lastId = (int) $pdo->lastInsertId();

    echo json_encode(['ok' => true, 'id' => $lastId]);

} catch (\RuntimeException $e) {
    // Usuario no encontrado u otro error de lógica de negocio
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Error de infraestructura (BD caída, RabbitMQ, etc.)
    $logger->error('Error en api.php', ['exception' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
}