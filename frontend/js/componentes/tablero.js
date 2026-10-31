// Piezas comunes de los dos dashboards (administrativo y deportista): saludo con la hora de
// Bogotá, tarjetas de dato con icono, chip del color del cinturón y accesos rápidos con el icono
// grande y difuminado de fondo. Cada tarjeta carga y maneja su error por separado.
import { crear, vaciar, cargando } from '../ui.js';
import { crear as crearIcono } from './iconos.js';
import { colorDeGrado, nombreDeGrado } from './grados.js';

const formatoFechaHora = new Intl.DateTimeFormat('es-CO', {
    timeZone: 'America/Bogota',
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
});

// Saludo con la fecha y hora de Bogotá (se actualiza cada 30 s mientras la vista esté abierta).
export function saludo(nombre) {
    const reloj = crear('p', { clase: 'reloj', texto: formatoFechaHora.format(new Date()) });
    const temporizador = setInterval(() => {
        if (!reloj.isConnected) {
            clearInterval(temporizador);
            return;
        }
        reloj.textContent = formatoFechaHora.format(new Date());
    }, 30000);
    return crear('div', { clase: 'encabezado-vista encabezado-inicio' },
        crear('h1', { texto: `Hola, ${nombre ?? ''}`, atributos: { id: 'saludo-inicio' } }),
        reloj);
}

// Tarjeta con icono y contenido cargado. cargar() devuelve { nodos, enlace? }: con enlace, toda
// la tarjeta es un enlace (accesible por su título).
export function tarjetaDato({ id, titulo, icono, cargar }) {
    const cuerpo = crear('div', { clase: 'cuerpo-tarjeta' });
    const tituloNodo = crear('h2', { atributos: { id: `titulo-${id}` } }, crearIcono(icono, { tamano: 20 }), crear('span', { texto: titulo }));
    const nodo = crear('section', { clase: 'tarjeta tarjeta-dato', atributos: { 'aria-labelledby': `titulo-${id}`, id, 'data-prueba': id } }, tituloNodo, cuerpo);
    cargando(cuerpo);
    cargar()
        .then(({ nodos, enlace }) => {
            vaciar(cuerpo);
            cuerpo.append(...nodos.filter(Boolean));
            if (enlace) {
                const a = crear('a', { clase: 'enlace-tarjeta', atributos: { href: enlace, 'aria-labelledby': `titulo-${id}` } });
                nodo.classList.add('tarjeta-enlazada');
                nodo.append(a);
            }
        })
        .catch((error) => {
            vaciar(cuerpo);
            cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    return nodo;
}

// Chip con el color del cinturón y el nombre del grado.
export function chipGrado(grado) {
    const { color } = colorDeGrado(grado);
    const muestra = crear('span', { clase: 'muestra-grado', atributos: { 'aria-hidden': 'true' } });
    muestra.style.setProperty('--color-grado', color);
    return crear('span', { clase: 'chip-grado' }, muestra, nombreDeGrado(grado));
}

// Accesos rápidos: nombre grande y fuerte, y el mismo icono grande y difuminado de fondo.
export function accesosRapidos(modulos, idLista = 'accesos-rapidos') {
    return crear('nav', { clase: 'accesos-rapidos', atributos: { 'aria-labelledby': `titulo-${idLista}`, id: idLista } },
        crear('h2', { clase: 'solo-lectores', texto: 'Accesos rápidos', atributos: { id: `titulo-${idLista}` } }),
        crear('ul', {}, ...modulos.map((m) => crear('li', {},
            crear('a', { clase: 'acceso', atributos: { href: m.ruta, 'data-prueba': `acceso-${m.clave}` } },
                crearIcono(m.icono, { tamano: 72, grosor: 1.5, clase: 'icono-fondo' }),
                crear('span', { clase: 'texto-acceso', texto: m.texto }))))));
}
