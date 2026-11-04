// Módulo Calendario — catálogo B.9. Lo usan los dos ámbitos.
// calendario.vista ya filtra por ámbito: el público ve los eventos públicos, su cumpleaños y sus
// vencimientos; el administrativo, todo. Solo el administrativo crea, edita e importa.
import { calendario, competencias } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, campo, conBotonOcupado, cargando,
    textoFecha, textoFechaLarga, textoDato, hoyBogota, sumarDias, nombreMes, insignia, textoConFechas, formatearTextoConFechas,
} from '../ui.js';

export const TIPOS_EVENTO = [
    { valor: 'competencia', texto: 'Torneo' },
    { valor: 'evaluacion', texto: 'Evaluación' },
    { valor: 'grado', texto: 'Grados' },
    { valor: 'reunion_administrativa', texto: 'Reunión administrativa' },
    { valor: 'evento_extra', texto: 'Evento' },
];
// Se pueden crear los cinco tipos. Evaluación y Grados creados aquí son eventos sueltos del
// calendario; las sesiones de examen (con sus dos eventos) se crean en Ascensos.
const TIPOS_CREABLES = TIPOS_EVENTO.map((t) => t.valor);
const VISIBILIDADES = [{ valor: 'publica', texto: 'Pública' }, { valor: 'administrativa', texto: 'Solo administrativa' }];
const textoTipoEvento = (valor) => TIPOS_EVENTO.find((t) => t.valor === valor)?.texto ?? valor;

// Filtros visibles (se conservan al cambiar de mes).
const filtros = { tipos: new Set(TIPOS_EVENTO.map((t) => t.valor)), cumpleanos: true, vencimientos: true };
let mesElegido = null; // 'AAAA-MM'
// evento_id de los torneos creados (administrativo): un evento «competencia» puede no tener torneo aún.
let torneosCreados = new Set();

function rangoDelMes(mes) {
    const [anio, numero] = mes.split('-').map(Number);
    const desde = `${mes}-01`;
    const ultimo = new Date(Date.UTC(anio, numero, 0)).getUTCDate();
    return { desde, hasta: `${mes}-${String(ultimo).padStart(2, '0')}`, anio, numero };
}

function mesVecino(mes, desplazamiento) {
    const [anio, numero] = mes.split('-').map(Number);
    const fecha = new Date(Date.UTC(anio, numero - 1 + desplazamiento, 1));
    return fecha.toISOString().slice(0, 7);
}

// Une eventos, cumpleaños y vencimientos en una lista por día, ordenada.
function agruparPorDia(vista, aplicarFiltros = true) {
    const dias = new Map();
    const agregar = (fecha, item) => {
        if (!dias.has(fecha)) dias.set(fecha, []);
        dias.get(fecha).push(item);
    };
    for (const e of vista.eventos) {
        if (!aplicarFiltros || filtros.tipos.has(e.tipo)) agregar(e.fecha, { clase: 'evento', evento: e });
    }
    if (!aplicarFiltros || filtros.cumpleanos) {
        for (const c of vista.cumpleanos) agregar(c.fecha, { clase: 'cumpleanos', deportistas: c.deportistas });
    }
    if (!aplicarFiltros || filtros.vencimientos) {
        for (const v of vista.vencimientos) agregar(v.fecha, { clase: 'vencimiento', deportistas: v.deportistas });
    }
    return [...dias.entries()].sort(([a], [b]) => a.localeCompare(b));
}

const nombres = (deportistas) => deportistas.map((d) => d.nombre).join(', ');

