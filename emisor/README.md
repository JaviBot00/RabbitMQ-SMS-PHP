# Emisor

Inserta mensajes en la BD y publica sus IDs en la cola `sms.entrada`.

## Responsabilidad

1. Recibe la orden de enviar un SMS a un usuario ya registrado.
2. Hace `INSERT` en `sms_mensajes` con estado `pendiente`.
3. Consulta los mensajes `pendiente` de la BD (nunca de RAM).
4. Publica el ID en `sms.entrada`.
5. Actualiza el estado a `encolado`.

No gestiona reintentos ni recuperación — eso es responsabilidad del Reintentador.

## Variables de entorno

| Variable | Descripción |
|---|---|
| `RABBITMQ_HOST` | Host del broker |
| `RABBITMQ_PORT` | Puerto AMQP (default 5672) |
| `RABBITMQ_USER` | Usuario |
| `RABBITMQ_PASS` | Contraseña |
| `MYSQL_HOST` | Host de MySQL |
| `MYSQL_PORT` | Puerto (default 3306) |
| `MYSQL_DATABASE` | Nombre de la BD |
| `MYSQL_USER` | Usuario |
| `MYSQL_PASSWORD` | Contraseña |
