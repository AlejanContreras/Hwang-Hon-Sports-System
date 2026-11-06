// Fecha en tres listas (Día, Mes por nombre, Año) para el ingreso público (D3, opción A + C).
// Así el orden es siempre DD/MM/AAAA, sin depender del idioma del navegador.
// El valor real sigue en el <input type="date" id="fecha-nacimiento" name="fecha_nacimiento"> oculto
// (AAAA-MM-DD, lo que espera la API). Si alguien lo llena directamente (p. ej. Selenium con
// `value` + evento input/change), las listas se sincronizan solas.
import { crear, hoyBogota, MESES } from '../ui.js';

const ANIOS_ATRAS = 100;

function opciones(lista, texto) {
    return [crear('option', { texto, atributos: { value: '' } }), ...lista.map(([valor, etiqueta]) => crear('option', { texto: etiqueta, atributos: { value: valor } }))];
}

const dos = (n) => String(n).padStart(2, '0');

function fechaExiste(anio, mes, dia) {
    const f = new Date(Date.UTC(anio, mes - 1, dia));
    return f.getUTCFullYear() === anio && f.getUTCMonth() === mes - 1 && f.getUTCDate() === dia;
}

// dia, mes, anio: <select> vacíos; oculto: el <input type="date">; leida: donde se muestra la fecha leída.
// Devuelve validar(): null si la fecha es válida, o { mensaje, control } para avisar y dar el foco.
export function prepararFechaEnListas({ dia, mes, anio, oculto, leida }) {
    const anioActual = Number(hoyBogota().slice(0, 4));
    dia.append(...opciones(Array.from({ length: 31 }, (_, i) => [dos(i + 1), String(i + 1)]), 'Día'));
    mes.append(...opciones(MESES.map((m, i) => [dos(i + 1), m.charAt(0).toUpperCase() + m.slice(1)]), 'Mes'));
    anio.append(...opciones(Array.from({ length: ANIOS_ATRAS + 1 }, (_, i) => [String(anioActual - i), String(anioActual - i)]), 'Año'));

    const revisar = () => {
        const [d, m, a] = [Number(dia.value), Number(mes.value), Number(anio.value)];
        if (!d || !m || !a) return { mensaje: 'Elige el día, el mes y el año de nacimiento del deportista.', control: [dia, mes, anio].find((c) => !c.value) };
        if (!fechaExiste(a, m, d)) return { mensaje: `El ${d} de ${MESES[m - 1]} de ${a} no existe. Revisa el día.`, control: dia };
        const iso = `${a}-${dos(m)}-${dos(d)}`;
        if (iso > hoyBogota()) return { mensaje: 'La fecha de nacimiento no puede ser posterior a hoy.', control: anio };
        return { iso, d, m, a };
    };

    // C: debajo se lee la fecha elegida en palabras (o por qué no es válida).
    const pintar = () => {
        const r = revisar();
        oculto.value = r.iso || '';
        leida.classList.toggle('fecha-leida-error', Boolean(r.mensaje) && Boolean(dia.value && mes.value && anio.value));
        if (r.iso) leida.textContent = `Fecha elegida: ${r.d} de ${MESES[r.m - 1]} de ${r.a}`;
        else leida.textContent = dia.value && mes.value && anio.value ? r.mensaje : '';
    };
    for (const lista of [dia, mes, anio]) lista.addEventListener('change', pintar);

    // Si se llena el campo oculto directamente, las listas lo reflejan.
    const desdeOculto = () => {
        const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(oculto.value);
        if (!partes) return;
        [anio.value, mes.value, dia.value] = [partes[1], partes[2], partes[3]];
        pintar();
    };
    oculto.addEventListener('input', desdeOculto);
    oculto.addEventListener('change', desdeOculto);

    return () => {
        const r = revisar();
        return r.mensaje ? r : null;
    };
}
