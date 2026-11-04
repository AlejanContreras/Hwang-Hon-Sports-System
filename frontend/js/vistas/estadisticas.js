// Estadísticas e historial — catálogo B.12 (pestaña «Registros») y B.13 (pestaña «Gráficas»).
// Una sola pantalla para los dos ámbitos: el administrativo elige deportista (o ve todos en
// Registros) y tiene el resumen por torneo; el público ve solo lo suyo.
import { historial, estadisticas, competencias, deportistas } from '../api.js';
import { crear, vaciar, campo, cargando, textoFecha, textoDato, textoGrado, insignia, hoyBogota, listaDatos, textoConFechas, formatearTextoConFechas } from '../ui.js';
import { selectorDeportista } from './comunes.js';
import { graficoBarras, graficoLinea, graficoSeries } from '../componentes/graficos.js';
import { chipGrado } from '../componentes/tablero.js';
import { bloqueRecord } from '../componentes/record.js';

const MODALIDADES_TORNEO = {
    combate_individual: 'Combate individual', combate_equipos: 'Combate por equipos', defensa_personal: 'Defensa personal',
    salto_alto: 'Salto alto', salto_largo: 'Salto largo', formas: 'Formas',
};
const MODALIDADES_EXAMEN = { teorico: 'Teórico', fisico: 'Físico', combate: 'Combate', rompimiento: 'Rompimiento', formas: 'Formas', llaves: 'Llaves' };
const TIPOS_TORNEO = { nacional: 'Nacional', internacional: 'Internacional', departamental: 'Departamental' };
const MEDALLAS = { oro: 'Oro', plata: 'Plata', bronce: 'Bronce', ninguna: 'Ninguna' };

const fechaCorta = (fecha) => textoFecha(fecha);
const etiquetaProvisional = () => insignia('PROVISIONAL', 'pendiente');

function tabla(titulo, encabezados, filas) {
    return crear('div', { clase: 'contenedor-tabla' },
        crear('table', {},
            crear('caption', { clase: 'solo-lectores', texto: titulo }),
            crear('thead', {}, crear('tr', {}, ...encabezados.map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            crear('tbody', {}, ...filas)));
}

function seccion(titulo, ...hijos) {
    const id = `sec-${titulo.toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '-')}`;
    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': id } }, crear('h2', { texto: titulo, atributos: { id } }), ...hijos);
}

function errorEn(zona, error) {
    vaciar(zona);
    zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
}

// Estado de la pantalla (administrativo): deportista elegido, el que vino desde su ficha y los dos
// deportistas de «Comparar». Se conservan al cambiar de pestaña.
let documentoElegido = null;
let documentoDeFicha = null;
const comparados = [null, null];
// «← Volver a la ficha» solo mientras se siga en Estadísticas/Historial: al salir a otro módulo se olvida.
window.addEventListener('hashchange', () => {
    if (!/^#(estadisticas|historial)/.test(window.location.hash)) documentoDeFicha = null;
});

// Cada pestaña es una ruta, así el panel lateral resalta el módulo correcto (A3):
// Gráficas → #estadisticas (Estadísticas) · Registros → #historial (Historial) ·
// Comparar → #estadisticas-comparar o #historial-comparar (según el módulo desde el que se abrió).
function rutaDePestana(pestana, modulo) {
    if (pestana === 'graficas') return '#estadisticas';
    if (pestana === 'registros') return '#historial';
    return `#${modulo}-comparar`;
}

// pestanaInicial: 'registros', 'graficas' o 'comparar'. modulo: 'estadisticas' o 'historial'.
// documento: viene en la ruta solo cuando se llega desde la ficha (#estadisticas/<doc>).
export async function mostrarEstadisticas(contenedor, { esAdministrativo, pestanaInicial = 'graficas', documento = null, modulo = null }) {
    const moduloActual = modulo || (pestanaInicial === 'registros' ? 'historial' : 'estadisticas');
    if (documento) {
        documentoElegido = documento;
        documentoDeFicha = documento;
    }
    const nombres = { registros: 'Registros', graficas: 'Gráficas', comparar: 'Comparar' };
    const claves = esAdministrativo ? ['registros', 'graficas', 'comparar'] : ['registros', 'graficas'];
    const pestanas = Object.fromEntries(claves.map((clave) => [clave, crear('button', {
        clase: 'pestana', texto: nombres[clave],
        atributos: {
            type: 'button', role: 'tab', id: `pestana-${clave}`, 'aria-controls': `panel-${clave}`, 'data-prueba': `pestana-${clave}`,
            'aria-selected': String(clave === pestanaInicial), tabindex: clave === pestanaInicial ? '0' : '-1',
        },
    })]));
    const ir = (clave) => {
        const destino = rutaDePestana(clave, moduloActual);
        if (window.location.hash !== destino) window.location.assign(destino);
    };
    for (const [clave, boton] of Object.entries(pestanas)) {
        boton.addEventListener('click', () => ir(clave));
        boton.addEventListener('keydown', (evento) => {
            if (evento.key !== 'ArrowRight' && evento.key !== 'ArrowLeft') return;
            evento.preventDefault();
            const i = claves.indexOf(pestanaInicial);
            const siguiente = claves[(i + (evento.key === 'ArrowRight' ? 1 : claves.length - 1)) % claves.length];
            ir(siguiente);
        });
    }
    const panel = crear('div', { atributos: { role: 'tabpanel', id: `panel-${pestanaInicial}`, 'aria-labelledby': `pestana-${pestanaInicial}` } });

    const elementos = [];
    if (esAdministrativo && documentoDeFicha && documentoDeFicha === documentoElegido && pestanaInicial !== 'comparar') {
        elementos.push(crear('p', { clase: 'miga' }, crear('a', {
            texto: '← Volver a la ficha', atributos: { href: `#deportista/${encodeURIComponent(documentoDeFicha)}`, 'data-prueba': 'volver-ficha' },
        })));
    }
    elementos.push(crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: esAdministrativo ? 'Estadísticas e historial' : 'Mis estadísticas e historial' })));
    if (esAdministrativo && pestanaInicial !== 'comparar') elementos.push(seccionElegirDeportista(() => recargar()));
    elementos.push(crear('div', { clase: 'pestanas pestanas-vista', atributos: { role: 'tablist', 'aria-label': 'Vista' } }, ...Object.values(pestanas)), panel);
    contenedor.append(...elementos);

    const recargar = () => {
        if (pestanaInicial === 'registros') pintarRegistros(panel, esAdministrativo);
        else if (pestanaInicial === 'graficas') pintarGraficas(panel, esAdministrativo);
        else pintarComparar(panel);
    };
    recargar();
}

