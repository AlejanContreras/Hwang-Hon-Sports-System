// Módulo Pagos (ámbito administrativo) — catálogo B.8 y C.4.
import { deportistas, pagos } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoFecha, insignia, nombreMes, anioActualBogota,
} from '../ui.js';
import { selectorDeportista } from './comunes.js';

// Año elegido en el estado de un deportista; se conserva al cambiar de deportista.
let anioElegido = null;

function enlaceFicha(documento, texto) {
    return crear('a', { texto, atributos: { href: `#deportista/${encodeURIComponent(documento)}` } });
}

function enlaceEstado(documento, texto) {
    return crear('a', { texto, atributos: { href: `#pagos/${encodeURIComponent(documento)}` } });
}

function tabla(titulo, encabezados, filas) {
    return crear('div', { clase: 'contenedor-tabla' },
        crear('table', {},
            crear('caption', { clase: 'solo-lectores', texto: titulo }),
            crear('thead', {}, crear('tr', {}, ...encabezados.map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            crear('tbody', {}, ...filas),
        ),
    );
}

export async function mostrarPagos(contenedor, documento) {
    if (documento) await mostrarEstado(contenedor, documento);
    else await mostrarInicio(contenedor);
}

// ===========================================================================
// Inicio: buscar deportista y deudores del año en curso
// ===========================================================================
async function mostrarInicio(contenedor) {
    const zonaDeudores = crear('div');
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Pagos' })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-buscar-pago' } },
            crear('h2', { texto: 'Estado de pagos de un deportista', atributos: { id: 'titulo-buscar-pago' } }),
            selectorDeportista({
                textoBoton: 'Ver estado',
                alElegir: (doc) => window.location.assign(`#pagos/${encodeURIComponent(doc)}`),
            }),
        ),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-deudores' } },
            crear('h2', { texto: `Deudores de ${anioActualBogota()}`, atributos: { id: 'titulo-deudores' } }),
            crear('p', { clase: 'ayuda', texto: 'Deuda = mes vencido, sin pago y con 2 o más asistencias desde el vencimiento. La matrícula no cuenta como deuda.' }),
            zonaDeudores,
        ),
    );

    cargando(zonaDeudores);
    let lista;
    try {
        lista = await pagos.deudores();
    } catch (error) {
        vaciar(zonaDeudores);
        zonaDeudores.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        return;
    }
    vaciar(zonaDeudores);
    if (lista.length === 0) {
        zonaDeudores.append(crear('p', { texto: 'No hay deudores este año.' }));
        return;
    }
    zonaDeudores.append(tabla('Deudores del año en curso', ['Deportista', 'Documento', 'Meses en deuda', 'Acciones'],
        lista.map((d) => crear('tr', {},
            crear('td', {}, enlaceFicha(d.documento, d.nombre)),
            crear('td', { texto: d.documento }),
            crear('td', { texto: d.meses_en_deuda.map(nombreMes).join(', ') }),
            crear('td', {}, enlaceEstado(d.documento, 'Ver estado de pagos')),
        ))));
}

// ===========================================================================
// Estado de un deportista
// ===========================================================================
async function mostrarEstado(contenedor, documento) {
    cargando(contenedor);
    const deportista = await deportistas.consultar(documento);
    vaciar(contenedor);

    const anioActual = anioActualBogota();
    if (!anioElegido) anioElegido = anioActual;
    const pendiente = deportista.estado_registro === 'pendiente_validacion';

    const zonaEstado = crear('div');
    const anios = [];
    for (let anio = anioActual + 1; anio >= 2020; anio -= 1) anios.push({ valor: String(anio), texto: String(anio) });
    const selectorAnio = campo({ etiqueta: 'Año', nombre: 'anio', tipo: 'select', valor: String(anioElegido), opciones: anios });
    selectorAnio.control.addEventListener('change', () => {
        anioElegido = Number(selectorAnio.control.value);
        cargarEstado(zonaEstado, deportista, pendiente);
    });

    // append() escribiría «null» como texto: se filtran los bloques que no aplican.
    contenedor.append(...[
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Pagos', atributos: { href: '#pagos' } })),
        crear('div', { clase: 'encabezado-vista' },
            crear('div', {},
                crear('h1', { texto: `Pagos: ${deportista.nombre}` }),
                crear('p', { clase: 'ayuda' }, `Documento ${deportista.documento} · `, enlaceFicha(deportista.documento, 'ver ficha')),
            ),
            crear('div', { clase: 'campo' }, selectorAnio.envoltura),
        ),
        pendiente
            ? crear('div', {
                clase: 'aviso aviso-advertencia',
                texto: 'Este deportista está pendiente de validación: aún no se le registran pagos. Valídalo primero desde su ficha.',
                atributos: { role: 'status' },
            })
            : null,
        zonaEstado,
    ].filter(Boolean));
    await cargarEstado(zonaEstado, deportista, pendiente);
}

