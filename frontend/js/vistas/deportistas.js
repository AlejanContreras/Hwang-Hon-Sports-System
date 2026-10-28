// Módulo Deportistas (ámbito administrativo) — catálogo B.3 y C.2.
import { deportistas, reportes } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoDato, textoNumero, textoFecha, textoGrado, textoEstado, textoEstadoRegistro, textoSexo,
    insignia, normalizarTexto, listaDatos, GRADOS,
} from '../ui.js';

// Filtros de la lista: se conservan al ir a una ficha y volver.
// estado, estado_registro y sede_entrenamiento van a la API; texto y grado se aplican aquí
// (grado «Ingresado» es null y la API no filtra nulos).
const filtros = { texto: '', estado: '', estado_registro: '', sede_entrenamiento: '', grado: '' };
const sedesConocidas = new Set();

function enlaceFicha(documento, texto) {
    return crear('a', { texto, atributos: { href: `#deportista/${encodeURIComponent(documento)}` } });
}

function insigniaEstado(deportista) {
    return insignia(textoEstado(deportista.estado), deportista.estado === 'activo' ? 'activo' : 'inactivo');
}

function insigniaRegistro(deportista) {
    return deportista.estado_registro === 'pendiente_validacion'
        ? insignia('Pendiente', 'pendiente')
        : insignia('Validado', 'activo');
}

function avisoVista() {
    return crear('div', { atributos: { hidden: true } });
}

// ===========================================================================
// Validar / rechazar (usados en la lista de pendientes y en la ficha)
// ===========================================================================
async function validar(deportista, aviso, alTerminar) {
    const aceptar = await confirmar({
        titulo: 'Validar registro',
        mensaje: [`Se validará el registro de ${deportista.nombre} (documento ${deportista.documento}).`,
            'Quedará registrado que tú lo validaste y ya podrá ingresar a «Mi perfil».'],
        textoAceptar: 'Validar',
    });
    if (!aceptar) return;
    try {
        await deportistas.actualizar(deportista.documento, { estado_registro: 'validado' });
        notificar(`Registro de ${deportista.nombre} validado.`);
        alTerminar();
    } catch (error) {
        mostrarAviso(aviso, error.message, 'error');
    }
}

async function rechazar(deportista, aviso, alTerminar) {
    const aceptar = await confirmar({
        titulo: 'Rechazar registro',
        mensaje: [`Se eliminará el registro pendiente de ${deportista.nombre} (documento ${deportista.documento}), con los documentos que haya cargado.`,
            'Esta acción no se puede deshacer.'],
        textoAceptar: 'Rechazar y eliminar',
        peligroso: true,
    });
    if (!aceptar) return;
    try {
        await deportistas.eliminar(deportista.documento);
        notificar(`Registro de ${deportista.nombre} rechazado y eliminado.`);
        alTerminar();
    } catch (error) {
        // 409 si ya tiene datos en otros módulos: se muestra el mensaje de la API tal cual.
        mostrarAviso(aviso, error.message, 'error');
        aviso.scrollIntoView({ block: 'nearest' });
    }
}

// ===========================================================================
// Lista
// ===========================================================================
function subpestanas(actual, totalPendientes) {
    const pendientes = totalPendientes > 0 ? `Pendientes de validación (${totalPendientes})` : 'Pendientes de validación';
    const enlace = (href, texto, clave) => crear('a', {
        texto,
        atributos: { href, 'aria-current': actual === clave ? 'page' : false },
    });
    return crear('nav', { clase: 'subpestanas', atributos: { 'aria-label': 'Listas de deportistas' } },
        enlace('#deportistas', 'Todos', 'todos'),
        enlace('#pendientes', pendientes, 'pendientes'),
    );
}

