// Estadísticas e historial — catálogo B.12 (pestaña «Registros») y B.13 (pestaña «Gráficas»).
// Una sola pantalla para los dos ámbitos: el administrativo elige deportista (o ve todos en
// Registros) y tiene el resumen por torneo; el público ve solo lo suyo.
import { historial, estadisticas, competencias } from '../api.js';
import { crear, vaciar, campo, cargando, textoFecha, textoDato, textoGrado, insignia, hoyBogota } from '../ui.js';
import { selectorDeportista } from './comunes.js';
import { graficoBarras, graficoLinea } from '../componentes/graficos.js';

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

// Estado de la pantalla: deportista elegido (administrativo) y pestaña.
let documentoElegido = null;

// pestanaInicial: 'registros' o 'graficas'. esAdministrativo: muestra el selector y el resumen por torneo.
export async function mostrarEstadisticas(contenedor, { esAdministrativo, pestanaInicial = 'graficas', documento = null }) {
    if (documento) documentoElegido = documento;
    const panelRegistros = crear('div', { atributos: { role: 'tabpanel', id: 'panel-registros', 'aria-labelledby': 'pestana-registros' } });
    const panelGraficas = crear('div', { atributos: { role: 'tabpanel', id: 'panel-graficas', 'aria-labelledby': 'pestana-graficas' } });
    const pestanas = {
        registros: crear('button', { clase: 'pestana', texto: 'Registros', atributos: { type: 'button', role: 'tab', id: 'pestana-registros', 'aria-controls': 'panel-registros' } }),
        graficas: crear('button', { clase: 'pestana', texto: 'Gráficas', atributos: { type: 'button', role: 'tab', id: 'pestana-graficas', 'aria-controls': 'panel-graficas' } }),
    };
    let actual = pestanaInicial;
    const cargar = () => {
        for (const [clave, boton] of Object.entries(pestanas)) {
            const activa = clave === actual;
            boton.setAttribute('aria-selected', String(activa));
            boton.tabIndex = activa ? 0 : -1;
        }
        panelRegistros.hidden = actual !== 'registros';
        panelGraficas.hidden = actual !== 'graficas';
        if (actual === 'registros') pintarRegistros(panelRegistros, esAdministrativo);
        else pintarGraficas(panelGraficas, esAdministrativo);
    };
    for (const [clave, boton] of Object.entries(pestanas)) {
        boton.addEventListener('click', () => {
            actual = clave;
            cargar();
        });
        boton.addEventListener('keydown', (evento) => {
            if (evento.key !== 'ArrowRight' && evento.key !== 'ArrowLeft') return;
            evento.preventDefault();
            actual = actual === 'registros' ? 'graficas' : 'registros';
            cargar();
            pestanas[actual].focus();
        });
    }

    const elementos = [crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: esAdministrativo ? 'Estadísticas e historial' : 'Mis estadísticas e historial' }))];
    if (esAdministrativo) {
        const elegido = crear('p', { clase: 'ayuda', atributos: { 'aria-live': 'polite' } });
        const pintarElegido = () => {
            elegido.textContent = documentoElegido ? `Deportista: ${documentoElegido}` : 'Sin deportista elegido: «Registros» muestra a todos; «Gráficas» pide un deportista.';
        };
        pintarElegido();
        const quitar = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Ver todos', atributos: { type: 'button' } });
        quitar.addEventListener('click', () => {
            documentoElegido = null;
            pintarElegido();
            cargar();
        });
        elementos.push(crear('section', { clase: 'tarjeta' },
            selectorDeportista({ textoBoton: 'Ver deportista', alElegir: (doc) => { documentoElegido = doc; pintarElegido(); cargar(); } }),
            crear('div', { clase: 'acciones' }, elegido, quitar)));
    }
    elementos.push(crear('div', { clase: 'pestanas pestanas-vista', atributos: { role: 'tablist', 'aria-label': 'Vista' } }, pestanas.registros, pestanas.graficas),
        panelRegistros, panelGraficas);
    contenedor.append(...elementos);
    cargar();
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
                crear('td', { texto: `${f.torneo} (${TIPOS_TORNEO[f.tipo_torneo] || f.tipo_torneo})` }),
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
            crear('td', { texto: fechaCorta(p.fecha) }), crear('td', { texto: p.torneo }), crear('td', { texto: MODALIDADES_TORNEO[p.modalidad] || p.modalidad }),
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
        opciones: [{ valor: '', texto: 'Elige un torneo…' }, ...realizados.map((t) => ({ valor: String(t.evento_id), texto: `${t.nombre} (${fechaCorta(t.fecha)})` }))] });
    selector.control.addEventListener('change', async () => {
        if (!selector.control.value) return vaciar(zona);
        cargando(zona);
        try {
            const t = await estadisticas.torneo(selector.control.value);
            vaciar(zona);
            zona.append(
                crear('p', { texto: `${t.nombre} · ${fechaCorta(t.fecha)} · ${textoDato(t.lugar)} · ${t.num_participantes} participante(s) · medallas: oro ${t.medallas_total.oro}, plata ${t.medallas_total.plata}, bronce ${t.medallas_total.bronce}` }),
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
