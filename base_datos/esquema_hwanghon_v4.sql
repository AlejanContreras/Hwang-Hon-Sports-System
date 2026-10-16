CREATE DATABASE IF NOT EXISTS hwanghon
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hwanghon;

CREATE TABLE acudiente_tutor (
    documento   VARCHAR(20)  NOT NULL,
    nombre      VARCHAR(150) NOT NULL,
    relacion    VARCHAR(50)  NOT NULL COMMENT 'ej. padre, madre, tutor legal',
    telefono    VARCHAR(20)  NULL     COMMENT 'Celular del acudiente que firma el formulario; el otro padre queda solo en el formulario escaneado',
    correo      VARCHAR(150) NULL     COMMENT 'Correo del acudiente que firma el formulario; el otro padre queda solo en el formulario escaneado',

    PRIMARY KEY (documento)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE usuario_administrativo (
    correo                      VARCHAR(150) NOT NULL,
    contrasena                  VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt/argon2, nunca texto plano',
    nombre                      VARCHAR(150) NOT NULL,
    rol                         VARCHAR(30)  NULL     COMMENT 'Solo identificacion (RN-T.2)',
    es_administrador_principal  TINYINT(1)   NOT NULL COMMENT 'Verdadero para quien gestiona cuentas administrativas (RN-T.11): Aldrin y la cuenta Desarrollador',
    activa                      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Falso = cuenta desactivada: no inicia sesion y su sesion abierta deja de valer; las cuentas no se borran (RN-T.11)',
    creado_por_correo           VARCHAR(150) NULL     COMMENT 'Nulo para la cuenta fundadora',
    token_recuperacion          CHAR(64)     NULL     COMMENT 'Hash SHA-256 (hex) del token de un solo uso, nunca el token en claro; nulo si no hay solicitud activa (RN-T.12)',
    token_recuperacion_expira   DATETIME     NULL     COMMENT 'Fecha y hora de vencimiento del token: 1 hora desde la solicitud (RN-T.12)',

    PRIMARY KEY (correo),

    UNIQUE KEY unico_token_recuperacion (token_recuperacion),

    CONSTRAINT lf_usuario_administrativo_creado_por
        FOREIGN KEY (creado_por_correo)
        REFERENCES usuario_administrativo (correo)
        ON DELETE SET NULL
        ON UPDATE RESTRICT
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE deportista (
    documento            VARCHAR(20)   NOT NULL,
    nombre               VARCHAR(150)  NOT NULL,
    fecha_nacimiento     DATE          NOT NULL,
    lugar_nacimiento     VARCHAR(100)  NULL     COMMENT 'Ciudad o municipio de nacimiento (formulario de inscripcion)',
    sexo                ENUM('masculino', 'femenino') NOT NULL COMMENT 'Sexo del deportista, se pide en la inscripcion; necesario para la categoria por peso y las competencias (RN-3.8)',
    estatura             DECIMAL(4,2)  NOT NULL COMMENT 'Metros, ej. 1.75',
    peso                 DECIMAL(5,2)  NOT NULL COMMENT 'Kilogramos',
    telefono             VARCHAR(20)   NULL     COMMENT 'Del deportista; en un menor arranca con el del acudiente. Editable en vista publica (RN-1.1, RN-1.4)',
    direccion            VARCHAR(150)  NULL     COMMENT 'Del deportista; en un menor arranca con la del acudiente. Editable en vista publica (RN-1.1, RN-1.4)',
    correo               VARCHAR(150)  NULL     COMMENT 'Del deportista; en un menor arranca con el del acudiente. Sin indice unico: hermanos menores pueden compartirlo (RN-1.1, RN-1.10)',
    discapacidad         VARCHAR(255)  NULL     COMMENT 'Condicion de salud relevante (RN-1.5); texto libre que reemplaza las seis preguntas de salud del formulario: infecto-contagiosas, cardiovasculares, respiratorias, alergicas, fracturas y otras',
    experiencia_previa   TEXT          NULL     COMMENT 'Opcional, texto libre: arte marcial anterior, club, pais/ciudad, maestro, fecha de retiro, motivo, ultimo grado y fecha de ultima graduacion (formulario de inscripcion)',
    estado_animico       VARCHAR(100)  NULL     COMMENT 'No visible en interfaz, reserva a futuro (RN-2.4)',
    sede_entrenamiento   VARCHAR(100)  NULL     COMMENT 'Club Hwang Hon / Escuela IMRDS (RN-T.8)',
    autorizacion_datos   TINYINT(1)    NOT NULL COMMENT 'Autorizacion de datos en registro virtual (RN-1.8/RN-T.9)',
    grado_actual         VARCHAR(30)   NULL     COMMENT 'Nulo equivale a Ingresado (sin cinturon) hasta el primer examen aprobado; editable a mano por el administrativo; si llega de otro club con cinturon, lo digita el administrativo (RN-5.3, RN-10.3)',
    fecha_ingreso        DATE          NOT NULL,
    estado               ENUM('activo', 'inactivo') NOT NULL,
    estado_registro      ENUM('pendiente_validacion', 'validado') NOT NULL,
    acudiente_documento  VARCHAR(20)   NULL     COMMENT 'Nulo si el deportista es mayor de edad',
    validado_por_correo  VARCHAR(150)  NULL     COMMENT 'Auditoria de autorregistro, nulo si no aplica',

    PRIMARY KEY (documento),

    CONSTRAINT lf_deportista_acudiente
        FOREIGN KEY (acudiente_documento)
        REFERENCES acudiente_tutor (documento)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT lf_deportista_validado_por
        FOREIGN KEY (validado_por_correo)
        REFERENCES usuario_administrativo (correo)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE documento (
    id                    INT           NOT NULL AUTO_INCREMENT,
    deportista_documento  VARCHAR(20)   NOT NULL,
    tipo_documento        VARCHAR(30)   NOT NULL COMMENT 'Tipos de RN-6.1: formulario_inscripcion, documento_identidad, documento_acudiente, fotografia, consentimiento, certificado_ascenso, competencia, preparacion_fisica, otro',
    archivo               VARCHAR(255)  NOT NULL COMMENT 'Nombre aleatorio del archivo en disco, fuera de la carpeta publica (RN-6.3)',
    nombre_original       VARCHAR(150)  NOT NULL COMMENT 'Nombre con el que se subio; con el se entrega la descarga (RN-6.3)',
    fecha_carga           DATE          NOT NULL,

    PRIMARY KEY (id),

    CONSTRAINT lf_documento_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE documento_general (
    id               INT           NOT NULL AUTO_INCREMENT,
    titulo           VARCHAR(150)  NOT NULL COMMENT 'ej. teoria de ascenso; visible para todos, sin filtro por grado (RN-6.8)',
    archivo          VARCHAR(255)  NOT NULL COMMENT 'Nombre del archivo almacenado',
    nombre_original  VARCHAR(150)  NOT NULL COMMENT 'Nombre con el que se subio; con el se entrega la descarga (RN-6.3)',
    fecha_carga      DATE          NOT NULL,

    PRIMARY KEY (id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE asistencia (
    deportista_documento  VARCHAR(20)  NOT NULL,
    fecha                 DATE         NOT NULL,
    asistio               TINYINT(1)   NOT NULL,

    PRIMARY KEY (deportista_documento, fecha),

    CONSTRAINT lf_asistencia_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE pago (
    id                    INT           NOT NULL AUTO_INCREMENT,
    deportista_documento  VARCHAR(20)   NOT NULL,
    tipo                  ENUM('mensualidad', 'matricula') NOT NULL COMMENT 'Solo se guardan pagos hechos; la deuda se calcula por ausencia de fila (RN-8.1, RN-8.3)',
    anio                 SMALLINT      NOT NULL COMMENT 'ej. 2026',
    mes                   TINYINT       NOT NULL COMMENT '0 = matricula; 1 a 12 = mes de la mensualidad. CHECK rango_pago_mes; algunos MySQL antiguos ignoran CHECK, el backend valida igual',
    semana                TINYINT       NULL     COMMENT '1 a 4, semana del mes del pago (RN-8.1), solo mensualidad. CHECK rango_pago_semana; algunos MySQL antiguos ignoran CHECK, el backend valida igual',

    PRIMARY KEY (id),

    UNIQUE KEY unico_pago_periodo (deportista_documento, tipo, anio, mes),

    CONSTRAINT rango_pago_mes CHECK (mes BETWEEN 0 AND 12),
    CONSTRAINT rango_pago_semana CHECK (semana IS NULL OR semana BETWEEN 1 AND 4),

    CONSTRAINT lf_pago_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE evento_calendario (
    id             INT           NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(150)  NOT NULL,
    tipo           ENUM('competencia', 'evaluacion', 'grado', 'reunion_administrativa', 'evento_extra') NOT NULL,
    fecha          DATE          NOT NULL,
    lugar          VARCHAR(150)  NOT NULL,
    visibilidad    ENUM('administrativa', 'publica') NOT NULL,
    observaciones  TEXT          NULL COMMENT 'Notas generales del evento (RN-3.1)',

    PRIMARY KEY (id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE competencia (
    evento_id    INT          NOT NULL,
    plataforma   VARCHAR(50)  NULL COMMENT 'Fitofan u otra, texto libre (RN-3.4)',
    tipo_torneo  ENUM('nacional', 'internacional', 'departamental') NOT NULL,

    PRIMARY KEY (evento_id),

    CONSTRAINT lf_competencia_evento
        FOREIGN KEY (evento_id)
        REFERENCES evento_calendario (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE participacion_competencia (
    deportista_documento   VARCHAR(20)   NOT NULL,
    competencia_evento_id  INT           NOT NULL,
    modalidad              ENUM('combate_individual', 'combate_equipos', 'defensa_personal',
                                'salto_alto', 'salto_largo', 'formas') NOT NULL,
    categoria_edad         ENUM('infantil', 'junior', 'juvenil', 'mayores', 'senior', 'master') NOT NULL,
    categoria_peso         VARCHAR(30)   NULL COMMENT 'PROPUESTA (RN-3.1/RN-3.8) — sugerida por la tabla consolidada del equipo, que cubre todas las categorias con corte inclusivo; editable; se reemplaza si llega la tabla oficial',
    puesto                 TINYINT UNSIGNED NULL COMMENT 'Lugar en el torneo, lo digita el staff, no se calcula; nulo hasta despues del torneo (RN-3.1, RN-3.3)',
    resultado              VARCHAR(255)  NULL COMMENT 'Resultado general, texto libre (RN-3.1)',
    medalla                ENUM('oro', 'plata', 'bronce', 'ninguna') NULL,
    combates_ganados       TINYINT       NULL COMMENT 'Modalidades de combate',
    combates_perdidos      TINYINT       NULL COMMENT 'Modalidades de combate',
    notas_oponente         TEXT          NULL COMMENT 'Uso interno del staff',

    PRIMARY KEY (deportista_documento, competencia_evento_id, modalidad),

    CONSTRAINT lf_participacion_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT lf_participacion_competencia
        FOREIGN KEY (competencia_evento_id)
        REFERENCES competencia (evento_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE sesion_examen (
    id                INT           NOT NULL AUTO_INCREMENT,
    fecha             DATE          NOT NULL COMMENT 'Fecha de inicio de los examenes (RN-5.5)',
    fecha_grado       DATE          NOT NULL COMMENT 'Fecha de la ceremonia de entrega de cinturones; fecha_entrega toma este valor por defecto (RN-5.5, RN-5.7)',
    lugar             VARCHAR(150)  NOT NULL,
    estado            ENUM('abierta', 'cerrada') NOT NULL DEFAULT 'abierta' COMMENT 'Cerrada solo con todas las modalidades completadas; para editar notas se reabre (RN-5.5)',
    evento_examen_id  INT           NULL COMMENT 'Evento de calendario tipo evaluacion, en la fecha de inicio (RN-5.5, RN-9.2)',
    evento_grado_id   INT           NULL COMMENT 'Evento de calendario tipo grado, en la fecha de grado (RN-5.5, RN-9.2)',

    PRIMARY KEY (id),

    UNIQUE KEY unico_evento_examen (evento_examen_id),
    UNIQUE KEY unico_evento_grado (evento_grado_id),

    CONSTRAINT lf_sesion_examen_evento_examen
        FOREIGN KEY (evento_examen_id)
        REFERENCES evento_calendario (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT lf_sesion_examen_evento_grado
        FOREIGN KEY (evento_grado_id)
        REFERENCES evento_calendario (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE detalle_evaluacion (
    sesion_examen_id      INT           NOT NULL,
    deportista_documento  VARCHAR(20)   NOT NULL,
    modalidad             VARCHAR(30)   NOT NULL COMMENT 'Lista abierta (RN-5.5): teorico, fisico, combate, rompimiento, formas, llaves, ...',
    grado_evaluado        VARCHAR(30)   NOT NULL COMMENT 'Grado al que aspira (RN-5.1)',
    estado                ENUM('pendiente', 'completado') NOT NULL,
    fecha_completado      DATE          NULL     COMMENT 'Cada modalidad puede completarse en fecha distinta (RN-5.5)',
    nota                  DECIMAL(4,2)  NULL     COMMENT 'Escala de 1 a 10; nulo hasta calificar (RN-5.1, RN-5.3). CHECK rango_detalle_nota; algunos MySQL antiguos ignoran CHECK, el backend valida igual',
    criterios_evaluados   TEXT          NULL     COMMENT 'PENDIENTE DE RESPUESTA (RN-5.1/RN-5.3) — estructura confirmada, valores exactos sin la plantilla oficial',
    puntajes              TEXT          NULL     COMMENT 'PENDIENTE DE RESPUESTA (RN-5.1/RN-5.3) — estructura confirmada, valores exactos sin la plantilla oficial',
    valor_medible         DECIMAL(6,2)  NULL     COMMENT 'Valor numerico de prueba fisica (RN-2.3)',
    aspectos_fallidos     TEXT          NULL     COMMENT 'PENDIENTE DE RESPUESTA (RN-5.1/RN-5.3) — estructura confirmada, valores exactos sin la plantilla oficial',
    observaciones         TEXT          NULL     COMMENT 'PENDIENTE DE RESPUESTA (RN-5.1/RN-5.3) — estructura confirmada, valores exactos sin la plantilla oficial',
    recomendaciones       TEXT          NULL     COMMENT 'PENDIENTE DE RESPUESTA (RN-5.1/RN-5.3) — estructura confirmada, valores exactos sin la plantilla oficial',
    aprobado              TINYINT(1)    NULL     COMMENT 'Nulo hasta calificar; se deriva de nota al guardar y es solo informativo. El resultado general del examen es provisional: las seis modalidades completadas y promedio de notas >= 5, sin exigir aprobar cada una (RN-5.3)',

    PRIMARY KEY (sesion_examen_id, deportista_documento, modalidad),

    CONSTRAINT rango_detalle_nota CHECK (nota IS NULL OR nota BETWEEN 1 AND 10),

    CONSTRAINT lf_detalle_evaluacion_sesion
        FOREIGN KEY (sesion_examen_id)
        REFERENCES sesion_examen (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT lf_detalle_evaluacion_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE entrega_cinturon (
    sesion_examen_id      INT          NOT NULL,
    deportista_documento  VARCHAR(20)  NOT NULL,
    cinturon_entregado    TINYINT(1)   NOT NULL COMMENT 'Confirmacion manual de la entrega fisica del cinturon, por el administrativo o por el deportista (RN-5.7)',
    fecha_entrega         DATE         NULL     COMMENT 'Nulo mientras no se haya entregado; por defecto la fecha de grado de la sesion (RN-5.7)',
    confirmado_por        ENUM('administrativo', 'deportista') NULL COMMENT 'Quien confirmo la entrega; nulo mientras no se haya entregado (RN-5.7)',

    PRIMARY KEY (sesion_examen_id, deportista_documento),

    CONSTRAINT lf_entrega_cinturon_sesion
        FOREIGN KEY (sesion_examen_id)
        REFERENCES sesion_examen (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT lf_entrega_cinturon_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE intento_acceso (
    ambito             ENUM('administrativa', 'publica') NOT NULL COMMENT 'Login al que corresponde el contador; mismos valores que el ambito de la sesion',
    identificador      VARCHAR(150) NOT NULL           COMMENT 'Lo digitado al entrar: correo (administrativa) o documento del deportista (publica). Sin llave foranea a proposito: puede no existir y no debe poder deducirse si existe',
    intentos_fallidos  INT          NOT NULL DEFAULT 0 COMMENT 'Fallos consecutivos; con 4 se bloquea 15 minutos; vuelve a 0 al acertar (RN-T.16)',
    bloqueado_hasta    DATETIME     NULL               COMMENT 'Fin del bloqueo de 15 minutos tras el cuarto fallo; nulo si no esta bloqueado (RN-T.16)',

    PRIMARY KEY (ambito, identificador)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

DELIMITER $$
CREATE PROCEDURE cambiar_correo_administrador(
    IN p_correo_actual VARCHAR(150),
    IN p_correo_nuevo VARCHAR(150)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET FOREIGN_KEY_CHECKS = 1;
        RESIGNAL;
    END;

    START TRANSACTION;
        SET FOREIGN_KEY_CHECKS = 0;

        UPDATE usuario_administrativo
        SET correo = p_correo_nuevo
        WHERE correo = p_correo_actual;

        UPDATE usuario_administrativo
        SET creado_por_correo = p_correo_nuevo
        WHERE creado_por_correo = p_correo_actual;

        UPDATE deportista
        SET validado_por_correo = p_correo_nuevo
        WHERE validado_por_correo = p_correo_actual;

        SET FOREIGN_KEY_CHECKS = 1;
    COMMIT;
END$$
DELIMITER ;
