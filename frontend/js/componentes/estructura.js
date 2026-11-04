// Componente compartido de las páginas con sesión: «Saltar al contenido», panel lateral FIJO
// (marca enlazada a #inicio con espacio para el logo, botón de menú en celular, <nav> que llena
// navegacion.js y, al pie, el selector de tema) y el <main>. Sin cabecera superior (John, 30/10):
// el nombre de la cuenta y «Cerrar sesión» viven en «Cuentas → Mi cuenta» / «Mi perfil».
import { crear } from '../ui.js';
import { selectorTema } from './tema.js';

// Logo oficial (PNG transparente). Con null no se pide nada y solo se ve el texto.
const RUTA_LOGO = 'recursos/logo_hwanghon.png';

export function montarEstructura({ etiquetaMenu = 'Módulos' } = {}) {
    const botonMenu = crear('button', {
        clase: 'boton boton-secundario boton-pequeno boton-menu',
        texto: 'Menú',
        atributos: { type: 'button', id: 'boton-menu', 'aria-expanded': 'false', 'aria-controls': 'cuerpo-menu' },
    });
    const logo = RUTA_LOGO
        ? crear('img', { clase: 'logo-club', atributos: { src: RUTA_LOGO, alt: 'Club Deportivo de Hapkido Hwang Hon', width: 44, height: 44, 'data-prueba': 'logo-club' } })
        : crear('span', { clase: 'espacio-logo', atributos: { 'aria-hidden': 'true', 'data-prueba': 'espacio-logo' } });
    const marca = crear('a', {
        clase: 'marca-sistema',
        atributos: { href: '#inicio', id: 'marca-inicio', 'data-modulo': 'inicio', 'data-prueba': 'marca-inicio', 'aria-label': 'HwangHon, ir al inicio' },
    }, logo, crear('span', { texto: 'HwangHon' }));

    const panel = crear('aside', { clase: 'menu-lateral', atributos: { id: 'menu-lateral' } },
        crear('div', { clase: 'cabecera-menu' }, marca, botonMenu),
        crear('div', { clase: 'cuerpo-menu', atributos: { id: 'cuerpo-menu' } },
            crear('nav', { atributos: { id: 'lista-menu', 'aria-label': etiquetaMenu } }),
            crear('div', { clase: 'pie-menu' }, selectorTema())));

    const contenido = crear('main', { clase: 'contenido', atributos: { id: 'contenido', tabindex: '-1' } },
        crear('p', { clase: 'cargando', texto: 'Cargando…', atributos: { role: 'status' } }));

    document.body.replaceChildren(
        crear('a', { clase: 'enlace-saltar', texto: 'Saltar al contenido', atributos: { href: '#contenido' } }),
        crear('div', { clase: 'estructura' }, panel, crear('div', { clase: 'columna-principal' }, contenido)),
    );
    return { panel, contenido, botonMenu, marca };
}