// Selector de deportista con el NOMBRE de la persona consultada (A2).
function seccionElegirDeportista(alCambiar) {
    const elegido = crear('p', { clase: 'deportista-consultado', atributos: { 'aria-live': 'polite', id: 'deportista-consultado' } });
    const pintarElegido = async () => {
        if (!documentoElegido) {
            elegido.textContent = 'Sin deportista elegido: «Registros» muestra a todos; «Gráficas» pide un deportista.';
            return;
        }
        elegido.textContent = `Consultando: ${documentoElegido}`;
        try {
            const d = await deportistas.consultar(documentoElegido);
            vaciar(elegido);
            elegido.append('Consultando: ', crear('strong', { texto: d.nombre }), ` · ${d.documento}`);
        } catch (error) {
            elegido.textContent = error.message;
        }
    };
    pintarElegido();
    const quitar = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Ver todos', atributos: { type: 'button' } });
    quitar.addEventListener('click', () => {
        documentoElegido = null;
        pintarElegido();
        alCambiar();
    });
    return crear('section', { clase: 'tarjeta' },
        selectorDeportista({ textoBoton: 'Ver deportista', alElegir: (doc) => { documentoElegido = doc; pintarElegido(); alCambiar(); } }),
        crear('div', { clase: 'acciones' }, elegido, quitar));
}