async function cargarEstado(zona, deportista, pendiente, avisoPrevio = null) {
    const anio = anioElegido;
    cargando(zona);
    let estado;
    let historial;
    try {
        [estado, historial] = await Promise.all([
            pagos.estado(deportista.documento, anio),
            pagos.listar({ documento: deportista.documento, anio }),
        ]);
    } catch (error) {
        vaciar(zona);
        zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        return;
    }
    vaciar(zona);
    const recargar = (nota) => cargarEstado(zona, deportista, pendiente, nota);
    if (avisoPrevio) zona.append(avisoPrevio);
    zona.append(...[
        resumenEstado(estado),
        tablaMeses(estado),
        pendiente ? null : formularioRegistro(deportista, estado, recargar),
        seccionHistorial(historial, recargar),
    ].filter(Boolean));
}

export function resumenEstado(estado) {
    const matricula = estado.matricula.pagada
        ? insignia('Pagada', 'activo')
        : estado.matricula.atrasada ? insignia('Atrasada', 'deuda') : insignia('Pendiente', 'vencido');
    const situacion = estado.al_dia
        ? insignia('Al día', 'activo')
        : insignia(`En deuda: ${estado.meses_en_deuda.map(nombreMes).join(', ')}`, 'deuda');
    const porVencer = estado.por_vencer
        ? `${nombreMes(estado.por_vencer.mes)} ${estado.por_vencer.anio}: vence el ${textoFecha(estado.por_vencer.vencimiento)}`
            + (estado.por_vencer.dias_restantes !== undefined ? ` (faltan ${estado.por_vencer.dias_restantes} días)` : '')
        : 'Nada por vencer';

    const lista = crear('dl', { clase: 'lista-datos' });
    for (const [etiqueta, valor] of [
        ['Situación', situacion],
        [`Matrícula ${estado.anio}`, matricula],
        ['Semana habitual de pago', `Semana ${estado.semana_habitual}`],
        ['Próximo vencimiento', porVencer],
    ]) {
        lista.append(crear('div', { clase: 'par' }, crear('dt', { texto: etiqueta }), crear('dd', {}, valor)));
    }
    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-estado' } },
        crear('h2', { texto: `Estado ${estado.anio}`, atributos: { id: 'titulo-estado' } }),
        lista,
        estado.genera_alertas ? null : crear('p', { clase: 'ayuda', texto: 'No genera alertas de pago (solo las generan los deportistas activos y validados).' }),
    );
}

export function tablaMeses(estado) {
    const filas = estado.meses.map((m) => {
        let marca;
        if (m.pagado) marca = insignia('Pagado', 'activo');
        else if (m.en_deuda) marca = insignia('En deuda', 'deuda');
        else if (m.vencido) marca = insignia('Vencido sin pago', 'vencido');
        else marca = insignia('Por pagar', 'inactivo');
        return crear('tr', {},
            crear('th', { texto: nombreMes(m.mes), atributos: { scope: 'row' } }),
            crear('td', {}, marca),
            crear('td', { texto: m.semana ? `Semana ${m.semana}` : '—' }),
            crear('td', { texto: textoFecha(m.vencimiento) }),
        );
    });
    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-meses' } },
        crear('h2', { texto: 'Mensualidades', atributos: { id: 'titulo-meses' } }),
        crear('p', { clase: 'ayuda', texto: '«Vencido sin pago» no es deuda mientras no tenga 2 asistencias después del vencimiento.' }),
        tabla(`Mensualidades de ${estado.anio}`, ['Mes', 'Estado', 'Semana de pago', 'Vencimiento'], filas),
    );
}

