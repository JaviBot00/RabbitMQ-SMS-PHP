# Reintentador

Recupera mensajes no enviados y los reencola respetando el backoff.

## Responsabilidad

Corre periódicamente y procesa mensajes en cualquier estado salvo `enviado`:

| Estado | Condición |
|---|---|
| `pendiente` | Siempre |
| `encolado` | Lleva más de `encolado_timeout_minutos` sin avanzar |
| `en_espera` | `proximo_reintento` ya pasó |
| `error` | `proximo_reintento` ya pasó (backoff cumplido) |

Cuando un mensaje alcanza `reintentos_maximos` se descarta (sin borrar de BD).
Con `reintentos_maximos = 0` el límite es infinito.

## Variables de entorno

Igual que el Emisor. Ver [`emisor/README.md`](../emisor/README.md).

Los parámetros de comportamiento se leen de la tabla `configuracion`:
`reintentador_intervalo_seg`, `encolado_timeout_minutos`, `reintentos_maximos`.
