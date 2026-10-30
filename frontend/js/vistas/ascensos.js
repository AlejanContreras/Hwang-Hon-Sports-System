// Ascensos (exámenes de grado) — catálogo B.11.
// Administrativo: sesiones (abierta/cerrada), candidatos con aviso de elegibilidad, notas por
// modalidad, cierre y reapertura, y la confirmación de la entrega del cinturón.
// Público (Mi perfil): sus exámenes y la confirmación de que recibió su cinturón.
// El resultado es PROVISIONAL (plantilla de Aldrin): promedio de las seis notas, 5 o más aprueba.
import { evaluaciones } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoFecha, textoDato, textoGrado, insignia, GRADOS, normalizarTexto,
} from '../ui.js';

const MODALIDADES = [
    { valor: 'teorico', texto: 'Teórico' },
    { valor: 'fisico', texto: 'Físico' },
    { valor: 'combate', texto: 'Combate' },
    { valor: 'rompimiento', texto: 'Rompimiento' },
    { valor: 'formas', texto: 'Formas' },
    { valor: 'llaves', texto: 'Llaves' },
];
const textoModalidad = (valor) => MODALIDADES.find((m) => m.valor === valor)?.texto ?? valor;
const provisional = () => insignia('PROVISIONAL', 'pendiente');

function avisoNuevo() {
    return crear('div', { atributos: { hidden: true } });
}

