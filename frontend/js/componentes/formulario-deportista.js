// Piezas compartidas por los formularios de deportista (crear/editar del club y autorregistro público)
// y por el lugar de las sesiones de ascenso: la sede del club y el acudiente obligatorio para menores.
import { crear, hoyBogota } from '../ui.js';

// Valor guardado y texto visible de la sede principal.
export const SEDE_PRINCIPAL = 'Club Hwang Hon';
export const ETIQUETA_SEDE_PRINCIPAL = 'Club Hwang Hon — sede principal (Ciudad Verde)';

// Opciones del <select> de sede. Si el deportista tiene otra sede guardada (p. ej. «Escuela IMRDS»),
// se agrega tal cual para no perderla al editar.
export function opcionesSede(valorActual = '') {
    const opciones = [
        { valor: '', texto: 'Sin especificar' },
        { valor: SEDE_PRINCIPAL, texto: ETIQUETA_SEDE_PRINCIPAL },
    ];
    const actual = (valorActual ?? '').trim();
    if (actual && actual !== SEDE_PRINCIPAL) opciones.push({ valor: actual, texto: actual });
    return opciones;
}

// Edad cumplida hoy (hora de Bogotá) para una fecha AAAA-MM-DD; null si la fecha no es válida.
export function edadCumplida(fecha) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fecha || '');
    if (!partes) return null;
    const [anioHoy, mesHoy, diaHoy] = hoyBogota().split('-').map(Number);
    const [, anio, mes, dia] = partes.map(Number);
    let edad = anioHoy - anio;
    if (mesHoy < mes || (mesHoy === mes && diaHoy < dia)) edad -= 1;
    return edad;
}

// Marca u oculta el asterisco y el atributo required de un control creado con campo().
function marcarObligatorio(control, obligatorio) {
    control.required = obligatorio;
    const etiqueta = control.closest('.campo')?.querySelector('label');
    const asterisco = etiqueta?.querySelector('.obligatorio');
    if (obligatorio && etiqueta && !asterisco) {
        etiqueta.append(crear('span', { clase: 'obligatorio', texto: ' *', atributos: { 'aria-hidden': 'true' } }));
    } else if (!obligatorio && asterisco) {
        asterisco.remove();
    }
}

// Acudiente obligatorio si la fecha de nacimiento da menos de 18 años (escucha el campo de fecha).
// controles: los que pasan a obligatorios (documento, nombre y relación del acudiente).
// nota: elemento donde se avisa «Obligatorio: el deportista es menor de edad».
// Devuelve validar(): el primer control vacío que falta (o null) para revisarlo ANTES de enviar.
export function acudienteSegunEdad({ controlFecha, controles, nota }) {
    let esMenor = false;
    const actualizar = () => {
        const edad = edadCumplida(controlFecha.value);
        esMenor = edad !== null && edad < 18;
        for (const control of controles) marcarObligatorio(control, esMenor);
        if (nota) {
            nota.hidden = !esMenor;
            nota.textContent = esMenor ? `El deportista tiene ${edad} años: el acudiente es obligatorio (documento, nombre y relación).` : '';
        }
    };
    controlFecha.addEventListener('input', actualizar);
    controlFecha.addEventListener('change', actualizar);
    actualizar();
    return () => (esMenor ? controles.find((control) => !control.value.trim()) || null : null);
}
