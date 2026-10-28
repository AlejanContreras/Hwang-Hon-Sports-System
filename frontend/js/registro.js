// Autorregistro público (catálogo B.4), sin sesión.
// 1) Formulario del deportista (+ acudiente si es menor: la API decide) y autorización de datos.
// 2) Permiso de 2 horas para cargar documentos en la misma cookie.
// 3) «Confirmar»: cierra el permiso; el registro queda en revisión hasta que el club lo valide.
import { autorregistro } from './api.js';
import { crear, vaciar, mostrarAviso, limpiarAviso, notificar, campo, conBotonOcupado, cargando, textoFecha } from './ui.js';
import { TIPOS, textoTipo, tabla, formularioSubida, botonReemplazar, botonEliminar } from './vistas/documentos.js';

const zona = document.getElementById('zona-registro');

const formatoHora = new Intl.DateTimeFormat('es-CO', {
    timeZone: 'America/Bogota', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit',
});

const AVISO_REVISION = 'El club revisará y validará tu registro. Mientras tanto no podrás ingresar a «Mi perfil»; cuando lo validen, entra con el documento y la fecha de nacimiento del deportista.';

function avisoFijo(texto, tipo) {
    return crear('div', { clase: `aviso aviso-${tipo}`, texto, atributos: { role: tipo === 'error' ? 'alert' : 'status' } });
}

function textoONulo(control) {
    const v = control.value.trim();
    return v === '' ? null : v;
}

