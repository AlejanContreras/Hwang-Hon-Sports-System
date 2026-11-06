// Botón con ojo junto a cada campo de contraseña (D1/E1). Alterna el type entre password y text.
// El ícono muestra la ACCIÓN disponible: oculta → ojo (mostrar); visible → ojo tachado (ocultar).
// Al enviar cualquier formulario, sus contraseñas vuelven a quedar ocultas.
// No cambia el id, name ni data-prueba del campo: solo lo envuelve en <span class="campo-contrasena">.
import { crear } from '../ui.js';
import { crear as crearIcono } from './iconos.js';

const MARCA = 'data-ver-contrasena';

function fijar(boton, campo, visible) {
    campo.type = visible ? 'text' : 'password';
    boton.replaceChildren(crearIcono(visible ? 'eye-off' : 'eye', { tamano: 20 }));
    boton.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    boton.setAttribute('aria-pressed', String(visible));
}

// Agrega el botón a un <input type="password"> (suelto o ya dentro de la página). Devuelve el campo.
export function agregarVerContrasena(campo) {
    if (campo.hasAttribute(MARCA)) return campo;
    campo.setAttribute(MARCA, '');
    const boton = crear('button', {
        clase: 'boton-ver-contrasena',
        atributos: { type: 'button', 'aria-controls': campo.id || null, 'data-prueba': campo.name ? `ver-${campo.name}` : null },
    });
    fijar(boton, campo, false);
    boton.addEventListener('click', () => fijar(boton, campo, campo.type === 'password'));
    const envoltura = crear('span', { clase: 'campo-contrasena' });
    campo.replaceWith(envoltura);
    envoltura.append(campo, boton);
    return campo;
}

// Para páginas estáticas (ingreso y recuperar): todos los campos de contraseña de la raíz.
export function prepararCamposContrasena(raiz = document) {
    for (const campo of raiz.querySelectorAll('input[type="password"]')) agregarVerContrasena(campo);
}

// Al enviar (en fase de captura, antes del manejador del formulario) se vuelven a ocultar.
document.addEventListener('submit', (evento) => {
    for (const campo of evento.target.querySelectorAll(`input[${MARCA}]`)) {
        const boton = campo.parentElement.querySelector('.boton-ver-contrasena');
        if (boton) fijar(boton, campo, false);
    }
}, true);
