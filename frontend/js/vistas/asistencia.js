// Módulo Asistencia (ámbito administrativo) — catálogo B.7 y C.3.
import { asistencia } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoFecha, textoFechaLarga, ultimoFinDeSemana, insignia,
} from '../ui.js';
import { selectorDeportista } from './comunes.js';

const FORMATO_FECHA = /^\d{4}-\d{2}-\d{2}$/;

// Tarjeta del resumen: se refresca después de tomar o eliminar una fecha.
let zonaResumenActual = null;
// Nombres de la última pantalla cargada: revisarInactivos solo devuelve documentos.
const nombresConocidos = new Map();

function enlaceFicha(documento, texto) {
    return crear('a', { texto, atributos: { href: `#deportista/${encodeURIComponent(documento)}` } });
}

function aviso() {
    return crear('div', { atributos: { hidden: true } });
}

export async function mostrarAsistencia(contenedor, fechaRuta) {
    const fecha = fechaRuta || ultimoFinDeSemana();
    const avisoGeneral = aviso();
    const zonaResumen = crear('div');
    zonaResumenActual = zonaResumen;
    const zonaToma = crear('section', { atributos: { 'aria-labelledby': 'titulo-toma' } });

    const botonRevisar = crear('button', { clase: 'boton boton-secundario', texto: 'Revisar inactivos', atributos: { type: 'button' } });
    botonRevisar.addEventListener('click', () => revisarInactivos(botonRevisar, avisoGeneral, () => cargarToma(zonaToma, fecha)));

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Asistencia' }), botonRevisar),
        avisoGeneral,
        crear('div', { clase: 'rejilla-tarjetas' }, zonaResumen, formularioFecha(fecha)),
        zonaToma,
        seccionPorcentaje(),
    );

    cargarResumen(zonaResumen);
    if (!FORMATO_FECHA.test(fecha)) {
        zonaToma.append(crear('div', { clase: 'aviso aviso-error', texto: 'La fecha de la ruta debe tener el formato AAAA-MM-DD.', atributos: { role: 'alert' } }));
        return;
    }
    await cargarToma(zonaToma, fecha);
}

// ===========================================================================
// Resumen del día y selección de fecha
// ===========================================================================
async function cargarResumen(zona) {
    cargando(zona);
    let resumen;
    try {
        resumen = await asistencia.resumenDia();
    } catch (error) {
        vaciar(zona);
        zona.append(crear('div', { clase: 'tarjeta' }, crear('h2', { texto: 'Resumen' }), crear('p', { clase: 'aviso aviso-error', texto: error.message })));
        return;
    }
    vaciar(zona);
    const tarjeta = crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-resumen' } },
        crear('h2', { texto: resumen.es_hoy ? 'Asistencia de hoy' : 'Última asistencia tomada', atributos: { id: 'titulo-resumen' } }));
    if (resumen.fecha === null) {
        tarjeta.append(crear('p', { texto: 'Todavía no se ha tomado asistencia.' }));
    } else {
        tarjeta.append(
            crear('p', { clase: 'cifra' }, `${resumen.presentes} de ${resumen.total}`),
            crear('p', { clase: 'ayuda' }, 'presentes el ', crear('a', {
                texto: textoFechaLarga(resumen.fecha), atributos: { href: `#asistencia/${resumen.fecha}` },
            })),
        );
    }
    zona.append(tarjeta);
}

function formularioFecha(fecha) {
    const { envoltura, control } = campo({
        etiqueta: 'Fecha de la sesión', nombre: 'fecha', tipo: 'date', valor: FORMATO_FECHA.test(fecha) ? fecha : '',
        ayuda: 'Solo sábados y domingos, de hoy hacia atrás (hora de Bogotá).',
    });
    const formulario = crear('form', { clase: 'tarjeta', atributos: { 'aria-label': 'Elegir fecha' } },
        crear('h2', { texto: 'Elegir fecha' }),
        envoltura,
        crear('div', { clase: 'acciones' },
            crear('button', { clase: 'boton', texto: 'Ver fecha', atributos: { type: 'submit' } }),
            crear('a', { clase: 'boton boton-secundario', texto: 'Último fin de semana', atributos: { href: `#asistencia/${ultimoFinDeSemana()}` } }),
        ),
    );
    formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        if (control.value) window.location.assign(`#asistencia/${control.value}`);
        else control.focus();
    });
    return formulario;
}