// ===========================================================================
// Paso 1: formulario
// ===========================================================================
function mostrarFormulario() {
    vaciar(zona);
    const aviso = crear('div', { atributos: { hidden: true } });
    const c = {};
    const grupo = (leyenda, ayuda, definiciones) => {
        const rejilla = crear('div', { clase: 'rejilla-campos' });
        for (const d of definiciones) {
            const { envoltura, control } = campo(d);
            if (d.ancho) envoltura.classList.add('campo-ancho');
            c[d.nombre] = control;
            rejilla.append(envoltura);
        }
        return crear('fieldset', { clase: 'tarjeta' }, crear('legend', { texto: leyenda }), ayuda ? crear('p', { clase: 'ayuda', texto: ayuda }) : null, rejilla);
    };

    const formulario = crear('form', { atributos: { novalidate: true, 'aria-label': 'Registro de deportista' } },
        grupo('Datos del deportista', null, [
            { etiqueta: 'Documento', nombre: 'documento', obligatorio: true, atributos: { maxlength: 20, inputmode: 'numeric', autocomplete: 'off' } },
            { etiqueta: 'Nombre completo', nombre: 'nombre', obligatorio: true, atributos: { maxlength: 150 } },
            { etiqueta: 'Fecha de nacimiento', nombre: 'fecha_nacimiento', tipo: 'date', obligatorio: true },
            { etiqueta: 'Lugar de nacimiento', nombre: 'lugar_nacimiento', atributos: { maxlength: 100 } },
            { etiqueta: 'Sexo', nombre: 'sexo', tipo: 'select', obligatorio: true, opciones: [{ valor: '', texto: 'Selecciona…' }, { valor: 'masculino', texto: 'Masculino' }, { valor: 'femenino', texto: 'Femenino' }] },
            { etiqueta: 'Estatura (m)', nombre: 'estatura', tipo: 'number', obligatorio: true, ayuda: 'En metros, p. ej. 1.55', atributos: { step: '0.01', min: '0.01', max: '99.99', inputmode: 'decimal' } },
            { etiqueta: 'Peso (kg)', nombre: 'peso', tipo: 'number', obligatorio: true, atributos: { step: '0.01', min: '0.01', max: '999.99', inputmode: 'decimal' } },
            { etiqueta: 'Sede de entrenamiento', nombre: 'sede_entrenamiento', atributos: { maxlength: 100 } },
        ]),
        grupo('Contacto', null, [
            { etiqueta: 'Teléfono', nombre: 'telefono', tipo: 'tel', ayuda: 'Dígitos, espacios y + inicial.', atributos: { maxlength: 20 } },
            { etiqueta: 'Correo', nombre: 'correo', tipo: 'email', atributos: { maxlength: 150 } },
            { etiqueta: 'Dirección', nombre: 'direccion', ancho: true, atributos: { maxlength: 150 } },
        ]),
        grupo('Salud y experiencia', null, [
            { etiqueta: 'Condición de salud', nombre: 'discapacidad', ancho: true, ayuda: 'Discapacidad o condición que el club debe conocer.', atributos: { maxlength: 255 } },
            { etiqueta: 'Experiencia previa', nombre: 'experiencia_previa', tipo: 'textarea', ancho: true, ayuda: 'Club, maestro, grado alcanzado y año, si practicaste antes.', atributos: { maxlength: 16000 } },
        ]),
        grupo('Acudiente', 'Obligatorio si el deportista es menor de 18 años: nombre y relación son obligatorios.', [
            { etiqueta: 'Documento del acudiente', nombre: 'acudiente_documento', atributos: { maxlength: 20, inputmode: 'numeric', autocomplete: 'off' } },
            { etiqueta: 'Nombre del acudiente', nombre: 'acudiente_nombre', atributos: { maxlength: 150 } },
            { etiqueta: 'Relación', nombre: 'acudiente_relacion', ayuda: 'Madre, padre, tío…', atributos: { maxlength: 30 } },
            { etiqueta: 'Teléfono del acudiente', nombre: 'acudiente_telefono', tipo: 'tel', atributos: { maxlength: 20 } },
            { etiqueta: 'Correo del acudiente', nombre: 'acudiente_correo', tipo: 'email', atributos: { maxlength: 150 } },
        ]),
    );

    // Campo trampa (B.4): oculto, vacío y fuera del orden de tabulación.
    const trampa = crear('input', { atributos: { type: 'text', name: 'sitio_web', tabindex: '-1', autocomplete: 'off' } });
    formulario.append(crear('div', { clase: 'campo-trampa', atributos: { 'aria-hidden': 'true' } },
        crear('label', { texto: 'Sitio web (no llenar)' }, trampa)));

    const autorizacion = campo({ etiqueta: 'Autorizo el tratamiento de los datos personales del deportista para la gestión del club.', nombre: 'autorizacion_datos', tipo: 'checkbox' });
    const boton = crear('button', { clase: 'boton', texto: 'Enviar registro', atributos: { type: 'submit' } });
    formulario.append(crear('div', { clase: 'tarjeta' }, autorizacion.envoltura, aviso, crear('div', { clase: 'acciones' }, boton)));

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const datos = { autorizacion_datos: autorizacion.control.checked, sitio_web: trampa.value };
        for (const nombre of ['documento', 'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'sede_entrenamiento', 'telefono', 'correo', 'direccion', 'discapacidad', 'experiencia_previa']) {
            const v = textoONulo(c[nombre]);
            if (v !== null) datos[nombre] = v;
        }
        for (const nombre of ['estatura', 'peso']) {
            const v = textoONulo(c[nombre]);
            if (v !== null) datos[nombre] = Number(v);
        }
        const acudiente = {};
        for (const nombre of ['documento', 'nombre', 'relacion', 'telefono', 'correo']) {
            const v = textoONulo(c[`acudiente_${nombre}`]);
            if (v !== null) acudiente[nombre] = v;
        }
        if (Object.keys(acudiente).length) datos.acudiente = acudiente;

        try {
            const respuesta = await conBotonOcupado(boton, 'Enviando…', () => autorregistro.registrar(datos));
            mostrarRegistrado(respuesta);
        } catch (error) {
            // 400 (campos), 413 (más de 128 KB) y 429 (límite por conexión): mensaje de la API tal cual.
            mostrarAviso(aviso, error.message, error.estado === 429 ? 'advertencia' : 'error');
            aviso.scrollIntoView({ block: 'center' });
        }
    });

    zona.append(
        avisoFijo(AVISO_REVISION, 'info'),
        crear('p', { clase: 'ayuda' }, 'Los campos con ', crear('span', { clase: 'obligatorio', texto: '*' }), ' son obligatorios.'),
        formulario);
    c.documento.focus();
}

// ===========================================================================
// Paso 2: documentos con el permiso de 2 horas
// ===========================================================================
function mostrarRegistrado(respuesta) {
    vaciar(zona);
    zona.append(avisoFijo(respuesta.mensaje, 'exito'));
    if (!respuesta.documentos || !respuesta.documentos.carga_habilitada) {
        zona.append(crear('div', { clase: 'tarjeta' },
            crear('p', { texto: 'No hay carga de documentos habilitada para este registro. Si necesitas enviar documentos, comunícate con el club.' })));
        return;
    }
    mostrarDocumentos(respuesta.mensaje);
}

