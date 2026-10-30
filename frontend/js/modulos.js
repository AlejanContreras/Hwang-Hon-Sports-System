// Módulos del ámbito administrativo: una sola lista para el menú lateral y los accesos rápidos
// del inicio. Para habilitar un módulo al construirlo basta con ponerle su «ruta».
// La agrupación sigue el boceto de John (12/10/2026), PROPUESTA aún sin validar.
export const GRUPOS = [
    {
        titulo: 'Gestión deportiva',
        modulos: [
            { clave: 'deportistas', texto: 'Deportistas', ruta: '#deportistas' },
            { clave: 'importar', texto: 'Carga masiva (CSV)', ruta: '#importar' },
            { clave: 'torneos', texto: 'Torneos', ruta: '#torneos' },
            { clave: 'ascensos', texto: 'Ascensos', ruta: '#ascensos' },
            { clave: 'estadisticas', texto: 'Estadísticas', ruta: '#estadisticas' },
            { clave: 'historial', texto: 'Historial', ruta: '#historial' },
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
            { clave: 'exportacion', texto: 'Exportación', ruta: '#exportacion' },
            // Solo cuentas principales (la API da 403 a las demás): se filtra con la sesión.
            { clave: 'cuentas', texto: 'Cuentas', ruta: '#cuentas', soloPrincipal: true },
        ],
    },
];

export const MODULOS = GRUPOS.flatMap((grupo) => grupo.modulos);

// Grupos visibles para una sesión: oculta los módulos «soloPrincipal» a las cuentas no principales.
export function gruposPara(sesion) {
    return GRUPOS.map((grupo) => ({
        ...grupo,
        modulos: grupo.modulos.filter((m) => !m.soloPrincipal || sesion.es_administrador_principal),
    }));
}

export function modulosPara(sesion) {
    return gruposPara(sesion).flatMap((grupo) => grupo.modulos);
}
