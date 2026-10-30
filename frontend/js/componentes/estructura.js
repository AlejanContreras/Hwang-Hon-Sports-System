// Componente compartido de las páginas con sesión: enlace «Saltar al contenido», panel lateral
// (marca + botón de menú en celular + <nav> que llena navegacion.js), cabecera con el usuario
// y «Salir», y el <main>. Se monta una vez por página; así el marcado no se repite en cada HTML.
// El boceto de John no tiene pie de página: no se agrega.
import { crear } from '../ui.js';

// etiquetaMenu: aria-label del <nav>. textoSalir: texto del botón de salida.
export function montarEstructura({ etiquetaMenu = 'Módulos', textoSalir = 'Cerrar sesión' } = {}) {
    const botonMenu = crear('button', {
        clase: 'boton boton-secundario boton-pequeno boton-menu',
        texto: 'Menú',
        atributos: { type: 'button', id: 'boton-menu', 'aria-expanded': 'false', 'aria-controls': 'cuerpo-menu' },
    });
    const panel = crear('aside', { clase: 'menu-lateral', atributos: { id: 'menu-lateral' } },
        crear('div', { clase: 'cabecera-menu' }, crear('p', { clase: 'titulo-sistema', texto: 'HwangHon' }), botonMenu),
        crear('div', { clase: 'cuerpo-menu', atributos: { id: 'cuerpo-menu' } },
            crear('nav', { atributos: { id: 'lista-menu', 'aria-label': etiquetaMenu } })));

    const cabecera = crear('header', { clase: 'cabecera-perfil' },
        crear('div', {},
            crear('p', { clase: 'nombre-perfil', atributos: { id: 'nombre-usuario' } }),
            crear('p', { clase: 'datos-perfil', atributos: { id: 'datos-perfil' } })),
        crear('button', { clase: 'boton boton-secundario', texto: textoSalir, atributos: { type: 'button', id: 'boton-salir' } }));

    const contenido = crear('main', { clase: 'contenido', atributos: { id: 'contenido', tabindex: '-1' } },
        crear('p', { clase: 'cargando', texto: 'Cargando…', atributos: { role: 'status' } }));

    document.body.replaceChildren(
        crear('a', { clase: 'enlace-saltar', texto: 'Saltar al contenido', atributos: { href: '#contenido' } }),
        crear('div', { clase: 'estructura' }, panel, crear('div', { clase: 'columna-principal' }, cabecera, contenido)),
    );
    return { panel, cabecera, contenido, botonMenu };
}