function tabla(titulo, encabezados, filas) {
    return crear('div', { clase: 'contenedor-tabla' },
        crear('table', {},
            crear('caption', { clase: 'solo-lectores', texto: titulo }),
            crear('thead', {}, crear('tr', {}, ...encabezados.map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            crear('tbody', {}, ...filas)));
}

function insigniaEstado(estado) {
    return estado === 'abierta' ? insignia('Abierta', 'pendiente') : insignia('Cerrada', 'inactivo');
}

function textoResultado(r) {
    if (!r) return '—';
    if (!r.completo) return `Incompleto${r.promedio !== null && r.promedio !== undefined ? ` (promedio parcial ${r.promedio})` : ''}`;
    return `Promedio ${r.promedio} · ${r.aprobado ? 'aprueba' : 'no aprueba'}`;
}

function textoEntrega(e) {
    if (!e) return 'Sin registro de entrega';
    if (e.cinturon_entregado) return `Cinturón entregado el ${textoFecha(e.fecha_entrega)} (confirmó: ${e.confirmado_por === 'deportista' ? 'el deportista' : 'el club'})`;
    return 'Cinturón pendiente de entrega';
}

// Opciones de grado: la lista oficial; si llega una escritura distinta se agrega tal cual.
function opcionesGrado(actual) {
    const opciones = GRADOS.map((g) => ({ valor: g, texto: g }));
    if (actual && !GRADOS.some((g) => normalizarTexto(g) === normalizarTexto(actual))) opciones.push({ valor: actual, texto: actual });
    return opciones;
}
const gradoEquivalente = (grado) => GRADOS.find((g) => normalizarTexto(g) === normalizarTexto(grado ?? '')) || grado || '';

// ===========================================================================
// Lista de sesiones (#ascensos)
// ===========================================================================
export async function mostrarAscensos(contenedor) {
    const zona = crear('div');
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' },
            crear('h1', { texto: 'Ascensos' }),
            crear('a', { clase: 'boton', texto: 'Nueva sesión de examen', atributos: { href: '#ascenso-nuevo' } })),
        crear('p', { clase: 'ayuda' }, 'El resultado de cada examen es ', provisional(), ': promedio de las seis notas, 5 o más aprueba.'),
        zona);
    cargando(zona);
    try {
        const lista = await evaluaciones.listar();
        vaciar(zona);
        if (lista.length === 0) return zona.append(crear('p', { clase: 'tarjeta', texto: 'No hay sesiones de examen.' }));
        zona.append(tabla('Sesiones de examen', ['Inicio', 'Grado', 'Lugar', 'Estado', 'Participantes', 'Modalidades calificadas'],
            lista.map((s) => crear('tr', {},
                crear('td', {}, crear('a', { texto: textoFecha(s.fecha), atributos: { href: `#ascenso/${s.id}` } })),
                crear('td', { texto: textoFecha(s.fecha_grado) }),
                crear('td', { texto: textoDato(s.lugar) }),
                crear('td', {}, insigniaEstado(s.estado)),
                crear('td', { texto: String(s.num_participantes) }),
                crear('td', { texto: `${s.num_completados} de ${s.total_modalidades}` })))));
    } catch (error) {
        vaciar(zona);
        zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
}

// ===========================================================================
// Candidatos (para crear o para inscribir): casillas + grado a evaluar + aviso de elegibilidad
// ===========================================================================
function listaCandidatos(candidatos, yaInscritos = new Set()) {
    const filas = [];
    const lista = crear('ul', { clase: 'lista-marcas' });
    for (const c of candidatos.filter((x) => !yaInscritos.has(x.documento))) {
        const id = `candidato-${c.documento}`;
        const casilla = crear('input', { atributos: { type: 'checkbox', id, value: c.documento } });
        const grado = crear('select', { atributos: { 'aria-label': `Grado a evaluar de ${c.nombre}` } },
            ...opcionesGrado(c.grado_siguiente).map((o) => crear('option', { texto: o.texto, atributos: { value: o.valor } })));
        grado.value = gradoEquivalente(c.grado_siguiente);
        const apto = c.apto === null ? insignia('Sin datos', 'inactivo') : c.apto ? insignia('Apto', 'activo') : insignia('Revisar', 'deuda');
        const detalle = `${textoGrado(c.grado_actual)} → · ${textoDato(c.edad)} años · inasistencia ${c.porcentaje_inasistencia === null ? '—' : `${c.porcentaje_inasistencia} %`} (umbral ${c.umbral} %)`;
        filas.push({ casilla, grado, documento: c.documento });
        lista.append(crear('li', { clase: 'fila-pago' },
            casilla,
            crear('label', { atributos: { for: id } }, crear('strong', { texto: c.nombre }), ` · ${c.documento} `, apto, crear('br'),
                crear('span', { clase: 'ayuda', texto: detalle }),
                c.avisos.length ? crear('span', { clase: 'salud', texto: ` ${c.avisos.join(' ')}` }) : null),
            grado));
    }
    const elegidos = () => filas.filter((f) => f.casilla.checked).map((f) => ({ deportista_documento: f.documento, grado_evaluado: f.grado.value }));
    return { nodo: filas.length ? lista : crear('p', { texto: 'No hay candidatos disponibles.' }), elegidos };
}

// ===========================================================================
// Nueva sesión (#ascenso-nuevo)
// ===========================================================================
export function mostrarNuevaSesion(contenedor) {
    const aviso = avisoNuevo();
    const c = {
        fecha: campo({ etiqueta: 'Inicio de los exámenes', nombre: 'fecha', tipo: 'date', obligatorio: true }),
        fecha_grado: campo({ etiqueta: 'Fecha de grado', nombre: 'fecha_grado', tipo: 'date', obligatorio: true }),
        lugar: campo({ etiqueta: 'Lugar', nombre: 'lugar', obligatorio: true, atributos: { maxlength: 150 } }),
    };
    const zonaCandidatos = crear('div');
    let seleccion = () => [];
    const botonCandidatos = crear('button', { clase: 'boton boton-secundario', texto: 'Ver candidatos para esa fecha', atributos: { type: 'button' } });
    botonCandidatos.addEventListener('click', async () => {
        limpiarAviso(aviso);
        if (!c.fecha.control.value) {
            mostrarAviso(aviso, 'Indica primero la fecha de inicio.', 'error');
            return;
        }
        cargando(zonaCandidatos);
        try {
            const lista = await evaluaciones.candidatos({ fecha: c.fecha.control.value });
            const { nodo, elegidos } = listaCandidatos(lista);
            seleccion = elegidos;
            vaciar(zonaCandidatos);
            zonaCandidatos.append(crear('p', { clase: 'ayuda', texto: 'La elegibilidad es solo un aviso (asistencia frente al umbral): nunca impide inscribir.' }), nodo);
        } catch (error) {
            vaciar(zonaCandidatos);
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    const boton = crear('button', { clase: 'boton', texto: 'Crear sesión', atributos: { type: 'submit' } });
    const formulario = crear('form', { clase: 'tarjeta', atributos: { novalidate: true } },
        crear('p', { clase: 'ayuda', texto: 'Crea la sesión y, en el calendario, los eventos públicos de exámenes y de grado. Los participantes se pueden inscribir ahora o después.' }),
        crear('div', { clase: 'rejilla-campos' }, c.fecha.envoltura, c.fecha_grado.envoltura, c.lugar.envoltura),
        crear('div', { clase: 'acciones' }, botonCandidatos),
        zonaCandidatos,
        aviso,
        crear('div', { clase: 'acciones' }, boton, crear('a', { clase: 'boton boton-secundario', texto: 'Cancelar', atributos: { href: '#ascensos' } })));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const datos = { fecha: c.fecha.control.value || null, fecha_grado: c.fecha_grado.control.value || null, lugar: c.lugar.control.value.trim() || null };
        const participantes = seleccion();
        if (participantes.length) datos.participantes = participantes;
        try {
            const sesion = await conBotonOcupado(boton, 'Creando…', () => evaluaciones.crear(datos));
            notificar('Sesión de examen creada.');
            window.location.assign(`#ascenso/${sesion.id}`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'nearest' });
        }
    });
    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Ascensos', atributos: { href: '#ascensos' } })),
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Nueva sesión de examen' })),
        formulario);
    c.fecha.control.focus();
}