// ===========================================================================
// Pestaña «Comparar» (solo administrativo, A1): dos deportistas lado a lado.
// Una llamada por deportista a estadisticas.deportista, historial.torneos e historial.ascensos.
// ===========================================================================
function pintarComparar(panel) {
    vaciar(panel);
    const columnas = [crear('div', { clase: 'columna-comparar', atributos: { 'data-prueba': 'comparar-a' } }),
        crear('div', { clase: 'columna-comparar', atributos: { 'data-prueba': 'comparar-b' } })];
    const zonaPosiciones = crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-comparar-posiciones' } },
        crear('h2', { texto: 'Posiciones en torneos', atributos: { id: 'titulo-comparar-posiciones' } }));
    const datos = [null, null];

    const pintarPosiciones = () => {
        while (zonaPosiciones.children.length > 1) zonaPosiciones.lastChild.remove();
        const series = datos.map((d, i) => d && {
            nombre: d.perfil.nombre,
            puntos: d.posiciones.map((p) => ({ clave: `${p.fecha}|${p.torneo}|${p.modalidad}`, etiqueta: `${fechaCorta(p.fecha).slice(0, 5)} ${MODALIDADES_TORNEO[p.modalidad]?.split(" ")[0] || ""}`.trim(), valor: p.puesto })),
            indice: i,
        }).filter(Boolean);
        if (series.length === 0) return zonaPosiciones.append(crear('p', { clase: 'ayuda', texto: 'Elige los deportistas para ver sus curvas.' }));
        if (series.every((s) => s.puntos.length === 0)) return zonaPosiciones.append(crear('p', { texto: 'Ninguno tiene puestos registrados en torneos.' }));
        const maximo = Math.max(3, ...series.flatMap((s) => s.puntos.map((p) => p.valor)));
        zonaPosiciones.append(
            crear('p', { clase: 'ayuda', texto: 'Arriba es mejor (el puesto 1 va arriba). Línea continua: primer deportista; punteada: segundo.' }),
            graficoSeries({ titulo: 'Puesto por torneo', series, invertido: true, minimo: 1, maximo, encabezadoX: 'Fecha' }));
        return undefined;
    };

    const cargarColumna = async (i) => {
        const zona = columnas[i];
        const documento = comparados[i];
        while (zona.children.length > 1) zona.lastChild.remove();
        datos[i] = null;
        if (!documento) {
            zona.append(crear('p', { clase: 'ayuda', texto: 'Elige un deportista.' }));
            pintarPosiciones();
            return;
        }
        const cuerpo = crear('div');
        zona.append(cuerpo);
        cargando(cuerpo);
        try {
            const [e, torneos, ascensos] = await Promise.all([
                estadisticas.deportista(documento),
                historial.torneos({ deportista: documento }),
                historial.ascensos({ deportista: documento }),
            ]);
            datos[i] = e;
            vaciar(cuerpo);
            cuerpo.append(...columnaComparada(e, torneos, ascensos));
        } catch (error) {
            errorEn(cuerpo, error);
        }
        pintarPosiciones();
    };

    columnas.forEach((zona, i) => {
        zona.append(crear('div', { clase: 'tarjeta' },
            crear('h2', { texto: i === 0 ? 'Deportista A' : 'Deportista B' }),
            selectorDeportista({ etiqueta: i === 0 ? 'Primer deportista' : 'Segundo deportista', textoBoton: 'Comparar', alElegir: (doc) => { comparados[i] = doc; cargarColumna(i); } })));
    });
    panel.append(
        crear('p', { clase: 'ayuda', texto: 'Elige dos deportistas para verlos lado a lado.' }),
        crear('div', { clase: 'rejilla-comparar' }, ...columnas),
        zonaPosiciones);
    cargarColumna(0);
    cargarColumna(1);
}

