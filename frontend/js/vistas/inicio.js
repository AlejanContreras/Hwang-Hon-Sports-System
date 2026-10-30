// Inicio del ámbito administrativo (dashboard base).
// Estructura según el boceto de John (12/10/2026), PROPUESTA aún sin validar con Aldrin:
// bienvenida, cuatro tarjetas, panel central, calendario lateral y accesos rápidos.
import { asistencia, competencias, pagos, deportistas } from '../api.js';
import { crear, vaciar, cargando, textoFecha, textoFechaLarga, anioActualBogota, hoyBogota } from '../ui.js';
import { modulosPara } from '../modulos.js';
import { proximasFechas } from './calendario.js';

const formatoFechaHora = new Intl.DateTimeFormat('es-CO', {
    timeZone: 'America/Bogota',
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    hour: 'numeric', minute: '2-digit',
});

export function mostrarInicio(contenedor, sesion) {
    contenedor.append(
        encabezado(sesion),
        crear('div', { clase: 'tarjetas-inicio' },
            tarjeta('Asistencia', cargarAsistencia),
            tarjeta('Próximo torneo', cargarProximoTorneo),
            tarjeta(`Deudores ${anioActualBogota()}`, cargarDeudores),
            tarjeta('Pendientes de validación', cargarPendientes),
        ),
        crear('div', { clase: 'rejilla-inicio' },
            panelEventoActivo(),
            calendarioLateral(),
        ),
        accesosRapidos(sesion),
    );
}

// ---------------------------------------------------------------------------
// «Evento activo»: el próximo torneo con sus inscritos; si no hay, la próxima fecha del calendario.
// ---------------------------------------------------------------------------
function diasHasta(fecha) {
    const [a, m, d] = fecha.split('-').map(Number);
    const [ah, mh, dh] = hoyBogota().split('-').map(Number);
    return Math.round((Date.UTC(a, m - 1, d) - Date.UTC(ah, mh - 1, dh)) / 86400000);
}

async function contenidoEventoActivo() {
    const torneo = await competencias.proximo();
    if (torneo) {
        const detalle = await competencias.consultar(torneo.evento_id);
        const faltan = diasHasta(torneo.fecha);
        const inscritos = detalle.participantes;
        return [
            crear('p', { clase: 'dato-destacado' }, crear('a', { texto: torneo.nombre, atributos: { href: `#torneo/${torneo.evento_id}` } })),
            crear('p', { clase: 'ayuda', texto: `${textoFechaLarga(torneo.fecha)} · ${torneo.lugar ?? ''} · ${faltan === 0 ? 'es hoy' : `faltan ${faltan} días`}` }),
            inscritos.length
                ? crear('ul', { clase: 'lista-proximas' }, ...inscritos.slice(0, 8).map((p) => crear('li', {},
                    crear('span', { clase: 'fecha-proxima', texto: p.categoria_peso || '' }),
                    crear('span', { texto: `${p.nombre} · ${p.modalidad.replace('_', ' ')}` }))))
                : crear('p', { texto: 'Aún no hay inscritos.' }),
            inscritos.length > 8 ? crear('p', { clase: 'ayuda', texto: `y ${inscritos.length - 8} más.` }) : null,
        ].filter(Boolean);
    }
    return [crear('p', { texto: 'No hay torneos próximos. Revisa las próximas fechas en el calendario.' })];
}