export async function mostrarLista(contenedor, pestana) {
    const aviso = avisoVista();
    const zonaPestanas = crear('div');
    const zonaResultado = crear('div');

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' },
            crear('h1', { texto: 'Deportistas' }),
            crear('a', { clase: 'boton', texto: 'Nuevo deportista', atributos: { href: '#deportista-nuevo' } }),
        ),
        zonaPestanas,
        aviso,
    );

    const pintarPestanas = (totalPendientes) => {
        vaciar(zonaPestanas);
        zonaPestanas.append(subpestanas(pestana, totalPendientes));
    };
    pintarPestanas(0);

    if (pestana === 'pendientes') {
        contenedor.append(zonaResultado);
        await cargarPendientes(zonaResultado, aviso, pintarPestanas);
    } else {
        contenedor.append(formularioFiltros(() => cargarTodos(zonaResultado, aviso)), zonaResultado);
        // El conteo de pendientes no bloquea la lista.
        deportistas.listar({ estado_registro: 'pendiente_validacion' })
            .then((lista) => pintarPestanas(lista.length))
            .catch(() => {});
        await cargarTodos(zonaResultado, aviso);
    }
}

function formularioFiltros(recargar) {
    let datosEnMemoria = null;
    const texto = campo({
        etiqueta: 'Buscar', nombre: 'texto', tipo: 'search', valor: filtros.texto,
        atributos: { placeholder: 'Documento o nombre', autocomplete: 'off' },
    });
    const estado = campo({
        etiqueta: 'Estado', nombre: 'estado', tipo: 'select', valor: filtros.estado,
        opciones: [{ valor: '', texto: 'Todos' }, { valor: 'activo', texto: 'Activo' }, { valor: 'inactivo', texto: 'Inactivo' }],
    });
    const registro = campo({
        etiqueta: 'Registro', nombre: 'estado_registro', tipo: 'select', valor: filtros.estado_registro,
        opciones: [{ valor: '', texto: 'Todos' }, { valor: 'validado', texto: 'Validado' }, { valor: 'pendiente_validacion', texto: 'Pendiente de validación' }],
    });
    const idListaSedes = 'lista-sedes';
    const sede = campo({
        etiqueta: 'Sede', nombre: 'sede_entrenamiento', valor: filtros.sede_entrenamiento,
        ayuda: 'Nombre exacto; Enter para aplicar.', atributos: { list: idListaSedes, autocomplete: 'off' },
    });
    const listaSedes = crear('datalist', { atributos: { id: idListaSedes } },
        ...[...sedesConocidas].sort().map((s) => crear('option', { atributos: { value: s } })));
    const grado = campo({
        etiqueta: 'Grado', nombre: 'grado', tipo: 'select', valor: filtros.grado,
        opciones: [{ valor: '', texto: 'Todos' }, { valor: 'ingresado', texto: 'Ingresado' },
            ...GRADOS.map((g) => ({ valor: normalizarTexto(g), texto: g }))],
    });
    const limpiar = crear('button', { clase: 'boton boton-secundario', texto: 'Limpiar filtros', atributos: { type: 'button' } });

    const formulario = crear('form', { clase: 'tarjeta', atributos: { role: 'search', 'aria-label': 'Filtrar deportistas' } },
        crear('div', { clase: 'filtros' },
            texto.envoltura, estado.envoltura, registro.envoltura, sede.envoltura, listaSedes, grado.envoltura,
            crear('div', { clase: 'campo' }, limpiar),
        ),
    );

    // Filtros de la API: recargan. Filtros locales: solo vuelven a pintar.
    const alCambiarApi = () => {
        filtros.estado = estado.control.value;
        filtros.estado_registro = registro.control.value;
        filtros.sede_entrenamiento = sede.control.value.trim();
        recargar();
    };
    estado.control.addEventListener('change', alCambiarApi);
    registro.control.addEventListener('change', alCambiarApi);
    sede.control.addEventListener('change', alCambiarApi);
    formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        alCambiarApi();
    });
    texto.control.addEventListener('input', () => {
        filtros.texto = texto.control.value;
        formulario.dispatchEvent(new CustomEvent('filtro-local'));
    });
    grado.control.addEventListener('change', () => {
        filtros.grado = grado.control.value;
        formulario.dispatchEvent(new CustomEvent('filtro-local'));
    });
    limpiar.addEventListener('click', () => {
        Object.assign(filtros, { texto: '', estado: '', estado_registro: '', sede_entrenamiento: '', grado: '' });
        texto.control.value = '';
        estado.control.value = '';
        registro.control.value = '';
        sede.control.value = '';
        grado.control.value = '';
        recargar();
    });
    // Mantener la referencia para que el pintado local no pida datos de nuevo.
    formulario.datosEnMemoria = () => datosEnMemoria;
    formulario.guardarDatos = (lista) => {
        datosEnMemoria = lista;
        for (const d of lista) if (d.sede_entrenamiento) sedesConocidas.add(d.sede_entrenamiento);
        vaciar(listaSedes);
        listaSedes.append(...[...sedesConocidas].sort().map((s) => crear('option', { atributos: { value: s } })));
    };
    return formulario;
}

