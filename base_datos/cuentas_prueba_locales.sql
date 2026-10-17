-- =====================================================================
-- CUENTAS DE PRUEBA SOLO PARA DESARROLLO LOCAL
-- Todas con contrasena 12345678. NUNCA cargar este archivo en produccion:
-- en produccion las cuentas reales se crean con
-- backend/Instalacion/crear_cuentas_iniciales.php (--sql en InfinityFree).
--
-- Orden de carga en una base limpia:
--   1) esquema_hwanghon_v4.sql
--   2) insercion_datos_prueba.sql
--   3) este archivo
--
-- Ingreso administrativo (correo + contrasena 12345678):
--   maestro@hwanghon.local          administrador principal (Aldrin)
--   desarrollador@hwanghon.local    desarrollador, principal
--   administrativo1@hwanghon.local  administrativo NO principal (prueba de RN-T.11)
--   inactivo@hwanghon.local         cuenta desactivada (debe ser rechazada)
--
-- Ingreso publico (documento + fecha de nacimiento, sin contrasena), ya en el seed:
--   1000000101 / 2014-03-12   activo, validado        -> entra
--   1000000102 / 2005-07-20   pendiente_validacion    -> 403 "Tu registro esta en revision."
--   1000000103 / 1998-11-05   activo                  -> entra
--
-- Para repetir pruebas de bloqueo (RN-T.16):  DELETE FROM intento_acceso;
-- =====================================================================
USE hwanghon;

INSERT INTO usuario_administrativo
    (correo, contrasena, nombre, rol, es_administrador_principal, activa, creado_por_correo)
VALUES
('maestro@hwanghon.local',         '$2y$10$UsoT47boHJRZxCGrfBlML.8qJ3wV/O8HuJs2vRbKQJ8nVn3p6XWrC', 'Maestro (prueba)',         'administrador', 1, 1, NULL),
('desarrollador@hwanghon.local',   '$2y$10$UsoT47boHJRZxCGrfBlML.8qJ3wV/O8HuJs2vRbKQJ8nVn3p6XWrC', 'Desarrollador (prueba)',   'desarrollador', 1, 1, NULL),
('administrativo1@hwanghon.local', '$2y$10$UsoT47boHJRZxCGrfBlML.8qJ3wV/O8HuJs2vRbKQJ8nVn3p6XWrC', 'Administrativo 1 (prueba)', 'administrador', 0, 1, 'maestro@hwanghon.local'),
('inactivo@hwanghon.local',        '$2y$10$UsoT47boHJRZxCGrfBlML.8qJ3wV/O8HuJs2vRbKQJ8nVn3p6XWrC', 'Inactivo (prueba)',        'administrador', 0, 0, 'maestro@hwanghon.local');

-- Las 3 cuentas ficticias del seed pasan a tener tambien la contrasena 12345678.
UPDATE usuario_administrativo SET contrasena = '$2y$10$UsoT47boHJRZxCGrfBlML.8qJ3wV/O8HuJs2vRbKQJ8nVn3p6XWrC'
WHERE correo IN (
    'admin.fundador@hwanghonficticio.test',
    'staff.instructor@hwanghonficticio.test',
    'desarrollador@hwanghonficticio.test'
);