// mensajePrevio: el mensaje del registro, que se conserva si el permiso no deja cargar documentos.
async function mostrarDocumentos(mensajePrevio = null) {
    const avisoDocs = crear('div', { atributos: { hidden: true } });
    const vigencia = crear('p', { clase: 'ayuda' });
    const zonaLista = crear('div');
    const zonaSubida = crear('div');
    const botonConfirmar = crear('button', { clase: 'boton boton-exito', texto: 'Confirmar y terminar', atributos: { type: 'button' } });

    const sinPermiso = (error) => {
        // 403: el permiso se confirmó, venció o no existe.
        vaciar(zona);
        if (mensajePrevio) zona.append(avisoFijo(mensajePrevio, 'exito'));
        zona.append(avisoFijo(error.message, 'advertencia'), crear('p', {}, crear('a', { texto: 'Volver al ingreso', atributos: { href: 'index.html' } })));
    };
    const manejar = async (tarea) => {
        try {
            await tarea();
        } catch (error) {
            if (error.estado === 403) sinPermiso(error);
            else throw error;
        }
    };

    const recargar = async () => {
        cargando(zonaLista);
        let datos;
        try {
            datos = await autorregistro.listarDocumentos();
        } catch (error) {
            if (error.estado === 403 || error.estado === 401) sinPermiso(error);
            else mostrarAviso(avisoDocs, error.message, 'error');
            return;
        }
        vigencia.textContent = `Puedes cargar documentos hasta el ${formatoHora.format(new Date(datos.vigente_hasta))} (hora de Colombia).`;
        vaciar(zonaLista);
        zonaLista.append(datos.documentos.length === 0
            ? crear('p', { texto: 'Aún no has cargado documentos.' })
            : tabla('Documentos cargados', ['Tipo', 'Archivo', 'Fecha', 'Acciones'], datos.documentos.map((doc) => {
                const tipo = TIPOS.find((t) => t.valor === doc.tipo_documento);
                const etiqueta = `${textoTipo(doc.tipo_documento)} (${doc.nombre_original})`;
                return crear('tr', {},
                    crear('td', { texto: textoTipo(doc.tipo_documento) }),
                    crear('td', { texto: doc.nombre_original }),
                    crear('td', { texto: textoFecha(doc.fecha_carga) }),
                    crear('td', { clase: 'acciones-celda' },
                        ...botonReemplazar(etiqueta, tipo.formatos, avisoDocs, (archivo) => manejar(async () => {
                            await autorregistro.reemplazarDocumento(doc.id, archivo);
                            notificar('Documento reemplazado.');
                            await recargar();
                        })),
                        botonEliminar(etiqueta, avisoDocs, () => manejar(async () => {
                            await autorregistro.eliminarDocumento(doc.id);
                            notificar('Documento eliminado.');
                            await recargar();
                        }))));
            })));

        const cargados = new Set(datos.documentos.map((doc) => doc.tipo_documento));
        const vacios = TIPOS.filter((t) => t.unico && !cargados.has(t.valor));
        vaciar(zonaSubida);
        zonaSubida.append(vacios.length === 0
            ? crear('p', { texto: 'Ya cargaste los cuatro documentos. Puedes reemplazarlos o confirmar.' })
            : formularioSubida({
                tipos: vacios,
                alSubir: (archivo, tipo) => manejar(async () => {
                    await autorregistro.subirDocumento(tipo, archivo);
                    notificar(`${textoTipo(tipo)} cargado.`);
                    await recargar();
                }),
            }));
    };

    botonConfirmar.addEventListener('click', async () => {
        limpiarAviso(avisoDocs);
        try {
            const respuesta = await conBotonOcupado(botonConfirmar, 'Confirmando…', () => autorregistro.confirmar());
            vaciar(zona);
            zona.append(avisoFijo(respuesta.mensaje, 'exito'), avisoFijo(AVISO_REVISION, 'info'),
                crear('p', {}, crear('a', { texto: 'Volver al ingreso', atributos: { href: 'index.html' } })));
        } catch (error) {
            if (error.estado === 403) sinPermiso(error);
            else mostrarAviso(avisoDocs, error.message, 'error');
        }
    });

    zona.append(
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-docs-registro' } },
            crear('h2', { texto: 'Documentos del registro', atributos: { id: 'titulo-docs-registro' } }),
            crear('p', { texto: 'Carga el documento de identidad, el formulario de inscripción, el documento del acudiente (si es menor) y una fotografía. Formatos: pdf, jpg, jpeg, png o webp; máximo 10 MB cada uno.' }),
            vigencia, avisoDocs, zonaLista),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-subir-registro' } },
            crear('h2', { texto: 'Cargar documento', atributos: { id: 'titulo-subir-registro' } }), zonaSubida),
        crear('div', { clase: 'tarjeta' },
            crear('p', { texto: 'Cuando termines, confirma. Después de confirmar, los documentos solo los cambia el club.' }),
            crear('div', { clase: 'acciones' }, botonConfirmar)),
    );
    await recargar();
}

// ===========================================================================
// Al cargar: si ya hay un permiso abierto (p. ej. tras recargar), se retoma la carga de documentos.
// ===========================================================================
async function iniciar() {
    try {
        await autorregistro.listarDocumentos();
        vaciar(zona);
        zona.append(avisoFijo('Tu registro ya fue recibido. Puedes seguir cargando tus documentos.', 'info'));
        await mostrarDocumentos();
    } catch {
        mostrarFormulario();
    }
}

iniciar();
