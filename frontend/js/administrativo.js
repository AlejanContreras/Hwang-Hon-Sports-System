// Página del ámbito administrativo: menú lateral y enrutado por «#ruta/parámetro».
import { exigirSesion, cerrarSesion } from './sesion.js';
import { crear, vaciar } from './ui.js';
import * as vistaDeportistas from './vistas/deportistas.js';

const contenido = document.getElementById('contenido');
const menuLateral = document.getElementById('menu-lateral');
const botonMenu = document.getElementById('boton-menu');

// ruta → { modulo (para marcar el menú), mostrar(contenedor, parametro) }
const rutas = {
    deportistas: { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarLista(c, 'todos') },
    pendientes: { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarLista(c, 'pendientes') },
    deportista: { modulo: 'deportistas', mostrar: (c, documento) => vistaDeportistas.mostrarFicha(c, documento) },
    'deportista-nuevo': { modulo: 'deportistas', mostrar: (c) => vistaDeportistas.mostrarFormulario(c, null) },
    'deportista-editar': { modulo: 'deportistas', mostrar: (c, documento) => vistaDeportistas.mostrarFormulario(c, documento) },
};

const RUTA_INICIAL = 'deportistas';

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

function marcarMenu(modulo) {
    for (const enlace of document.querySelectorAll('#lista-menu a')) {
        if (enlace.dataset.modulo === modulo) enlace.setAttribute('aria-current', 'page');
        else enlace.removeAttribute('aria-current');
    }
}

async function mostrarRuta() {
    const { nombre, parametro } = leerRuta();
    const ruta = rutas[nombre];
    if (!ruta) {
        window.location.replace(`#${RUTA_INICIAL}`);
        return;
    }
    marcarMenu(ruta.modulo);
    cerrarMenuMovil();
    vaciar(contenido);
    try {
        await ruta.mostrar(contenido, parametro);
    } catch (error) {
        vaciar(contenido);
        contenido.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
    contenido.focus({ preventScroll: true });
    window.scrollTo(0, 0);
}

function cerrarMenuMovil() {
    menuLateral.classList.remove('abierto');
    botonMenu.setAttribute('aria-expanded', 'false');
}

botonMenu.addEventListener('click', () => {
    const abierto = menuLateral.classList.toggle('abierto');
    botonMenu.setAttribute('aria-expanded', String(abierto));
});

document.getElementById('boton-salir').addEventListener('click', (evento) => {
    evento.currentTarget.disabled = true;
    cerrarSesion();
});

async function iniciar() {
    const sesion = await exigirSesion('administrativa');
    if (!sesion) return;
    document.getElementById('nombre-usuario').textContent = sesion.nombre;
    window.addEventListener('hashchange', mostrarRuta);
    if (!window.location.hash) window.location.replace(`#${RUTA_INICIAL}`);
    else mostrarRuta();
}

iniciar();