function columnaComparada(e, torneos, ascensos) {
    const p = e.perfil;
    const r = e.record;
    const a = e.asistencia;
    const filaTipo = (tipo, c) => crear('tr', {}, crear('td', { texto: TIPOS_TORNEO[tipo] || tipo }),
        crear('td', { texto: String(c.oro) }), crear('td', { texto: String(c.plata) }), crear('td', { texto: String(c.bronce) }));
    return [
        crear('section', { clase: 'tarjeta' },
            crear('h3', { texto: p.nombre }),
            crear('p', { clase: 'ayuda', texto: p.documento }),
            listaDatos([
                ['Grado', chipGrado(p.grado_actual)],
                ['Edad', p.edad === null ? null : `${p.edad} años`],
                ['Categoría', p.categoria_edad],
                ['Récord', bloqueRecord({ combates_ganados: r.ganados, combates_perdidos: r.perdidos }, { porcentaje: r.porcentaje_victorias })],
                ['Asistencia (90 días)', a.datos_incompletos || a.porcentaje === null ? 'Datos insuficientes' : `${Number(a.porcentaje).toLocaleString('es-CO')} %`],
                ['Medallas', `${e.medallas.totales.total} (oro ${e.medallas.totales.oro}, plata ${e.medallas.totales.plata}, bronce ${e.medallas.totales.bronce})`],
            ]),
            tabla(`Medallas por tipo de ${p.nombre}`, ['Tipo', 'Oro', 'Plata', 'Bronce'], Object.entries(e.medallas.por_tipo_torneo).map(([tipo, c]) => filaTipo(tipo, c)))),
        crear('section', { clase: 'tarjeta' },
            crear('h3', { texto: 'Torneos' }),
            torneos.length
                ? tabla(`Torneos de ${p.nombre}`, ['Fecha', 'Torneo', 'Modalidad', 'Puesto', 'Medalla'], torneos.map((t) => crear('tr', {},
                    crear('td', { texto: fechaCorta(t.fecha) }), crear('td', {}, ...textoConFechas(t.torneo)), crear('td', { texto: MODALIDADES_TORNEO[t.modalidad] || t.modalidad }),
                    crear('td', { texto: t.puesto ? `${t.puesto} de ${t.participantes_en_modalidad}` : '—' }), crear('td', { texto: MEDALLAS[t.medalla] || textoDato(t.medalla) }))))
                : crear('p', { texto: 'Sin torneos realizados.' })),
        crear('section', { clase: 'tarjeta' },
            crear('h3', {}, 'Ascensos ', etiquetaProvisional()),
            ascensos.length
                ? tabla(`Ascensos de ${p.nombre}`, ['Grado', 'Fecha de grado', 'Promedio', 'Resultado'], ascensos.map((x) => crear('tr', {},
                    crear('td', { texto: textoGrado(x.grado_evaluado) }), crear('td', { texto: fechaCorta(x.fecha_grado) }),
                    crear('td', { texto: textoDato(x.resultado_general.promedio) }), crear('td', { texto: x.resultado_general.aprobado ? 'Aprobado' : 'No aprobado' }))))
                : crear('p', { texto: 'Sin exámenes en sesiones cerradas.' })),
    ];
}

