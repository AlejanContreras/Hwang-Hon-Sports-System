// Tema claro / oscuro / automático. Automático = sigue prefers-color-scheme (sin data-tema).
// La elección se guarda en localStorage (si el navegador lo permite). Para evitar el parpadeo,
// administrativo.html y publico.html aplican el valor guardado con un script corto en <head>
// antes de pintar; este módulo solo arma el selector y cambia el tema después.
import { crear } from '../ui.js';
import { crear as crearIcono } from './iconos.js';

const CLAVE = 'hwanghon_tema';
const OPCIONES = [
    { valor: 'claro', texto: 'Claro', icono: 'sun' },
    { valor: 'oscuro', texto: 'Oscuro', icono: 'moon' },
    { valor: 'automatico', texto: 'Automático', icono: 'monitor' },
];

export function leerTema() {
    try {
        const valor = localStorage.getItem(CLAVE);
        return valor === 'claro' || valor === 'oscuro' ? valor : 'automatico';
    } catch {
        return 'automatico';
    }
}

export function aplicarTema(valor) {
    if (valor === 'claro' || valor === 'oscuro') document.documentElement.setAttribute('data-tema', valor);
    else document.documentElement.removeAttribute('data-tema');
    try {
        if (valor === 'automatico') localStorage.removeItem(CLAVE);
        else localStorage.setItem(CLAVE, valor);
    } catch {
        // sin almacenamiento: el tema vale solo para esta página
    }
}

// Grupo de tres botones con aria-pressed (uno activo).
export function selectorTema() {
    const botones = OPCIONES.map((o) => crear('button', {
        clase: 'opcion-tema',
        atributos: { type: 'button', 'aria-pressed': 'false', 'data-tema-opcion': o.valor, 'data-prueba': `tema-${o.valor}`, title: o.texto },
    }, crearIcono(o.icono, { tamano: 16 }), crear('span', { texto: o.texto })));
    const marcar = (valor) => {
        for (const b of botones) b.setAttribute('aria-pressed', String(b.dataset.temaOpcion === valor));
    };
    for (const b of botones) {
        b.addEventListener('click', () => {
            aplicarTema(b.dataset.temaOpcion);
            marcar(b.dataset.temaOpcion);
        });
    }
    marcar(leerTema());
    return crear('div', { clase: 'selector-tema', atributos: { role: 'group', 'aria-label': 'Tema de colores', id: 'selector-tema' } }, ...botones);
}