function cumpleFiltrosLocales(deportista) {
    if (filtros.grado) {
        const grado = deportista.grado_actual ? normalizarTexto(deportista.grado_actual) : 'ingresado';
        if (grado !== filtros.grado) return false;
    }
    const texto = normalizarTexto(filtros.texto);
    if (texto) {
        // Igual que deportistas.buscar: documento que empieza por el texto o nombre que lo contiene.
        return deportista.documento.startsWith(texto) || normalizarTexto(deportista.nombre).includes(texto);
    }
    return true;
}

async function cargarTodos(zona, aviso) {
    const formulario = zona.previousElementSibling;
    limpiarAviso(aviso);
    cargando(zona);
    let lista;
    try {
        lista = await deportistas.listar({
            estado: filtros.estado,
            estado_registro: filtros.estado_registro,
            sede_entrenamiento: filtros.sede_entrenamiento,
        });
    } catch (error) {
        vaciar(zona);
        mostrarAviso(aviso, error.message, 'error');
        return;
    }
    formulario.guardarDatos(lista);

    const pintar = () => pintarTablaTodos(zona, formulario.datosEnMemoria().filter(cumpleFiltrosLocales));
    // Un solo oyente por formulario: se reemplaza en cada recarga.
    if (formulario.oyenteLocal) formulario.removeEventListener('filtro-local', formulario.oyenteLocal);
    formulario.oyenteLocal = pintar;
    formulario.addEventListener('filtro-local', pintar);
    pintar();
}