// ===========================================================================
// Pestaña «Registros» (historial)
// ===========================================================================
async function pintarRegistros(panel, esAdministrativo) {
    const filtros = esAdministrativo && documentoElegido ? { deportista: documentoElegido } : {};
    const zonaTorneos = crear('div');
    const zonaAscensos = crear('div');
    const zonaPermanencia = crear('div');
    vaciar(panel);
    panel.append(seccion('Torneos realizados', zonaTorneos), seccion('Ascensos (sesiones cerradas)', zonaAscensos), seccion('Permanencia', zonaPermanencia));
    cargando(zonaTorneos);
    cargando(zonaAscensos);
    cargando(zonaPermanencia);

    historial.torneos(filtros).then((filas) => {
        vaciar(zonaTorneos);
        if (filas.length === 0) return zonaTorneos.append(crear('p', { texto: 'Sin participaciones en torneos realizados.' }));
        zonaTorneos.append(tabla('Participaciones en torneos', ['Fecha', 'Torneo', 'Deportista', 'Modalidad', 'Puesto', 'Medalla', 'Combates G/P', 'Resultado'],
            filas.map((f) => crear('tr', {},
                crear('td', { texto: fechaCorta(f.fecha) }),
                crear('td', {}, ...textoConFechas(`${f.torneo} (${TIPOS_TORNEO[f.tipo_torneo] || f.tipo_torneo})`)),
                crear('td', { texto: f.nombre }),
                crear('td', { texto: MODALIDADES_TORNEO[f.modalidad] || f.modalidad }),
                crear('td', { texto: f.puesto ? `${f.puesto} de ${f.participantes_en_modalidad}` : '—' }),
                crear('td', { texto: MEDALLAS[f.medalla] || textoDato(f.medalla) }),
                crear('td', { texto: f.combates_ganados === null && f.combates_perdidos === null ? '—' : `${textoDato(f.combates_ganados)} / ${textoDato(f.combates_perdidos)}` }),
                crear('td', { texto: textoDato(f.resultado) })))));
    }).catch((error) => errorEn(zonaTorneos, error));

    historial.ascensos(filtros).then((filas) => {
        vaciar(zonaAscensos);
        if (filas.length === 0) return zonaAscensos.append(crear('p', { texto: 'Sin exámenes en sesiones cerradas.' }));
        for (const a of filas) {
            const entrega = a.entrega
                ? (a.entrega.cinturon_entregado ? `Cinturón entregado el ${fechaCorta(a.entrega.fecha_entrega)} (confirmó: ${a.entrega.confirmado_por})` : 'Cinturón pendiente de entrega')
                : 'Sin registro de entrega';
            zonaAscensos.append(crear('details', { clase: 'participante' },
                crear('summary', {},
                    crear('strong', { texto: `${a.nombre} · ${textoGrado(a.grado_evaluado)}` }),
                    ` · grado el ${fechaCorta(a.fecha_grado)} · promedio ${textoDato(a.resultado_general.promedio)} · `,
                    a.resultado_general.aprobado ? insignia('Aprobado', 'activo') : insignia('No aprobado', 'inactivo'), ' ', etiquetaProvisional()),
                crear('p', { clase: 'ayuda', texto: `Examen del ${fechaCorta(a.fecha)} en ${textoDato(a.lugar)} · ${entrega}` }),
                tabla('Notas por modalidad', ['Modalidad', 'Nota', 'Aprobó', 'Valor medible', 'Aspectos por mejorar', 'Observaciones', 'Recomendaciones'],
                    a.modalidades.map((m) => crear('tr', {},
                        crear('td', { texto: MODALIDADES_EXAMEN[m.modalidad] || m.modalidad }),
                        crear('td', { texto: textoDato(m.nota) }),
                        crear('td', { texto: textoDato(m.aprobado) }),
                        crear('td', { texto: textoDato(m.valor_medible) }),
                        crear('td', { texto: textoDato(m.aspectos_fallidos) }),
                        crear('td', { texto: textoDato(m.observaciones) }),
                        crear('td', { texto: textoDato(m.recomendaciones) }))))));
        }
        zonaAscensos.append(crear('p', { clase: 'ayuda', texto: 'El resultado (promedio de las seis notas; 5 o más aprueba) es PROVISIONAL hasta que el club valide la regla.' }));
    }).catch((error) => errorEn(zonaAscensos, error));

    historial.permanencia(filtros).then((filas) => {
        vaciar(zonaPermanencia);
        if (filas.length === 0) return zonaPermanencia.append(crear('p', { texto: 'Sin datos de permanencia.' }));
        zonaPermanencia.append(tabla('Permanencia por deportista', ['Deportista', 'Estado', 'Ingreso', 'Meses pagados', 'Primer mes', 'Último mes'],
            filas.map((p) => crear('tr', {},
                crear('td', { texto: p.nombre }),
                crear('td', { texto: textoDato(p.estado) }),
                crear('td', { texto: fechaCorta(p.fecha_ingreso) }),
                crear('td', { texto: textoDato(p.meses_pagados) }),
                crear('td', { texto: textoDato(p.primer_mes_pagado) }),
                crear('td', { texto: textoDato(p.ultimo_mes_pagado) })))));
    }).catch((error) => errorEn(zonaPermanencia, error));
}

// ===========================================================================
// Pestaña «Gráficas» (estadísticas)
// ===========================================================================
async function pintarGraficas(panel, esAdministrativo) {
    vaciar(panel);
    const zonaDeportista = crear('div');
    panel.append(zonaDeportista);
    if (esAdministrativo) panel.append(await seccionTorneo());

    if (esAdministrativo && !documentoElegido) {
        zonaDeportista.append(crear('p', { clase: 'tarjeta', texto: 'Elige un deportista para ver sus gráficas.' }));
        return;
    }
    cargando(zonaDeportista);
    let e;
    try {
        e = await estadisticas.deportista(esAdministrativo ? documentoElegido : undefined);
    } catch (error) {
        errorEn(zonaDeportista, error);
        return;
    }
    vaciar(zonaDeportista);
    const p = e.perfil;
    zonaDeportista.append(
        seccion('Perfil', crear('p', {},
            crear('strong', { texto: p.nombre }), ` · ${p.documento} · ${textoGrado(p.grado_actual)} · ${textoDato(p.edad)} años · ${textoDato(p.categoria_edad)} · ingreso ${fechaCorta(p.fecha_ingreso)} · ${textoDato(p.estado)}`)),
        seccionMedallas(e.medallas),
        seccionRecord(e.record),
        seccionPosiciones(e.posiciones),
        seccionExamenes(e.examenes),
        seccionPruebas(e.pruebas_fisicas),
        seccionAsistencia(e.asistencia));
}