// ===========================================================================
// Ficha de la sesión (#ascenso/<id>)
// ===========================================================================
export async function mostrarSesion(contenedor, id) {
    cargando(contenedor);
    const s = await evaluaciones.consultar(id);
    vaciar(contenedor);
    const abierta = s.estado === 'abierta';
    const aviso = avisoNuevo();
    const recargar = (nota = null) => {
        vaciar(contenedor);
        return mostrarSesion(contenedor, id).then(() => {
            if (nota) contenedor.insertBefore(nota, contenedor.children[2] || null);
        }).catch((error) => contenedor.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } })));
    };
    const ejecutar = async (boton, espera, tarea) => {
        limpiarAviso(aviso);
        try {
            return await conBotonOcupado(boton, espera, tarea);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'nearest' });
            return undefined;
        }
    };

    const acciones = crear('div', { clase: 'acciones', atributos: { style: 'margin: 0 0 1rem' } });
    if (abierta) {
        const cerrar = crear('button', { clase: 'boton', texto: 'Cerrar sesión de examen', atributos: { type: 'button' } });
        cerrar.addEventListener('click', async () => {
            const aceptar = await confirmar({
                titulo: 'Cerrar la sesión de examen',
                mensaje: ['Al cerrar, los resultados quedan definitivos y se habilita la entrega de cinturones. Exige las seis modalidades calificadas de todos.',
                    'Para cambiar algo después habrá que reabrirla (y el grado ya entregado se corrige a mano).'],
                textoAceptar: 'Cerrar la sesión',
                peligroso: true,
                textoConfirmacion: 'CERRAR',
            });
            if (!aceptar) return;
            if (await ejecutar(cerrar, 'Cerrando…', () => evaluaciones.cerrar(s.id))) {
                notificar('Sesión cerrada.');
                recargar();
            }
        });
        const eliminar = crear('button', { clase: 'boton boton-peligro', texto: 'Eliminar sesión', atributos: { type: 'button' } });
        eliminar.addEventListener('click', async () => {
            const aceptar = await confirmar({
                titulo: 'Eliminar sesión', mensaje: ['Se elimina la sesión y sus dos eventos del calendario. Solo se puede si ninguna modalidad está calificada.'],
                textoAceptar: 'Eliminar', peligroso: true,
            });
            if (!aceptar) return;
            if (await ejecutar(eliminar, 'Eliminando…', () => evaluaciones.eliminar(s.id))) {
                notificar('Sesión eliminada.');
                window.location.assign('#ascensos');
            }
        });
        acciones.append(cerrar, eliminar);
    } else {
        const reabrir = crear('button', { clase: 'boton boton-secundario', texto: 'Reabrir sesión', atributos: { type: 'button' } });
        reabrir.addEventListener('click', async () => {
            const aceptar = await confirmar({
                titulo: 'Reabrir sesión', mensaje: ['La sesión vuelve a abierta para corregir notas. Si alguien ya recibió su cinturón, su grado no se revierte solo.'],
                textoAceptar: 'Reabrir',
            });
            if (!aceptar) return;
            const resultado = await ejecutar(reabrir, 'Reabriendo…', () => evaluaciones.reabrir(s.id));
            if (resultado) {
                const nota = crear('div');
                mostrarAviso(nota, resultado.advertencias?.length ? `Sesión reabierta. ${resultado.advertencias.join(' ')}` : 'Sesión reabierta.', resultado.advertencias?.length ? 'advertencia' : 'exito');
                recargar(nota);
            }
        });
        acciones.append(reabrir);
    }

    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Ascensos', atributos: { href: '#ascensos' } })),
        crear('div', { clase: 'encabezado-vista' },
            crear('div', {},
                crear('h1', { texto: `Examen del ${textoFecha(s.fecha)}` }),
                crear('p', { clase: 'ayuda' }, `Grado el ${textoFecha(s.fecha_grado)} · ${textoDato(s.lugar)} · `, insigniaEstado(s.estado)))),
        aviso,
        acciones,
        abierta ? seccionEditarSesion(s, recargar) : crear('span'),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-part-examen' } },
            crear('h2', { texto: `Participantes (${s.participantes.length})`, atributos: { id: 'titulo-part-examen' } }),
            crear('p', { clase: 'ayuda' }, 'Resultado ', provisional(), ': promedio de las seis notas, 5 o más aprueba. Cada nota va de 1 a 10.'),
            ...(s.participantes.length ? s.participantes.map((p) => tarjetaParticipante(s, p, recargar)) : [crear('p', { texto: 'Aún no hay inscritos.' })])),
        abierta ? await seccionInscribir(s, recargar) : crear('span'),
    );
}

