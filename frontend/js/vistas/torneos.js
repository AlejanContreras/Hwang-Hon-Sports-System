// Módulo Torneos (ámbito administrativo) — catálogo B.10.
// Un torneo es un evento del calendario de tipo «competencia» más tipo_torneo y plataforma.
// Al editar: plataforma, tipo, fecha, lugar y visibilidad van a competencias.actualizar;
// nombre y observaciones van a calendario.actualizar.
import { competencias, calendario } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoFecha, textoDato, insignia,
} from '../ui.js';
import { selectorDeportista } from './comunes.js';

const TIPOS_TORNEO = [
    { valor: 'departamental', texto: 'Departamental' },
    { valor: 'nacional', texto: 'Nacional' },
    { valor: 'internacional', texto: 'Internacional' },
];
const MODALIDADES = [
    { valor: 'combate_individual', texto: 'Combate individual' },
    { valor: 'combate_equipos', texto: 'Combate por equipos' },
    { valor: 'defensa_personal', texto: 'Defensa personal' },
    { valor: 'salto_alto', texto: 'Salto alto' },
    { valor: 'salto_largo', texto: 'Salto largo' },
    { valor: 'formas', texto: 'Formas' },
];
const MEDALLAS = [
    { valor: '', texto: 'Sin registrar' },
    { valor: 'oro', texto: 'Oro' },
    { valor: 'plata', texto: 'Plata' },
    { valor: 'bronce', texto: 'Bronce' },
    { valor: 'ninguna', texto: 'Ninguna' },
];
const CATEGORIAS_EDAD = [
    { valor: '', texto: 'Sin categoría' },
    { valor: 'infantil', texto: 'Infantil' },
    { valor: 'junior', texto: 'Junior' },
    { valor: 'juvenil', texto: 'Juvenil' },
    { valor: 'mayores', texto: 'Mayores' },
    { valor: 'senior', texto: 'Sénior' },
    { valor: 'master', texto: 'Máster' },
];
const VISIBILIDADES = [
    { valor: 'publica', texto: 'Pública' },
    { valor: 'administrativa', texto: 'Solo administrativa' },
];
const MAXIMO_INSCRIPCION = 50;

const texto = (lista, valor) => lista.find((o) => o.valor === valor)?.texto ?? textoDato(valor);
const esCombate = (modalidad) => modalidad === 'combate_individual' || modalidad === 'combate_equipos';

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

const textoONulo = (control) => {
    const v = control.value.trim();
    return v === '' ? null : v;
};
const numeroONulo = (control) => {
    const v = control.value.trim();
    return v === '' ? null : Number(v);
};

// ===========================================================================
// Lista
// ===========================================================================
export async function mostrarTorneos(contenedor) {
    const zona = crear('div');
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' },
            crear('h1', { texto: 'Torneos' }),
            crear('a', { clase: 'boton', texto: 'Nuevo torneo', atributos: { href: '#torneo-nuevo' } })),
        zona,
    );
    cargando(zona);
    let lista;
    try {
        lista = await competencias.listar();
    } catch (error) {
        vaciar(zona);
        zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        return;
    }
    vaciar(zona);
    if (lista.length === 0) {
        zona.append(crear('p', { clase: 'tarjeta', texto: 'No hay torneos registrados.' }));
        return;
    }
    zona.append(tabla('Torneos, del más reciente al más antiguo', ['Torneo', 'Fecha', 'Lugar', 'Tipo', 'Plataforma', 'Inscritos', 'Visibilidad'],
        lista.map((t) => crear('tr', {},
            crear('td', {}, crear('a', { texto: t.nombre, atributos: { href: `#torneo/${t.evento_id}` } })),
            crear('td', { texto: textoFecha(t.fecha) }),
            crear('td', { texto: textoDato(t.lugar) }),
            crear('td', { texto: texto(TIPOS_TORNEO, t.tipo_torneo) }),
            crear('td', { texto: textoDato(t.plataforma) }),
            crear('td', { texto: textoDato(t.num_participantes) }),
            crear('td', { texto: texto(VISIBILIDADES, t.visibilidad) })))));
}