function pintarTablaTodos(zona, lista) {
    vaciar(zona);
    const resumen = crear('p', {
        clase: 'ayuda',
        texto: lista.length === 1 ? '1 deportista' : `${lista.length} deportistas`,
        atributos: { role: 'status' },
    });
    if (lista.length === 0) {
        zona.append(resumen, crear('p', { texto: 'No hay deportistas que cumplan los filtros.' }));
        return;
    }
    const cuerpo = crear('tbody');
    for (const d of lista) {
        cuerpo.append(crear('tr', {},
            crear('td', {}, enlaceFicha(d.documento, d.documento)),
            crear('td', {}, enlaceFicha(d.documento, d.nombre)),
            crear('td', { texto: textoDato(d.edad) }),
            crear('td', { texto: textoDato(d.categoria_edad) }),
            crear('td', { texto: textoGrado(d.grado_actual) }),
            crear('td', { texto: textoDato(d.sede_entrenamiento) }),
            crear('td', {}, insigniaEstado(d)),
            crear('td', {}, insigniaRegistro(d)),
        ));
    }
    zona.append(resumen, crear('div', { clase: 'contenedor-tabla' },
        crear('table', {},
            crear('caption', { clase: 'solo-lectores', texto: 'Lista de deportistas' }),
            crear('thead', {}, crear('tr', {},
                ...['Documento', 'Nombre', 'Edad', 'Categoría', 'Grado', 'Sede', 'Estado', 'Registro']
                    .map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            cuerpo,
        ),
    ));
}

async function cargarPendientes(zona, aviso, pintarPestanas) {
    cargando(zona);
    let lista;
    try {
        lista = await deportistas.listar({ estado_registro: 'pendiente_validacion' });
    } catch (error) {
        vaciar(zona);
        mostrarAviso(aviso, error.message, 'error');
        return;
    }
    pintarPestanas(lista.length);
    vaciar(zona);

    if (lista.length === 0) {
        zona.append(crear('p', { clase: 'tarjeta', texto: 'No hay registros pendientes de validación.' }));
        return;
    }
    const recargar = () => {
        limpiarAviso(aviso);
        cargarPendientes(zona, aviso, pintarPestanas);
    };
    const cuerpo = crear('tbody');
    for (const d of lista) {
        cuerpo.append(crear('tr', {},
            crear('td', { texto: d.documento }),
            crear('td', {}, enlaceFicha(d.documento, d.nombre)),
            crear('td', { texto: textoDato(d.edad) }),
            crear('td', { texto: textoFecha(d.fecha_ingreso) }),
            crear('td', { texto: textoDato(d.sede_entrenamiento) }),
            crear('td', { clase: 'acciones-celda' },
                crear('button', {
                    clase: 'boton boton-exito boton-pequeno', texto: 'Validar',
                    atributos: { type: 'button', 'aria-label': `Validar a ${d.nombre}` },
                    eventos: { click: () => validar(d, aviso, recargar) },
                }),
                crear('button', {
                    clase: 'boton boton-peligro boton-pequeno', texto: 'Rechazar',
                    atributos: { type: 'button', 'aria-label': `Rechazar a ${d.nombre}` },
                    eventos: { click: () => rechazar(d, aviso, recargar) },
                }),
            ),
        ));
    }
    zona.append(
        crear('p', { clase: 'ayuda', texto: 'Validar deja el registro activo en el club. Rechazar lo elimina junto con sus documentos; si ya tiene datos en otros módulos, la API no lo permite.' }),
        crear('div', { clase: 'contenedor-tabla' },
            crear('table', {},
                crear('caption', { clase: 'solo-lectores', texto: 'Registros pendientes de validación' }),
                crear('thead', {}, crear('tr', {},
                    ...['Documento', 'Nombre', 'Edad', 'Ingreso', 'Sede', 'Acciones']
                        .map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
                cuerpo,
            ),
        ),
    );
}

// ===========================================================================
// Ficha
// ===========================================================================
export async function mostrarFicha(contenedor, documento) {
    if (!documento) {
        window.location.replace('#deportistas');
        return;
    }
    cargando(contenedor);
    const d = await deportistas.consultar(documento);
    vaciar(contenedor);

    const aviso = avisoVista();
    const recargar = () => {
        vaciar(contenedor);
        mostrarFicha(contenedor, documento).catch((error) => {
            contenedor.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        });
    };
    const pendiente = d.estado_registro === 'pendiente_validacion';

    const botonPdf = crear('button', { clase: 'boton boton-secundario', texto: 'Descargar ficha PDF', atributos: { type: 'button' } });
    botonPdf.addEventListener('click', async () => {
        limpiarAviso(aviso);
        try {
            const nombre = await conBotonOcupado(botonPdf, 'Generando…', () => reportes.deportistaFicha(d.documento));
            notificar(`Descargado: ${nombre}`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });

    const acciones = [
        crear('a', { clase: 'boton', texto: 'Editar', atributos: { href: `#deportista-editar/${encodeURIComponent(d.documento)}` } }),
        botonPdf,
    ];
    if (pendiente) {
        acciones.push(
            crear('button', { clase: 'boton boton-exito', texto: 'Validar', atributos: { type: 'button' }, eventos: { click: () => validar(d, aviso, recargar) } }),
            crear('button', {
                clase: 'boton boton-peligro', texto: 'Rechazar', atributos: { type: 'button' },
                eventos: { click: () => rechazar(d, aviso, () => window.location.assign('#pendientes')) },
            }),
        );
    } else {
        acciones.push(botonCambioEstado(d, aviso, recargar));
    }

    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: pendiente ? '← Pendientes de validación' : '← Deportistas', atributos: { href: pendiente ? '#pendientes' : '#deportistas' } })),
        crear('div', { clase: 'encabezado-vista' },
            crear('div', {},
                crear('h1', { texto: d.nombre }),
                crear('p', { clase: 'ayuda' }, `Documento ${d.documento} · `, insigniaEstado(d), ' ', insigniaRegistro(d)),
            ),
        ),
        aviso,
        crear('div', { clase: 'acciones', atributos: { style: 'margin: 0 0 1rem' } }, ...acciones),
        seccionFicha('Datos personales', [
            ['Fecha de nacimiento', textoFecha(d.fecha_nacimiento)],
            ['Edad', d.edad === null ? null : `${d.edad} años`],
            ['Lugar de nacimiento', d.lugar_nacimiento],
            ['Sexo', textoSexo(d.sexo)],
            ['Estatura', textoNumero(d.estatura, 'm')],
            ['Peso', textoNumero(d.peso, 'kg')],
            ['Categoría de edad', d.categoria_edad],
            ['Categoría de peso', d.categoria_peso],
        ]),
        seccionFicha('Contacto', [
            ['Teléfono', d.telefono],
            ['Dirección', d.direccion],
            ['Correo', d.correo],
        ]),
        seccionFicha('Club', [
            ['Sede de entrenamiento', d.sede_entrenamiento],
            ['Grado actual', textoGrado(d.grado_actual)],
            ['Fecha de ingreso', textoFecha(d.fecha_ingreso)],
            ['Estado', textoEstado(d.estado)],
            ['Registro', textoEstadoRegistro(d.estado_registro)],
            ['Validado por', d.validado_por_correo],
            ['Autorización de datos', d.autorizacion_datos],
            ['Combates ganados / perdidos', `${textoDato(d.combates_ganados)} / ${textoDato(d.combates_perdidos)}`],
        ]),
        seccionFicha('Salud y experiencia', [
            ['Condición de salud', d.discapacidad],
            ['Experiencia previa', crear('span', { clase: 'texto-largo', texto: textoDato(d.experiencia_previa) })],
        ]),
        d.acudiente
            ? seccionFicha('Acudiente', [
                ['Documento', d.acudiente.documento],
                ['Nombre', d.acudiente.nombre],
                ['Relación', d.acudiente.relacion],
                ['Teléfono', d.acudiente.telefono],
                ['Correo', d.acudiente.correo],
            ])
            : crear('section', { clase: 'tarjeta' }, crear('h2', { texto: 'Acudiente' }), crear('p', { texto: 'Sin acudiente registrado.' })),
    );
}

function seccionFicha(titulo, pares) {
    return crear('section', { clase: 'tarjeta' }, crear('h2', { texto: titulo }), listaDatos(pares));
}

function botonCambioEstado(d, aviso, recargar) {
    const aInactivo = d.estado === 'activo';
    const boton = crear('button', {
        clase: aInactivo ? 'boton boton-peligro' : 'boton boton-secundario',
        texto: aInactivo ? 'Pasar a inactivo' : 'Pasar a activo',
        atributos: { type: 'button' },
    });
    boton.addEventListener('click', async () => {
        const aceptar = await confirmar({
            titulo: aInactivo ? 'Pasar a inactivo' : 'Pasar a activo',
            mensaje: aInactivo
                ? [`${d.nombre} quedará inactivo: no aparecerá en la toma de asistencia de activos ni generará alertas de pago.`,
                    'Si vuelve y se marca presente en asistencia, se reactiva solo.']
                : [`${d.nombre} quedará activo de nuevo.`],
            textoAceptar: aInactivo ? 'Pasar a inactivo' : 'Pasar a activo',
            peligroso: aInactivo,
        });
        if (!aceptar) return;
        try {
            await conBotonOcupado(boton, 'Guardando…', () =>
                deportistas.actualizar(d.documento, { estado: aInactivo ? 'inactivo' : 'activo' }));
            notificar(aInactivo ? `${d.nombre} pasó a inactivo.` : `${d.nombre} pasó a activo.`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return boton;
}

// ===========================================================================
// Formulario (crear / editar)
// ===========================================================================

// Campos de la ficha que el formulario maneja, en el orden de la API (B.3 crear).
const CAMPOS_TEXTO_OPCIONALES = ['lugar_nacimiento', 'telefono', 'direccion', 'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento'];

export async function mostrarFormulario(contenedor, documento) {
    const esEdicion = Boolean(documento);
    let original = null;
    if (esEdicion) {
        cargando(contenedor);
        original = await deportistas.consultar(documento);
        vaciar(contenedor);
    }
    const valor = (nombre) => (original ? original[nombre] : undefined);

    const aviso = avisoVista();
    const c = {}; // controles por nombre de campo de la API

    const agregar = (destino, definicion, ancho = false) => {
        const { envoltura, control } = campo(definicion);
        if (ancho) envoltura.classList.add('campo-ancho');
        c[definicion.nombre] = control;
        destino.append(envoltura);
    };

    // --- Identificación
    const rejillaId = crear('div', { clase: 'rejilla-campos' });
    if (esEdicion) {
        rejillaId.append(crear('div', { clase: 'campo' },
            crear('span', { clase: 'ayuda', texto: 'Documento' }),
            crear('strong', { texto: original.documento }),
            crear('small', { clase: 'ayuda', texto: 'El documento no se puede cambiar.' })));
    } else {
        agregar(rejillaId, { etiqueta: 'Documento', nombre: 'documento', obligatorio: true, atributos: { maxlength: 20, inputmode: 'numeric', autocomplete: 'off' } });
    }
    agregar(rejillaId, { etiqueta: 'Nombre completo', nombre: 'nombre', obligatorio: true, valor: valor('nombre'), atributos: { maxlength: 150 } });
    agregar(rejillaId, { etiqueta: 'Fecha de nacimiento', nombre: 'fecha_nacimiento', tipo: 'date', obligatorio: true, valor: valor('fecha_nacimiento') });
    agregar(rejillaId, { etiqueta: 'Lugar de nacimiento', nombre: 'lugar_nacimiento', valor: valor('lugar_nacimiento'), atributos: { maxlength: 100 } });
    agregar(rejillaId, {
        etiqueta: 'Sexo', nombre: 'sexo', tipo: 'select', obligatorio: true, valor: valor('sexo') ?? '',
        opciones: [{ valor: '', texto: 'Selecciona…' }, { valor: 'masculino', texto: 'Masculino' }, { valor: 'femenino', texto: 'Femenino' }],
    });
    agregar(rejillaId, { etiqueta: 'Estatura (m)', nombre: 'estatura', tipo: 'number', obligatorio: true, valor: valor('estatura'), ayuda: 'En metros, p. ej. 1.55', atributos: { step: '0.01', min: '0.01', max: '99.99', inputmode: 'decimal' } });
    agregar(rejillaId, { etiqueta: 'Peso (kg)', nombre: 'peso', tipo: 'number', obligatorio: true, valor: valor('peso'), atributos: { step: '0.01', min: '0.01', max: '999.99', inputmode: 'decimal' } });

    // --- Contacto
    const rejillaContacto = crear('div', { clase: 'rejilla-campos' });
    agregar(rejillaContacto, { etiqueta: 'Teléfono', nombre: 'telefono', tipo: 'tel', valor: valor('telefono'), ayuda: 'Dígitos, espacios y + inicial.', atributos: { maxlength: 20 } });
    agregar(rejillaContacto, { etiqueta: 'Correo', nombre: 'correo', tipo: 'email', valor: valor('correo'), atributos: { maxlength: 150 } });
    agregar(rejillaContacto, { etiqueta: 'Dirección', nombre: 'direccion', valor: valor('direccion'), atributos: { maxlength: 150 } }, true);

    // --- Club
    const rejillaClub = crear('div', { clase: 'rejilla-campos' });
    agregar(rejillaClub, { etiqueta: 'Sede de entrenamiento', nombre: 'sede_entrenamiento', valor: valor('sede_entrenamiento'), atributos: { maxlength: 100 } });
    const opcionesGrado = [{ valor: '', texto: 'Ingresado' }, ...GRADOS.map((g) => ({ valor: g, texto: g }))];
    // Un grado guardado con otra escritura (p. ej. «amarillo») se muestra con la opción equivalente.
    const gradoGuardado = valor('grado_actual');
    const gradoEquivalente = gradoGuardado ? GRADOS.find((g) => normalizarTexto(g) === normalizarTexto(gradoGuardado)) : '';
    if (gradoGuardado && !gradoEquivalente) opcionesGrado.push({ valor: gradoGuardado, texto: gradoGuardado });
    agregar(rejillaClub, { etiqueta: 'Grado actual', nombre: 'grado_actual', tipo: 'select', valor: gradoEquivalente || gradoGuardado || '', opciones: opcionesGrado });
    agregar(rejillaClub, { etiqueta: 'Fecha de ingreso', nombre: 'fecha_ingreso', tipo: 'date', valor: valor('fecha_ingreso'), ayuda: esEdicion ? '' : 'Si se deja vacía, la API usa la fecha de hoy.' });
    agregar(rejillaClub, {
        etiqueta: 'Estado', nombre: 'estado', tipo: 'select', valor: valor('estado') ?? 'activo',
        opciones: [{ valor: 'activo', texto: 'Activo' }, { valor: 'inactivo', texto: 'Inactivo' }],
    });
    agregar(rejillaClub, { etiqueta: 'Autoriza el tratamiento de datos', nombre: 'autorizacion_datos', tipo: 'checkbox', valor: valor('autorizacion_datos') ?? false }, true);

    // --- Salud y experiencia
    const rejillaSalud = crear('div', { clase: 'rejilla-campos' });
    agregar(rejillaSalud, { etiqueta: 'Condición de salud', nombre: 'discapacidad', valor: valor('discapacidad'), ayuda: 'Discapacidad o condición que el club debe conocer.', atributos: { maxlength: 255 } }, true);
    agregar(rejillaSalud, { etiqueta: 'Experiencia previa', nombre: 'experiencia_previa', tipo: 'textarea', valor: valor('experiencia_previa'), atributos: { maxlength: 16000 } }, true);

    // --- Acudiente
    const acudienteOriginal = original ? original.acudiente : null;
    const a = {};
    const rejillaAcudiente = crear('div', { clase: 'rejilla-campos' });
    const agregarAcudiente = (definicion) => {
        const { envoltura, control } = campo({ ...definicion, nombre: `acudiente_${definicion.nombre}` });
        a[definicion.nombre] = control;
        rejillaAcudiente.append(envoltura);
    };
    const va = (nombre) => (acudienteOriginal ? acudienteOriginal[nombre] : '');
    agregarAcudiente({ etiqueta: 'Documento del acudiente', nombre: 'documento', valor: va('documento'), atributos: { maxlength: 20, inputmode: 'numeric', autocomplete: 'off' } });
    agregarAcudiente({ etiqueta: 'Nombre del acudiente', nombre: 'nombre', valor: va('nombre'), atributos: { maxlength: 150 } });
    agregarAcudiente({ etiqueta: 'Relación', nombre: 'relacion', valor: va('relacion'), ayuda: 'Madre, padre, tío…', atributos: { maxlength: 30 } });
    agregarAcudiente({ etiqueta: 'Teléfono del acudiente', nombre: 'telefono', tipo: 'tel', valor: va('telefono'), atributos: { maxlength: 20 } });
    agregarAcudiente({ etiqueta: 'Correo del acudiente', nombre: 'correo', tipo: 'email', valor: va('correo'), atributos: { maxlength: 150 } });

    const ayudaAcudiente = esEdicion
        ? 'Obligatorio si es menor de 18 años. Con el mismo documento se editan los datos del acudiente actual; con otro documento se asigna otro acudiente (si ya existe, se reutiliza). Vaciar todo lo quita (solo mayores de edad).'
        : 'Obligatorio si es menor de 18 años. Si el acudiente ya está registrado (p. ej. por un hermano) basta su documento: se reutiliza sin modificarlo.';

    const botonGuardar = crear('button', { clase: 'boton', texto: esEdicion ? 'Guardar cambios' : 'Crear deportista', atributos: { type: 'submit' } });
    const urlVolver = esEdicion ? `#deportista/${encodeURIComponent(documento)}` : '#deportistas';

    const formulario = crear('form', { atributos: { novalidate: true, 'aria-labelledby': 'titulo-formulario' } },
        crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: 'Identificación y medidas' }), rejillaId),
        crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: 'Contacto' }), rejillaContacto),
        crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: 'Club' }), rejillaClub),
        crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: 'Salud y experiencia' }), rejillaSalud),
        crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: 'Acudiente' }),
            crear('p', { clase: 'ayuda', texto: ayudaAcudiente }), rejillaAcudiente),
        crear('div', { clase: 'acciones' },
            botonGuardar,
            crear('a', { clase: 'boton boton-secundario', texto: 'Cancelar', atributos: { href: urlVolver } }),
        ),
    );

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const cuerpo = esEdicion ? cambiosEdicion(c, a, original) : datosCreacion(c, a);
        if (esEdicion && Object.keys(cuerpo).length === 0) {
            mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
            aviso.scrollIntoView({ block: 'nearest' });
            return;
        }
        try {
            const resultado = await conBotonOcupado(botonGuardar, 'Guardando…', () =>
                (esEdicion ? deportistas.actualizar(documento, cuerpo) : deportistas.crear(cuerpo)));
            notificar(esEdicion ? 'Cambios guardados.' : `Deportista ${resultado.nombre} creado.`);
            window.location.assign(`#deportista/${encodeURIComponent(resultado.documento)}`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
            aviso.scrollIntoView({ block: 'center' });
        }
    });

    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: esEdicion ? '← Volver a la ficha' : '← Deportistas', atributos: { href: urlVolver } })),
        crear('div', { clase: 'encabezado-vista' },
            crear('h1', { texto: esEdicion ? `Editar: ${original.nombre}` : 'Nuevo deportista', atributos: { id: 'titulo-formulario' } })),
        crear('p', { clase: 'ayuda' }, 'Los campos con ', crear('span', { clase: 'obligatorio', texto: '*' }), ' son obligatorios.'),
        aviso,
        formulario,
    );
    (esEdicion ? c.nombre : c.documento).focus();
}

