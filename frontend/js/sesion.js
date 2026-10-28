// Control de sesión compartido por las páginas con ingreso (administrativa y pública).
import { auth, alVencerSesion } from './api.js';

const PAGINA_INGRESO = 'index.html';
const PAGINA_POR_AMBITO = { administrativa: 'administrativo.html', publica: 'publico.html' };
const CLAVE_MOTIVO = 'hwanghon_motivo_salida';

export function paginaDeAmbito(ambito) {
    return PAGINA_POR_AMBITO[ambito] || PAGINA_INGRESO;
}

// Mensaje que la pantalla de ingreso muestra al volver (p. ej. «Tu sesión ya no es válida.»).
export function guardarMotivoSalida(mensaje) {
    try {
        sessionStorage.setItem(CLAVE_MOTIVO, mensaje);
    } catch {
        // sin almacenamiento: se vuelve al ingreso sin mensaje
    }
}

export function leerMotivoSalida() {
    try {
        const mensaje = sessionStorage.getItem(CLAVE_MOTIVO);
        sessionStorage.removeItem(CLAVE_MOTIVO);
        return mensaje;
    } catch {
        return null;
    }
}

function volverAlIngreso(mensaje) {
    if (mensaje) guardarMotivoSalida(mensaje);
    window.location.replace(PAGINA_INGRESO);
}

// Llamar al cargar una página con sesión. Devuelve los datos de auth.sesionActual o
// redirige (sin sesión → ingreso; otro ámbito → su página).
export async function exigirSesion(ambitoEsperado) {
    alVencerSesion((error) => volverAlIngreso(error.message));

    let sesion;
    try {
        sesion = await auth.sesionActual();
    } catch (error) {
        volverAlIngreso(error.estado === 401 ? null : error.message);
        return null;
    }
    if (sesion.ambito !== ambitoEsperado) {
        window.location.replace(paginaDeAmbito(sesion.ambito));
        return null;
    }
    return sesion;
}

export async function cerrarSesion() {
    try {
        await auth.cerrarSesion();
    } finally {
        volverAlIngreso('Sesión cerrada.');
    }
}