// ===========================================================================
// Crear
// ===========================================================================
function camposTorneo(t = {}) {
    return {
        nombre: campo({ etiqueta: 'Nombre', nombre: 'nombre', obligatorio: true, valor: t.nombre, atributos: { maxlength: 150 } }),
        fecha: campo({ etiqueta: 'Fecha', nombre: 'fecha', tipo: 'date', obligatorio: true, valor: t.fecha }),
        lugar: campo({ etiqueta: 'Lugar', nombre: 'lugar', obligatorio: true, valor: t.lugar, atributos: { maxlength: 150 } }),
        visibilidad: campo({ etiqueta: 'Visibilidad', nombre: 'visibilidad', tipo: 'select', valor: t.visibilidad ?? 'publica', opciones: VISIBILIDADES }),
        tipo_torneo: campo({ etiqueta: 'Tipo de torneo', nombre: 'tipo_torneo', tipo: 'select', obligatorio: true, valor: t.tipo_torneo ?? 'departamental', opciones: TIPOS_TORNEO }),
        plataforma: campo({ etiqueta: 'Plataforma', nombre: 'plataforma', valor: t.plataforma, ayuda: 'Ej.: Fitofan. Opcional.', atributos: { maxlength: 50 } }),
        observaciones: campo({ etiqueta: 'Observaciones', nombre: 'observaciones', tipo: 'textarea', valor: t.observaciones }),
    };
}

function rejillaDe(campos) {
    const rejilla = crear('div', { clase: 'rejilla-campos' });
    for (const [nombre, { envoltura }] of Object.entries(campos)) {
        if (nombre === 'observaciones') envoltura.classList.add('campo-ancho');
        rejilla.append(envoltura);
    }
    return rejilla;
}

export function mostrarNuevoTorneo(contenedor) {
    const aviso = avisoNuevo();
    const c = camposTorneo();
    const boton = crear('button', { clase: 'boton', texto: 'Crear torneo', atributos: { type: 'submit' } });
    const formulario = crear('form', { clase: 'tarjeta', atributos: { novalidate: true } },
        crear('p', { clase: 'ayuda', texto: 'Crea el evento del calendario (tipo competencia) y el torneo juntos.' }),
        rejillaDe(c),
        crear('div', { clase: 'acciones' }, boton, crear('a', { clase: 'boton boton-secundario', texto: 'Cancelar', atributos: { href: '#torneos' } })));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const datos = {
            tipo_torneo: c.tipo_torneo.control.value,
            evento: {
                nombre: textoONulo(c.nombre.control),
                fecha: textoONulo(c.fecha.control),
                lugar: textoONulo(c.lugar.control),
                visibilidad: c.visibilidad.control.value,
                observaciones: textoONulo(c.observaciones.control),
            },
        };
        const plataforma = textoONulo(c.plataforma.control);
        if (plataforma) datos.plataforma = plataforma;
        try {
            const torneo = await conBotonOcupado(boton, 'Creando…', () => competencias.crear(datos));
            notificar(`Torneo «${torneo.nombre}» creado.`);
            window.location.assign(`#torneo/${torneo.evento_id}`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'nearest' });
        }
    });
    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Torneos', atributos: { href: '#torneos' } })),
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Nuevo torneo' })),
        aviso,
        formulario);
    c.nombre.control.focus();
}

