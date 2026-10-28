// Módulos del ámbito administrativo: una sola lista para el menú lateral y los accesos rápidos
// del inicio. Para habilitar un módulo al construirlo basta con ponerle su «ruta».
// La agrupación sigue el boceto de John (12/10/2026), PROPUESTA aún sin validar.
export const GRUPOS = [
    {
        titulo: 'Gestión deportiva',
        modulos: [
            { clave: 'deportistas', texto: 'Deportistas', ruta: '#deportistas' },
            { clave: 'torneos', texto: 'Torneos', ruta: '#torneos' },
            { clave: 'ascensos', texto: 'Ascensos', ruta: null },
            { clave: 'estadisticas', texto: 'Estadísticas', ruta: null },
            { clave: 'historial', texto: 'Historial', ruta: null },
        ],
    },
    {
        titulo: 'Gestión administrativa',
        modulos: [
            { clave: 'documentos', texto: 'Documentos', ruta: '#documentos' },
            { clave: 'asistencia', texto: 'Asistencia', ruta: '#asistencia' },
            { clave: 'pagos', texto: 'Pagos y matrícula', ruta: '#pagos' },
        ],
    },
    {
        titulo: 'Otros',
        modulos: [
            { clave: 'calendario', texto: 'Calendario', ruta: '#calendario' },
            { clave: 'exportacion', texto: 'Exportación', ruta: null },
        ],
    },
];

export const MODULOS = GRUPOS.flatMap((grupo) => grupo.modulos);
