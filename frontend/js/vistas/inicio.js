// Inicio del ámbito administrativo (dashboard), según el boceto de John (12/10/2026) y sus
// precisiones del 30/10: cuatro tarjetas (Grupo torneo, Próximo torneo, Asistencia, Historial),
// franja de avisos, panel «Evento activo» (Torneo / Examen, TOP 3), «Próximas fechas» y los 10
// accesos rápidos.
import { asistencia, competencias, pagos, deportistas, historial, evaluaciones, estadisticas } from '../api.js';
import { crear, vaciar, cargando, textoFecha, textoFechaLarga, hoyBogota, insignia } from '../ui.js';
import { MODULOS_ACCESO } from '../modulos.js';
import { proximasFechas } from './calendario.js';
import { saludo, tarjetaDato, chipGrado, accesosRapidos } from '../componentes/tablero.js';

const MEDALLAS = { oro: 'Oro', plata: 'Plata', bronce: 'Bronce' };

export function mostrarInicio(contenedor, sesion) {
    // El próximo torneo lo usan dos tarjetas: una sola llamada.
    const proximo = competencias.proximo();
    contenedor.append(
        saludo(sesion?.nombre),
        crear('div', { clase: 'tarjetas-inicio' },
            tarjetaDato({ id: 'tarjeta-grupo-torneo', titulo: 'Grupo torneo', icono: 'circle-user-round', cargar: () => cargarGrupoTorneo(proximo) }),
            tarjetaDato({ id: 'tarjeta-proximo-torneo', titulo: 'Próximo torneo', icono: 'medal', cargar: () => cargarProximoTorneo(proximo) }),
            tarjetaDato({ id: 'tarjeta-asistencia', titulo: 'Asistencia', icono: 'calendar-check', cargar: cargarAsistencia }),
            tarjetaDato({ id: 'tarjeta-historial', titulo: 'Historial', icono: 'history', cargar: cargarHistorial }),
        ),
        franjaAvisos(),
        crear('div', { clase: 'rejilla-inicio' }, panelEventoActivo(), calendarioLateral()),
        accesosRapidos(MODULOS_ACCESO),
    );
}

// ---------------------------------------------------------------------------
// Tarjetas
// ---------------------------------------------------------------------------
async function cargarGrupoTorneo(proximo) {
    const torneo = await proximo;
    if (!torneo) return { nodos: [crear('p', { clase: 'cifra', texto: '0' }), crear('p', { clase: 'ayuda', texto: 'Sin torneo próximo.' })] };
    return {
        nodos: [
            crear('p', { clase: 'cifra', texto: String(torneo.num_participantes) }),
            crear('p', { clase: 'ayuda', texto: torneo.num_participantes === 1 ? 'deportista inscrito' : 'deportistas inscritos' }),
        ],
        enlace: `#torneo/${torneo.evento_id}`,
    };
}

async function cargarProximoTorneo(proximo) {
    const torneo = await proximo;
    if (!torneo) return { nodos: [crear('p', { texto: 'No hay torneos próximos.' })] };
    return {
        nodos: [
            crear('p', { clase: 'dato-destacado', texto: torneo.nombre }),
            crear('p', { clase: 'ayuda', texto: `${textoFecha(torneo.fecha)}${torneo.lugar ? ` · ${torneo.lugar}` : ''}` }),
        ],
        enlace: `#torneo/${torneo.evento_id}`,
    };
}

async function cargarAsistencia() {
    const resumen = await asistencia.resumenDia();
    if (resumen.fecha === null) return { nodos: [crear('p', { texto: 'Todavía no se ha tomado asistencia.' })], enlace: '#asistencia' };
    return {
        nodos: [
            crear('p', { clase: 'cifra', texto: `${resumen.presentes} de ${resumen.total}` }),
            crear('p', { clase: 'ayuda', texto: resumen.es_hoy ? 'presentes hoy' : `presentes el ${textoFechaLarga(resumen.fecha)}` }),
        ],
        enlace: `#asistencia/${resumen.fecha}`,
    };
}

