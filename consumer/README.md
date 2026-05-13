# Consumer

Ejecuta el envío real del SMS y actualiza la BD con el resultado.

## Responsabilidad

1. Escucha `sms.directo` (mensajes directos y liberados del delayed).
2. Por cada ID recibido, consulta `sms_mensajes` para obtener datos frescos.
3. Llama al proveedor SMS (`SmsProviderInterface`).
4. Si el envío es exitoso: marca `enviado` y actualiza `ultimo_envio`.
5. Si falla: marca `error` con backoff exponencial.

## Proveedor SMS

En producción, implementar `SmsProviderInterface` con el proveedor real
(Twilio, Vonage, etc.) y sustituir `SimuladoSmsProvider` en `run.php`.

## Variables de entorno

Igual que el Emisor. Ver [`emisor/README.md`](../emisor/README.md).
