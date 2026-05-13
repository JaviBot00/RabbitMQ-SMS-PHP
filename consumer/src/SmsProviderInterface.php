<?php

declare(strict_types=1);

namespace RabbitmqSms\Consumer;

/**
 * Contrato que debe cumplir cualquier proveedor de envío SMS.
 *
 * Desacopla el Consumer de la implementación concreta del proveedor,
 * facilitando el testing (mocks) y el cambio de proveedor sin tocar
 * la lógica de negocio.
 */
interface SmsProviderInterface
{
    /**
     * Envía un SMS al número indicado con el contenido dado.
     *
     * @param string $telefono Número de destino en formato internacional (ej: +34600000001).
     * @param string $contenido Texto del mensaje.
     * @return bool true si el proveedor confirmó el envío, false si falló.
     */
    public function enviar(string $telefono, string $contenido): bool;
}