function seccionMedallas(m) {
    const t = m.totales;
    if (!t.total) return seccion('Medallas', crear('p', { texto: 'Aún no tiene medallas.' }));
    return seccion('Medallas',
        crear('p', { texto: `Total: ${t.total} (oro ${t.oro}, plata ${t.plata}, bronce ${t.bronce}).` }),
        graficoBarras({ titulo: 'Medallas por color', datos: [
            { etiqueta: 'Oro', valor: t.oro, clase: 'oro' }, { etiqueta: 'Plata', valor: t.plata, clase: 'plata' }, { etiqueta: 'Bronce', valor: t.bronce, clase: 'bronce' }] }),
        crear('details', {}, crear('summary', { texto: 'Por tipo de torneo' }),
            tabla('Medallas por tipo de torneo', ['Tipo', 'Oro', 'Plata', 'Bronce'], Object.entries(m.por_tipo_torneo).map(([tipo, c]) => crear('tr', {},
                crear('td', { texto: TIPOS_TORNEO[tipo] || tipo }), crear('td', { texto: String(c.oro) }),
                crear('td', { texto: String(c.plata) }), crear('td', { texto: String(c.bronce) }))))));
}

function seccionRecord(r) {
    if (!r.total) return seccion('Récord de combates', crear('p', { texto: 'Sin combates registrados.' }));
    return seccion('Récord de combates',
        crear('p', { texto: `${r.ganados} ganados y ${r.perdidos} perdidos de ${r.total} · ${r.porcentaje_victorias === null ? '—' : `${Number(r.porcentaje_victorias).toLocaleString('es-CO')} % de victorias`}` }),
        graficoBarras({ titulo: 'Combates ganados y perdidos', datos: [
            { etiqueta: 'Ganados', valor: r.ganados, clase: 'positivo' }, { etiqueta: 'Perdidos', valor: r.perdidos, clase: 'negativo' }] }));
}

function seccionPosiciones(posiciones) {
    if (posiciones.length === 0) return seccion('Posiciones en torneos', crear('p', { texto: 'Sin puestos registrados.' }));
    const maximo = Math.max(...posiciones.map((p) => p.puesto), 3);
    return seccion('Posiciones en torneos',
        crear('p', { clase: 'ayuda', texto: 'Arriba es mejor (el puesto 1 va arriba).' }),
        graficoLinea({
            titulo: 'Puesto en cada torneo', invertido: true, minimo: 1, maximo, encabezados: ['Torneo', 'Puesto'],
            puntos: posiciones.map((p) => ({ etiqueta: `${fechaCorta(p.fecha).slice(0, 5)} ${MODALIDADES_TORNEO[p.modalidad]?.split(' ')[0] || ''}`.trim(), valor: p.puesto })),
        }),
        tabla('Detalle de posiciones', ['Fecha', 'Torneo', 'Modalidad', 'Puesto', 'Medalla'], posiciones.map((p) => crear('tr', {},
            crear('td', { texto: fechaCorta(p.fecha) }), crear('td', {}, ...textoConFechas(p.torneo)), crear('td', { texto: MODALIDADES_TORNEO[p.modalidad] || p.modalidad }),
            crear('td', { texto: `${p.puesto} de ${p.participantes_en_modalidad}` }), crear('td', { texto: MEDALLAS[p.medalla] || textoDato(p.medalla) })))));
}

function seccionExamenes(examenes) {
    if (examenes.length === 0) return seccion('Exámenes de ascenso', crear('p', { texto: 'Sin exámenes en sesiones cerradas.' }));
    const ultimo = examenes[examenes.length - 1];
    return seccion('Exámenes de ascenso',
        crear('p', {}, `Último: ${textoGrado(ultimo.grado_evaluado)} (${fechaCorta(ultimo.fecha_grado)}) · promedio ${ultimo.promedio} · `,
            ultimo.aprobado ? insignia('Aprobado', 'activo') : insignia('No aprobado', 'inactivo'), ' ', etiquetaProvisional()),
        graficoBarras({ titulo: `Notas del examen de ${textoGrado(ultimo.grado_evaluado)}`, maximo: 10,
            datos: Object.entries(ultimo.notas).map(([m, nota]) => ({ etiqueta: MODALIDADES_EXAMEN[m] || m, valor: nota ?? 0 })) }),
        examenes.length > 1
            ? graficoLinea({ titulo: 'Promedio por examen', minimo: 0, maximo: 10, encabezados: ['Grado', 'Promedio'],
                puntos: examenes.map((x) => ({ etiqueta: textoGrado(x.grado_evaluado), valor: x.promedio })) })
            : null);
}

