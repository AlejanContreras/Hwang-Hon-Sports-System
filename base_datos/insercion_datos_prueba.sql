INSERT INTO acudiente_tutor (documento, nombre, relacion) VALUES
('1000000001', 'Marcela Reyes Ortiz', 'madre');

INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, creado_por_correo) VALUES
('admin.fundador@hwanghonficticio.test', '$2y$10$EJEMPLODEHASHFICTICIOPARAPRUEBASNOUSAR0000000000000', 'Carlos Andrés Gómez Ruiz', 'administrador', 1, NULL),
('staff.instructor@hwanghonficticio.test', '$2y$10$OTROHASHFICTICIODEPRUEBASOLONOPRODUCCION000000000000', 'Diana Patricia Lozano Salas', 'instructor', 0, 'admin.fundador@hwanghonficticio.test');

INSERT INTO deportista (documento, nombre, fecha_nacimiento, estatura, peso, discapacidad, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES
('1000000101', 'Samuel Reyes Ortiz', '2014-03-12', 1.55, 45.30, NULL, NULL, 'Club Hwang Hon', 1, NULL, '2025-02-01', 'activo', 'validado', '1000000001', NULL),
('1000000102', 'Juliana Andrea Castillo Vargas', '2005-07-20', 1.68, 60.00, NULL, NULL, 'Escuela IMRDS', 1, NULL, '2026-01-15', 'activo', 'pendiente_validacion', NULL, NULL),
('1000000103', 'Fabián Esteban Rincón Peña', '1998-11-05', 1.75, 78.50, NULL, NULL, 'Club Hwang Hon', 1, 'amarillo', '2023-06-10', 'inactivo', 'validado', NULL, 'staff.instructor@hwanghonficticio.test');

INSERT INTO documento (id, deportista_documento, tipo_documento, archivo, fecha_carga, estado) VALUES
(1, '1000000101', 'registro_civil', 'registro_civil_samuel.pdf', '2025-02-01', 'cargado'),
(2, '1000000102', 'cedula', 'cedula_juliana.pdf', '2026-01-15', 'cargado'),
(3, '1000000103', 'certificado_medico', 'certificado_medico_fabian.pdf', '2023-06-10', 'pendiente');

INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES
('1000000101', '2026-03-03', 1),
('1000000101', '2026-03-10', 0),
('1000000102', '2026-03-03', 1);

INSERT INTO pago (id, deportista_documento, tipo, anio, mes, semana, estado, consecuencia_mora) VALUES
(1, '1000000101', 'matricula', 2026, NULL, NULL, 'pagado', NULL),
(2, '1000000101', 'mensualidad', 2026, 3, 1, 'pagado', NULL),
(3, '1000000102', 'mensualidad', 2026, 3, 2, 'pendiente', NULL);

INSERT INTO evento_calendario (id, nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES
(1, 'Copa Ficticia Hwang Hon 2026', 'competencia', '2026-05-10', 'Coliseo Municipal de Soacha', 'publica', NULL),
(2, 'Examen de grado abril 2026', 'evaluacion', '2026-04-26', 'Sede Club Hwang Hon', 'administrativa', NULL),
(3, 'Reunión de staff trimestral', 'reunion_administrativa', '2026-04-15', 'Sede Club Hwang Hon', 'administrativa', 'Revisión de indicadores del trimestre'),
(4, 'Jornada de integración familiar', 'evento_extra', '2026-06-20', 'Parque Recreativo Soacha', 'publica', NULL);

INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES
(1, 'Fitofan', 'departamental');

INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES
('1000000101', 1, 'formas', 'infantil', NULL, 'Primer lugar categoría infantil', 'oro', NULL, NULL, NULL),
('1000000102', 1, 'combate_individual', 'mayores', NULL, NULL, 'bronce', 2, 1, NULL),
('1000000102', 1, 'defensa_personal', 'mayores', NULL, 'Participó sin clasificar', 'ninguna', NULL, NULL, NULL);

INSERT INTO sesion_examen (id, fecha, lugar) VALUES
(1, '2026-04-26', 'Sede Club Hwang Hon');

INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, fecha_completado, criterios_evaluados, puntajes, valor_medible, aspectos_fallidos, observaciones, recomendaciones, aprobado) VALUES
(1, '1000000101', 'teorico', 'amarillo', 'completado', '2026-04-26', NULL, NULL, NULL, NULL, NULL, NULL, 1),
(1, '1000000101', 'fisico', 'amarillo', 'completado', '2026-04-26', NULL, NULL, 35.50, NULL, NULL, NULL, 1),
(1, '1000000101', 'combate', 'amarillo', 'pendiente', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