function seccionEditarSesion(s, recargar) {
    const aviso = avisoNuevo();
    const c = {
        fecha: campo({ etiqueta: 'Inicio', nombre: 'fecha', tipo: 'date', valor: s.fecha }),
        fecha_grado: campo({ etiqueta: 'Fecha de grado', nombre: 'fecha_grado', tipo: 'date', valor: s.fecha_grado }),
        lugar: campo({ etiqueta: 'Lugar', nombre: 'lugar', valor: s.lugar, atributos: { maxlength: 150 } }),
    };
    const boton = crear('button', { clase: 'boton', texto: 'Guardar', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } }, aviso,
        crear('div', { clase: 'rejilla-campos' }, c.fecha.envoltura, c.fecha_grado.envoltura, c.lugar.envoltura),
        crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const cambios = {};
        for (const nombre of Object.keys(c)) {
            const v = c[nombre].control.value.trim() || null;
            if (v !== (s[nombre] ?? null)) cambios[nombre] = v;
        }
        if (!Object.keys(cambios).length) return mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
        try {
            await conBotonOcupado(boton, 'Guardando…', () => evaluaciones.actualizar(s.id, cambios));
            notificar('Sesión actualizada (también sus eventos del calendario).');
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });
    return crear('details', { clase: 'tarjeta' }, crear('summary', { texto: 'Editar fechas y lugar' }), formulario);
}

