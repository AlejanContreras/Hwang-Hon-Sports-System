// Página del ámbito público: el deportista o su acudiente ven solo lo suyo.
// La API entra por el documento del deportista; no hay selector de hermanos.
// La ruta inicial es #inicio (dashboard del deportista); la marca del panel enlaza ahí.
import { iniciarPagina } from './navegacion.js';
import { GRUPOS_PUBLICOS } from './modulos.js';
import { mostrarDatos, mostrarAsistenciaPropia, mostrarPagosPropios } from './vistas/perfil.js';
import { mostrarDocumentosPropios } from './vistas/documentos.js';
import { mostrarCalendario } from './vistas/calendario.js';
import { mostrarEstadisticas } from './vistas/estadisticas.js';
import { mostrarMisAscensos } from './vistas/ascensos.js';
import { mostrarInicioDeportista } from './vistas/inicio-deportista.js';

const rutas = {
    inicio: { modulo: 'inicio', mostrar: (c, _p, sesion) => mostrarInicioDeportista(c, sesion) },
    perfil: { modulo: 'perfil', mostrar: (c, _p, sesion) => mostrarDatos(c, sesion.identificador) },
    asistencia: { modulo: 'asistencia', mostrar: (c) => mostrarAsistenciaPropia(c) },
    pagos: { modulo: 'pagos', mostrar: (c) => mostrarPagosPropios(c) },
    documentos: { modulo: 'documentos', mostrar: (c, _p, sesion) => mostrarDocumentosPropios(c, sesion.identificador) },
    calendario: { modulo: 'calendario', mostrar: (c) => mostrarCalendario(c, false) },
    ascensos: { modulo: 'ascensos', mostrar: (c, _p, sesion) => mostrarMisAscensos(c, sesion.identificador) },
    estadisticas: { modulo: 'estadisticas', mostrar: (c) => mostrarEstadisticas(c, { esAdministrativo: false, pestanaInicial: 'graficas' }) },
    historial: { modulo: 'historial', mostrar: (c) => mostrarEstadisticas(c, { esAdministrativo: false, pestanaInicial: 'registros' }) },
};

iniciarPagina({
    ambito: 'publica',
    rutas,
    rutaInicial: 'inicio',
    grupos: GRUPOS_PUBLICOS,
    etiquetaMenu: 'Secciones del deportista',
});