// ===========================================================================
// Ficha del torneo
// ===========================================================================
export async function mostrarTorneo(contenedor, id) {
    cargando(contenedor);
    const t = await competencias.consultar(id);
    vaciar(contenedor);
    const recargar = () => {
        vaciar(contenedor);
        return mostrarTorneo(contenedor, id).catch((error) => {
            contenedor.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    };
    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Torneos', atributos: { href: '#torneos' } })),
        crear('div', { clase: 'encabezado-vista' },
            crear('div', {},
                crear('h1', { texto: t.nombre }),
                crear('p', { clase: 'ayuda' },
                    `${textoFecha(t.fecha)} · ${textoDato(t.lugar)} · ${texto(TIPOS_TORNEO, t.tipo_torneo)}`,
                    t.plataforma ? ` · ${t.plataforma}` : '', ' · ',
                    insignia(texto(VISIBILIDADES, t.visibilidad), t.visibilidad === 'publica' ? 'activo' : 'inactivo')))),
        t.observaciones ? crear('p', { clase: 'texto-largo', texto: t.observaciones }) : crear('span'),
        seccionEditar(t, recargar),
        seccionParticipantes(t, recargar),
        seccionInscribir(t, recargar),
    );
}

function seccionEditar(t, recargar) {
    const aviso = avisoNuevo();
    const c = camposTorneo(t);
    const boton = crear('button', { clase: 'boton', texto: 'Guardar cambios', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } }, aviso, rejillaDe(c), crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const delEvento = {};
        const delTorneo = {};
        const cambiado = (nombre, valor) => (valor ?? null) !== (t[nombre] ?? null);
        for (const nombre of ['nombre', 'observaciones']) {
            const valor = textoONulo(c[nombre].control);
            if (cambiado(nombre, valor)) delEvento[nombre] = valor;
        }
        for (const nombre of ['fecha', 'lugar', 'visibilidad', 'tipo_torneo', 'plataforma']) {
            const valor = textoONulo(c[nombre].control);
            if (cambiado(nombre, valor)) delTorneo[nombre] = valor;
        }
        if (!Object.keys(delEvento).length && !Object.keys(delTorneo).length) {
            mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
            return;
        }
        try {
            await conBotonOcupado(boton, 'Guardando…', async () => {
                if (Object.keys(delTorneo).length) await competencias.actualizar(t.evento_id, delTorneo);
                if (Object.keys(delEvento).length) await calendario.actualizar(t.evento_id, delEvento);
            });
            notificar('Torneo actualizado.');
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return crear('details', { clase: 'tarjeta' },
        crear('summary', { texto: 'Editar datos del torneo' }),
        crear('p', { clase: 'ayuda', texto: 'Nombre y observaciones se guardan en el calendario; lo demás, en el torneo. Con resultados cargados no se puede mover a una fecha futura.' }),
        formulario);
}

// ---------------------------------------------------------------------------
// Participantes y resultados
// ---------------------------------------------------------------------------
function seccionParticipantes(t, recargar) {
    const aviso = avisoNuevo();
    const seccion = crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-participantes' } },
        crear('h2', { texto: `Participantes (${t.participantes.length})`, atributos: { id: 'titulo-participantes' } }),
        aviso);
    if (t.participantes.length === 0) {
        seccion.append(crear('p', { texto: 'Aún no hay inscritos.' }));
        return seccion;
    }
    seccion.append(crear('p', { clase: 'ayuda', texto: 'Los resultados (puesto, medalla, combates, resultado) se cargan desde la fecha del torneo.' }));
    for (const p of t.participantes) seccion.append(tarjetaParticipante(t, p, aviso, recargar));
    return seccion;
}

function resumenResultado(p) {
    const partes = [];
    if (p.puesto) partes.push(`Puesto ${p.puesto}`);
    if (p.medalla && p.medalla !== 'ninguna') partes.push(`Medalla de ${p.medalla}`);
    if (esCombate(p.modalidad) && (p.combates_ganados !== null || p.combates_perdidos !== null)) {
        partes.push(`${textoDato(p.combates_ganados)} G / ${textoDato(p.combates_perdidos)} P`);
    }
    return partes.length ? partes.join(' · ') : 'Sin resultados';
}

function tarjetaParticipante(t, p, aviso, recargar) {
    const combate = esCombate(p.modalidad);
    const c = {
        categoria_edad: campo({ etiqueta: 'Categoría de edad', nombre: 'categoria_edad', tipo: 'select', valor: p.categoria_edad ?? '', opciones: CATEGORIAS_EDAD }),
        puesto: campo({ etiqueta: 'Puesto', nombre: 'puesto', tipo: 'number', valor: p.puesto, atributos: { min: 1, max: 99 } }),
        medalla: campo({ etiqueta: 'Medalla', nombre: 'medalla', tipo: 'select', valor: p.medalla ?? '', opciones: MEDALLAS }),
        resultado: campo({ etiqueta: 'Resultado', nombre: 'resultado', valor: p.resultado, atributos: { maxlength: 255 } }),
    };
    if (combate) {
        c.categoria_peso = campo({ etiqueta: 'Categoría de peso', nombre: 'categoria_peso', valor: p.categoria_peso, ayuda: 'Ej.: -45 o +80', atributos: { maxlength: 30 } });
        c.combates_ganados = campo({ etiqueta: 'Combates ganados', nombre: 'combates_ganados', tipo: 'number', valor: p.combates_ganados, atributos: { min: 0, max: 127 } });
        c.combates_perdidos = campo({ etiqueta: 'Combates perdidos', nombre: 'combates_perdidos', tipo: 'number', valor: p.combates_perdidos, atributos: { min: 0, max: 127 } });
    }
    c.notas_oponente = campo({ etiqueta: 'Notas sobre oponentes', nombre: 'notas_oponente', tipo: 'textarea', valor: p.notas_oponente, ayuda: 'Solo las ve el club.' });
    const rejilla = crear('div', { clase: 'rejilla-campos' });
    for (const [nombre, { envoltura }] of Object.entries(c)) {
        if (nombre === 'notas_oponente') envoltura.classList.add('campo-ancho');
        rejilla.append(envoltura);
    }
    const etiqueta = `${p.nombre} (${texto(MODALIDADES, p.modalidad)})`;
    const botonGuardar = crear('button', { clase: 'boton', texto: 'Guardar resultados', atributos: { type: 'submit' } });
    const botonQuitar = crear('button', { clase: 'boton boton-peligro', texto: 'Quitar del torneo', atributos: { type: 'button', 'aria-label': `Quitar a ${etiqueta}` } });
    const formulario = crear('form', { atributos: { novalidate: true } }, rejilla, crear('div', { clase: 'acciones' }, botonGuardar, botonQuitar));

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const numericos = ['puesto', 'combates_ganados', 'combates_perdidos'];
        const cambios = {};
        for (const [nombre, { control }] of Object.entries(c)) {
            const valor = numericos.includes(nombre) ? numeroONulo(control) : textoONulo(control);
            if (valor !== (p[nombre] ?? null)) cambios[nombre] = valor;
        }
        if (Object.keys(cambios).length === 0) {
            mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
            return;
        }
        try {
            await conBotonOcupado(botonGuardar, 'Guardando…', () =>
                competencias.actualizarParticipacion(p.deportista_documento, t.evento_id, p.modalidad, cambios));
            notificar(`Resultados de ${p.nombre} guardados.`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'nearest' });
        }
    });
    botonQuitar.addEventListener('click', async () => {
        const aceptar = await confirmar({
            titulo: 'Quitar participante',
            mensaje: [`Se quitará a ${etiqueta} de este torneo, con sus resultados.`],
            textoAceptar: 'Quitar',
            peligroso: true,
        });
        if (!aceptar) return;
        try {
            await competencias.quitarParticipante(p.deportista_documento, t.evento_id, p.modalidad);
            notificar(`${p.nombre} quitado del torneo.`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });

    return crear('details', { clase: 'participante' },
        crear('summary', {},
            crear('strong', { texto: p.nombre }), ` · ${p.deportista_documento} · ${texto(MODALIDADES, p.modalidad)}`,
            ` · ${texto(CATEGORIAS_EDAD, p.categoria_edad ?? '')}${p.categoria_peso ? ` ${p.categoria_peso}` : ''} · `,
            crear('span', { clase: 'ayuda', texto: resumenResultado(p) })),
        formulario);
}

// ---------------------------------------------------------------------------
// Inscribir (hasta 50 por petición) con la sugerencia de categorías
// ---------------------------------------------------------------------------
function seccionInscribir(t, recargar) {
    const aviso = avisoNuevo();
    const porInscribir = [];
    const lista = crear('ul', { clase: 'lista-marcas' });
    const modalidad = campo({ etiqueta: 'Modalidad', nombre: 'modalidad', tipo: 'select', valor: 'combate_individual', opciones: MODALIDADES });
    const botonInscribir = crear('button', { clase: 'boton', texto: 'Inscribir', atributos: { type: 'button' } });

    const pintarLista = () => {
        vaciar(lista);
        for (const [indice, item] of porInscribir.entries()) {
            const quitar = crear('button', {
                clase: 'boton boton-secundario boton-pequeno', texto: 'Quitar de la lista',
                atributos: { type: 'button', 'aria-label': `Quitar de la lista a ${item.documento}` },
            });
            quitar.addEventListener('click', () => {
                porInscribir.splice(indice, 1);
                pintarLista();
            });
            lista.append(crear('li', { clase: 'fila-inscripcion' },
                crear('span', {}, crear('strong', { texto: item.documento }), ` · ${texto(MODALIDADES, item.modalidad)}`, crear('br'),
                    crear('span', { clase: 'ayuda', texto: item.sugerencia })),
                quitar));
        }
        botonInscribir.textContent = porInscribir.length ? `Inscribir ${porInscribir.length}` : 'Inscribir';
        botonInscribir.disabled = porInscribir.length === 0;
    };

    const agregar = async (documento) => {
        limpiarAviso(aviso);
        if (porInscribir.length >= MAXIMO_INSCRIPCION) {
            mostrarAviso(aviso, `Máximo ${MAXIMO_INSCRIPCION} participantes por inscripción.`, 'error');
            return;
        }
        if (porInscribir.some((i) => i.documento === documento && i.modalidad === modalidad.control.value)) {
            mostrarAviso(aviso, 'Ese deportista ya está en la lista con esa modalidad.', 'error');
            return;
        }
        let sugerencia;
        try {
            const s = await competencias.sugerencia(documento, t.evento_id, modalidad.control.value);
            sugerencia = [
                `Edad a la fecha: ${textoDato(s.edad_a_la_fecha)}`,
                `categoría sugerida: ${texto(CATEGORIAS_EDAD, s.categoria_edad_sugerida ?? '')}`,
                `peso: ${textoDato(s.peso_registrado)} kg`,
                s.categoria_peso_sugerida ? `categoría de peso: ${s.categoria_peso_sugerida}` : null,
                s.recomendacion_peso || null,
            ].filter(Boolean).join(' · ');
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            return;
        }
        porInscribir.push({ documento, modalidad: modalidad.control.value, sugerencia });
        pintarLista();
    };

    botonInscribir.addEventListener('click', async () => {
        limpiarAviso(aviso);
        try {
            const creadas = await conBotonOcupado(botonInscribir, 'Inscribiendo…', () => competencias.inscribir(t.evento_id,
                porInscribir.map((i) => ({ deportista_documento: i.documento, modalidad: i.modalidad }))));
            notificar(`${creadas.length} inscripción(es) creadas.`);
            recargar();
        } catch (error) {
            // Todo o nada: la lista se conserva para corregirla.
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    pintarLista();

    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-inscribir' } },
        crear('h2', { texto: 'Inscribir participantes', atributos: { id: 'titulo-inscribir' } }),
        crear('p', { clase: 'ayuda', texto: 'Elige la modalidad y agrega deportistas a la lista (hasta 50). Las categorías se completan con la sugerencia de la API; se ajustan después en los resultados. Es todo o nada.' }),
        aviso,
        crear('div', { clase: 'filtros' }, modalidad.envoltura, selectorDeportista({ textoBoton: 'Agregar a la lista', alElegir: agregar })),
        lista,
        crear('div', { clase: 'acciones' }, botonInscribir));
}