// ---------------------------------------------------------------------------
// Participante: grado a evaluar, seis modalidades, quitar y entrega del cinturón
// ---------------------------------------------------------------------------
function tarjetaParticipante(s, p, recargar) {
    const abierta = s.estado === 'abierta';
    const aviso = avisoNuevo();
    const completadas = p.modalidades.filter((m) => m.estado === 'completado').length;
    const r = p.resultado_general;
    const resumen = crear('summary', {},
        crear('strong', { texto: p.nombre }), ` · ${textoGrado(p.grado_actual)} → ${textoGrado(p.grado_evaluado)} · ${completadas}/6 · ${textoResultado(r)} `,
        r?.completo ? (r.aprobado ? insignia('Aprueba', 'activo') : insignia('No aprueba', 'deuda')) : null);
    const cuerpo = crear('div', {}, aviso);

    if (abierta) {
        const grado = campo({ etiqueta: 'Grado a evaluar', nombre: 'grado_evaluado', tipo: 'select', valor: gradoEquivalente(p.grado_evaluado), opciones: opcionesGrado(p.grado_evaluado) });
        const guardarGrado = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Cambiar grado', atributos: { type: 'button' } });
        guardarGrado.addEventListener('click', async () => {
            limpiarAviso(aviso);
            try {
                await conBotonOcupado(guardarGrado, 'Guardando…', () => evaluaciones.actualizarParticipante(s.id, p.deportista_documento, grado.control.value));
                notificar('Grado a evaluar actualizado.');
                recargar();
            } catch (error) {
                mostrarAviso(aviso, error.message, 'error');
            }
        });
        const quitar = crear('button', { clase: 'boton boton-peligro boton-pequeno', texto: 'Quitar de la sesión', atributos: { type: 'button', 'aria-label': `Quitar a ${p.nombre} de la sesión` } });
        quitar.addEventListener('click', async () => {
            const aceptar = await confirmar({ titulo: 'Quitar participante', mensaje: [`Se quitará a ${p.nombre}. Solo se puede si no tiene modalidades calificadas.`], textoAceptar: 'Quitar', peligroso: true });
            if (!aceptar) return;
            try {
                await evaluaciones.quitarParticipante(s.id, p.deportista_documento);
                notificar(`${p.nombre} quitado de la sesión.`);
                recargar();
            } catch (error) {
                mostrarAviso(aviso, error.message, 'error');
            }
        });
        cuerpo.append(crear('div', { clase: 'filtros' }, grado.envoltura, crear('div', { clase: 'campo' }, guardarGrado), crear('div', { clase: 'campo' }, quitar)));
    }

    for (const m of p.modalidades) cuerpo.append(modalidad(s, p, m, abierta, aviso, recargar));

    cuerpo.append(crear('p', { clase: 'ayuda', texto: textoEntrega(p.entrega) }));
    if (!abierta && r?.aprobado && !p.entrega?.cinturon_entregado) {
        const fecha = campo({ etiqueta: 'Fecha de entrega (opcional; por defecto la del grado)', nombre: 'fecha_entrega', tipo: 'date' });
        const boton = crear('button', { clase: 'boton boton-exito', texto: 'Confirmar entrega del cinturón', atributos: { type: 'button' } });
        boton.addEventListener('click', async () => {
            const aceptar = await confirmar({ titulo: 'Confirmar entrega', mensaje: [`${p.nombre} pasará a grado ${textoGrado(p.grado_evaluado)}.`], textoAceptar: 'Confirmar entrega' });
            if (!aceptar) return;
            limpiarAviso(aviso);
            try {
                const resultado = await conBotonOcupado(boton, 'Confirmando…', () =>
                    evaluaciones.confirmarEntregaCinturon(s.id, p.deportista_documento, fecha.control.value ? { fecha_entrega: fecha.control.value } : {}));
                const nota = crear('div');
                mostrarAviso(nota, resultado.aviso ? `Entrega registrada. ${resultado.aviso}` : `Entrega registrada: ${p.nombre} queda en grado ${textoGrado(resultado.grado_actual)}.`, resultado.aviso ? 'advertencia' : 'exito');
                recargar(nota);
            } catch (error) {
                mostrarAviso(aviso, error.message, 'error');
            }
        });
        cuerpo.append(crear('div', { clase: 'filtros' }, fecha.envoltura, crear('div', { clase: 'campo' }, boton)));
    }
    return crear('details', { clase: 'participante' }, resumen, cuerpo);
}

