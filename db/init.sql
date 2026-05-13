-- ─────────────────────────────────────────────────────────────────────────────
-- init.sql
-- Se ejecuta automáticamente la primera vez que arranca MySQL.
-- Si ya existe la BD con datos, este fichero NO se vuelve a ejecutar.
-- ─────────────────────────────────────────────────────────────────────────────

USE sms_sistema;

-- ─── TABLA: usuarios ─────────────────────────────────────────────────────────
-- Todos los usuarios del sistema, especiales y normales.
-- es_especial = 1 → el mensaje espera a la próxima ventana de envío (delayed).
-- es_especial = 0 → el mensaje se envía inmediatamente (directo).
CREATE TABLE IF NOT EXISTS usuarios (
    usuario_id        VARCHAR(100) NOT NULL,
    telefono          VARCHAR(20)  NOT NULL,                   -- nº al que se envían los SMS
    es_especial       TINYINT(1)   NOT NULL DEFAULT 0,         -- 0=directo, 1=retiene hasta ventana
    intervalo_minutos INT          DEFAULT NULL,               -- NULL = usa el intervalo global
    ultimo_envio      DATETIME     DEFAULT NULL,               -- cuándo se mandó el último SMS
    PRIMARY KEY (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── TABLA: sms_mensajes ─────────────────────────────────────────────────────
-- Registro completo de cada mensaje: desde que se crea hasta que se envía.
-- Es la fuente de verdad del sistema; todos los actores leen y escriben aquí.
CREATE TABLE IF NOT EXISTS sms_mensajes (
    id                INT          NOT NULL AUTO_INCREMENT,
    usuario_id        VARCHAR(100) NOT NULL,
    telefono          VARCHAR(20)  NOT NULL,                   -- nº destino en el momento del INSERT
    contenido         TEXT         NOT NULL,                   -- texto del SMS
    es_urgente        TINYINT(1)   NOT NULL DEFAULT 0,         -- 1=prioridad alta en cola
    estado            ENUM(
                        'pendiente',   -- insertado, aún no encolado
                        'encolado',    -- publicado en sms.entrada, pendiente de intermediario
                        'en_espera',   -- en exchange delayed, esperando su ventana
                        'enviado',     -- confirmado por el proveedor
                        'error'        -- falló el envío, candidato a reintento
                      )            NOT NULL DEFAULT 'pendiente',
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encolado_en       DATETIME     DEFAULT NULL,               -- cuando el emisor lo publica
    enviado_en        DATETIME     DEFAULT NULL,               -- cuando el consumer confirma
    reintentos        INT          NOT NULL DEFAULT 0,         -- nº de reintentos realizados
    proximo_reintento DATETIME     DEFAULT NULL,               -- cuándo puede volver a intentarse
    PRIMARY KEY (id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── TABLA: configuracion ────────────────────────────────────────────────────
-- Parámetros globales modificables sin tocar código.
CREATE TABLE IF NOT EXISTS configuracion (
    clave       VARCHAR(100) NOT NULL,
    valor       VARCHAR(255) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('intervalo_minutos',          '30',  'Ventana de agrupación en minutos para usuarios especiales'),
    ('reintentos_maximos',         '5',   'Máximo de reintentos por mensaje; 0 = infinito'),
    ('backoff_base_minutos',       '2',   'Base del backoff exponencial entre reintentos (minutos)'),
    ('reintentador_intervalo_seg', '120', 'Frecuencia de ejecución del reintentador en segundos'),
    ('encolado_timeout_minutos',   '5',   'Minutos que puede estar un mensaje en estado encolado antes de considerarse atascado')
ON DUPLICATE KEY UPDATE valor = valor;

-- ─── DATOS DE PRUEBA ─────────────────────────────────────────────────────────
INSERT INTO usuarios (usuario_id, telefono, es_especial, intervalo_minutos) VALUES
    ('user_normal_01',   '+34600000001', 0, NULL),  -- normal: SMS directo siempre
    ('user_normal_02',   '+34600000002', 0, NULL),  -- normal: SMS directo siempre
    ('user_especial_01', '+34600000003', 1, NULL),  -- especial: intervalo global (30 min)
    ('user_especial_02', '+34600000004', 1, 60)     -- especial: intervalo propio (60 min)
ON DUPLICATE KEY UPDATE es_especial = es_especial;
