// Página del ámbito público («Mi perfil»): el deportista o su acudiente ven solo lo suyo.
// La API entra por el documento del deportista; no hay selector de hermanos.
import { iniciarPagina } from './navegacion.js';
import { deportistas } from './api.js';
import { textoGrado, textoEstado, insignia } from './ui.js';
import { mostrarDatos, mostrarAsistenciaPropia, mostrarPagosPropios } from './vistas/perfil.js';
import { mostrarDocumentosPropios } from './vistas/documentos.js';
import { mostrarCalendario } from './vistas/calendario.js';

// Secciones de pasos posteriores: sin ruta, salen «Próximamente».
const GRUPOS_PUBLICOS = [
    {
        titulo: 'Mi perfil',
        modulos: [
            { clave: 'perfil', texto: 'Mis datos', ruta: '#perfil' },
            { clave: 'asistencia', texto: 'Asistencia', ruta: '#asistencia' },
            { clave: 'pagos', texto: 'Pagos', ruta: '#pagos' },
            { clave: 'documentos', texto: 'Documentos', ruta: '#documentos' },
        ],
    },
    {
        titulo: 'Seguimiento',
        modulos: [
            { clave: 'estadisticas', texto: 'Estadísticas', ruta: null },
            { clave: 'historial', texto: 'Historial', ruta: null },
            { clave: 'calendario', texto: 'Calendario', ruta: '#calendario' },
        ],
    },
];

const rutas = {
    perfil: { modulo: 'perfil', mostrar: (c, _p, sesion) => mostrarDatos(c, sesion.identificador, pintarCabecera) },
    asistencia: { modulo: 'asistencia', mostrar: (c) => mostrarAsistenciaPropia(c) },
    pagos: { modulo: 'pagos', mostrar: (c) => mostrarPagosPropios(c) },
    documentos: { modulo: 'documentos', mostrar: (c, _p, sesion) => mostrarDocumentosPropios(c, sesion.identificador) },
    calendario: { modulo: 'calendario', mostrar: (c) => mostrarCalendario(c, false) },
};

// Cabecera: grado (cinturón) y estado junto al nombre.
function pintarCabecera(deportista) {
    const datos = document.getElementById('datos-perfil');
    datos.replaceChildren(
        `Documento ${deportista.documento} · Grado: ${textoGrado(deportista.grado_actual)} · `,
        insignia(textoEstado(deportista.estado), deportista.estado === 'activo' ? 'activo' : 'inactivo'),
    );
}

iniciarPagina({
    ambito: 'publica',
    rutas,
    rutaInicial: 'perfil',
    grupos: GRUPOS_PUBLICOS,
    alIniciar: async (sesion) => {
        try {
            pintarCabecera(await deportistas.consultar(sesion.identificador));
        } catch {
            // la cabecera conserva el nombre; la vista mostrará el error si lo hay
        }
    },
});
