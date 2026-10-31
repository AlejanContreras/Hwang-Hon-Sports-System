// Inicio del deportista (#inicio en publico.html), según el boceto de John (30/10/2026):
// tarjetas Grado actual, Próximo torneo o ascenso, Asistencia y Mis pagos; «Próximas fechas» a la
// derecha y «Estadísticas relevantes» a la izquierda, igual que el administrativo (John, 30/10);
// accesos rápidos a sus módulos.
// Solo datos que la API pública entrega (todo es «lo suyo»).
import { deportistas, asistencia, pagos, calendario, evaluaciones, estadisticas } from '../api.js';
import { crear, vaciar, cargando, textoFecha, hoyBogota, sumarDias, nombreMes, insignia, textoGrado } from '../ui.js';
import { GRUPOS_PUBLICOS } from '../modulos.js';
import { calendarioLateral } from './inicio.js';
import { saludo, tarjetaDato, accesosRapidos } from '../componentes/tablero.js';
import { crearCinturonDeColor } from '../componentes/iconos.js';
import { colorDeGrado, nombreDeGrado } from '../componentes/grados.js';
import { bloqueRecord, textoRecord } from '../componentes/record.js';

const MODALIDADES = { combate_individual: 'combate individual', combate_equipos: 'combate por equipos', defensa_personal: 'defensa personal', salto_alto: 'salto alto', salto_largo: 'salto largo', formas: 'formas' };
const MEDALLAS = { oro: 'oro', plata: 'plata', bronce: 'bronce' };
const MAXIMO_ESTADISTICAS = 4;

export function mostrarInicioDeportista(contenedor, sesion) {
    const documento = sesion.identificador;
    const ficha = deportistas.consultar(documento);
    // Una sola llamada a estadísticas: la usan la tarjeta del grado (porcentaje de victorias) y el panel.
    const resumen = estadisticas.deportista();
    contenedor.append(
        saludo(sesion.nombre),
        crear('div', { clase: 'tarjetas-inicio' },
            tarjetaDato({ id: 'tarjeta-grado', titulo: 'Grado actual', icono: 'cinturon', cargar: () => cargarGrado(ficha, resumen) }),
            tarjetaDato({ id: 'tarjeta-proximo-evento', titulo: 'Próximo torneo o ascenso', icono: 'medal', cargar: cargarProximoEvento }),
            tarjetaDato({ id: 'tarjeta-asistencia', titulo: 'Asistencia', icono: 'calendar-check', cargar: cargarAsistencia }),
            tarjetaDato({ id: 'tarjeta-pagos', titulo: 'Mis pagos', icono: 'wallet', cargar: cargarPagos }),
        ),
        crear('div', { clase: 'rejilla-inicio rejilla-inicio-deportista' },
            panelEstadisticas(documento, resumen),
            calendarioLateral(proximasFechasDeportista)),
        accesosRapidos(GRUPOS_PUBLICOS.flatMap((g) => g.modulos)),
    );
}

// ---------------------------------------------------------------------------
// Tarjetas
// ---------------------------------------------------------------------------
// Récord «G-P» (deportistas.consultar) a la izquierda del cinturón; el % de victorias sale de
// estadisticas.deportista y solo se muestra, pequeño, si la API lo trae (es null sin combates).
async function cargarGrado(ficha, resumen) {
    const d = await ficha;
    const { color, claro, oscuro } = colorDeGrado(d.grado_actual);
    const nombre = crear('p', { clase: `nombre-grado${claro ? ' grado-claro' : ''}${oscuro ? ' grado-oscuro' : ''}`, texto: nombreDeGrado(d.grado_actual) });
    nombre.style.setProperty('--color-grado', color);
    let porcentaje = null;
    try {
        porcentaje = (await resumen).record.porcentaje_victorias;
    } catch {
        // sin el porcentaje, el récord se muestra igual
    }
    const record = bloqueRecord(d, { porcentaje, id: 'record-combates' });
    return {
        nodos: [nombre, crear('div', { clase: 'fila-grado' }, record, crearCinturonDeColor(color, { tamano: 34 }))],
        enlace: '#ascensos',
    };
}