function seccionPruebas(pruebas) {
    const encabezado = crear('p', {}, etiquetaProvisional(), ' Depende de la plantilla de pruebas físicas de Aldrin.');
    if (pruebas.length === 0) return seccion('Pruebas físicas', encabezado, crear('p', { texto: 'Sin pruebas físicas registradas.' }));
    return seccion('Pruebas físicas', encabezado,
        graficoLinea({ titulo: 'Valor medible de la prueba física', encabezados: ['Grado', 'Valor'],
            puntos: pruebas.map((x) => ({ etiqueta: `${textoGrado(x.grado_evaluado)} ${fechaCorta(x.fecha_grado).slice(0, 5)}`, valor: x.valor_medible ?? 0 })) }));
}

function seccionAsistencia(a) {
    return seccion('Asistencia (últimos 90 días)',
        crear('p', { clase: 'cifra', texto: a.datos_incompletos || a.porcentaje === null ? 'Datos insuficientes' : `${Number(a.porcentaje).toLocaleString('es-CO')} %` }),
        crear('p', { clase: 'ayuda', texto: `${a.presentes} presente(s) en ${a.sesiones} sesión(es) desde el ${fechaCorta(a.desde)}.` }));
}

// ---------------------------------------------------------------------------
// Resumen por torneo (solo administrativo; torneos ya realizados)
// ---------------------------------------------------------------------------
async function seccionTorneo() {
    const zona = crear('div');
    let realizados = [];
    try {
        const hoy = hoyBogota();
        realizados = (await competencias.listar()).filter((t) => t.fecha <= hoy);
    } catch (error) {
        errorEn(zona, error);
    }
    const selector = campo({ etiqueta: 'Torneo realizado', nombre: 'torneo', tipo: 'select', valor: '',
        opciones: [{ valor: '', texto: 'Elige un torneo…' }, ...realizados.map((t) => ({ valor: String(t.evento_id), texto: `${formatearTextoConFechas(t.nombre)} (${fechaCorta(t.fecha)})` }))] });
    selector.control.addEventListener('change', async () => {
        if (!selector.control.value) return vaciar(zona);
        cargando(zona);
        try {
            const t = await estadisticas.torneo(selector.control.value);
            vaciar(zona);
            zona.append(
                crear('p', { texto: `${formatearTextoConFechas(t.nombre)} · ${fechaCorta(t.fecha)} · ${textoDato(t.lugar)} · ${t.num_participantes} participante(s) · medallas: oro ${t.medallas_total.oro}, plata ${t.medallas_total.plata}, bronce ${t.medallas_total.bronce}` }),
                graficoBarras({ titulo: 'Medallas por modalidad (total)', datos: t.por_modalidad.map((m) => ({ etiqueta: (MODALIDADES_TORNEO[m.modalidad] || m.modalidad).split(' ')[0], valor: m.oro + m.plata + m.bronce })) }),
                tabla('Resumen por modalidad', ['Modalidad', 'Participantes', 'Oro', 'Plata', 'Bronce', 'Combates G/P'], t.por_modalidad.map((m) => crear('tr', {},
                    crear('td', { texto: MODALIDADES_TORNEO[m.modalidad] || m.modalidad }), crear('td', { texto: String(m.participantes) }),
                    crear('td', { texto: String(m.oro) }), crear('td', { texto: String(m.plata) }), crear('td', { texto: String(m.bronce) }),
                    crear('td', { texto: `${m.combates_ganados} / ${m.combates_perdidos}` })))),
                tabla('Participantes del torneo', ['Deportista', 'Modalidad', 'Puesto', 'Medalla', 'Resultado'], t.participantes.map((x) => crear('tr', {},
                    crear('td', { texto: x.nombre }), crear('td', { texto: MODALIDADES_TORNEO[x.modalidad] || x.modalidad }),
                    crear('td', { texto: textoDato(x.puesto) }), crear('td', { texto: MEDALLAS[x.medalla] || textoDato(x.medalla) }), crear('td', { texto: textoDato(x.resultado) })))));
        } catch (error) {
            errorEn(zona, error);
        }
    });
    return seccion('Resumen por torneo', crear('div', { clase: 'filtros' }, selector.envoltura), zona);
}
