# RabbitMQ-SMS

Sistema de envío de SMS por colas con RabbitMQ, MySQL y PHP 8.3.

Implementa enrutamiento inteligente por tipo de usuario (normal/especial),
prioridad para mensajes urgentes y recuperación automática ante caídas.

---

## Índice de documentación

| Documento | Contenido |
|---|---|
| `README.md` (este fichero) | Visión general, arquitectura y puesta en marcha |
| [`docs/arquitectura.md`](docs/arquitectura.md) | Flujo de mensajes, estados, reglas de negocio |
| [`docs/tdd.md`](docs/tdd.md) | Cómo ejecutar los tests y qué cubre cada suite |
| [`emisor/README.md`](emisor/README.md) | Responsabilidad y variables del Emisor |
| [`intermediario/README.md`](intermediario/README.md) | Responsabilidad y variables del Intermediario |
| [`consumer/README.md`](consumer/README.md) | Responsabilidad y variables del Consumer |
| [`reintentador/README.md`](reintentador/README.md) | Responsabilidad y variables del Reintentador |

---

## Arquitectura

```
[Emisor]
   │  INSERT sms_mensajes (estado: pendiente)
   │  publica ID → sms.entrada
   │  UPDATE estado: encolado
   ▼
[Intermediario]
   │  SELECT sms_mensajes + usuarios por ID
   │
   ├─ urgente ──────────────────────────────► [sms.directo] priority=10
   ├─ normal + no especial ─────────────────► [sms.directo] priority=0
   └─ normal + especial ────────────────────► [sms.delayed] → [sms.directo]
                                               UPDATE estado: en_espera
   ▼
[Consumer]
   │  SELECT sms_mensajes por ID
   │  llama al proveedor SMS
   │
   ├─ éxito ─► UPDATE estado: enviado + usuarios.ultimo_envio
   └─ fallo ─► UPDATE estado: error + backoff exponencial

[Reintentador] (cron periódico)
   │  SELECT mensajes no enviados candidatos a reintento
   └─ publica IDs en sms.entrada → reinicia el flujo
```

### Actores

| Actor | Imagen Docker | Descripción |
|---|---|---|
| `emisor` | `emisor/Dockerfile` | Script de prueba. En producción lo reemplaza la aplicación real. |
| `intermediario` | `intermediario/Dockerfile` | Cerebro del sistema. Enruta cada mensaje según las reglas de negocio. |
| `consumer` | `consumer/Dockerfile` | Ejecuta el envío real y actualiza la BD. |
| `reintentador` | `reintentador/Dockerfile` | Recupera mensajes atascados o fallidos. |

### Colas y exchanges RabbitMQ

| Nombre | Tipo | Descripción |
|---|---|---|
| `sms.entrada` | Cola durable | Recibe IDs de mensajes a procesar |
| `sms.directo` | Cola durable con `x-max-priority=10` | Mensajes listos para enviar |
| `sms.delayed` | Exchange `x-delayed-message` | Retiene mensajes de usuarios especiales |

---

## Requisitos

- Docker >= 24
- Docker Compose >= 2.20
- Red externa `red-compartida` (ver abajo)

---

## Puesta en marcha

### 1. Crear la red compartida (solo la primera vez)

```bash
docker network create red-compartida
```

### 2. Copiar y editar el fichero de entorno

```bash
cp .env.example .env
# editar credenciales si hace falta
```

### 3. Levantar todos los servicios

```bash
docker compose up -d --build
```

### 4. Ver logs de un actor

```bash
docker compose logs -f intermediario
docker compose logs -f consumer
```

### 5. Parar el sistema

```bash
docker compose down
```

Para borrar también los datos de MySQL:

```bash
docker compose down -v
```

---

## Estructura del proyecto

```
proyecto-sms/
├── shared/                  # Código compartido entre actores
│   ├── src/
│   │   ├── Connection/      # DatabaseConnection, AmqpConnection
│   │   ├── Model/           # Mensaje (value object)
│   │   └── Repository/      # MensajeRepository, UsuarioRepository
│   └── composer.json
├── emisor/
│   ├── src/Emisor.php
│   ├── tests/EmisorTest.php
│   ├── run.php              # Punto de entrada
│   ├── composer.json
│   ├── phpunit.xml
│   └── Dockerfile
├── intermediario/
│   ├── src/Intermediario.php
│   ├── tests/IntermediarioTest.php
│   ├── run.php
│   ├── composer.json
│   ├── phpunit.xml
│   └── Dockerfile
├── consumer/
│   ├── src/
│   │   ├── Consumer.php
│   │   ├── SmsProviderInterface.php
│   │   └── SimuladoSmsProvider.php
│   ├── tests/ConsumerTest.php
│   ├── run.php
│   ├── composer.json
│   ├── phpunit.xml
│   └── Dockerfile
├── reintentador/
│   ├── src/Reintentador.php
│   ├── tests/ReintentadorTest.php
│   ├── run.php
│   ├── composer.json
│   ├── phpunit.xml
│   └── Dockerfile
├── rabbitmq/
│   └── Dockerfile           # Imagen con plugin delayed message
├── db/
│   └── init.sql             # Esquema y datos de prueba
├── docs/
│   ├── arquitectura.md
│   └── tdd.md
├── docker-compose.yml
└── .env
```