// La fecha más cercana entre los eventos públicos de torneo/examen/grado del calendario y sus
// propias sesiones de examen (competencias.proximo es solo administrativo).
async function cargarProximoEvento() {
    const hoy = hoyBogota();
    const [vista, sesiones] = await Promise.all([calendario.vista(hoy, sumarDias(hoy, 365)), evaluaciones.listar()]);
    const candidatos = [
        ...vista.eventos.filter((e) => ['competencia', 'evaluacion', 'grado'].includes(e.tipo))
            .map((e) => ({ fecha: e.fecha, texto: e.nombre, tipo: e.tipo === 'competencia' ? 'Torneo' : e.tipo === 'grado' ? 'Grados' : 'Examen' })),
        ...sesiones.filter((s) => s.fecha >= hoy).map((s) => ({ fecha: s.fecha, texto: `Tu examen en ${s.lugar}`, tipo: 'Mi examen' })),
    ].sort((a, b) => a.fecha.localeCompare(b.fecha));
    const primero = candidatos[0];
    if (!primero) return { nodos: [crear('p', { texto: 'No hay torneos ni ascensos próximos.' })], enlace: '#calendario' };
    return {
        nodos: [
            crear('p', { clase: 'dato-destacado', texto: primero.texto }),
            crear('p', { clase: 'ayuda', texto: `${primero.tipo} · ${textoFecha(primero.fecha)}` }),
        ],
        enlace: '#calendario',
    };
}

async function cargarAsistencia() {
    const p = await asistencia.porcentaje(null, '30');
    return {
        nodos: [
            crear('p', { clase: 'cifra', texto: p.datos_incompletos || p.porcentaje === null ? 'Datos insuficientes' : `${Number(p.porcentaje).toLocaleString('es-CO')} %` }),
            crear('p', { clase: 'ayuda', texto: `Últimos 30 días: ${p.presentes} de ${p.sesiones} sesión(es)` }),
        ],
        enlace: '#asistencia',
    };
}

async function cargarPagos() {
    const e = await pagos.estado();
    const nodos = e.al_dia
        ? [crear('p', { clase: 'cifra texto-exito', texto: 'Al día' }),
            crear('p', { clase: 'ayuda', texto: e.por_vencer ? `Próxima mensualidad: ${nombreMes(e.por_vencer.mes)}, vence el ${textoFecha(e.por_vencer.vencimiento)}` : 'Sin mensualidades por vencer.' })]
        : [crear('p', { clase: 'cifra texto-peligro', texto: 'Con deuda' }),
            crear('p', { clase: 'ayuda', texto: `Meses en deuda: ${e.meses_en_deuda.map(nombreMes).join(', ')}` })];
    return { nodos, enlace: '#pagos' };
}

// ---------------------------------------------------------------------------
// «Próximas fechas»: solo torneos, exámenes/grados y el vencimiento de SU mensualidad.
// Sin cumpleaños (la API los incluye; aquí se descartan).
// ---------------------------------------------------------------------------
async function proximasFechasDeportista() {
    const hoy = hoyBogota();
    const vista = await calendario.vista(hoy, sumarDias(hoy, 30));
    const items = [
        ...vista.eventos.filter((e) => ['competencia', 'evaluacion', 'grado'].includes(e.tipo))
            .map((e) => ({ fecha: e.fecha, texto: `${e.tipo === 'competencia' ? 'Torneo' : e.tipo === 'grado' ? 'Grados' : 'Examen'}: ${e.nombre}` })),
        ...vista.vencimientos.map((v) => ({ fecha: v.fecha, texto: 'Vence tu mensualidad' })),
    ].sort((a, b) => a.fecha.localeCompare(b.fecha));
    if (!items.length) return [crear('p', { texto: 'Nada en los próximos 30 días.' }), crear('p', {}, crear('a', { texto: 'Ver calendario', atributos: { href: '#calendario' } }))];
    return [
        crear('ul', { clase: 'lista-proximas' }, ...items.slice(0, 8).map((i) => crear('li', {},
            crear('span', { clase: 'fecha-proxima', texto: textoFecha(i.fecha).slice(0, 5) }), crear('span', { texto: i.texto })))),
        crear('p', {}, crear('a', { texto: 'Ver calendario', atributos: { href: '#calendario' } })),
    ];
}

