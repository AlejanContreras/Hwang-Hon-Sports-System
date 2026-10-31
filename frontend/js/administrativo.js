// Página del ámbito administrativo: rutas por «#ruta/parámetro» sobre la estructura común.
import { iniciarPagina } from './navegacion.js';
import * as vistaDeportistas from './vistas/deportistas.js';
import { mostrarAsistencia } from './vistas/asistencia.js';
import { mostrarPagos } from './vistas/pagos.js';
import { mostrarInicio } from './vistas/inicio.js';
import { mostrarDocumentos } from './vistas/documentos.js';
import { mostrarTorneos, mostrarTorneo, mostrarNuevoTorneo } from './vistas/torneos.js';
import { mostrarCalendario } from './vistas/calendario.js';
import { mostrarEstadisticas } from './vistas/estadisticas.js';
import { mostrarAscensos, mostrarSesion, mostrarNuevaSesion } from './vistas/ascensos.js';
import { mostrarExportacion } from './vistas/exportacion.js';
import { mostrarImportacion } from './vistas/importacion.js';
import { mostrarCuentas, mostrarCambiarContrasena } from './vistas/cuentas.js';
import { GRUPOS } from './modulos.js';

// ruta → { modulo (para marcar el menú), mostrar(contenedor, parametro, sesion) }
const rutas = {
    inicio: { modulo: 'inicio', mostrar: (c, _parametro, sesion) => mostrarInicio(c, sesion) },
    deportistas: { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarLista(c, 'todos') },
    pendientes: { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarLista(c, 'pendientes') },
    deportista: { modulo: 'deportistas', mostrar: (c, documento) => vistaDeportistas.mostrarFicha(c, documento) },
    'deportista-nuevo': { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarFormulario(c, null) },
    'deportista-editar': { modulo: 'deportistas', mostrar: (c, documento) => vistaDeportistas.mostrarFormulario(c, documento) },
    asistencia: { modulo: 'asistencia', mostrar: (c, fecha) => mostrarAsistencia(c, fecha) },
    pagos: { modulo: 'pagos', mostrar: (c, documento) => mostrarPagos(c, documento) },
    documentos: { modulo: 'documentos', mostrar: (c, documento) => mostrarDocumentos(c, documento) },
    torneos: { modulo: 'torneos', mostrar: (c) => mostrarTorneos(c) },
    torneo: { modulo: 'torneos', mostrar: (c, id) => mostrarTorneo(c, id) },
    'torneo-nuevo': { modulo: 'torneos', mostrar: (c) => mostrarNuevoTorneo(c) },
    calendario: { modulo: 'calendario', mostrar: (c) => mostrarCalendario(c, true) },
    ascensos: { modulo: 'ascensos', mostrar: (c) => mostrarAscensos(c) },
    ascenso: { modulo: 'ascensos', mostrar: (c, id) => mostrarSesion(c, id) },
    'ascenso-nuevo': { modulo: 'ascensos', mostrar: (c) => mostrarNuevaSesion(c) },
    exportacion: { modulo: 'exportacion', mostrar: (c) => mostrarExportacion(c) },
    importar: { modulo: 'importar', mostrar: (c) => mostrarImportacion(c) },
    cuentas: { modulo: 'cuentas', mostrar: (c, _p, sesion) => mostrarCuentas(c, sesion) },
    'mi-contrasena': { modulo: 'cuentas', mostrar: (c) => mostrarCambiarContrasena(c) },
    estadisticas: { modulo: 'estadisticas', mostrar: (c, documento) => mostrarEstadisticas(c, { esAdministrativo: true, pestanaInicial: 'graficas', documento }) },
    historial: { modulo: 'historial', mostrar: (c, documento) => mostrarEstadisticas(c, { esAdministrativo: true, pestanaInicial: 'registros', documento }) },
};

iniciarPagina({
    ambito: 'administrativa',
    rutas,
    rutaInicial: 'inicio',
    grupos: GRUPOS,
});
