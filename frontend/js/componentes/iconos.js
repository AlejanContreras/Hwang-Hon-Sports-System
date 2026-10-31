// Iconos del front (estilo outline, 24×24, trazo 2).
// Los trazos de Lucide (https://lucide.dev, paquete lucide-static 0.460.0) se copiaron una sola vez;
// no hay npm, CDN ni dependencias en ejecución.
//
// Lucide — Licencia ISC:
// Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of Feather (MIT).
// All other copyright (c) for Lucide are held by Lucide Contributors 2022.
// Permission to use, copy, modify, and/or distribute this software for any purpose with or without
// fee is hereby granted, provided that the above copyright notice and this permission notice appear
// in all copies. THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES.
//
// «cinturon» es propio (Lucide no tiene cinturón de artes marciales), dibujado con el mismo estilo.
// Los SVG se arman con createElementNS: nunca con innerHTML.

const ESPACIO_SVG = 'http://www.w3.org/2000/svg';

// nombre → lista de [etiqueta, atributos]
const FIGURAS = {
    'circle-user-round': [
        ['path', { d: 'M18 20a6 6 0 0 0-12 0' }],
        ['circle', { cx: 12, cy: 10, r: 4 }],
        ['circle', { cx: 12, cy: 12, r: 10 }],
    ],
    medal: [
        ['path', { d: 'M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15' }],
        ['path', { d: 'M11 12 5.12 2.2' }],
        ['path', { d: 'm13 12 5.88-9.8' }],
        ['path', { d: 'M8 7h8' }],
        ['circle', { cx: 12, cy: 17, r: 5 }],
        ['path', { d: 'M12 18v-2h-.5' }],
    ],
    'chart-no-axes-combined': [
        ['path', { d: 'M12 16v5' }],
        ['path', { d: 'M16 14v7' }],
        ['path', { d: 'M20 10v11' }],
        ['path', { d: 'm22 3-8.646 8.646a.5.5 0 0 1-.708 0L9.354 8.354a.5.5 0 0 0-.707 0L2 15' }],
        ['path', { d: 'M4 18v3' }],
        ['path', { d: 'M8 14v7' }],
    ],
    history: [
        ['path', { d: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8' }],
        ['path', { d: 'M3 3v5h5' }],
        ['path', { d: 'M12 7v5l4 2' }],
    ],
    files: [
        ['path', { d: 'M20 7h-3a2 2 0 0 1-2-2V2' }],
        ['path', { d: 'M9 18a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7l4 4v10a2 2 0 0 1-2 2Z' }],
        ['path', { d: 'M3 7.6v12.8A1.6 1.6 0 0 0 4.6 22h9.8' }],
    ],
    'calendar-check': [
        ['path', { d: 'M8 2v4' }],
        ['path', { d: 'M16 2v4' }],
        ['rect', { width: 18, height: 18, x: 3, y: 4, rx: 2 }],
        ['path', { d: 'M3 10h18' }],
        ['path', { d: 'm9 16 2 2 4-4' }],
    ],
    wallet: [
        ['path', { d: 'M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1' }],
        ['path', { d: 'M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4' }],
    ],
    'calendar-days': [
        ['path', { d: 'M8 2v4' }],
        ['path', { d: 'M16 2v4' }],
        ['rect', { width: 18, height: 18, x: 3, y: 4, rx: 2 }],
        ['path', { d: 'M3 10h18' }],
        ['path', { d: 'M8 14h.01' }],
        ['path', { d: 'M12 14h.01' }],
        ['path', { d: 'M16 14h.01' }],
        ['path', { d: 'M8 18h.01' }],
        ['path', { d: 'M12 18h.01' }],
        ['path', { d: 'M16 18h.01' }],
    ],
    'file-down': [
        ['path', { d: 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z' }],
        ['path', { d: 'M14 2v4a2 2 0 0 0 2 2h4' }],
        ['path', { d: 'M12 18v-6' }],
        ['path', { d: 'm9 15 3 3 3-3' }],
    ],
    'file-up': [
        ['path', { d: 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z' }],
        ['path', { d: 'M14 2v4a2 2 0 0 0 2 2h4' }],
        ['path', { d: 'M12 12v6' }],
        ['path', { d: 'm15 15-3-3-3 3' }],
    ],
    users: [
        ['path', { d: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' }],
        ['circle', { cx: 9, cy: 7, r: 4 }],
        ['path', { d: 'M22 21v-2a4 4 0 0 0-3-3.87' }],
        ['path', { d: 'M16 3.13a4 4 0 0 1 0 7.75' }],
    ],
    sun: [
        ['circle', { cx: 12, cy: 12, r: 4 }],
        ['path', { d: 'M12 2v2' }],
        ['path', { d: 'M12 20v2' }],
        ['path', { d: 'm4.93 4.93 1.41 1.41' }],
        ['path', { d: 'm17.66 17.66 1.41 1.41' }],
        ['path', { d: 'M2 12h2' }],
        ['path', { d: 'M20 12h2' }],
        ['path', { d: 'm6.34 17.66-1.41 1.41' }],
        ['path', { d: 'm19.07 4.93-1.41 1.41' }],
    ],
    moon: [
        ['path', { d: 'M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z' }],
    ],
    monitor: [
        ['rect', { width: 20, height: 14, x: 2, y: 3, rx: 2 }],
        ['line', { x1: 8, x2: 16, y1: 21, y2: 21 }],
        ['line', { x1: 12, x2: 12, y1: 17, y2: 21 }],
    ],
    shirt: [
        ['path', { d: 'M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z' }],
    ],
    'log-out': [
        ['path', { d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4' }],
        ['polyline', { points: '16 17 21 12 16 7' }],
        ['line', { x1: 21, x2: 9, y1: 12, y2: 12 }],
    ],
    // Propio: cinturón anudado visto de frente (banda, nudo y dos puntas colgando).
    cinturon: [
        ['path', { d: 'M2 8.5h7.5' }],
        ['path', { d: 'M14.5 8.5H22' }],
        ['path', { d: 'M2 12.5h7.5' }],
        ['path', { d: 'M14.5 12.5H22' }],
        ['rect', { width: 5, height: 6, x: 9.5, y: 7.5, rx: 1.5 }],
        ['path', { d: 'm10.5 13.5-2.5 8 2.6-.6' }],
        ['path', { d: 'm13.5 13.5 2.5 8-2.6-.6' }],
    ],
};

function nodoSvg(etiqueta, atributos) {
    const nodo = document.createElementNS(ESPACIO_SVG, etiqueta);
    for (const [nombre, valor] of Object.entries(atributos)) nodo.setAttribute(nombre, String(valor));
    return nodo;
}

// Icono decorativo: aria-hidden, sin foco, trazo del color del texto.
export function crear(nombre, { tamano = 20, grosor = 2, clase = 'icono' } = {}) {
    const figuras = FIGURAS[nombre] || FIGURAS.shirt;
    const svg = nodoSvg('svg', {
        xmlns: ESPACIO_SVG, width: tamano, height: tamano, viewBox: '0 0 24 24',
        fill: 'none', stroke: 'currentColor', 'stroke-width': grosor,
        'stroke-linecap': 'round', 'stroke-linejoin': 'round',
        'aria-hidden': 'true', focusable: 'false', class: clase,
    });
    for (const [etiqueta, atributos] of figuras) svg.append(nodoSvg(etiqueta, atributos));
    return svg;
}

// Cinturón grande con relleno del color del grado (tarjeta «Grado actual» del deportista).
// El trazo usa el color del texto para que el cinturón blanco o el negro se vean en los dos temas.
export function crearCinturonDeColor(color, { tamano = 72, titulo = '' } = {}) {
    const svg = nodoSvg('svg', {
        xmlns: ESPACIO_SVG, width: tamano * 1.6, height: tamano, viewBox: '0 0 40 25', class: 'cinturon-grado',
        ...(titulo ? { role: 'img' } : { 'aria-hidden': 'true' }),
    });
    if (titulo) {
        const t = nodoSvg('title', {});
        t.textContent = titulo;
        svg.append(t);
    }
    const trazo = { fill: color, stroke: 'currentColor', 'stroke-width': 1.2, 'stroke-linejoin': 'round' };
    svg.append(
        nodoSvg('rect', { x: 1, y: 6, width: 38, height: 6, rx: 1.5, ...trazo }),
        nodoSvg('path', { d: 'M17 12 13 23l4-1 3-10Z', ...trazo }),
        nodoSvg('path', { d: 'M23 12l4 11-4-1-3-10Z', ...trazo }),
        nodoSvg('rect', { x: 16, y: 4, width: 8, height: 10, rx: 2.5, ...trazo }),
    );
    return svg;
}

export const NOMBRES = Object.keys(FIGURAS);
