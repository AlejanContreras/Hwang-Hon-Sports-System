// Gráficas SVG propias (sin librerías). Cada gráfica lleva <title> y <desc> (role="img")
// y, debajo, la tabla equivalente en un <details> para quien no ve el dibujo.
// Los textos se ponen con textContent: nunca se arma SVG con cadenas.
import { crear } from '../ui.js';

const ESPACIO_SVG = 'http://www.w3.org/2000/svg';
let contador = 0;

function svg(etiqueta, atributos = {}, ...hijos) {
    const nodo = document.createElementNS(ESPACIO_SVG, etiqueta);
    for (const [nombre, valor] of Object.entries(atributos)) nodo.setAttribute(nombre, String(valor));
    for (const hijo of hijos) if (hijo) nodo.append(hijo);
    return nodo;
}

function texto(contenido, atributos) {
    const nodo = svg('text', atributos);
    nodo.textContent = contenido;
    return nodo;
}

const formatear = (valor) => Number(valor).toLocaleString('es-CO', { maximumFractionDigits: 2 });

// Tabla equivalente: encabezados + filas de textos.
function tablaEquivalente(titulo, encabezados, filas) {
    return crear('details', { clase: 'tabla-grafico' },
        crear('summary', { texto: 'Ver datos en tabla' }),
        crear('div', { clase: 'contenedor-tabla' },
            crear('table', {},
                crear('caption', { clase: 'solo-lectores', texto: titulo }),
                crear('thead', {}, crear('tr', {}, ...encabezados.map((e) => crear('th', { texto: e, atributos: { scope: 'col' } })))),
                crear('tbody', {}, ...filas.map((f) => crear('tr', {}, ...f.map((c) => crear('td', { texto: c }))))))));
}

function marco(titulo, descripcion, ancho, alto) {
    contador += 1;
    const idTitulo = `grafico-titulo-${contador}`;
    const idDesc = `grafico-desc-${contador}`;
    const titulo_ = svg('title', { id: idTitulo });
    titulo_.textContent = titulo;
    const desc = svg('desc', { id: idDesc });
    desc.textContent = descripcion;
    return svg('svg', {
        viewBox: `0 0 ${ancho} ${alto}`, role: 'img', 'aria-labelledby': `${idTitulo} ${idDesc}`,
        class: 'grafico', preserveAspectRatio: 'xMidYMid meet',
    }, titulo_, desc);
}

// Barras verticales. datos: [{ etiqueta, valor, clase? }] (clase: color, p. ej. 'oro').
export function graficoBarras({ titulo, datos, unidad = '', maximo = null }) {
    const ancho = 480;
    const alto = 220;
    const margen = { arriba: 16, abajo: 40, izquierda: 36, derecha: 8 };
    const altoUtil = alto - margen.arriba - margen.abajo;
    const tope = maximo ?? Math.max(1, ...datos.map((d) => Number(d.valor) || 0));
    const descripcion = datos.map((d) => `${d.etiqueta}: ${formatear(d.valor)}${unidad}`).join('; ');
    const dibujo = marco(titulo, descripcion || 'Sin datos', ancho, alto);

    // Ejes y guías (0, mitad y tope).
    for (const fraccion of [0, 0.5, 1]) {
        const y = margen.arriba + altoUtil * (1 - fraccion);
        dibujo.append(
            svg('line', { x1: margen.izquierda, x2: ancho - margen.derecha, y1: y, y2: y, class: 'guia' }),
            texto(formatear(tope * fraccion), { x: margen.izquierda - 6, y: y + 4, 'text-anchor': 'end', class: 'eje' }));
    }
    const paso = (ancho - margen.izquierda - margen.derecha) / Math.max(1, datos.length);
    const anchoBarra = Math.min(48, paso * 0.6);
    datos.forEach((d, i) => {
        const valor = Number(d.valor) || 0;
        const altoBarra = altoUtil * (valor / tope);
        const x = margen.izquierda + paso * i + (paso - anchoBarra) / 2;
        const y = margen.arriba + altoUtil - altoBarra;
        dibujo.append(
            svg('rect', { x, y, width: anchoBarra, height: Math.max(altoBarra, valor ? 1 : 0), rx: 3, class: `barra ${d.clase || ''}`.trim() }),
            texto(`${formatear(valor)}${unidad}`, { x: x + anchoBarra / 2, y: y - 4, 'text-anchor': 'middle', class: 'valor' }),
            texto(d.etiqueta, { x: x + anchoBarra / 2, y: alto - margen.abajo + 16, 'text-anchor': 'middle', class: 'eje' }));
    });
    return crear('figure', { clase: 'figura-grafico' }, dibujo,
        tablaEquivalente(titulo, ['Dato', 'Valor'], datos.map((d) => [d.etiqueta, `${formatear(d.valor)}${unidad}`])));
}

// Línea con puntos. puntos: [{ etiqueta, valor }] en orden. invertido: el 1 arriba (puestos).
export function graficoLinea({ titulo, puntos, unidad = '', invertido = false, minimo = null, maximo = null, encabezados = ['Fecha', 'Valor'] }) {
    const ancho = 480;
    const alto = 220;
    const margen = { arriba: 16, abajo: 40, izquierda: 36, derecha: 16 };
    const valores = puntos.map((p) => Number(p.valor));
    // Sin mínimo indicado el eje parte de 0 (así un solo punto no queda pegado al borde).
    const bajo = minimo ?? Math.min(0, ...valores);
    const alto_ = maximo ?? Math.max(...valores);
    const rango = alto_ - bajo || 1;
    const descripcion = puntos.map((p) => `${p.etiqueta}: ${formatear(p.valor)}${unidad}`).join('; ');
    const dibujo = marco(titulo, descripcion || 'Sin datos', ancho, alto);
    const altoUtil = alto - margen.arriba - margen.abajo;
    const anchoUtil = ancho - margen.izquierda - margen.derecha;
    const yDe = (v) => {
        const fraccion = (v - bajo) / rango;
        return margen.arriba + altoUtil * (invertido ? fraccion : 1 - fraccion);
    };
    const xDe = (i) => margen.izquierda + (puntos.length === 1 ? anchoUtil / 2 : (anchoUtil * i) / (puntos.length - 1));

    for (const v of [bajo, alto_]) {
        dibujo.append(
            svg('line', { x1: margen.izquierda, x2: ancho - margen.derecha, y1: yDe(v), y2: yDe(v), class: 'guia' }),
            texto(formatear(v), { x: margen.izquierda - 6, y: yDe(v) + 4, 'text-anchor': 'end', class: 'eje' }));
    }
    if (puntos.length > 1) {
        dibujo.append(svg('polyline', { points: puntos.map((p, i) => `${xDe(i)},${yDe(Number(p.valor))}`).join(' '), class: 'linea' }));
    }
    puntos.forEach((p, i) => {
        dibujo.append(
            svg('circle', { cx: xDe(i), cy: yDe(Number(p.valor)), r: 4, class: 'punto' }),
            texto(`${formatear(p.valor)}${unidad}`, { x: xDe(i), y: yDe(Number(p.valor)) - 8, 'text-anchor': 'middle', class: 'valor' }),
            texto(p.etiqueta, { x: xDe(i), y: alto - margen.abajo + 16, 'text-anchor': 'middle', class: 'eje' }));
    });
    return crear('figure', { clase: 'figura-grafico' }, dibujo,
        tablaEquivalente(titulo, encabezados, puntos.map((p) => [p.etiqueta, `${formatear(p.valor)}${unidad}`])));
}