// Texto vacío → null (en una actualización, null borra el dato opcional).
function textoONulo(control) {
    const v = control.value.trim();
    return v === '' ? null : v;
}

function numeroONulo(control) {
    const v = control.value.trim();
    return v === '' ? null : Number(v);
}

function leerFormulario(c) {
    const datos = {
        nombre: textoONulo(c.nombre),
        fecha_nacimiento: textoONulo(c.fecha_nacimiento),
        sexo: textoONulo(c.sexo),
        estatura: numeroONulo(c.estatura),
        peso: numeroONulo(c.peso),
        grado_actual: textoONulo(c.grado_actual),
        fecha_ingreso: textoONulo(c.fecha_ingreso),
        estado: c.estado.value,
        autorizacion_datos: c.autorizacion_datos.checked,
    };
    for (const nombre of CAMPOS_TEXTO_OPCIONALES) datos[nombre] = textoONulo(c[nombre]);
    return datos;
}

function leerAcudiente(a) {
    const datos = {};
    for (const [nombre, control] of Object.entries(a)) datos[nombre] = textoONulo(control);
    return datos;
}

function sinNulos(objeto) {
    return Object.fromEntries(Object.entries(objeto).filter(([, v]) => v !== null));
}

function datosCreacion(c, a) {
    const datos = sinNulos({ documento: textoONulo(c.documento), ...leerFormulario(c) });
    const acudiente = sinNulos(leerAcudiente(a));
    if (Object.keys(acudiente).length > 0) datos.acudiente = acudiente;
    return datos;
}