function panelEventoActivo() {
    const cuerpo = crear('div');
    cargando(cuerpo);
    contenidoEventoActivo()
        .then((contenido) => {
            vaciar(cuerpo);
            cuerpo.append(...contenido);
        })
        .catch((error) => {
            vaciar(cuerpo);
            cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    return crear('section', { clase: 'tarjeta panel-central', atributos: { 'aria-labelledby': 'titulo-panel-central' } },
        crear('h2', { texto: 'Evento activo: próximo torneo', atributos: { id: 'titulo-panel-central' } }),
        cuerpo);
}

// ---------------------------------------------------------------------------
// «Próximas fechas»: los próximos 30 días del calendario (con su propio error)
// ---------------------------------------------------------------------------
function calendarioLateral() {
    const cuerpo = crear('div');
    cargando(cuerpo);
    proximasFechas()
        .then((contenido) => {
            vaciar(cuerpo);
            cuerpo.append(...contenido);
        })
        .catch((error) => {
            vaciar(cuerpo);
            cuerpo.append(crear('p', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    return crear('section', { clase: 'tarjeta calendario-lateral', atributos: { 'aria-labelledby': 'titulo-calendario-lateral' } },
        crear('h2', { texto: 'Próximas fechas', atributos: { id: 'titulo-calendario-lateral' } }),
        cuerpo);
}

// ---------------------------------------------------------------------------
// Bienvenida con fecha y hora de Bogotá (se actualiza cada 30 s mientras la vista esté abierta)
// ---------------------------------------------------------------------------
function encabezado(sesion) {
    const reloj = crear('p', { clase: 'reloj', texto: formatoFechaHora.format(new Date()) });
    const temporizador = setInterval(() => {
        if (!reloj.isConnected) {
            clearInterval(temporizador);
            return;
        }
        reloj.textContent = formatoFechaHora.format(new Date());
    }, 30000);
    return crear('div', { clase: 'encabezado-vista encabezado-inicio' },
        crear('h1', { texto: `Hola, ${sesion ? sesion.nombre : ''}` }),
        reloj,
    );
}

// ---------------------------------------------------------------------------
// Tarjetas: cada una carga y maneja su error por separado
// ---------------------------------------------------------------------------
function tarjeta(titulo, cargar) {
    const cuerpo = crear('div', { clase: 'cuerpo-tarjeta' });
    const id = `tarjeta-${titulo.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    const nodo = crear('section', { clase: 'tarjeta tarjeta-dato', atributos: { 'aria-labelledby': id } },
        crear('h2', { texto: titulo, atributos: { id } }),
        cuerpo,
    );
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
    return nodo;
}

async function cargarAsistencia() {
    const resumen = await asistencia.resumenDia();
    if (resumen.fecha === null) return [crear('p', { texto: 'Todavía no se ha tomado asistencia.' })];
    return [
        crear('p', { clase: 'cifra', texto: `${resumen.presentes} de ${resumen.total}` }),
        crear('p', { clase: 'ayuda' }, resumen.es_hoy ? 'presentes hoy · ' : 'presentes el ',
            crear('a', { texto: resumen.es_hoy ? 'ver' : textoFechaLarga(resumen.fecha), atributos: { href: `#asistencia/${resumen.fecha}` } })),
    ];
}

async function cargarProximoTorneo() {
    const torneo = await competencias.proximo();
    if (torneo === null) return [crear('p', { texto: 'No hay torneos próximos.' })];
    return [
        crear('p', { clase: 'dato-destacado' }, crear('a', { texto: torneo.nombre, atributos: { href: `#torneo/${torneo.evento_id}` } })),
        crear('p', { clase: 'ayuda', texto: `${textoFecha(torneo.fecha)}${torneo.lugar ? ` · ${torneo.lugar}` : ''}` }),
        crear('p', { clase: 'ayuda', texto: torneo.num_participantes === 1 ? '1 inscrito' : `${torneo.num_participantes} inscritos` }),
    ];
}

async function cargarDeudores() {
    const lista = await pagos.deudores();
    return [
        crear('p', { clase: 'cifra', texto: String(lista.length) }),
        crear('p', { clase: 'ayuda' }, crear('a', {
            texto: lista.length === 1 ? 'deportista con deuda' : 'deportistas con deuda',
            atributos: { href: '#pagos' },
        })),
    ];
}

async function cargarPendientes() {
    const lista = await deportistas.listar({ estado_registro: 'pendiente_validacion' });
    return [
        crear('p', { clase: 'cifra', texto: String(lista.length) }),
        crear('p', { clase: 'ayuda' }, crear('a', {
            texto: lista.length === 1 ? 'registro por validar' : 'registros por validar',
            atributos: { href: '#pendientes' },
        })),
    ];
}

// ---------------------------------------------------------------------------
// Accesos rápidos a los diez módulos (los no construidos, deshabilitados)
// ---------------------------------------------------------------------------
function accesosRapidos(sesion) {
    return crear('nav', { clase: 'accesos-rapidos', atributos: { 'aria-labelledby': 'titulo-accesos' } },
        crear('h2', { clase: 'solo-lectores', texto: 'Accesos rápidos', atributos: { id: 'titulo-accesos' } }),
        crear('ul', {}, ...modulosPara(sesion).map((modulo) => crear('li', {}, modulo.ruta
            ? crear('a', { clase: 'acceso', texto: modulo.texto, atributos: { href: modulo.ruta } })
            : crear('a', { clase: 'acceso deshabilitado', atributos: { role: 'link', 'aria-disabled': 'true' } },
                modulo.texto, crear('span', { clase: 'proximamente', texto: 'Próximamente' }))))),
    );
}
