// Recuperación de contraseña (solo cuentas administrativas, sin sesión):
// sin token → auth.solicitarRecuperacionContrasena; con ?token= (enlace del correo) → auth.restablecerContrasena.
import { auth } from './api.js';
import { mostrarAviso, limpiarAviso, conBotonOcupado } from './ui.js';
import { prepararCamposContrasena } from './componentes/ver-contrasena.js';

const aviso = document.getElementById('aviso-recuperar');
const zonaSolicitar = document.getElementById('zona-solicitar');
const zonaRestablecer = document.getElementById('zona-restablecer');
const formularioSolicitar = document.getElementById('formulario-solicitar');
const formularioRestablecer = document.getElementById('formulario-restablecer');

const token = new URLSearchParams(window.location.search).get('token');
prepararCamposContrasena();

formularioSolicitar.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    const correo = formularioSolicitar.correo;
    if (!correo.value.trim() || !correo.checkValidity()) {
        mostrarAviso(aviso, 'Escribe un correo válido.', 'error');
        correo.focus();
        return;
    }
    const boton = formularioSolicitar.querySelector('button[type="submit"]');
    limpiarAviso(aviso);
    try {
        const datos = await conBotonOcupado(boton, 'Enviando…', () => auth.solicitarRecuperacionContrasena(correo.value.trim()));
        // El correo de recuperación puede caer en spam: se avisa aquí, sin tocar el mensaje del servidor.
        const mensaje = String(datos.mensaje || '').trim();
        const separador = /[.!?]$/.test(mensaje) ? ' ' : '. ';
        mostrarAviso(aviso, mensaje + separador + 'Si no lo ves en tu bandeja de entrada, revisa tu carpeta de spam o correo no deseado.', 'exito');
    } catch (error) {
        mostrarAviso(aviso, error.message, 'error');
    }
});

formularioRestablecer.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    const nueva = formularioRestablecer.contrasena_nueva;
    const repetida = formularioRestablecer.contrasena_repetida;
    if (nueva.value.length < 8) {
        mostrarAviso(aviso, 'La contraseña nueva debe tener mínimo 8 caracteres.', 'error');
        nueva.focus();
        return;
    }
    if (nueva.value !== repetida.value) {
        mostrarAviso(aviso, 'Las dos contraseñas no coinciden.', 'error');
        repetida.focus();
        return;
    }
    const boton = formularioRestablecer.querySelector('button[type="submit"]');
    limpiarAviso(aviso);
    try {
        await conBotonOcupado(boton, 'Guardando…', () => auth.restablecerContrasena(token, nueva.value));
        formularioRestablecer.reset();
        zonaRestablecer.hidden = true;
        mostrarAviso(aviso, 'Contraseña guardada. Ya puedes ingresar con la contraseña nueva.', 'exito');
    } catch (error) {
        mostrarAviso(aviso, error.message, 'error');
    }
});

if (token) {
    zonaSolicitar.hidden = true;
    zonaRestablecer.hidden = false;
    formularioRestablecer.contrasena_nueva.focus();
} else {
    formularioSolicitar.correo.focus();
}