// ---------------------------------------------------------------------------
// «Estadísticas relevantes»: indicadores que entrega estadisticas.deportista; el deportista elige 4
// (se guardan en localStorage por documento). Solo se ofrecen los que tienen datos.
// ---------------------------------------------------------------------------
function indicadores(e) {
    const lista = [];
    const ultimaPosicion = e.posiciones[e.posiciones.length - 1];
    lista.push({
        clave: 'ultimo_torneo', titulo: 'Último torneo',
        valor: ultimaPosicion ? `Puesto ${ultimaPosicion.puesto}${MEDALLAS[ultimaPosicion.medalla] ? ` · ${MEDALLAS[ultimaPosicion.medalla]}` : ''}` : 'Sin resultados',
        detalle: ultimaPosicion ? `${ultimaPosicion.torneo} (${MODALIDADES[ultimaPosicion.modalidad] || ultimaPosicion.modalidad}), ${textoFecha(ultimaPosicion.fecha)}` : 'Aún no tienes puestos en torneos.',
    });
    lista.push({
        clave: 'asistencia', titulo: 'Asistencia (90 días)',
        valor: e.asistencia.datos_incompletos || e.asistencia.porcentaje === null ? 'Datos insuficientes' : `${Number(e.asistencia.porcentaje).toLocaleString('es-CO')} %`,
        detalle: `${e.asistencia.presentes} de ${e.asistencia.sesiones} sesión(es)`,
    });
    const prueba = e.pruebas_fisicas[e.pruebas_fisicas.length - 1];
    if (prueba) {
        lista.push({ clave: 'prueba_fisica', titulo: 'Prueba física', provisional: true, valor: String(prueba.valor_medible ?? '—'), detalle: `${textoGrado(prueba.grado_evaluado)}, ${textoFecha(prueba.fecha_grado)}` });
    }
    const examen = e.examenes[e.examenes.length - 1];
    lista.push({
        clave: 'ultimo_ascenso', titulo: 'Último ascenso', provisional: Boolean(examen),
        valor: examen ? `${textoGrado(examen.grado_evaluado)} · ${examen.aprobado ? 'aprobado' : 'no aprobado'}` : 'Sin exámenes',
        detalle: examen ? `Promedio ${examen.promedio}, ${textoFecha(examen.fecha_grado)}` : 'Aún no tienes exámenes cerrados.',
    });
    lista.push({ clave: 'medallas', titulo: 'Medallas', valor: String(e.medallas.totales.total), detalle: `Oro ${e.medallas.totales.oro} · plata ${e.medallas.totales.plata} · bronce ${e.medallas.totales.bronce}` });
    lista.push({
        clave: 'record', titulo: 'Récord de combates',
        valor: textoRecord(e.record.ganados, e.record.perdidos) ?? 'Sin combates',
        detalle: e.record.porcentaje_victorias === null ? '—' : `${Number(e.record.porcentaje_victorias).toLocaleString('es-CO')} % de victorias`,
    });
    return lista;
}

const POR_DEFECTO = ['ultimo_torneo', 'asistencia', 'prueba_fisica', 'ultimo_ascenso', 'medallas'];

function leerElegidas(documento) {
    try {
        const valor = JSON.parse(localStorage.getItem(`hwanghon_estadisticas_${documento}`) || 'null');
        return Array.isArray(valor) ? valor : null;
    } catch {
        return null;
    }
}