// ===========================================================================
// Toma / corrección de una fecha
// ===========================================================================
async function cargarToma(zona, fecha, avisoPrevio = null) {
    cargando(zona, 'Cargando la lista de la fecha…');
    let pantalla;
    try {
        pantalla = await asistencia.listarPantalla(fecha);
    } catch (error) {
        vaciar(zona);
        zona.append(
            crear('h2', { texto: `Sesión del ${textoFecha(fecha)}`, atributos: { id: 'titulo-toma' } }),
            crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }),
        );
        return;
    }
    for (const d of [...pantalla.activos, ...pantalla.inactivos]) nombresConocidos.set(d.documento, d.nombre);
    vaciar(zona);
    zona.append(crear('h2', { texto: `Sesión del ${textoFechaLarga(pantalla.fecha)}`, atributos: { id: 'titulo-toma' } }));
    if (avisoPrevio) zona.append(avisoPrevio);
    if (pantalla.tomada) pintarCorreccion(zona, pantalla);
    else pintarToma(zona, pantalla);
}

function textoSalud(deportista) {
    return deportista.discapacidad
        ? crear('span', { clase: 'salud', texto: `Condición de salud: ${deportista.discapacidad}` })
        : null;
}

function pintarToma(zona, pantalla) {
    const avisoToma = aviso();
    const casillas = [];
    const contador = crear('p', { clase: 'ayuda', atributos: { 'aria-live': 'polite' } });
    const actualizarContador = () => {
        const marcados = casillas.filter((c) => c.checked).length;
        contador.textContent = marcados === 1 ? '1 presente marcado' : `${marcados} presentes marcados`;
    };

    const grupo = (titulo, lista, ayuda) => {
        const conjunto = crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: `${titulo} (${lista.length})` }));
        if (ayuda) conjunto.append(crear('p', { clase: 'ayuda', texto: ayuda }));
        if (lista.length === 0) conjunto.append(crear('p', { texto: 'Ninguno.' }));
        const listaNodo = crear('ul', { clase: 'lista-marcas' });
        for (const d of lista) {
            const id = `presente-${d.documento}`;
            const casilla = crear('input', { atributos: { type: 'checkbox', id, value: d.documento } });
            casilla.addEventListener('change', actualizarContador);
            casillas.push(casilla);
            listaNodo.append(crear('li', {},
                casilla,
                crear('label', { atributos: { for: id } }, crear('strong', { texto: d.nombre }), ` · ${d.documento}`),
                textoSalud(d),
            ));
        }
        conjunto.append(listaNodo);
        return conjunto;
    };

    const marcarActivos = (valor) => {
        for (const c of casillas.slice(0, pantalla.activos.length)) c.checked = valor;
        actualizarContador();
    };
    const botonGuardar = crear('button', { clase: 'boton', texto: 'Guardar asistencia', atributos: { type: 'submit' } });
    const formulario = crear('form', {},
        crear('p', { texto: 'Esta fecha aún no tiene asistencia. Marca a los presentes; los activos sin marcar quedan como ausentes.' }),
        crear('div', { clase: 'acciones', atributos: { style: 'margin: 0 0 1rem' } },
            crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Marcar todos los activos', atributos: { type: 'button' }, eventos: { click: () => marcarActivos(true) } }),
            crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Desmarcar todos', atributos: { type: 'button' }, eventos: { click: () => { for (const c of casillas) c.checked = false; actualizarContador(); } } }),
        ),
        grupo('Activos', pantalla.activos),
        grupo('Inactivos', pantalla.inactivos, 'Un inactivo marcado presente se reactiva automáticamente.'),
        avisoToma,
        contador,
        crear('div', { clase: 'acciones' }, botonGuardar),
    );
    actualizarContador();

    const nombres = new Map([...pantalla.activos, ...pantalla.inactivos].map((d) => [d.documento, d.nombre]));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(avisoToma);
        const presentes = casillas.filter((c) => c.checked).map((c) => c.value);
        try {
            const resultado = await conBotonOcupado(botonGuardar, 'Guardando…', () => asistencia.tomar(pantalla.fecha, presentes));
            const partes = [`Asistencia guardada: ${resultado.presentes} presentes de ${resultado.registrados} registrados.`];
            if (resultado.reactivados.length > 0) {
                partes.push(`Se reactivaron por venir presentes: ${resultado.reactivados.map((doc) => `${nombres.get(doc) || doc} (${doc})`).join(', ')}.`);
            }
            const nota = crear('div');
            mostrarAviso(nota, partes.join(' '), resultado.reactivados.length > 0 ? 'advertencia' : 'exito');
            await cargarToma(zona, pantalla.fecha, nota);
            cargarResumen(zonaResumenActual);
        } catch (error) {
            if (error.estado === 409) {
                // Ya la tomó otra persona: no se ofrece volver a tomarla, se pasa a corregir.
                const nota = crear('div');
                mostrarAviso(nota, error.message, 'advertencia');
                await cargarToma(zona, pantalla.fecha, nota);
                return;
            }
            mostrarAviso(avisoToma, error.message, 'error');
        }
    });
    zona.append(formulario);
}

