CREATE DATABASE IF NOT EXISTS hwanghon
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hwanghon;

CREATE TABLE acudiente_tutor (
    documento   VARCHAR(20)  NOT NULL,
    nombre      VARCHAR(150) NOT NULL,
    relacion    VARCHAR(50)  NOT NULL COMMENT 'ej. padre, madre, tutor legal',

    PRIMARY KEY (documento)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE usuario_administrativo (
    correo                      VARCHAR(150) NOT NULL,
    contrasena                  VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt/argon2, nunca texto plano',
    nombre                      VARCHAR(150) NOT NULL,
    rol                         VARCHAR(30)  NULL     COMMENT 'Solo identificacion (RN-T.2)',
    es_administrador_principal  TINYINT(1)   NOT NULL COMMENT 'Verdadero solo para Aldrin',
    creado_por_correo           VARCHAR(150) NULL     COMMENT 'Nulo para la cuenta fundadora',

    PRIMARY KEY (correo),

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
    estatura             DECIMAL(4,2)  NOT NULL COMMENT 'Metros, ej. 1.75',
    peso                 DECIMAL(5,2)  NOT NULL COMMENT 'Kilogramos',
    discapacidad         VARCHAR(255)  NULL     COMMENT 'Condicion de salud relevante (RN-1.5)',
    estado_animico       VARCHAR(100)  NULL     COMMENT 'No visible en interfaz, reserva a futuro (RN-2.4)',
    sede_entrenamiento   VARCHAR(100)  NULL     COMMENT 'Club Hwang Hon / Escuela IMRDS (RN-T.8)',
    autorizacion_datos   TINYINT(1)    NOT NULL COMMENT 'Autorizacion de datos en registro virtual (RN-1.8/RN-T.9)',
    grado_actual         VARCHAR(30)   NULL     COMMENT 'Nulo hasta el primer examen aprobado (RN-10.3)',
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
    tipo_documento        VARCHAR(30)   NOT NULL COMMENT 'Lista abierta por el valor otro (RN-6.1)',
    archivo               VARCHAR(255)  NOT NULL COMMENT 'Ruta o nombre del archivo almacenado',
    fecha_carga           DATE          NOT NULL,
    estado                ENUM('cargado', 'pendiente') NOT NULL,

    PRIMARY KEY (id),

    CONSTRAINT lf_documento_deportista
        FOREIGN KEY (deportista_documento)
        REFERENCES deportista (documento)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
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
    tipo                  ENUM('mensualidad', 'matricula') NOT NULL,
    anio                  SMALLINT      NOT NULL COMMENT 'ej. 2026',
    mes                   TINYINT       NULL     COMMENT '1 a 12, solo mensualidad',
    semana                TINYINT       NULL     COMMENT '1 a 4, semana del mes del pago (RN-8.1), solo mensualidad',
    estado                ENUM('pagado', 'pendiente') NOT NULL,
    consecuencia_mora     VARCHAR(255)  NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-8.7)',

    PRIMARY KEY (id),

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
    tipo           ENUM('competencia', 'evaluacion', 'reunion_administrativa', 'evento_extra') NOT NULL,
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
    categoria_peso         VARCHAR(30)   NULL COMMENT 'PENDIENTE DE VALIDACION (RN-3.1/RN-3.8)',
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
    id     INT           NOT NULL AUTO_INCREMENT,
    fecha  DATE          NOT NULL,
    lugar  VARCHAR(150)  NOT NULL,

    PRIMARY KEY (id)
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
    criterios_evaluados   TEXT          NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-5.1)',
    puntajes              TEXT          NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-5.1)',
    valor_medible         DECIMAL(6,2)  NULL     COMMENT 'Valor numerico de prueba fisica (RN-2.3)',
    aspectos_fallidos     TEXT          NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-5.1)',
    observaciones         TEXT          NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-5.1)',
    recomendaciones       TEXT          NULL     COMMENT 'PENDIENTE DE ALDRIN (RN-5.1)',
    aprobado              TINYINT(1)    NULL     COMMENT 'Nulo hasta completar; si es verdadero actualiza deportista.grado_actual (RN-2.5)',

    PRIMARY KEY (sesion_examen_id, deportista_documento, modalidad),

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

DELIMITER $$
CREATE PROCEDURE cambiar_correo_administrador(
    IN p_correo_actual VARCHAR(150),
    IN p_correo_nuevo VARCHAR(150)
)
BEGIN
    START TRANSACTION;
        SET FOREIGN_KEY_CHECKS = 0;

        UPDATE usuario_administrativo
        SET correo = p_correo_nuevo
        WHERE correo = p_correo_actual;

        UPDATE usuario_administrativo
        SET creado_por_correo = p_correo_nuevo
        WHERE creado_por_correo = p_correo_actual;

        SET FOREIGN_KEY_CHECKS = 1;
    COMMIT;
END$$
DELIMITER ;
