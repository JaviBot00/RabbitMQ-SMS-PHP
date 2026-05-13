# Intermediario

Enruta cada mensaje al destino correcto según las reglas de negocio.

## Responsabilidad

1. Escucha `sms.entrada` de forma continua.
2. Por cada ID recibido, consulta `sms_mensajes` y `usuarios`.
3. Decide la ruta:
   - **Urgente** → `sms.directo` con priority=10.
   - **Normal + no especial** → `sms.directo` con priority=0.
   - **Normal + especial** → `sms.delayed` (espera la próxima ventana).
4. Ante errores de BD lanza warnings y hace nack+requeue.

## Variables de entorno

Igual que el Emisor. Ver [`emisor/README.md`](../emisor/README.md).