// Último torneo realizado y último ascenso (sesión cerrada), del historial.
async function cargarHistorial() {
    const [torneos, ascensos] = await Promise.all([historial.torneos(), historial.ascensos()]);
    const torneo = torneos[0];
    const ascenso = [...ascensos].sort((a, b) => (b.fecha_grado || '').localeCompare(a.fecha_grado || ''))[0];
    const nodos = [];
    nodos.push(torneo
        ? crear('p', {}, crear('span', { clase: 'ayuda', texto: 'Último torneo: ' }), crear('strong', { texto: torneo.torneo }), crear('span', { clase: 'ayuda', texto: ` · ${textoFecha(torneo.fecha)}` }))
        : crear('p', { clase: 'ayuda', texto: 'Sin torneos realizados.' }));
    nodos.push(ascenso
        ? crear('p', {}, crear('span', { clase: 'ayuda', texto: 'Último ascenso: ' }), crear('strong', { texto: ascenso.grado_evaluado }), crear('span', { clase: 'ayuda', texto: ` · ${textoFecha(ascenso.fecha_grado)}` }))
        : crear('p', { clase: 'ayuda', texto: 'Sin ascensos registrados.' }));
    return { nodos, enlace: '#historial' };
}

// Franja de avisos: solo aparece si hay registros por validar o deudores.
function franjaAvisos() {
    const franja = crear('div', { clase: 'franja-avisos', atributos: { id: 'franja-avisos', hidden: true, role: 'status' } });
    Promise.allSettled([deportistas.listar({ estado_registro: 'pendiente_validacion' }), pagos.deudores()]).then(([pendientes, deudores]) => {
        const avisos = [];
        if (pendientes.status === 'fulfilled' && pendientes.value.length) {
            const n = pendientes.value.length;
            avisos.push(crear('a', { clase: 'aviso-franja', texto: `${n} ${n === 1 ? 'registro' : 'registros'} por validar`, atributos: { href: '#pendientes', 'data-prueba': 'aviso-pendientes' } }));
        }
        if (deudores.status === 'fulfilled' && deudores.value.length) {
            const n = deudores.value.length;
            avisos.push(crear('a', { clase: 'aviso-franja', texto: `${n} ${n === 1 ? 'deportista' : 'deportistas'} con deuda`, atributos: { href: '#pagos', 'data-prueba': 'aviso-deudores' } }));
        }
        if (avisos.length) {
            franja.append(...avisos);
            franja.hidden = false;
        }
    });
    return franja;
}

// ---------------------------------------------------------------------------
// Panel «Evento activo»: Torneo / Examen, TOP 3 con el chip del cinturón
// ---------------------------------------------------------------------------
async function mapaDeGrados() {
    const lista = await deportistas.listar();
    return new Map(lista.map((d) => [d.documento, d.grado_actual]));
}

function filaTop(posicion, nombre, grado, dato) {
    return crear('li', { clase: 'fila-top' },
        crear('span', { clase: 'puesto-top', texto: String(posicion) }),
        crear('div', {}, crear('strong', { texto: nombre }), crear('br'), chipGrado(grado)),
        crear('span', { clase: 'dato-top' }, ...[].concat(dato)));
}

// Torneo: el último torneo realizado (TOP 3 por puesto); si no hay, el próximo con sus inscritos.
async function contenidoTorneo() {
    const hoy = hoyBogota();
    const [lista, grados] = await Promise.all([competencias.listar(), mapaDeGrados()]);
    const realizado = lista.find((t) => t.fecha <= hoy);
    if (realizado) {
        const t = await estadisticas.torneo(realizado.evento_id);
        const conPuesto = t.participantes.filter((p) => p.puesto).sort((a, b) => a.puesto - b.puesto).slice(0, 3);
        const encabezado = crear('p', {}, crear('a', { texto: t.nombre, atributos: { href: `#torneo/${t.evento_id}` } }), crear('span', { clase: 'ayuda', texto: ` · ${textoFecha(t.fecha)} · torneo realizado` }));
        if (!conPuesto.length) return [encabezado, crear('p', { texto: 'El torneo aún no tiene puestos registrados.' })];
        return [encabezado, crear('ol', { clase: 'lista-top' }, ...conPuesto.map((p, i) => filaTop(i + 1, p.nombre, grados.get(p.documento),
            [`Puesto ${p.puesto}`, MEDALLAS[p.medalla] ? ` · ${MEDALLAS[p.medalla]}` : ''])))];
    }
    const proximo = lista.filter((t) => t.fecha >= hoy).sort((a, b) => a.fecha.localeCompare(b.fecha))[0];
    if (!proximo) return [crear('p', { texto: 'No hay torneos registrados.' })];
    const detalle = await competencias.consultar(proximo.evento_id);
    return [
        crear('p', {}, crear('a', { texto: detalle.nombre, atributos: { href: `#torneo/${detalle.evento_id}` } }), crear('span', { clase: 'ayuda', texto: ` · ${textoFecha(detalle.fecha)}` })),
        crear('p', { clase: 'ayuda', texto: 'Sin resultados todavía: estos son los inscritos.' }),
        crear('ul', { clase: 'lista-top' }, ...detalle.participantes.slice(0, 3).map((p, i) => filaTop(i + 1, p.nombre, grados.get(p.deportista_documento), p.modalidad.replace('_', ' ')))),
    ];
}