function modalidad(s, p, m, abierta, aviso, recargar) {
    const encabezado = crear('h3', { clase: 'titulo-modalidad' },
        `${textoModalidad(m.modalidad)} · `, m.estado === 'completado'
            ? `nota ${m.nota} ${m.aprobado ? '(aprueba)' : '(no aprueba)'}${m.fecha_completado ? ` · ${textoFecha(m.fecha_completado)}` : ''}`
            : 'pendiente');
    if (!abierta) {
        return crear('div', { clase: 'modalidad-examen' }, encabezado,
            m.valor_medible !== null ? crear('p', { clase: 'ayuda', texto: `Valor medible: ${m.valor_medible}` }) : null,
            m.aspectos_fallidos ? crear('p', { clase: 'ayuda', texto: `Por mejorar: ${m.aspectos_fallidos}` }) : null,
            m.recomendaciones ? crear('p', { clase: 'ayuda', texto: `Recomendaciones: ${m.recomendaciones}` }) : null);
    }
    const c = {
        nota: campo({ etiqueta: 'Nota (1 a 10)', nombre: 'nota', tipo: 'number', valor: m.nota, atributos: { min: 1, max: 10, step: '0.01', inputmode: 'decimal' } }),
        fecha_completado: campo({ etiqueta: 'Fecha', nombre: 'fecha_completado', tipo: 'date', valor: m.fecha_completado, ayuda: 'Por defecto hoy.' }),
    };
    if (m.modalidad === 'fisico') c.valor_medible = campo({ etiqueta: 'Valor medible', nombre: 'valor_medible', tipo: 'number', valor: m.valor_medible, ayuda: 'PROVISIONAL: según la plantilla de pruebas físicas.', atributos: { min: 0, max: 9999.99, step: '0.01' } });
    const textos = {
        aspectos_fallidos: campo({ etiqueta: 'Aspectos por mejorar', nombre: 'aspectos_fallidos', tipo: 'textarea', valor: m.aspectos_fallidos }),
        observaciones: campo({ etiqueta: 'Observaciones', nombre: 'observaciones', tipo: 'textarea', valor: m.observaciones }),
        recomendaciones: campo({ etiqueta: 'Recomendaciones', nombre: 'recomendaciones', tipo: 'textarea', valor: m.recomendaciones }),
        criterios_evaluados: campo({ etiqueta: 'Criterios evaluados', nombre: 'criterios_evaluados', tipo: 'textarea', valor: m.criterios_evaluados }),
        puntajes: campo({ etiqueta: 'Puntajes', nombre: 'puntajes', tipo: 'textarea', valor: m.puntajes }),
    };
    const boton = crear('button', { clase: 'boton boton-pequeno', texto: 'Guardar', atributos: { type: 'submit', 'aria-label': `Guardar ${textoModalidad(m.modalidad)} de ${p.nombre}` } });
    const formulario = crear('form', { clase: 'modalidad-examen', atributos: { novalidate: true } },
        encabezado,
        crear('div', { clase: 'rejilla-campos' }, ...Object.values(c).map((x) => x.envoltura)),
        crear('details', {}, crear('summary', { texto: 'Detalle (aspectos, observaciones, recomendaciones, criterios y puntajes)' }),
            crear('div', { clase: 'rejilla-campos' }, ...Object.values(textos).map((x) => x.envoltura))),
        crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const cambios = {};
        for (const [nombre, { control }] of Object.entries({ ...c, ...textos })) {
            const v = control.value.trim();
            const nuevo = v === '' ? null : (nombre === 'nota' || nombre === 'valor_medible') ? Number(v) : v;
            if (nuevo !== (m[nombre] ?? null)) cambios[nombre] = nuevo;
        }
        if (!Object.keys(cambios).length) return mostrarAviso(aviso, 'No hay cambios en esa modalidad.', 'info');
        try {
            await conBotonOcupado(boton, 'Guardando…', () => evaluaciones.calificar(s.id, p.deportista_documento, m.modalidad, cambios));
            notificar(`${textoModalidad(m.modalidad)} de ${p.nombre} guardado.`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'nearest' });
        }
        return undefined;
    });
    return formulario;
}

