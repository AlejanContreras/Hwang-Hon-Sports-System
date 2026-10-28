// Pantalla de ingreso (catálogo C.1): auth.sesionActual decide si se muestra o se redirige.
import { auth } from './api.js';
import { mostrarAviso, limpiarAviso, conBotonOcupado } from './ui.js';
import { paginaDeAmbito, leerMotivoSalida } from './sesion.js';

const verificando = document.getElementById('verificando');
const zonaIngreso = document.getElementById('zona-ingreso');
const aviso = document.getElementById('aviso-ingreso');

const pestanas = {
    administrativa: { boton: document.getElementById('pestana-administrativa'), panel: document.getElementById('panel-administrativa') },
    publica: { boton: document.getElementById('pestana-publica'), panel: document.getElementById('panel-publica') },
};

const formularioAdministrativo = document.getElementById('formulario-administrativo');
const formularioPublico = document.getElementById('formulario-publico');

// ---------------------------------------------------------------------------
// Pestañas (con flechas del teclado)
// ---------------------------------------------------------------------------
function seleccionarPestana(nombre, moverFoco = false) {
    for (const [clave, { boton, panel }] of Object.entries(pestanas)) {
        const activa = clave === nombre;
        boton.setAttribute('aria-selected', String(activa));
        boton.tabIndex = activa ? 0 : -1;
        panel.hidden = !activa;
    }
    limpiarAviso(aviso);
    if (moverFoco) pestanas[nombre].boton.focus();
}

for (const [clave, { boton }] of Object.entries(pestanas)) {
    boton.addEventListener('click', () => seleccionarPestana(clave));
    boton.addEventListener('keydown', (evento) => {
        if (evento.key === 'ArrowRight' || evento.key === 'ArrowLeft') {
            evento.preventDefault();
            seleccionarPestana(clave === 'administrativa' ? 'publica' : 'administrativa', true);
        }
    });
}

// ---------------------------------------------------------------------------
// Bloqueo por intentos (429): el botón queda deshabilitado los minutos que dice
// el mensaje, o hasta que se cambie el correo / documento (el bloqueo es por cuenta).
// ---------------------------------------------------------------------------
function bloquearEnvio(formulario, campoIdentificador, mensaje) {
    const boton = formulario.querySelector('button[type="submit"]');
    const minutos = Number((/(\d+)\s*minuto/.exec(mensaje) || [])[1] || 15);
    const valorBloqueado = campoIdentificador.value;

    boton.disabled = true;

    const desbloquear = () => {
        boton.disabled = false;
        clearTimeout(temporizador);
        campoIdentificador.removeEventListener('input', alCambiar);
    };
    const alCambiar = () => {
        if (campoIdentificador.value !== valorBloqueado) desbloquear();
    };
    const temporizador = setTimeout(desbloquear, minutos * 60 * 1000);
    campoIdentificador.addEventListener('input', alCambiar);
}

function manejarError(error, formulario, campoIdentificador) {
    mostrarAviso(aviso, error.message, error.estado === 403 ? 'advertencia' : 'error');
    if (error.estado === 429) bloquearEnvio(formulario, campoIdentificador, error.message);
}

function entrar(datos) {
    window.location.replace(paginaDeAmbito(datos.ambito));
}

// ---------------------------------------------------------------------------
// Envíos
// ---------------------------------------------------------------------------
formularioAdministrativo.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    const correo = formularioAdministrativo.correo;
    const contrasena = formularioAdministrativo.contrasena;
    if (!correo.value.trim() || !contrasena.value) {
        mostrarAviso(aviso, 'Escribe el correo y la contraseña.', 'error');
        (correo.value.trim() ? contrasena : correo).focus();
        return;
    }

    const boton = formularioAdministrativo.querySelector('button[type="submit"]');
    limpiarAviso(aviso);
    try {
        const datos = await conBotonOcupado(boton, 'Ingresando…', () =>
            auth.iniciarSesionAdministrativa(correo.value.trim(), contrasena.value));
        entrar(datos);
    } catch (error) {
        contrasena.value = '';
        manejarError(error, formularioAdministrativo, correo);
    }
});

formularioPublico.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    const documento = formularioPublico.documento;
    const fecha = formularioPublico.fecha_nacimiento;
    if (!documento.value.trim() || !fecha.value) {
        mostrarAviso(aviso, 'Escribe el documento y la fecha de nacimiento del deportista.', 'error');
        (documento.value.trim() ? fecha : documento).focus();
        return;
    }

    const boton = formularioPublico.querySelector('button[type="submit"]');
    limpiarAviso(aviso);
    try {
        const datos = await conBotonOcupado(boton, 'Ingresando…', () =>
            auth.iniciarSesionPublica(documento.value.trim(), fecha.value));
        entrar(datos);
    } catch (error) {
        manejarError(error, formularioPublico, documento);
    }
});

// ---------------------------------------------------------------------------
// Al cargar: ¿ya hay sesión?
// ---------------------------------------------------------------------------
async function iniciar() {
    try {
        const sesion = await auth.sesionActual();
        entrar(sesion);
        return;
    } catch (error) {
        if (error.estado !== 401) mostrarAviso(aviso, error.message, 'error');
    }

    const motivo = leerMotivoSalida();
    if (motivo) mostrarAviso(aviso, motivo, motivo === 'Sesión cerrada.' ? 'info' : 'advertencia');

    verificando.hidden = true;
    zonaIngreso.hidden = false;
    formularioAdministrativo.correo.focus();
}

iniciar();