// ===========================================================================
// Pantalla principal (#calendario)
// ===========================================================================
export async function mostrarCalendario(contenedor, esAdministrativo) {
    if (!mesElegido) mesElegido = hoyBogota().slice(0, 7);
    const zonaMes = crear('div', { atributos: { 'aria-live': 'polite' } });
    const titulo = crear('h2', { atributos: { id: 'titulo-mes' } });

    const cambiarMes = (desplazamiento) => {
        mesElegido = desplazamiento === 0 ? hoyBogota().slice(0, 7) : mesVecino(mesElegido, desplazamiento);
        cargarMes();
    };
    const cargarMes = async () => {
        const { desde, hasta, anio, numero } = rangoDelMes(mesElegido);
        titulo.textContent = `${nombreMes(numero)} ${anio}`;
        cargando(zonaMes);
        let vista;
        try {
            vista = await calendario.vista(desde, hasta);
        } catch (error) {
            vaciar(zonaMes);
            zonaMes.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
            return;
        }
        pintarMes(zonaMes, vista, esAdministrativo, cargarMes);
    };

    const casillas = [
        ...TIPOS_EVENTO.filter((t) => esAdministrativo || t.valor !== 'reunion_administrativa').map((t) => ({ clave: t.valor, texto: t.texto, tipo: true })),
        { clave: 'cumpleanos', texto: 'Cumpleaños' },
        { clave: 'vencimientos', texto: 'Vencimientos de mensualidad' },
    ];
    const zonaFiltros = crear('fieldset', { clase: 'filtros-calendario' }, crear('legend', { texto: 'Mostrar' }));
    for (const c of casillas) {
        const { envoltura, control } = campo({
            etiqueta: c.texto, nombre: `ver_${c.clave}`, tipo: 'checkbox',
            valor: c.tipo ? filtros.tipos.has(c.clave) : filtros[c.clave],
        });
        control.addEventListener('change', () => {
            if (c.tipo) {
                if (control.checked) filtros.tipos.add(c.clave);
                else filtros.tipos.delete(c.clave);
            } else {
                filtros[c.clave] = control.checked;
            }
            cargarMes();
        });
        zonaFiltros.append(envoltura);
    }

    const boton = (texto, desplazamiento) => crear('button', {
        clase: 'boton boton-secundario boton-pequeno', texto, atributos: { type: 'button' },
        eventos: { click: () => cambiarMes(desplazamiento) },
    });
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Calendario' })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-mes' } },
            crear('div', { clase: 'navegacion-mes' }, boton('← Mes anterior', -1), titulo, boton('Mes siguiente →', 1), boton('Hoy', 0)),
            zonaFiltros,
            zonaMes),
    );
    if (esAdministrativo) {
        contenedor.append(seccionCrear(cargarMes), seccionImportar(cargarMes));
        try {
            torneosCreados = new Set((await competencias.listar()).map((t) => t.evento_id));
        } catch {
            torneosCreados = new Set();
        }
    }
    await cargarMes();
}

function pintarMes(zona, vista, esAdministrativo, recargar) {
    vaciar(zona);
    const dias = agruparPorDia(vista);
    if (dias.length === 0) {
        zona.append(crear('p', { texto: 'No hay nada en este mes con los filtros elegidos.' }));
        return;
    }
    const hoy = hoyBogota();
    const lista = crear('ol', { clase: 'lista-dias' });
    for (const [fecha, items] of dias) {
        const dia = crear('li', { clase: fecha === hoy ? 'dia dia-hoy' : 'dia' },
            crear('h3', { texto: fecha === hoy ? `${textoFechaLarga(fecha)} (hoy)` : textoFechaLarga(fecha) }));
        for (const item of items) {
            if (item.clase === 'evento') dia.append(detalleEvento(item.evento, esAdministrativo, recargar));
            else if (item.clase === 'cumpleanos') dia.append(crear('p', { clase: 'item-calendario' }, insignia('Cumpleaños', 'activo'), ` ${nombres(item.deportistas)}`));
            else dia.append(crear('p', { clase: 'item-calendario' }, insignia('Vence mensualidad', 'vencido'), ` ${nombres(item.deportistas)}`));
        }
        lista.append(dia);
    }
    zona.append(lista);
}

// Detalle desplegable de un evento; el administrativo puede editarlo aquí.
function detalleEvento(e, esAdministrativo, recargar) {
    const detalle = crear('details', { clase: 'item-calendario evento-calendario' },
        crear('summary', {}, insignia(textoTipoEvento(e.tipo), e.tipo === 'competencia' ? 'pendiente' : 'inactivo'), ' ', ...textoConFechas(e.nombre)),
        crear('p', { clase: 'ayuda', texto: `${textoDato(e.lugar)} · ${e.visibilidad === 'publica' ? 'Pública' : 'Solo administrativa'}` }),
        e.observaciones ? crear('p', { clase: 'texto-largo', texto: e.observaciones }) : null);
    if (esAdministrativo && e.tipo === 'competencia') {
        detalle.append(torneosCreados.has(e.id)
            ? crear('p', {}, crear('a', { texto: 'Ver el torneo', atributos: { href: `#torneo/${e.id}` } }))
            : crear('p', { clase: 'ayuda', texto: 'Este evento aún no tiene torneo creado (participantes y resultados).' }));
    }
    if (esAdministrativo) detalle.append(formularioEditar(e, recargar));
    return detalle;
}

