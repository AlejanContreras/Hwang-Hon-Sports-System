// Módulos del ámbito administrativo: una sola lista para el menú lateral y los accesos rápidos
// del inicio. Para habilitar un módulo basta con ponerle su «ruta».
// Agrupación según John (30/10/2026). icono: nombre en componentes/iconos.js.
// acceso: false = no va en los accesos rápidos del inicio (solo los 10 módulos del boceto).
export const GRUPOS = [
    {
        titulo: 'Gestión deportiva',
        modulos: [
            { clave: 'deportistas', texto: 'Deportistas', ruta: '#deportistas', icono: 'circle-user-round' },
            { clave: 'torneos', texto: 'Torneos', ruta: '#torneos', icono: 'medal' },
            { clave: 'ascensos', texto: 'Ascensos', ruta: '#ascensos', icono: 'cinturon' },
            { clave: 'estadisticas', texto: 'Estadísticas', ruta: '#estadisticas', icono: 'chart-no-axes-combined' },
            { clave: 'historial', texto: 'Historial', ruta: '#historial', icono: 'history' },
        ],
    },
    {
        titulo: 'Gestión administrativa',
        modulos: [
            { clave: 'documentos', texto: 'Documentos', ruta: '#documentos', icono: 'files' },
            { clave: 'asistencia', texto: 'Asistencia', ruta: '#asistencia', icono: 'calendar-check' },
            { clave: 'pagos', texto: 'Pagos y matrícula', ruta: '#pagos', icono: 'wallet' },
        ],
    },
    {
        titulo: 'Otros',
        modulos: [
            { clave: 'calendario', texto: 'Calendario', ruta: '#calendario', icono: 'calendar-days' },
            { clave: 'exportacion', texto: 'Exportación', ruta: '#exportacion', icono: 'file-down' },
            // «Cuentas» lo ve cualquier cuenta administrativa: arriba va «Mi cuenta» (con Cerrar sesión);
            // la gestión de las cuentas del club solo la ve la cuenta principal.
            { clave: 'cuentas', texto: 'Cuentas', ruta: '#cuentas', icono: 'users', acceso: false },
            { clave: 'importar', texto: 'Carga masiva', ruta: '#importar', icono: 'file-up', acceso: false },
        ],
    },
];

export const MODULOS = GRUPOS.flatMap((grupo) => grupo.modulos);

// Los 10 módulos de los accesos rápidos del inicio.
export const MODULOS_ACCESO = MODULOS.filter((m) => m.acceso !== false);

// Módulos del deportista (publico.html). Sin «Inicio»: la marca enlaza a #inicio (su dashboard).
export const GRUPOS_PUBLICOS = [
    {
        titulo: 'Mi cuenta',
        modulos: [
            { clave: 'perfil', texto: 'Mi perfil', ruta: '#perfil', icono: 'circle-user-round' },
            { clave: 'asistencia', texto: 'Asistencia', ruta: '#asistencia', icono: 'calendar-check' },
            { clave: 'pagos', texto: 'Pagos', ruta: '#pagos', icono: 'wallet' },
            { clave: 'documentos', texto: 'Documentos', ruta: '#documentos', icono: 'files' },
        ],
    },
    {
        titulo: 'Seguimiento',
        modulos: [
            { clave: 'ascensos', texto: 'Ascensos', ruta: '#ascensos', icono: 'cinturon' },
            { clave: 'estadisticas', texto: 'Estadísticas', ruta: '#estadisticas', icono: 'chart-no-axes-combined' },
            { clave: 'historial', texto: 'Historial', ruta: '#historial', icono: 'history' },
            { clave: 'calendario', texto: 'Calendario', ruta: '#calendario', icono: 'calendar-days' },
        ],
    },
];