function iguales(nuevo, anterior) {
    if (typeof nuevo === 'number' && anterior !== null && anterior !== undefined) return nuevo === Number(anterior);
    return (nuevo ?? null) === (anterior ?? null);
}

function cambiosEdicion(c, a, original) {
    const cambios = {};
    for (const [nombre, nuevo] of Object.entries(leerFormulario(c))) {
        if (nombre === 'grado_actual') {
            // Mismo grado con otra escritura (amarillo / Amarillo): no es un cambio.
            if (normalizarTexto(nuevo ?? '') === normalizarTexto(original.grado_actual ?? '')) continue;
        } else if (iguales(nuevo, original[nombre])) {
            continue;
        }
        cambios[nombre] = nuevo;
    }

    const nuevoAcudiente = leerAcudiente(a);
    const anterior = original.acudiente;
    const todoVacio = Object.values(nuevoAcudiente).every((v) => v === null);
    if (todoVacio) {
        if (anterior) cambios.acudiente = null;
    } else if (anterior && nuevoAcudiente.documento === anterior.documento) {
        // Editar el acudiente actual: solo lo que cambió.
        const editados = {};
        for (const nombre of ['nombre', 'relacion', 'telefono', 'correo']) {
            if (!iguales(nuevoAcudiente[nombre], anterior[nombre])) editados[nombre] = nuevoAcudiente[nombre];
        }
        if (Object.keys(editados).length > 0) cambios.acudiente = editados;
    } else {
        // Acudiente nuevo o reasignado: se envían los datos escritos.
        cambios.acudiente = sinNulos(nuevoAcudiente);
    }
    return cambios;
}
