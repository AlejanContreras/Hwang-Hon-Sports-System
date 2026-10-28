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
// opciones.formulario (FormData): envío multipart. No se fija Content-Type: el navegador
// pone el límite (boundary) por su cuenta.
async function solicitar(metodo, recurso, accion, { parametros, cuerpo, formulario, avisar401 = true } = {}) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (formulario !== undefined) {
        opciones.body = formulario;
    } else if (cuerpo !== undefined) {
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
// B.6 documentos
// ---------------------------------------------------------------------------
function formularioArchivo(campos) {
    const datos = new FormData();
    for (const [clave, valor] of Object.entries(campos)) {
        if (valor !== undefined && valor !== null && valor !== '') datos.append(clave, valor);
    }
    return datos;
}

export const documentos = {
    // El público puede omitir el documento: se usan los suyos.
    listar: (documento) => solicitar('GET', 'documentos', 'listar', { parametros: { documento } }),
    subir: (documento, tipoDocumento, archivo) => solicitar('POST', 'documentos', 'subir', {
        formulario: formularioArchivo({ documento, tipo_documento: tipoDocumento, archivo }),
    }),
    reemplazar: (id, archivo) => solicitar('POST', 'documentos', 'reemplazar', {
        parametros: { id }, formulario: formularioArchivo({ archivo }),
    }),
    eliminar: (id) => solicitar('DELETE', 'documentos', 'eliminar', { parametros: { id } }),
    descargar: (id, nombre) => descargar('documentos', 'descargar', { id }, nombre),
    // Sin documento: todos los pendientes de validación con sus faltantes.
    faltantes: (documento) => solicitar('GET', 'documentos', 'faltantes', { parametros: { documento } }),
    listarGenerales: () => solicitar('GET', 'documentos', 'listarGenerales'),
    descargarGeneral: (id, nombre) => descargar('documentos', 'descargarGeneral', { id }, nombre),
    subirGeneral: (titulo, archivo) => solicitar('POST', 'documentos', 'subirGeneral', {
        formulario: formularioArchivo({ titulo, archivo }),
    }),
    reemplazarGeneral: (id, archivo, titulo) => solicitar('POST', 'documentos', 'reemplazarGeneral', {
        parametros: { id }, formulario: formularioArchivo({ archivo, titulo }),
    }),
    eliminarGeneral: (id) => solicitar('DELETE', 'documentos', 'eliminarGeneral', { parametros: { id } }),
};

// ---------------------------------------------------------------------------
// B.4 autorregistro (sin sesión; las acciones de documentos usan el permiso de 2 horas)
// ---------------------------------------------------------------------------
export const autorregistro = {
    registrar: (datos) => solicitar('POST', 'autorregistro', 'registrar', { cuerpo: datos, avisar401: false }),
    listarDocumentos: () => solicitar('GET', 'autorregistro', 'listarDocumentos', { avisar401: false }),
    subirDocumento: (tipoDocumento, archivo) => solicitar('POST', 'autorregistro', 'subirDocumento', {
        formulario: formularioArchivo({ tipo_documento: tipoDocumento, archivo }), avisar401: false,
    }),
    reemplazarDocumento: (id, archivo) => solicitar('POST', 'autorregistro', 'reemplazarDocumento', {
        parametros: { id }, formulario: formularioArchivo({ archivo }), avisar401: false,
    }),
    eliminarDocumento: (id) => solicitar('DELETE', 'autorregistro', 'eliminarDocumento', { parametros: { id }, avisar401: false }),
    confirmar: () => solicitar('POST', 'autorregistro', 'confirmar', { avisar401: false }),
};

// ---------------------------------------------------------------------------
// B.7 asistencia
// ---------------------------------------------------------------------------
export const asistencia = {
    listarPantalla: (fecha) => solicitar('GET', 'asistencia', 'listarPantalla', { parametros: { fecha } }),
    tomar: (fecha, presentes) => solicitar('POST', 'asistencia', 'tomar', { cuerpo: { fecha, presentes } }),
    resumenDia: () => solicitar('GET', 'asistencia', 'resumenDia'),
    // filtros: fecha, o desde + hasta, o documento (el público puede no enviar nada: usa el propio)
    listar: (filtros = {}) => solicitar('GET', 'asistencia', 'listar', { parametros: filtros }),
    corregir: (documento, fecha, asistio) =>
        solicitar('PUT', 'asistencia', 'corregir', { parametros: { documento, fecha }, cuerpo: { asistio } }),
    eliminarMarca: (documento, fecha) =>
        solicitar('DELETE', 'asistencia', 'eliminarMarca', { parametros: { documento, fecha } }),
    eliminarFecha: (fecha) => solicitar('DELETE', 'asistencia', 'eliminarFecha', { parametros: { fecha } }),
    // ventana: '30', '90' o 'todo'
    porcentaje: (documento, ventana) => solicitar('GET', 'asistencia', 'porcentaje', { parametros: { documento, ventana } }),
    revisarInactivos: () => solicitar('POST', 'asistencia', 'revisarInactivos'),
};

// ---------------------------------------------------------------------------
// B.8 pagos
// ---------------------------------------------------------------------------
export const pagos = {
    estado: (documento, anio) => solicitar('GET', 'pagos', 'estado', { parametros: { documento, anio } }),
    // mensualidad: lista de { mes, semana }
    registrarMensualidades: (documento, anio, meses) =>
        solicitar('POST', 'pagos', 'registrar', { cuerpo: { documento, tipo: 'mensualidad', anio, pagos: meses } }),
    registrarMatricula: (documento, anio) =>
        solicitar('POST', 'pagos', 'registrar', { cuerpo: { documento, tipo: 'matricula', anio } }),
    anular: (id) => solicitar('DELETE', 'pagos', 'anular', { parametros: { id } }),
    deudores: () => solicitar('GET', 'pagos', 'deudores'),
    listar: (filtros = {}) => solicitar('GET', 'pagos', 'listar', { parametros: filtros }),
};

// ---------------------------------------------------------------------------
// B.9 calendario
// ---------------------------------------------------------------------------
export const calendario = {
    listar: (filtros = {}) => solicitar('GET', 'calendario', 'listar', { parametros: filtros }),
    consultar: (id) => solicitar('GET', 'calendario', 'consultar', { parametros: { id } }),
    crear: (datos) => solicitar('POST', 'calendario', 'crear', { cuerpo: datos }),
    // nombre, fecha, lugar, visibilidad y observaciones (en torneos y exámenes solo nombre y observaciones).
    actualizar: (id, cambios) => solicitar('PUT', 'calendario', 'actualizar', { parametros: { id }, cuerpo: cambios }),
    vista: (desde, hasta) => solicitar('GET', 'calendario', 'vista', { parametros: { desde, hasta } }),
    importar: (archivo) => solicitar('POST', 'calendario', 'importar', { formulario: formularioArchivo({ archivo }) }),
};

// ---------------------------------------------------------------------------
// B.10 competencias (torneos)
// ---------------------------------------------------------------------------
function claveParticipacion(documento, eventoId, modalidad) {
    return { deportista_documento: documento, competencia_evento_id: eventoId, modalidad };
}

export const competencias = {
    // filtros: deportista, desde, hasta, lugar, tipo_torneo
    listar: (filtros = {}) => solicitar('GET', 'competencias', 'listar', { parametros: filtros }),
    consultar: (id) => solicitar('GET', 'competencias', 'consultar', { parametros: { id } }),
    // El próximo torneo (fecha ≥ hoy) o null.
    proximo: () => solicitar('GET', 'competencias', 'proximo'),
    // datos: { tipo_torneo, plataforma?, evento: {...} } o { tipo_torneo, plataforma?, evento_id }
    crear: (datos) => solicitar('POST', 'competencias', 'crear', { cuerpo: datos }),
    // plataforma, tipo_torneo, fecha, lugar y visibilidad (nombre y observaciones: calendario.actualizar).
    actualizar: (id, cambios) => solicitar('PUT', 'competencias', 'actualizar', { parametros: { id }, cuerpo: cambios }),
    sugerencia: (documento, eventoId, modalidad) => solicitar('GET', 'competencias', 'sugerencia', {
        parametros: { deportista_documento: documento, competencia_evento_id: eventoId, modalidad },
    }),
    // participantes: [{ deportista_documento, modalidad, categoria_edad?, categoria_peso? }] (máximo 50)
    inscribir: (eventoId, participantes) => solicitar('POST', 'competencias', 'inscribir', {
        cuerpo: { competencia_evento_id: eventoId, participantes },
    }),
    actualizarParticipacion: (documento, eventoId, modalidad, cambios) => solicitar('PUT', 'competencias', 'actualizarParticipacion', {
        parametros: claveParticipacion(documento, eventoId, modalidad), cuerpo: cambios,
    }),
    quitarParticipante: (documento, eventoId, modalidad) => solicitar('DELETE', 'competencias', 'quitarParticipante', {
        parametros: claveParticipacion(documento, eventoId, modalidad),
    }),
};

// ---------------------------------------------------------------------------
// B.14 reportes (archivos)
// ---------------------------------------------------------------------------
export const reportes = {
    deportistaFicha: (documento) =>
        descargar('reportes', 'deportistaFicha', { deportista: documento }, `ficha_${documento}.pdf`),
};