function pintarCorreccion(zona, pantalla) {
    const avisoCorreccion = aviso();
    const recargar = (nota = null) => cargarToma(zona, pantalla.fecha, nota);

    const fila = (d, grupo) => {
        const celdaMarca = crear('td', {}, d.asistio === true
            ? insignia('Presente', 'activo')
            : d.asistio === false ? insignia('Ausente', 'inactivo') : crear('span', { clase: 'ayuda', texto: 'Sin marca' }));
        const acciones = crear('td', { clase: 'acciones-celda' });
        if (d.asistio !== null) {
            const nuevoValor = !d.asistio;
            const botonCambiar = crear('button', {
                clase: 'boton boton-secundario boton-pequeno',
                texto: nuevoValor ? 'Marcar presente' : 'Marcar ausente',
                atributos: { type: 'button', 'aria-label': `${nuevoValor ? 'Marcar presente' : 'Marcar ausente'} a ${d.nombre}` },
            });
            botonCambiar.addEventListener('click', async () => {
                limpiarAviso(avisoCorreccion);
                try {
                    const marca = await conBotonOcupado(botonCambiar, 'Guardando…', () => asistencia.corregir(d.documento, pantalla.fecha, nuevoValor));
                    notificar(marca.reactivado ? `${d.nombre}: presente. Estaba inactivo y se reactivó.` : `${d.nombre}: ${marca.asistio ? 'presente' : 'ausente'}.`);
                    recargar();
                } catch (error) {
                    mostrarAviso(avisoCorreccion, error.message, 'error');
                }
            });
            const botonEliminar = crear('button', {
                clase: 'boton boton-peligro boton-pequeno', texto: 'Eliminar marca',
                atributos: { type: 'button', 'aria-label': `Eliminar la marca de ${d.nombre}` },
            });
            botonEliminar.addEventListener('click', async () => {
                const aceptar = await confirmar({
                    titulo: 'Eliminar marca',
                    mensaje: [`Se eliminará la marca de ${d.nombre} del ${textoFecha(pantalla.fecha)}.`,
                        'Si la marca lo había reactivado, seguirá activo.'],
                    textoAceptar: 'Eliminar marca',
                    peligroso: true,
                });
                if (!aceptar) return;
                try {
                    await asistencia.eliminarMarca(d.documento, pantalla.fecha);
                    notificar(`Marca de ${d.nombre} eliminada.`);
                    recargar();
                } catch (error) {
                    mostrarAviso(avisoCorreccion, error.message, 'error');
                }
            });
            acciones.append(botonCambiar, botonEliminar);
        }
        return crear('tr', {},
            crear('td', {}, enlaceFicha(d.documento, d.nombre), crear('br'), crear('span', { clase: 'ayuda', texto: d.documento })),
            crear('td', { texto: d.discapacidad || '—' }),
            crear('td', { texto: grupo }),
            celdaMarca,
            acciones,
        );
    };

    const cuerpo = crear('tbody',
        {},
        ...pantalla.activos.map((d) => fila(d, 'Activo')),
        ...pantalla.inactivos.map((d) => fila(d, 'Inactivo')),
    );
    const presentes = [...pantalla.activos, ...pantalla.inactivos].filter((d) => d.asistio === true).length;

    const botonEliminarFecha = crear('button', { clase: 'boton boton-peligro', texto: 'Eliminar toda la fecha', atributos: { type: 'button' } });
    botonEliminarFecha.addEventListener('click', async () => {
        const aceptar = await confirmar({
            titulo: 'Eliminar toda la asistencia de la fecha',
            mensaje: [`Se borrarán TODAS las marcas del ${textoFechaLarga(pantalla.fecha)} (presentes y ausentes).`,
                'Úsalo solo si la fecha se tomó por error. Las reactivaciones no se revierten. No se puede deshacer.'],
            textoAceptar: 'Eliminar la fecha',
            peligroso: true,
            textoConfirmacion: pantalla.fecha,
        });
        if (!aceptar) return;
        try {
            const resultado = await conBotonOcupado(botonEliminarFecha, 'Eliminando…', () => asistencia.eliminarFecha(pantalla.fecha));
            const nota = crear('div');
            mostrarAviso(nota, `Se eliminaron ${resultado.eliminadas} marcas del ${textoFecha(resultado.fecha)}. La fecha se puede tomar de nuevo.`, 'exito');
            await recargar(nota);
            cargarResumen(zonaResumenActual);
        } catch (error) {
            mostrarAviso(avisoCorreccion, error.message, 'error');
        }
    });

    zona.append(
        crear('div', { clase: 'aviso aviso-info', texto: 'Esta fecha ya fue tomada: no se puede volver a tomar. Corrige o elimina marca por marca.' }),
        crear('p', { clase: 'ayuda', texto: `${presentes} presentes. «Sin marca»: el deportista no estaba activo o aún no había ingresado ese día.` }),
        avisoCorreccion,
        crear('div', { clase: 'contenedor-tabla' },
            crear('table', {},
                crear('caption', { clase: 'solo-lectores', texto: `Marcas del ${textoFecha(pantalla.fecha)}` }),
                crear('thead', {}, crear('tr', {},
                    ...['Deportista', 'Condición de salud', 'Grupo', 'Marca', 'Acciones'].map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
                cuerpo,
            ),
        ),
        crear('div', { clase: 'acciones' }, botonEliminarFecha),
    );
}

// ===========================================================================
// Revisar inactivos
// ===========================================================================
async function revisarInactivos(boton, avisoGeneral, alTerminar) {
    const aceptar = await confirmar({
        titulo: 'Revisar inactivos',
        mensaje: ['Pasa a INACTIVO a cada deportista activo que lleva 3 meses o más sin asistir (desde su última asistencia, o desde su ingreso si nunca asistió).',
            'La inactividad no se aplica sola: solo ocurre al pulsar este botón. Si después viene y se marca presente, se reactiva.'],
        textoAceptar: 'Revisar ahora',
    });
    if (!aceptar) return;
    try {
        const resultado = await conBotonOcupado(boton, 'Revisando…', () => asistencia.revisarInactivos());
        vaciar(avisoGeneral);
        avisoGeneral.hidden = false;
        avisoGeneral.className = 'aviso aviso-info';
        avisoGeneral.setAttribute('role', 'status');
        avisoGeneral.append(crear('p', { texto: `Revisión hecha con fecha límite ${textoFecha(resultado.fecha_limite)}.` }));
        if (resultado.desactivados.length === 0) {
            avisoGeneral.append(crear('p', { texto: 'Nadie pasó a inactivo.' }));
        } else {
            avisoGeneral.append(
                crear('p', { texto: `Pasaron a inactivo (${resultado.desactivados.length}):` }),
                crear('ul', {}, ...resultado.desactivados.map((doc) => crear('li', {},
                    enlaceFicha(doc, nombresConocidos.has(doc) ? `${nombresConocidos.get(doc)} (${doc})` : doc)))),
            );
        }
        alTerminar();
    } catch (error) {
        mostrarAviso(avisoGeneral, error.message, 'error');
    }
}

// ===========================================================================
// Porcentaje por deportista
// ===========================================================================

// Pinta el resultado de asistencia.porcentaje. documento null = el propio (ámbito público).
export async function pintarPorcentaje(resultado, documento, ventana, conEnlace = false) {
    cargando(resultado);
    try {
        const datos = await asistencia.porcentaje(documento, ventana);
        vaciar(resultado);
        resultado.append(
            crear('p', { clase: 'cifra' }, datos.datos_incompletos || datos.porcentaje === null
                ? 'Datos insuficientes'
                : `${Number(datos.porcentaje).toLocaleString('es-CO')} %`),
            crear('p', { clase: 'ayuda' },
                conEnlace ? [enlaceFicha(datos.documento, datos.documento), ' · '] : null,
                `${datos.presentes} presente(s) en ${datos.sesiones} sesión(es) desde el ${textoFecha(datos.desde)}`,
                datos.datos_incompletos ? ' · se necesitan al menos 4 sesiones para calcular el porcentaje.' : '.'),
        );
    } catch (error) {
        vaciar(resultado);
        resultado.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
}

// Selector de ventana (30, 90 o todo) con valor por defecto 90.
export function campoVentana() {
    return campo({
        etiqueta: 'Ventana', nombre: 'ventana', tipo: 'select', valor: '90',
        opciones: [{ valor: '30', texto: 'Últimos 30 días' }, { valor: '90', texto: 'Últimos 90 días' }, { valor: 'todo', texto: 'Todo' }],
    });
}
function seccionPorcentaje() {
    const resultado = crear('div', { atributos: { 'aria-live': 'polite' } });
    const ventana = campoVentana();
    let documentoActual = null;

    const consultar = () => {
        if (documentoActual) pintarPorcentaje(resultado, documentoActual, ventana.control.value, true);
    };
    ventana.control.addEventListener('change', consultar);

    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-porcentaje' } },
        crear('h2', { texto: 'Porcentaje de asistencia por deportista', atributos: { id: 'titulo-porcentaje' } }),
        crear('div', { clase: 'filtros' },
            selectorDeportista({
                textoBoton: 'Calcular',
                alElegir: (documento) => {
                    documentoActual = documento;
                    consultar();
                },
            }),
            ventana.envoltura,
        ),
        resultado,
    );
}
