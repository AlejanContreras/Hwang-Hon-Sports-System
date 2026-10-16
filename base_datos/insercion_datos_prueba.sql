SET NAMES utf8mb4;

INSERT INTO acudiente_tutor (documento, nombre, relacion, telefono, correo) VALUES
('1000000001', 'Marcela Reyes Ortiz', 'madre', '3000000001', 'marcela.reyes@correoficticio.test');

INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, activa, creado_por_correo) VALUES
('admin.fundador@hwanghonficticio.test', '$2y$10$EJEMPLODEHASHFICTICIOPARAPRUEBASNOUSAR0000000000000', 'Carlos Andrés Gómez Ruiz', 'administrador', 1, 1, NULL),
('staff.instructor@hwanghonficticio.test', '$2y$10$OTROHASHFICTICIODEPRUEBASOLONOPRODUCCION000000000000', 'Diana Patricia Lozano Salas', 'instructor', 0, 1, 'admin.fundador@hwanghonficticio.test'),
('desarrollador@hwanghonficticio.test', '$2y$10$HASHFICTICIODELACUENTADESARROLLADORNOUSAR00000000000', 'Desarrollador', 'desarrollador', 1, 1, 'admin.fundador@hwanghonficticio.test');

INSERT INTO deportista (documento, nombre, fecha_nacimiento, lugar_nacimiento, sexo, estatura, peso, telefono, direccion, correo, discapacidad, experiencia_previa, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES
('1000000101', 'Samuel Reyes Ortiz', '2014-03-12', 'Soacha', 'masculino', 1.55, 45.30, '3000000001', 'Calle 1 # 2-03, Soacha', 'marcela.reyes@correoficticio.test', NULL, NULL, NULL, 'Club Hwang Hon', 1, NULL, '2025-02-01', 'activo', 'validado', '1000000001', NULL),
('1000000102', 'Juliana Andrea Castillo Vargas', '2005-07-20', NULL, 'femenino', 1.68, 60.00, '3000000002', 'Carrera 4 # 5-06, Soacha', 'juliana.castillo@correoficticio.test', NULL, NULL, NULL, 'Escuela IMRDS', 1, NULL, '2026-01-15', 'activo', 'pendiente_validacion', NULL, NULL),
('1000000103', 'Fabián Esteban Rincón Peña', '1998-11-05', 'Bogotá', 'masculino', 1.75, 78.50, NULL, NULL, 'fabian.rincon@correoficticio.test', NULL, 'Taekwondo en el Club Ficticio Dragón, Bogotá (Colombia), maestro Pedro Ficticio Ruiz; retiro en 2022 por cambio de residencia; último grado amarillo, graduado en 2021', NULL, 'Club Hwang Hon', 1, 'amarillo', '2023-06-10', 'inactivo', 'validado', NULL, 'staff.instructor@hwanghonficticio.test');

INSERT INTO documento (id, deportista_documento, tipo_documento, archivo, nombre_original, fecha_carga) VALUES
(1, '1000000101', 'documento_identidad', '7f3a9c1e5b2d4a6f8e0c1b3d5f7a9c2e.pdf', 'registro_civil_samuel.pdf', '2025-02-01'),
(2, '1000000102', 'documento_identidad', '2b4d6f8a0c1e3a5c7e9b1d3f5a7c9e0b.pdf', 'cedula_juliana.pdf', '2026-01-15'),
(3, '1000000103', 'consentimiento', '9e1c3a5f7b9d0f2a4c6e8b0d2f4a6c8e.pdf', 'consentimiento_fabian.pdf', '2023-06-10');

INSERT INTO documento_general (id, titulo, archivo, nombre_original, fecha_carga) VALUES
(1, 'Teoría de ascenso — cinturón amarillo', '4c6e8a0b2d4f6a8c0e2b4d6f8a0c2e4b.pdf', 'teoria_ascenso_amarillo.pdf', '2026-03-01');

INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES
('1000000101', '2026-03-07', 1),
('1000000101', '2026-03-14', 0),
('1000000102', '2026-03-07', 1);

INSERT INTO pago (id, deportista_documento, tipo, anio, mes, semana) VALUES
(1, '1000000101', 'matricula', 2026, 0, NULL),
(2, '1000000101', 'mensualidad', 2026, 3, 1),
(3, '1000000102', 'mensualidad', 2026, 3, 2);

INSERT INTO evento_calendario (id, nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES
(1, 'Copa Ficticia Hwang Hon 2026', 'competencia', '2026-05-10', 'Coliseo Municipal de Soacha', 'publica', NULL),
(2, 'Exámenes de ascenso abril 2026 (hasta el día del grado)', 'evaluacion', '2026-04-26', 'Sede Club Hwang Hon', 'publica', NULL),
(3, 'Reunión de staff trimestral', 'reunion_administrativa', '2026-04-15', 'Sede Club Hwang Hon', 'administrativa', 'Revisión de indicadores del trimestre'),
(4, 'Jornada de integración familiar', 'evento_extra', '2026-06-20', 'Parque Recreativo Soacha', 'publica', NULL),
(5, 'Grado abril 2026', 'grado', '2026-05-03', 'Sede Club Hwang Hon', 'publica', NULL);

INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES
(1, 'Fitofan', 'departamental');

INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES
('1000000101', 1, 'formas', 'infantil', NULL, 1, 'Primer lugar categoría infantil', 'oro', NULL, NULL, NULL),
('1000000102', 1, 'combate_individual', 'mayores', NULL, 3, NULL, 'bronce', 2, 1, NULL),
('1000000102', 1, 'defensa_personal', 'mayores', NULL, NULL, 'Participó sin clasificar', 'ninguna', NULL, NULL, NULL);

INSERT INTO sesion_examen (id, fecha, fecha_grado, lugar, estado, evento_examen_id, evento_grado_id) VALUES
(1, '2026-04-26', '2026-05-03', 'Sede Club Hwang Hon', 'abierta', 2, 5);

INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, fecha_completado, nota, criterios_evaluados, puntajes, valor_medible, aspectos_fallidos, observaciones, recomendaciones, aprobado) VALUES
(1, '1000000101', 'teorico', 'amarillo', 'completado', '2026-04-26', 8.50, NULL, NULL, NULL, NULL, NULL, NULL, 1),
(1, '1000000101', 'fisico', 'amarillo', 'completado', '2026-04-26', 7.00, NULL, NULL, 35.50, NULL, NULL, NULL, 1),
(1, '1000000101', 'combate', 'amarillo', 'pendiente', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