function enlaceModulo(tipo, id) {
    if (tipo === 'competencia') return crear('a', { texto: 'Ir al torneo', atributos: { href: `#torneo/${id}` } });
    return crear('span', { clase: 'ayuda', texto: 'Se edita en Ascensos (próximamente).' });
}

function formularioEditar(e, recargar) {
    const aviso = crear('div', { atributos: { hidden: true } });
    const c = {
        nombre: campo({ etiqueta: 'Nombre', nombre: 'nombre', valor: e.nombre, atributos: { maxlength: 150 } }),
        fecha: campo({ etiqueta: 'Fecha', nombre: 'fecha', tipo: 'date', valor: e.fecha }),
        lugar: campo({ etiqueta: 'Lugar', nombre: 'lugar', valor: e.lugar, atributos: { maxlength: 150 } }),
        visibilidad: campo({ etiqueta: 'Visibilidad', nombre: 'visibilidad', tipo: 'select', valor: e.visibilidad, opciones: VISIBILIDADES }),
        observaciones: campo({ etiqueta: 'Observaciones', nombre: 'observaciones', tipo: 'textarea', valor: e.observaciones }),
    };
    if (e.tipo === 'reunion_administrativa') bloquearVisibilidad(c.visibilidad);
    const rejilla = crear('div', { clase: 'rejilla-campos' },
        crear('div', { clase: 'campo' },
            crear('span', { clase: 'ayuda', texto: 'Tipo' }),
            crear('strong', { texto: textoTipoEvento(e.tipo), atributos: { 'data-prueba': 'tipo-evento' } }),
            crear('small', { clase: 'ayuda', texto: 'El tipo no se puede cambiar.' })));
    for (const [nombre, { envoltura }] of Object.entries(c)) {
        if (nombre === 'observaciones') envoltura.classList.add('campo-ancho');
        rejilla.append(envoltura);
    }
    const boton = crear('button', { clase: 'boton boton-pequeno', texto: 'Guardar evento', atributos: { type: 'submit' } });
    const formulario = crear('form', { clase: 'formulario-evento', atributos: { novalidate: true } },
        (e.tipo === 'competencia' && torneosCreados.has(e.id)) || e.tipo === 'evaluacion' || e.tipo === 'grado'
            ? crear('p', { clase: 'ayuda', texto: 'Aquí solo se cambian el nombre y las observaciones; la fecha, el lugar y la visibilidad se editan en su módulo.' })
            : null,
        aviso, rejilla, crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const cambios = {};
        for (const [nombre, { control }] of Object.entries(c)) {
            if (control.disabled) continue;
            const v = control.value.trim();
            const nuevo = v === '' ? null : v;
            if (nuevo !== (e[nombre] ?? null)) cambios[nombre] = nuevo;
        }
        if (Object.keys(cambios).length === 0) {
            mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
            return;
        }
        try {
            await conBotonOcupado(boton, 'Guardando…', () => calendario.actualizar(e.id, cambios));
            notificar('Evento actualizado.');
            recargar();
        } catch (error) {
            // 409 de torneo o examen: mensaje tal cual y enlace al módulo que corresponde.
            vaciar(aviso);
            aviso.hidden = false;
            aviso.className = 'aviso aviso-error';
            aviso.setAttribute('role', 'alert');
            aviso.append(crear('p', { texto: error.message }), error.estado === 409 && e.tipo !== 'reunion_administrativa' && e.tipo !== 'evento_extra' ? enlaceModulo(e.tipo, e.id) : null);
        }
    });
    return crear('details', { clase: 'editar-evento' }, crear('summary', { texto: 'Editar' }), formulario);
}

// ===========================================================================
// Crear evento e importar CSV (administrativo)
// ===========================================================================
// Reunión administrativa: visibilidad fija «Solo administrativa» (la API la guarda así siempre).
function bloquearVisibilidad(visibilidad) {
    visibilidad.control.value = 'administrativa';
    visibilidad.control.disabled = true;
    let nota = visibilidad.envoltura.querySelector('.nota-visibilidad');
    if (!nota) {
        nota = crear('small', { clase: 'ayuda nota-visibilidad', texto: 'Una reunión administrativa siempre es solo administrativa.' });
        visibilidad.envoltura.append(nota);
    }
}