function guardarElegidas(documento, claves) {
    try {
        localStorage.setItem(`hwanghon_estadisticas_${documento}`, JSON.stringify(claves));
    } catch {
        // sin almacenamiento: la elección dura mientras la página esté abierta
    }
}

function panelEstadisticas(documento, resumen) {
    const cuerpo = crear('div', { atributos: { 'aria-live': 'polite' } });
    const zonaElegir = crear('div');
    let disponibles = [];
    let elegidas = null;

    const pintar = () => {
        const claves = (elegidas || POR_DEFECTO).filter((c) => disponibles.some((i) => i.clave === c)).slice(0, MAXIMO_ESTADISTICAS);
        vaciar(cuerpo);
        cuerpo.append(crear('ul', { clase: 'lista-estadisticas' }, ...claves.map((c) => disponibles.find((i) => i.clave === c)).map((i) => crear('li', { atributos: { 'data-prueba': `estadistica-${i.clave}` } },
            crear('span', { clase: 'ayuda' }, i.titulo, i.provisional ? ' ' : null, i.provisional ? insignia('PROVISIONAL', 'pendiente') : null),
            crear('strong', { clase: 'valor-estadistica', texto: i.valor }),
            crear('span', { clase: 'ayuda', texto: i.detalle })))));
    };

    const formularioElegir = () => {
        vaciar(zonaElegir);
        const actuales = new Set((elegidas || POR_DEFECTO).filter((c) => disponibles.some((i) => i.clave === c)).slice(0, MAXIMO_ESTADISTICAS));
        const aviso = crear('p', { clase: 'ayuda', atributos: { 'aria-live': 'polite' } });
        const casillas = disponibles.map((i) => {
            const id = `elegir-${i.clave}`;
            const c = crear('input', { atributos: { type: 'checkbox', id, value: i.clave } });
            c.checked = actuales.has(i.clave);
            return { c, nodo: crear('div', { clase: 'campo campo-casilla' }, c, crear('label', { texto: i.titulo, atributos: { for: id } })) };
        });
        const actualizar = () => {
            const marcadas = casillas.filter((x) => x.c.checked).length;
            aviso.textContent = `${marcadas} de ${MAXIMO_ESTADISTICAS} elegidas.`;
            for (const x of casillas) x.c.disabled = !x.c.checked && marcadas >= MAXIMO_ESTADISTICAS;
        };
        for (const x of casillas) x.c.addEventListener('change', actualizar);
        actualizar();
        const guardar = crear('button', { clase: 'boton boton-pequeno', texto: 'Guardar', atributos: { type: 'submit' } });
        const formulario = crear('form', { atributos: { 'aria-label': 'Elegir mis estadísticas' } }, ...casillas.map((x) => x.nodo), aviso, crear('div', { clase: 'acciones' }, guardar));
        formulario.addEventListener('submit', (evento) => {
            evento.preventDefault();
            elegidas = casillas.filter((x) => x.c.checked).map((x) => x.c.value);
            guardarElegidas(documento, elegidas);
            detalles.open = false;
            pintar();
        });
        zonaElegir.append(formulario);
    };

    const detalles = crear('details', { clase: 'elegir-estadisticas' }, crear('summary', { texto: 'Elegir mis 4 estadísticas', atributos: { 'data-prueba': 'elegir-estadisticas' } }), zonaElegir);
    detalles.addEventListener('toggle', () => { if (detalles.open) formularioElegir(); });

    cargando(cuerpo);
    resumen
        .then((e) => {
            disponibles = indicadores(e);
            elegidas = leerElegidas(documento);
            pintar();
        })
        .catch((error) => {
            vaciar(cuerpo);
            cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });

    return crear('section', { clase: 'tarjeta panel-central', atributos: { 'aria-labelledby': 'titulo-estadisticas-relevantes', id: 'estadisticas-relevantes' } },
        crear('h2', { texto: 'Estadísticas relevantes', atributos: { id: 'titulo-estadisticas-relevantes' } }),
        cuerpo, detalles);
}