// Examen: la sesión abierta más reciente; si no hay, la última cerrada. TOP 3 por promedio.
async function contenidoExamen() {
    const sesiones = await evaluaciones.listar();
    const elegida = sesiones.find((s) => s.estado === 'abierta') || sesiones[0];
    if (!elegida) return [crear('p', { texto: 'No hay sesiones de examen.' })];
    const s = await evaluaciones.consultar(elegida.id);
    const encabezado = crear('p', {}, crear('a', { texto: `Examen del ${textoFecha(s.fecha)}`, atributos: { href: `#ascenso/${s.id}` } }),
        crear('span', { clase: 'ayuda', texto: ` · sesión ${s.estado}` }), ' ', insignia('PROVISIONAL', 'pendiente'));
    const fisico = (p) => p.modalidades.find((m) => m.modalidad === 'fisico' && m.valor_medible !== null);
    const completos = s.participantes.filter((p) => p.resultado_general?.completo)
        .sort((a, b) => b.resultado_general.promedio - a.resultado_general.promedio).slice(0, 3);
    if (completos.length) {
        return [encabezado, crear('ol', { clase: 'lista-top' }, ...completos.map((p, i) => filaTop(i + 1, p.nombre, p.grado_actual, [
            `Promedio ${p.resultado_general.promedio}`,
            fisico(p) ? crear('span', { clase: 'ayuda', texto: ` · prueba física ${fisico(p).valor_medible}` }) : '',
        ])))];
    }
    return [encabezado, crear('p', { clase: 'ayuda', texto: 'Sin resultados todavía: estos son los inscritos.' }),
        crear('ul', { clase: 'lista-top' }, ...s.participantes.slice(0, 3).map((p, i) => filaTop(i + 1, p.nombre, p.grado_actual, `${p.modalidades.filter((m) => m.estado === 'completado').length}/6 calificadas`)))];
}

function panelEventoActivo() {
    const cuerpo = crear('div', { atributos: { 'aria-live': 'polite' } });
    const opciones = { torneo: contenidoTorneo, examen: contenidoExamen };
    const botones = Object.keys(opciones).map((clave) => crear('button', {
        clase: 'opcion-evento', texto: clave === 'torneo' ? 'Torneo' : 'Examen',
        atributos: { type: 'button', 'aria-pressed': 'false', 'data-evento': clave, 'data-prueba': `evento-${clave}` },
    }));
    const cargar = (clave) => {
        for (const b of botones) b.setAttribute('aria-pressed', String(b.dataset.evento === clave));
        cargando(cuerpo);
        opciones[clave]()
            .then((nodos) => {
                vaciar(cuerpo);
                cuerpo.append(...nodos.filter(Boolean));
            })
            .catch((error) => {
                vaciar(cuerpo);
                cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
            });
    };
    for (const b of botones) b.addEventListener('click', () => cargar(b.dataset.evento));
    cargar('torneo');
    return crear('section', { clase: 'tarjeta panel-central', atributos: { 'aria-labelledby': 'titulo-panel-central', id: 'panel-evento-activo' } },
        crear('div', { clase: 'encabezado-panel' },
            crear('h2', { texto: 'Evento activo · TOP 3', atributos: { id: 'titulo-panel-central' } }),
            crear('div', { clase: 'selector-evento', atributos: { role: 'group', 'aria-label': 'Tipo de evento' } }, ...botones)),
        cuerpo);
}

// ---------------------------------------------------------------------------
// «Próximas fechas»: los próximos 30 días del calendario (con su propio error)
// ---------------------------------------------------------------------------
export function calendarioLateral(cargar = proximasFechas) {
    const cuerpo = crear('div');
    cargando(cuerpo);
    cargar()
        .then((contenido) => {
            vaciar(cuerpo);
            cuerpo.append(...contenido);
        })
        .catch((error) => {
            vaciar(cuerpo);
            cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    return crear('section', { clase: 'tarjeta calendario-lateral', atributos: { 'aria-labelledby': 'titulo-calendario-lateral', id: 'proximas-fechas' } },
        crear('h2', { texto: 'Próximas fechas', atributos: { id: 'titulo-calendario-lateral' } }),
        cuerpo);
}

