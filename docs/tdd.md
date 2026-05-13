# Tests — TDD

Los tests cubren la lógica de negocio de cada actor de forma aislada.
No requieren RabbitMQ ni MySQL: todos los colaboradores se mockean con PHPUnit.

---

## Ejecutar los tests

Desde la carpeta de cada actor:

```bash
cd emisor
composer install
./vendor/bin/phpunit

cd ../intermediario
composer install
./vendor/bin/phpunit

cd ../consumer
composer install
./vendor/bin/phpunit

cd ../reintentador
composer install
./vendor/bin/phpunit
```

Para ver cobertura (requiere Xdebug):

```bash
./vendor/bin/phpunit --coverage-text
```

---

## Qué cubre cada suite

### Emisor (`emisor/tests/EmisorTest.php`)

| Test | Qué verifica |
|---|---|
| `emitir_usuarioExiste_insertaPublicaYMarcaEncolado` | Flujo feliz completo |
| `emitir_usuarioNoExiste_lanzaExcepcion` | El emisor solo trabaja con usuarios registrados |
| `emitir_mensajeUrgente_sePublicaCorrectamente` | Los urgentes se insertan con `es_urgente=true` |
| `emitir_fallaDurantePublicacion_noMarcaEncolado` | Si RabbitMQ falla, no se actualiza el estado |
| `procesarPendientes_sinPendientes_noPublicaNada` | Sin pendientes, no hay actividad |
| `procesarPendientes_conPendientes_publicaTodos` | N pendientes → N publicaciones y N marcados |

### Intermediario (`intermediario/tests/IntermediarioTest.php`)

| Test | Qué verifica |
|---|---|
| `procesarMensaje_usuarioNormalNoUrgente_vaDirectoConPrioridadNormal` | Enrutamiento base |
| `procesarMensaje_usuarioEspecialNoUrgente_vaAlDelayed` | Usuarios especiales van al delayed |
| `procesarMensaje_mensajeUrgenteUsuarioNormal_vaDirectoConPrioridadAlta` | Urgente siempre directo |
| `procesarMensaje_mensajeUrgenteUsuarioEspecial_vaDirectoIgnorandoDelayed` | Urgente > especial |
| `procesarMensaje_mensajeNoEncontradoEnBD_descartaConAck` | IDs huérfanos se descartan limpiamente |
| `procesarMensaje_usuarioDesconocido_creaUsuarioYEnrutaDirecto` | Auto-registro de usuarios |
| `procesarMensaje_errorDeBD_nacksConRequeue` | Errores de BD no tumban el proceso |
| `calcularDelay_devuelveDelayDentroDelRango` | El delay está entre 0 y intervalo*60*1000 ms |
| `calcularDelay_ventanaEstaEnElFuturo` | La ventana calculada siempre es futura |

### Consumer (`consumer/tests/ConsumerTest.php`)

| Test | Qué verifica |
|---|---|
| `procesarMensaje_envioExitoso_marcaEnviadoYActualizaUsuario` | Flujo feliz completo |
| `procesarMensaje_proveedorFalla_marcaErrorConBackoffBase` | Primer fallo → backoff 2 min |
| `procesarMensaje_proveedorFallaEnTercerIntento_backoffExponencial` | Backoff crece exponencialmente |
| `procesarMensaje_mensajeNoEncontrado_ackSinEnviar` | IDs huérfanos → ack limpio |
| `procesarMensaje_mensajeYaEnviado_seIgnora` | Idempotencia ante duplicados |
| `procesarMensaje_errorDeBD_nacksConRequeueSinCaer` | Error de BD en búsqueda → nack+requeue |
| `procesarMensaje_errorDeBDAlMarcarEnviado_procesoNoCae` | Error de BD post-envío no tumba el proceso |

### Reintentador (`reintentador/tests/ReintentadorTest.php`)

| Test | Qué verifica |
|---|---|
| `ejecutar_sinCandidatos_noPublicaNada` | Sin trabajo, no hay actividad |
| `ejecutar_mensajeErrorDentroDelLimite_reencola` | Mensajes en error dentro del límite se reencolán |
| `ejecutar_mensajePendiente_siempreReencola` | Pendientes se reencolán siempre |
| `ejecutar_multiplescandidatos_reencolaTodos` | N candidatos → N publicaciones |
| `ejecutar_mensajeAlcanzoLimite_seDescartaSinReencolar` | Al límite → descarte, no reencola |
| `ejecutar_reintentosMaximosInfinito_nuncaDescarta` | Límite 0 = infinito, nunca descarta |
| `ejecutar_mezclaDeLimites_procesaCadaUnoCorrectamente` | Mezcla correcta según límite individual |

---

## Filosofía de los tests

- **Aislamiento total**: ningún test toca BD ni RabbitMQ reales.
- **Mocks sobre interfaces**: `SmsProviderInterface` permite simular éxitos y fallos del proveedor.
- **Un test = una casuística**: cada test tiene nombre descriptivo con el patrón `método_condición_resultado`.
- **Proceso no cae**: los tests de error verifican que el proceso sigue vivo tras la excepción.