async function seccionInscribir(s, recargar) {
    const aviso = avisoNuevo();
    const zona = crear('div');
    const boton = crear('button', { clase: 'boton', texto: 'Inscribir seleccionados', atributos: { type: 'button' } });
    let seleccion = () => [];
    try {
        const lista = await evaluaciones.candidatos({ sesion_id: s.id });
        const { nodo, elegidos } = listaCandidatos(lista, new Set(s.participantes.map((p) => p.deportista_documento)));
        seleccion = elegidos;
        zona.append(nodo);
    } catch (error) {
        zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
    boton.addEventListener('click', async () => {
        limpiarAviso(aviso);
        const participantes = seleccion();
        if (!participantes.length) return mostrarAviso(aviso, 'Marca al menos un candidato.', 'error');
        try {
            await conBotonOcupado(boton, 'Inscribiendo…', () => evaluaciones.inscribir(s.id, participantes));
            notificar(`${participantes.length} inscrito(s).`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });
    return crear('details', { clase: 'tarjeta' },
        crear('summary', { texto: 'Inscribir candidatos' }),
        crear('p', { clase: 'ayuda', texto: 'La elegibilidad (asistencia frente al umbral: 30 % hasta 16 años, 40 % desde 17) es solo un aviso; nunca impide inscribir.' }),
        aviso, zona, crear('div', { clase: 'acciones' }, boton));
}

// ===========================================================================
// Público: mis exámenes y confirmar que recibí mi cinturón
// ===========================================================================
export async function mostrarMisAscensos(contenedor, documentoPropio) {
    const zona = crear('div');
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Mis ascensos' })),
        crear('p', { clase: 'ayuda' }, 'El resultado es ', provisional(), ': promedio de las seis notas, 5 o más aprueba.'),
        zona);
    cargando(zona);
    let sesiones;
    try {
        sesiones = await evaluaciones.listar();
    } catch (error) {
        vaciar(zona);
        return zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
    vaciar(zona);
    if (!sesiones.length) return zona.append(crear('p', { clase: 'tarjeta', texto: 'Aún no tienes exámenes de ascenso.' }));
    for (const resumen of sesiones) {
        const tarjeta = crear('section', { clase: 'tarjeta' });
        zona.append(tarjeta);
        try {
            const s = await evaluaciones.consultar(resumen.id);
            const p = s.participantes.find((x) => x.deportista_documento === documentoPropio) || s.participantes[0];
            const aviso = avisoNuevo();
            tarjeta.append(
                crear('h2', { texto: `${textoGrado(p.grado_evaluado)} · examen del ${textoFecha(s.fecha)}` }),
                crear('p', { clase: 'ayuda' }, `Grado el ${textoFecha(s.fecha_grado)} · ${textoDato(s.lugar)} · `, insigniaEstado(s.estado), ` · ${textoResultado(p.resultado_general)}`),
                tabla('Mis notas', ['Modalidad', 'Nota', 'Aprobó', 'Por mejorar', 'Recomendaciones'], p.modalidades.map((m) => crear('tr', {},
                    crear('td', { texto: textoModalidad(m.modalidad) }), crear('td', { texto: textoDato(m.nota) }), crear('td', { texto: m.estado === 'completado' ? textoDato(m.aprobado) : 'Pendiente' }),
                    crear('td', { texto: textoDato(m.aspectos_fallidos) }), crear('td', { texto: textoDato(m.recomendaciones) })))),
                crear('p', { texto: textoEntrega(p.entrega) }),
                aviso);
            if (s.estado === 'cerrada' && p.resultado_general?.aprobado && !p.entrega?.cinturon_entregado) {
                const boton = crear('button', { clase: 'boton boton-exito', texto: 'Confirmar que recibí mi cinturón', atributos: { type: 'button' } });
                boton.addEventListener('click', async () => {
                    const aceptar = await confirmar({ titulo: 'Confirmar entrega', mensaje: [`Confirmas que recibiste el cinturón ${textoGrado(p.grado_evaluado)}.`], textoAceptar: 'Sí, lo recibí' });
                    if (!aceptar) return;
                    limpiarAviso(aviso);
                    try {
                        const r = await conBotonOcupado(boton, 'Confirmando…', () => evaluaciones.confirmarEntregaCinturon(s.id, p.deportista_documento, {}));
                        notificar(r.aviso || `¡Felicitaciones! Ya figuras en grado ${textoGrado(r.grado_actual)}.`);
                        vaciar(contenedor);
                        mostrarMisAscensos(contenedor, documentoPropio);
                    } catch (error) {
                        mostrarAviso(aviso, error.message, 'error');
                    }
                });
                tarjeta.append(crear('div', { clase: 'acciones' }, boton));
            }
        } catch (error) {
            tarjeta.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        }
    }
    return undefined;
}
