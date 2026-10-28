// Único punto de contacto con la API (documentacion/api/catalogo_api.md).
// El front se sirve desde el mismo origen que la API (A.4): la cookie de sesión
// la maneja el navegador y nunca se lee desde aquí.

const URL_API = '../backend/Public/api.php';

export class ErrorApi extends Error {
    constructor(estado, mensaje) {
        super(mensaje);
        this.name = 'ErrorApi';
        this.estado = estado;
    }
}

// Se llama cuando una ruta autenticada responde 401 (sesión vencida o invalidada).
let manejadorSesionVencida = null;

export function alVencerSesion(funcion) {
    manejadorSesionVencida = funcion;
}

function construirUrl(recurso, accion, parametros = {}) {
    const busqueda = new URLSearchParams({ recurso, accion });
    for (const [clave, valor] of Object.entries(parametros)) {
        if (valor !== undefined && valor !== null && valor !== '') {
            busqueda.append(clave, String(valor));
        }
    }
    return `${URL_API}?${busqueda.toString()}`;
}

async function leerJson(respuesta) {
    try {
        return await respuesta.json();
    } catch {
        return null;
    }
}

// opciones.avisar401 = false en las rutas de ingreso: ahí el 401 es «credenciales
// erradas» y lo maneja la pantalla, no el aviso de sesión vencida.
async function solicitar(metodo, recurso, accion, { parametros, cuerpo, avisar401 = true } = {}) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (cuerpo !== undefined) {
        opciones.headers['Content-Type'] = 'application/json';
        opciones.body = JSON.stringify(cuerpo);
    }

    let respuesta;
    try {
        respuesta = await fetch(construirUrl(recurso, accion, parametros), opciones);
    } catch {
        throw new ErrorApi(0, 'No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.');
    }

    const contenido = await leerJson(respuesta);
    if (contenido && contenido.exito === true) {
        return contenido.datos;
    }

    const mensaje = contenido && typeof contenido.mensaje === 'string'
        ? contenido.mensaje
        : `Respuesta inesperada del servidor (código ${respuesta.status}).`;
    const error = new ErrorApi(respuesta.status, mensaje);

    if (respuesta.status === 401 && avisar401 && manejadorSesionVencida) {
        manejadorSesionVencida(error);
    }
    throw error;
}

function nombreDesdeCabecera(cabecera, nombrePorDefecto) {
    if (!cabecera) return nombrePorDefecto;
    const extendido = /filename\*=UTF-8''([^;]+)/i.exec(cabecera);
    if (extendido) {
        try {
            return decodeURIComponent(extendido[1]);
        } catch {
            // se usa el nombre simple
        }
    }
    const simple = /filename="([^"]+)"/i.exec(cabecera);
    return simple ? simple[1] : nombrePorDefecto;
}

// Descargas (A.8): si algo falla la API responde el JSON de error, así que se
// revisa el código y el tipo antes de guardar.
async function descargar(recurso, accion, parametros, nombrePorDefecto) {
    let respuesta;
    try {
        respuesta = await fetch(construirUrl(recurso, accion, parametros), { credentials: 'same-origin' });
    } catch {
        throw new ErrorApi(0, 'No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.');
    }

    const tipo = respuesta.headers.get('Content-Type') || '';
    if (!respuesta.ok || tipo.includes('application/json')) {
        const contenido = await leerJson(respuesta);
        const mensaje = contenido && contenido.mensaje ? contenido.mensaje : 'No se pudo descargar el archivo.';
        const error = new ErrorApi(respuesta.status, mensaje);
        if (respuesta.status === 401 && manejadorSesionVencida) manejadorSesionVencida(error);
        throw error;
    }

    const archivo = await respuesta.blob();
    const nombre = nombreDesdeCabecera(respuesta.headers.get('Content-Disposition'), nombrePorDefecto);
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(archivo);
    enlace.download = nombre;
    document.body.append(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(enlace.href), 10000);
    return nombre;
}

// ---------------------------------------------------------------------------
// B.1 auth
// ---------------------------------------------------------------------------
export const auth = {
    sesionActual: () => solicitar('GET', 'auth', 'sesionActual', { avisar401: false }),
    iniciarSesionAdministrativa: (correo, contrasena) =>
        solicitar('POST', 'auth', 'iniciarSesionAdministrativa', { cuerpo: { correo, contrasena }, avisar401: false }),
    iniciarSesionPublica: (documento, fechaNacimiento) =>
        solicitar('POST', 'auth', 'iniciarSesionPublica', {
            cuerpo: { documento, fecha_nacimiento: fechaNacimiento },
            avisar401: false,
        }),
    cerrarSesion: () => solicitar('POST', 'auth', 'cerrarSesion', { avisar401: false }),
};

// ---------------------------------------------------------------------------
// B.3 deportistas
// ---------------------------------------------------------------------------
export const deportistas = {
    // filtros: estado, estado_registro, sede_entrenamiento, grado_actual, acudiente_documento
    listar: (filtros = {}) => solicitar('GET', 'deportistas', 'listar', { parametros: filtros }),
    consultar: (documento) => solicitar('GET', 'deportistas', 'consultar', { parametros: { documento } }),
    buscar: (texto) => solicitar('GET', 'deportistas', 'buscar', { parametros: { texto } }),
    crear: (datos) => solicitar('POST', 'deportistas', 'crear', { cuerpo: datos }),
    actualizar: (documento, cambios) =>
        solicitar('PUT', 'deportistas', 'actualizar', { parametros: { documento }, cuerpo: cambios }),
    eliminar: (documento) => solicitar('DELETE', 'deportistas', 'eliminar', { parametros: { documento } }),
};

// ---------------------------------------------------------------------------
// B.14 reportes (archivos)
// ---------------------------------------------------------------------------
export const reportes = {
    deportistaFicha: (documento) =>
        descargar('reportes', 'deportistaFicha', { deportista: documento }, `ficha_${documento}.pdf`),
};
