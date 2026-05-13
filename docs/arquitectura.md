# Arquitectura del sistema

## Flujo completo de un mensaje

```
Emisor
  │
  ├─ 1. INSERT sms_mensajes → estado: pendiente
  ├─ 2. SELECT mensajes pendientes
  ├─ 3. Publica ID en sms.entrada
  └─ 4. UPDATE estado: encolado
         │
         ▼
  Intermediario
         │
         ├─ 5. Recibe ID de sms.entrada
         ├─ 6. SELECT sms_mensajes por ID
         ├─ 7. SELECT usuarios por usuario_id
         │
         ├─ [urgente] ──────────────────────────────────────────────────────┐
         │    Publica ID en sms.directo con priority=10                     │
         │                                                                  │
         ├─ [no urgente + usuario normal] ──────────────────────────────────┤
         │    Publica ID en sms.directo con priority=0                      │
         │                                                                  │
         └─ [no urgente + usuario especial] ────────────────────────────────┤
              Publica ID en sms.delayed con x-delay=ms_hasta_ventana        │
              UPDATE estado: en_espera                                      │
              (cuando expira el delay → llega a sms.directo)               │
                                                                            │
                                                                            ▼
                                                                       Consumer
                                                                            │
                                                                  8. Recibe ID de sms.directo
                                                                  9. SELECT sms_mensajes por ID
                                                                 10. Llama al proveedor SMS
                                                                            │
                                                             ┌──────────────┴──────────────┐
                                                          éxito                          fallo
                                                             │                              │
                                                11. UPDATE estado: enviado      11. UPDATE estado: error
                                                    UPDATE ultimo_envio               + backoff exponencial
```

---

## Estados de un mensaje

| Estado | Significado | Quién lo pone |
|---|---|---|
| `pendiente` | Insertado en BD, aún no publicado en cola | Emisor (al hacer INSERT) |
| `encolado` | Publicado en `sms.entrada`, esperando al intermediario | Emisor / Reintentador |
| `en_espera` | En el exchange delayed, esperando su ventana | Intermediario |
| `enviado` | Confirmado por el proveedor | Consumer |
| `error` | Falló el envío; candidato a reintento con backoff | Consumer |

### Diagrama de transiciones

```
pendiente ──► encolado ──► en_espera ──► enviado
                 │               │
                 └───────────────┴──────► error ──► (reintentador) ──► encolado
```

---

## Reglas de enrutamiento (Intermediario)

| Condición | Destino | Prioridad |
|---|---|---|
| `es_urgente = true` | `sms.directo` | 10 (alta) |
| `es_urgente = false` + usuario normal | `sms.directo` | 0 (normal) |
| `es_urgente = false` + usuario especial | `sms.delayed` → `sms.directo` | 0 (normal) |

**Nota:** la urgencia siempre prevalece sobre el tipo de usuario. Un mensaje urgente de un usuario especial va directo, nunca al delayed.

---

## Lógica del Reintentador

El reintentador corre cada N segundos (configurable en `configuracion.reintentador_intervalo_seg`) y recupera mensajes candidatos según estas reglas:

| Estado | Condición para reintento |
|---|---|
| `pendiente` | Siempre (nunca llegó a publicarse) |
| `encolado` | Lleva más de `encolado_timeout_minutos` sin avanzar |
| `en_espera` | `proximo_reintento` ya pasó (el delayed expiró sin procesarse) |
| `error` | `proximo_reintento` ya pasó (respetando el backoff) |
| `enviado` | Nunca |

### Backoff exponencial

`backoff_minutos = backoff_base * 2^reintentos`

Con `backoff_base = 2`:

| Reintento | Espera |
|---|---|
| 1 | 2 min |
| 2 | 4 min |
| 3 | 8 min |
| 4 | 16 min |
| 5 | 32 min |

### Límite de reintentos

Configurable en `configuracion.reintentos_maximos`. Con valor `0` el límite es infinito (no recomendado en producción).

Cuando un mensaje alcanza el límite se marca como `error` con un backoff de 99999 minutos, efectivamente descartándolo sin borrarlo de la BD (conservamos el histórico).

---

## Tabla de usuarios

| Campo | Descripción |
|---|---|
| `usuario_id` | Identificador único |
| `telefono` | Número de destino de los SMS |
| `es_especial` | 0 = envío inmediato; 1 = espera ventana |
| `intervalo_minutos` | Ventana propia; NULL = usa el valor global |
| `ultimo_envio` | Timestamp del último envío confirmado |

## Tabla de mensajes

| Campo | Descripción |
|---|---|
| `id` | AUTO_INCREMENT, es el valor que viaja por las colas |
| `usuario_id` | FK a usuarios |
| `telefono` | Número destino en el momento del INSERT |
| `contenido` | Texto del SMS |
| `es_urgente` | 1 = prioridad alta, independiente del tipo de usuario |
| `estado` | Ver tabla de estados |
| `creado_en` | Momento del INSERT |
| `encolado_en` | Momento en que se publicó el ID |
| `enviado_en` | Momento en que el consumer confirmó el envío |
| `reintentos` | Contador de reintentos realizados |
| `proximo_reintento` | Cuándo puede volver a intentarse (backoff / ventana delayed) |