function desbloquearVisibilidad(visibilidad) {
    if (!visibilidad.control.disabled) return;
    visibilidad.control.disabled = false;
    visibilidad.control.value = 'publica';
    visibilidad.envoltura.querySelector('.nota-visibilidad')?.remove();
}

function seccionCrear(recargar) {
    const aviso = crear('div', { atributos: { hidden: true } });
    const c = {
        nombre: campo({ etiqueta: 'Nombre', nombre: 'nombre', obligatorio: true, atributos: { maxlength: 150 } }),
        tipo: campo({
            etiqueta: 'Tipo', nombre: 'tipo', tipo: 'select', obligatorio: true, valor: 'evento_extra',
            opciones: TIPOS_EVENTO.filter((t) => TIPOS_CREABLES.includes(t.valor)),
            ayuda: 'Un torneo creado aquí se completa en Torneos. Evaluación y Grados quedan como eventos sueltos: las sesiones de examen se crean en Ascensos.',
        }),
        fecha: campo({ etiqueta: 'Fecha', nombre: 'fecha', tipo: 'date', obligatorio: true }),
        lugar: campo({ etiqueta: 'Lugar', nombre: 'lugar', obligatorio: true, atributos: { maxlength: 150 } }),
        visibilidad: campo({ etiqueta: 'Visibilidad', nombre: 'visibilidad', tipo: 'select', valor: 'publica', opciones: VISIBILIDADES }),
        observaciones: campo({ etiqueta: 'Observaciones', nombre: 'observaciones', tipo: 'textarea' }),
    };
    const confidencial = campo({ etiqueta: 'Confirmo que es una reunión confidencial (solo visible para el club).', nombre: 'confirmar_confidencial', tipo: 'checkbox' });
    const actualizarTipo = () => {
        const reunion = c.tipo.control.value === 'reunion_administrativa';
        confidencial.envoltura.hidden = !reunion;
        if (reunion) bloquearVisibilidad(c.visibilidad);
        else desbloquearVisibilidad(c.visibilidad);
    };
    c.tipo.control.addEventListener('change', actualizarTipo);
    actualizarTipo();

    const rejilla = crear('div', { clase: 'rejilla-campos' });
    for (const [nombre, { envoltura }] of Object.entries(c)) {
        if (nombre === 'observaciones') envoltura.classList.add('campo-ancho');
        rejilla.append(envoltura);
    }
    confidencial.envoltura.classList.add('campo-ancho');
    rejilla.append(confidencial.envoltura);

    const boton = crear('button', { clase: 'boton', texto: 'Crear evento', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } }, aviso, rejilla, crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const datos = {};
        for (const nombre of ['nombre', 'tipo', 'fecha', 'lugar', 'observaciones']) {
            const v = c[nombre].control.value.trim();
            if (v !== '') datos[nombre] = v;
        }
        if (datos.tipo === 'reunion_administrativa') {
            if (confidencial.control.checked) datos.confirmar_confidencial = true;
        } else {
            datos.visibilidad = c.visibilidad.control.value;
        }
        try {
            const creado = await conBotonOcupado(boton, 'Creando…', () => calendario.crear(datos));
            notificar(`Evento «${formatearTextoConFechas(creado.nombre)}» creado.`);
            formulario.reset();
            actualizarTipo();
            mesElegido = creado.fecha.slice(0, 7);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return crear('details', { clase: 'tarjeta' }, crear('summary', { texto: 'Crear evento' }), formulario);
}

function seccionImportar(recargar) {
    const aviso = crear('div', { atributos: { hidden: true } });
    const resultado = crear('div');
    const archivo = campo({ etiqueta: 'Archivo CSV', nombre: 'archivo', tipo: 'file', atributos: { accept: '.csv' } });
    const boton = crear('button', { clase: 'boton', texto: 'Importar', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } },
        crear('div', { clase: 'ayuda-importar', atributos: { 'data-prueba': 'ayuda-importar-eventos' } },
            crear('p', {}, 'CSV en UTF-8 (separador ; o ,) con las columnas ', crear('code', { texto: 'nombre;tipo;fecha;lugar;visibilidad;observaciones' }), '. Máximo 500 filas.'),
            crear('ul', {},
                crear('li', {}, crear('strong', { texto: 'tipo: ' }), ...TIPOS_EVENTO.flatMap((t, i) => [i ? ', ' : '', crear('code', { texto: t.valor }), ` (${t.texto})`])),
                crear('li', {}, crear('strong', { texto: 'visibilidad: ' }), crear('code', { texto: 'publica' }), ' o ', crear('code', { texto: 'administrativa' }),
                    '. La reunión administrativa queda siempre administrativa.'),
                crear('li', {}, crear('strong', { texto: 'fecha: ' }), crear('code', { texto: 'AAAA-MM-DD' }), ' o ', crear('code', { texto: 'DD/MM/AAAA' }), ' (ej. 2027-03-20 o 20/03/2027).')),
            crear('p', {}, crear('a', {
                texto: 'Descargar plantilla', clase: 'boton boton-secundario boton-pequeno',
                atributos: { href: 'recursos/plantilla_importar_eventos.csv', download: 'plantilla_importar_eventos.csv', 'data-prueba': 'plantilla-eventos' },
            }), ' Trae un ejemplo de cada uno de los cinco tipos.')),
        aviso, archivo.envoltura, crear('div', { clase: 'acciones' }, boton), resultado);
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        vaciar(resultado);
        const elegido = archivo.control.files[0];
        if (!elegido) {
            mostrarAviso(aviso, 'Elige un archivo CSV.', 'error');
            return;
        }
        try {
            const r = await conBotonOcupado(boton, 'Importando…', () => calendario.importar(elegido));
            const creados = Array.isArray(r.creados) ? r.creados.length : r.creados;
            resultado.append(crear('p', { clase: 'aviso aviso-exito', texto: `Creados: ${creados}. Omitidos: ${r.omitidos.length}. Con errores: ${r.errores.length}.` }));
            const lineas = [
                ...r.omitidos.map((o) => `Línea ${o.linea}: omitida${o.motivo ? ` (${o.motivo})` : ' (duplicado)'}`),
                ...r.errores.map((e) => `Línea ${e.linea}: ${e.motivo}`),
            ];
            if (lineas.length) resultado.append(crear('ul', {}, ...lineas.map((l) => crear('li', { texto: l }))));
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return crear('details', { clase: 'tarjeta' }, crear('summary', { texto: 'Importar eventos desde CSV' }), formulario);
}

// ===========================================================================
// Widget «Próximas fechas» del inicio (próximos 30 días, sin filtros)
// ===========================================================================
export async function proximasFechas(limite = 8) {
    const hoy = hoyBogota();
    const [vista, torneos] = await Promise.all([calendario.vista(hoy, sumarDias(hoy, 30)), competencias.listar().catch(() => [])]);
    // Solo se enlaza un evento de competencia que ya tiene torneo creado.
    const conTorneo = new Set(torneos.map((t) => t.evento_id));
    const items = [];
    for (const [fecha, del_dia] of agruparPorDia(vista, false)) {
        for (const item of del_dia) {
            let texto;
            if (item.clase === 'evento') texto = `${textoTipoEvento(item.evento.tipo)}: ${item.evento.nombre}`;
            else if (item.clase === 'cumpleanos') texto = `Cumpleaños: ${nombres(item.deportistas)}`;
            else texto = `Vence mensualidad: ${nombres(item.deportistas)}`;
            items.push({ fecha, texto, enlace: item.clase === 'evento' && conTorneo.has(item.evento.id) ? `#torneo/${item.evento.id}` : null });
        }
    }
    if (items.length === 0) return [crear('p', { texto: 'Nada en los próximos 30 días.' })];
    const lista = crear('ul', { clase: 'lista-proximas' }, ...items.slice(0, limite).map((i) => crear('li', {},
        crear('span', { clase: 'fecha-proxima', texto: textoFecha(i.fecha).slice(0, 5) }),
        i.enlace ? crear('a', { atributos: { href: i.enlace } }, ...textoConFechas(i.texto)) : crear('span', {}, ...textoConFechas(i.texto)))));
    const resto = items.length > limite ? crear('p', { clase: 'ayuda', texto: `y ${items.length - limite} más.` }) : null;
    return [lista, resto, crear('p', {}, crear('a', { texto: 'Ver calendario', atributos: { href: '#calendario' } }))].filter(Boolean);
}
