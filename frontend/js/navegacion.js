// Estructura común de las páginas con sesión (administrativa y pública): menú lateral por
// grupos con iconos, menú plegable en celular, «Saltar al contenido» y enrutado por «#ruta/parámetro».
// No hay botón «Inicio»: la marca «HwangHon» del panel enlaza a #inicio.
import { exigirSesion } from './sesion.js';
import { crear, vaciar } from './ui.js';
import { montarEstructura } from './componentes/estructura.js';
import { crear as crearIcono } from './componentes/iconos.js';

export function construirMenu(nav, grupos) {
    grupos.forEach((grupo, indice) => {
        const idTitulo = `grupo-menu-${indice}`;
        nav.append(
            crear('h2', { clase: 'titulo-grupo-menu', texto: grupo.titulo, atributos: { id: idTitulo } }),
            crear('ul', { clase: 'grupo-menu', atributos: { 'aria-labelledby': idTitulo } },
                ...grupo.modulos.map((modulo) => crear('li', {}, enlaceModulo(modulo)))),
        );
    });
}

// Icono decorativo primero y luego el nombre (siempre visible). Sin ruta: deshabilitado.
export function enlaceModulo(modulo, clase = '') {
    const icono = modulo.icono ? crearIcono(modulo.icono, { tamano: 20 }) : null;
    if (modulo.ruta) {
        return crear('a', {
            clase: `${clase} enlace-modulo`.trim(),
            atributos: { href: modulo.ruta, 'data-modulo': modulo.clave, 'data-prueba': `menu-${modulo.clave}` },
        }, icono, crear('span', { texto: modulo.texto }));
    }
    return crear('a', { clase: `${clase} enlace-modulo deshabilitado`.trim(), atributos: { role: 'link', 'aria-disabled': 'true' } },
        icono, crear('span', {}, modulo.texto, crear('span', { clase: 'proximamente', texto: 'Próximamente' })));
}

// aria-current en el enlace del módulo activo (incluida la marca, que representa el inicio).
function marcarMenu(panel, modulo) {
    for (const enlace of panel.querySelectorAll('a[data-modulo]')) {
        if (enlace.dataset.modulo === modulo) enlace.setAttribute('aria-current', 'page');
        else enlace.removeAttribute('aria-current');
    }
}

function leerRuta() {
    const hash = window.location.hash.replace(/^#/, '');
    const separador = hash.indexOf('/');
    const nombre = separador === -1 ? hash : hash.slice(0, separador);
    let parametro = separador === -1 ? null : hash.slice(separador + 1);
    if (parametro !== null) {
        try {
            parametro = decodeURIComponent(parametro);
        } catch {
            parametro = null;
        }
    }
    return { nombre, parametro };
}

// rutas: { nombre: { modulo, mostrar(contenedor, parametro, sesion) } }
// grupos: lista o función de la sesión. La estructura la monta el componente compartido.
export async function iniciarPagina({ ambito, rutas, rutaInicial, grupos, alIniciar = null, etiquetaMenu }) {
    const { panel: menuLateral, contenido, botonMenu } = montarEstructura({ etiquetaMenu });
    const nav = document.getElementById('lista-menu');

    const cerrarMenuMovil = () => {
        menuLateral.classList.remove('abierto');
        botonMenu.setAttribute('aria-expanded', 'false');
    };
    botonMenu.addEventListener('click', () => {
        const abierto = menuLateral.classList.toggle('abierto');
        botonMenu.setAttribute('aria-expanded', String(abierto));
    });
    // «Saltar al contenido» solo mueve el foco: cambiar el hash a #contenido haría salir de la vista actual.
    document.querySelector('.enlace-saltar').addEventListener('click', (evento) => {
        evento.preventDefault();
        contenido.focus();
    });

    const sesion = await exigirSesion(ambito);
    if (!sesion) return;
    construirMenu(nav, typeof grupos === 'function' ? grupos(sesion) : grupos);
    if (alIniciar) await alIniciar(sesion);

    const mostrarRuta = async () => {
        const { nombre, parametro } = leerRuta();
        const ruta = rutas[nombre];
        if (!ruta) {
            window.location.replace(`#${rutaInicial}`);
            return;
        }
        marcarMenu(menuLateral, ruta.modulo);
        cerrarMenuMovil();
        vaciar(contenido);
        try {
            await ruta.mostrar(contenido, parametro, sesion);
        } catch (error) {
            vaciar(contenido);
            contenido.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        }
        // Si la vista ya puso el foco en un campo (p. ej. el formulario en «Nombre»), se respeta.
        if (!contenido.contains(document.activeElement) || document.activeElement === contenido) {
            contenido.focus({ preventScroll: true });
        }
        window.scrollTo(0, 0);
    };

    window.addEventListener('hashchange', mostrarRuta);
    if (!window.location.hash) window.location.replace(`#${rutaInicial}`);
    else mostrarRuta();
}
