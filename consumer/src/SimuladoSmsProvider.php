<?php

declare(strict_types=1);

namespace RabbitmqSms\Consumer;

use Psr\Log\LoggerInterface;

/**
 * Proveedor SMS simulado para entornos de prueba.
 *
 * En producción sustituir esta clase por una implementación real
 * que llame al proveedor elegido (Twilio, Vonage, etc.) y devuelva
 * true solo si la confirmación es satisfactoria.
 *
 * Implementación de referencia:
 * ```php
 * $response = $httpClient->post($url, ['json' => ['to' => $telefono, 'body' => $contenido]]);
 * return $response->getStatusCode() === 200;
 * ```
 */
final class SimuladoSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        /** Permite forzar un fallo en tests de escenarios de error. */
        private readonly bool $simularFallo = false,
    ) {}

    /**
     * {@inheritdoc}
     *
     * Simula el envío escribiendo en el log. Devuelve false si $simularFallo = true.
     */
    public function enviar(string $telefono, string $contenido): bool
    {
        if ($this->simularFallo) {
            $this->logger->warning('SMS simulado: fallo forzado.', [
                'telefono' => $telefono,
            ]);
            return false;
        }

        $this->logger->info('>>> SMS ENVIADO (simulado)', [
            'telefono' => $telefono,
            'contenido' => $contenido,
        ]);

        return true;
    }
}
