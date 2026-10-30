// Estructura común de las páginas con sesión (administrativa y pública): menú lateral por
// grupos, menú plegable en celular, «Saltar al contenido», salir y enrutado por «#ruta/parámetro».
import { exigirSesion, cerrarSesion } from './sesion.js';
import { crear, vaciar } from './ui.js';
import { montarEstructura } from './componentes/estructura.js';

// inicio: { texto, ruta, clave } opcional, va arriba y fuera de los grupos.
export function construirMenu(nav, grupos, inicio = null) {
    if (inicio) {
        nav.append(crear('ul', { clase: 'grupo-menu' },
            crear('li', {}, crear('a', { texto: inicio.texto, atributos: { href: inicio.ruta, 'data-modulo': inicio.clave } }))));
    }
    grupos.forEach((grupo, indice) => {
        const idTitulo = `grupo-menu-${indice}`;
        nav.append(
            crear('h2', { clase: 'titulo-grupo-menu', texto: grupo.titulo, atributos: { id: idTitulo } }),
            crear('ul', { clase: 'grupo-menu', atributos: { 'aria-labelledby': idTitulo } },
                ...grupo.modulos.map((modulo) => crear('li', {}, enlaceModulo(modulo)))),
        );
    });
}

// Un módulo sin ruta se muestra deshabilitado con «Próximamente» (sin href: no recibe foco).
export function enlaceModulo(modulo, clase = '') {
    if (modulo.ruta) {
        return crear('a', { clase, texto: modulo.texto, atributos: { href: modulo.ruta, 'data-modulo': modulo.clave } });
    }
    return crear('a', { clase: `${clase} deshabilitado`.trim(), atributos: { role: 'link', 'aria-disabled': 'true' } },
        modulo.texto, crear('span', { clase: 'proximamente', texto: 'Próximamente' }));
}

function marcarMenu(nav, modulo) {
    for (const enlace of nav.querySelectorAll('a[data-modulo]')) {
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
// La estructura (panel, cabecera y <main>) la monta el componente compartido.
export async function iniciarPagina({ ambito, rutas, rutaInicial, grupos, inicio = null, alIniciar = null, etiquetaMenu, textoSalir }) {
    const { panel: menuLateral, contenido, botonMenu } = montarEstructura({ etiquetaMenu, textoSalir });
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
    document.getElementById('boton-salir').addEventListener('click', (evento) => {
        evento.currentTarget.disabled = true;
        cerrarSesion();
    });

    const sesion = await exigirSesion(ambito);
    if (!sesion) return;
    document.getElementById('nombre-usuario').textContent = sesion.nombre;
    // grupos puede ser una lista o una función de la sesión (p. ej. para ocultar módulos por permiso).
    construirMenu(nav, typeof grupos === 'function' ? grupos(sesion) : grupos, inicio);
    if (alIniciar) await alIniciar(sesion);

    const mostrarRuta = async () => {
        const { nombre, parametro } = leerRuta();
        const ruta = rutas[nombre];
        if (!ruta) {
            window.location.replace(`#${rutaInicial}`);
            return;
        }
        marcarMenu(nav, ruta.modulo);
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