// ===========================================================================
// Registrar mensualidades y matrícula
// ===========================================================================
function formularioRegistro(deportista, estado, recargar) {
    const avisoRegistro = crear('div', { atributos: { hidden: true } });
    const pendientes = estado.meses.filter((m) => !m.pagado);
    const filas = [];
    const semanaPorDefecto = String(estado.semana_habitual || 1);

    const lista = crear('ul', { clase: 'lista-marcas' });
    for (const m of pendientes) {
        const id = `pagar-${m.mes}`;
        const casilla = crear('input', { atributos: { type: 'checkbox', id, value: String(m.mes) } });
        const semana = crear('select', { atributos: { 'aria-label': `Semana de pago de ${nombreMes(m.mes)}` } },
            ...[1, 2, 3, 4].map((s) => crear('option', { texto: `Semana ${s}`, atributos: { value: String(s) } })));
        semana.value = semanaPorDefecto;
        filas.push({ mes: m.mes, casilla, semana });
        lista.append(crear('li', { clase: 'fila-pago' },
            casilla,
            crear('label', { atributos: { for: id }, texto: nombreMes(m.mes) }),
            semana,
        ));
    }

    const botonPagar = crear('button', { clase: 'boton', texto: 'Registrar meses marcados', atributos: { type: 'submit' } });
    const formulario = crear('form', {},
        crear('p', { clase: 'ayuda', texto: 'Marca uno o varios meses (se permiten meses adelantados) y la semana del mes en que se pagó cada uno. Si alguno ya está pagado, no se guarda ninguno.' }),
        pendientes.length ? lista : crear('p', { texto: `Todas las mensualidades de ${estado.anio} están pagadas.` }),
        pendientes.length ? crear('div', { clase: 'acciones' }, botonPagar) : null,
    );
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(avisoRegistro);
        const meses = filas.filter((f) => f.casilla.checked).map((f) => ({ mes: f.mes, semana: Number(f.semana.value) }));
        if (meses.length === 0) {
            mostrarAviso(avisoRegistro, 'Marca al menos un mes.', 'error');
            return;
        }
        try {
            const creados = await conBotonOcupado(botonPagar, 'Registrando…', () =>
                pagos.registrarMensualidades(deportista.documento, estado.anio, meses));
            const nota = crear('div');
            mostrarAviso(nota, `Registrado: ${creados.map((p) => nombreMes(p.mes)).join(', ')} de ${estado.anio}.`, 'exito');
            recargar(nota);
        } catch (error) {
            mostrarAviso(avisoRegistro, error.message, 'error');
        }
    });

    const seccion = crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-registrar' } },
        crear('h2', { texto: `Registrar pagos de ${estado.anio}`, atributos: { id: 'titulo-registrar' } }),
        avisoRegistro,
        formulario,
    );

    if (!estado.matricula.pagada) {
        const botonMatricula = crear('button', { clase: 'boton boton-secundario', texto: `Registrar matrícula ${estado.anio}`, atributos: { type: 'button' } });
        botonMatricula.addEventListener('click', async () => {
            const aceptar = await confirmar({
                titulo: 'Registrar matrícula',
                mensaje: `Se registrará la matrícula de ${estado.anio} de ${deportista.nombre}.`,
                textoAceptar: 'Registrar matrícula',
            });
            if (!aceptar) return;
            limpiarAviso(avisoRegistro);
            try {
                await conBotonOcupado(botonMatricula, 'Registrando…', () => pagos.registrarMatricula(deportista.documento, estado.anio));
                const nota = crear('div');
                mostrarAviso(nota, `Matrícula de ${estado.anio} registrada.`, 'exito');
                recargar(nota);
            } catch (error) {
                mostrarAviso(avisoRegistro, error.message, 'error');
            }
        });
        seccion.append(crear('h3', { texto: 'Matrícula' }), crear('div', { clase: 'acciones' }, botonMatricula));
    }
    return seccion;
}

// ===========================================================================
// Historial y anulación (doble confirmación)
// ===========================================================================
function seccionHistorial(historial, recargar) {
    const avisoHistorial = crear('div', { atributos: { hidden: true } });
    const descripcion = (p) => (p.tipo === 'matricula' ? `Matrícula ${p.anio}` : `${nombreMes(p.mes)} ${p.anio}`);

    const filas = historial.map((p) => {
        const boton = crear('button', {
            clase: 'boton boton-peligro boton-pequeno', texto: 'Anular',
            atributos: { type: 'button', 'aria-label': `Anular el pago ${descripcion(p)}` },
        });
        boton.addEventListener('click', async () => {
            const primera = await confirmar({
                titulo: 'Anular pago',
                mensaje: [`Se anulará el pago «${descripcion(p)}».`, 'El mes volverá a quedar sin pagar.'],
                textoAceptar: 'Continuar',
                peligroso: true,
            });
            if (!primera) return;
            const segunda = await confirmar({
                titulo: '¿Seguro?',
                mensaje: `Confirma de nuevo: anular «${descripcion(p)}». No se puede deshacer.`,
                textoAceptar: 'Sí, anular el pago',
                peligroso: true,
            });
            if (!segunda) return;
            try {
                await pagos.anular(p.id);
                notificar(`Pago «${descripcion(p)}» anulado.`);
                recargar();
            } catch (error) {
                mostrarAviso(avisoHistorial, error.message, 'error');
            }
        });
        return crear('tr', {},
            crear('td', { texto: p.tipo === 'matricula' ? 'Matrícula' : 'Mensualidad' }),
            crear('td', { texto: p.tipo === 'matricula' ? '—' : nombreMes(p.mes) }),
            crear('td', { texto: p.semana ? `Semana ${p.semana}` : '—' }),
            crear('td', { clase: 'acciones-celda' }, boton),
        );
    });

    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-historial' } },
        crear('h2', { texto: 'Historial de pagos del año', atributos: { id: 'titulo-historial' } }),
        avisoHistorial,
        historial.length
            ? tabla('Pagos registrados', ['Tipo', 'Mes', 'Semana', 'Acciones'], filas)
            : crear('p', { texto: 'No hay pagos registrados este año.' }),
    );
}
