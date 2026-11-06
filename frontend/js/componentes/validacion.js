// Validación de cliente (D2) para documento, nombre y teléfono. Es solo del front: el backend no
// cambia (sus baterías usan documentos cortos). Mensajes junto al campo, nunca bloqueos silenciosos.
// Un valor guardado que no cumple y que la persona NO tocó solo muestra advertencia y deja guardar.
import { crear } from '../ui.js';

const REGLAS = {
    documento: {
        valido: (v) => /^\d{7,10}$/.test(v),
        mensaje: 'El documento debe tener entre 7 y 10 números.',
        filtro: /\D/g,
        aviso: 'Solo se permiten números.',
        maximo: 10,
        atributos: { inputmode: 'numeric', maxlength: '10', pattern: '\\d{7,10}', autocomplete: 'off' },
    },
    telefono: {
        valido: (v) => /^\d{7,10}$/.test(v),
        mensaje: 'El teléfono debe tener entre 7 y 10 números, sin espacios, símbolos ni el indicativo +57.',
        filtro: /\D/g,
        aviso: 'Solo se permiten números.',
        // Sin recorte a 10: un número pegado con +57 se ve completo y se marca, no se corta en silencio.
        atributos: { inputmode: 'numeric', pattern: '\\d{7,10}' },
    },
    nombre: {
        // Letras (con tildes, ñ y ü), espacios, apóstrofo y guion. Ej.: «María José Núñez», «O'Brien», «Pérez-Gómez».
        valido: (v) => /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+(?:[ '’-][A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+)*$/.test(v.replace(/\s+/g, ' ')),
        mensaje: 'El nombre solo puede tener letras, espacios, apóstrofo (\') y guion (-).',
    },
};

const estado = new WeakMap(); // control → { tipo, obligatorio, original, mensaje }

function mensajeDe(control) {
    let nodo = estado.get(control).mensaje;
    if (!nodo) {
        nodo = crear('small', { clase: 'mensaje-campo', atributos: { id: `${control.id}-mensaje`, 'aria-live': 'polite', hidden: true } });
        const campo = control.closest('.campo') || control.parentElement;
        campo.append(nodo);
        const descrito = (control.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        control.setAttribute('aria-describedby', [...descrito, nodo.id].join(' '));
        estado.get(control).mensaje = nodo;
    }
    return nodo;
}

function mostrar(control, texto, tipo) {
    const nodo = mensajeDe(control);
    nodo.textContent = texto || '';
    nodo.hidden = !texto;
    nodo.className = `mensaje-campo${tipo ? ` mensaje-${tipo}` : ''}`;
    if (tipo === 'error') control.setAttribute('aria-invalid', 'true');
    else control.removeAttribute('aria-invalid');
}

// Revisa un control: devuelve true si se puede enviar. Marca error o advertencia junto al campo.
export function revisarCampo(control) {
    const datos = estado.get(control);
    if (!datos) return true;
    const regla = REGLAS[datos.tipo];
    const valor = control.value.trim();
    if (!valor) {
        mostrar(control, '', null);
        return true; // los obligatorios vacíos los avisa el formulario
    }
    if (regla.valido(valor)) {
        mostrar(control, '', null);
        return true;
    }
    if (datos.original !== null && valor === datos.original) {
        mostrar(control, `Dato guardado antes de esta validación: ${regla.mensaje} Se puede guardar sin cambiarlo.`, 'advertencia');
        return true;
    }
    mostrar(control, regla.mensaje, 'error');
    return false;
}

// tipo: 'documento' | 'nombre' | 'telefono'. original: valor guardado (edición) o null.
export function validarCampo(control, tipo, { original = null } = {}) {
    const regla = REGLAS[tipo];
    estado.set(control, { tipo, original: original === null || original === undefined ? null : String(original).trim(), mensaje: null });
    for (const [nombre, valor] of Object.entries(regla.atributos || {})) control.setAttribute(nombre, valor);
    if (regla.filtro) {
        // Filtra al teclear o pegar y lo dice (no se borra nada en silencio).
        control.addEventListener('input', () => {
            const sinSimbolos = control.value.replace(regla.filtro, '');
            const limpio = regla.maximo ? sinSimbolos.slice(0, regla.maximo) : sinSimbolos;
            if (limpio !== control.value) {
                control.value = limpio;
                mostrar(control, regla.aviso, 'advertencia');
            } else if (estado.get(control).mensaje?.classList.contains('mensaje-advertencia') && !control.getAttribute('aria-invalid')) {
                mostrar(control, '', null);
            }
        });
    }
    control.addEventListener('blur', () => revisarCampo(control));
    if (estado.get(control).original !== null) revisarCampo(control);
    return control;
}

// Revisa varios controles al enviar. Devuelve el primero con error (y le da el foco) o null.
export function primerCampoInvalido(controles) {
    let primero = null;
    for (const control of controles.filter(Boolean)) {
        if (!revisarCampo(control) && !primero) primero = control;
    }
    if (primero) primero.focus();
    return primero;
}
