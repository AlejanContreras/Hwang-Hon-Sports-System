// Módulo Documentos — catálogo B.6, A.6, A.7 y A.8.
// Administrativo: documentos de un deportista, faltantes de los pendientes y documentos generales.
// Público: los suyos (subir solo los cuatro tipos de un archivo que estén vacíos) y los generales.
import { deportistas, documentos } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando,
    textoFecha, insignia,
} from '../ui.js';
import { selectorDeportista } from './comunes.js';

const TAMANO_MAXIMO = 10 * 1024 * 1024;
const FORMATOS_IDENTIDAD = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
const FORMATOS_OFICIO = ['pdf', 'doc', 'docx'];
const FORMATOS_HOJAS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
const FORMATOS_GENERALES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];

// Tipos de documento (A.7): «unico» = un solo archivo por tipo (los únicos que sube el público).
export const TIPOS = [
    { valor: 'documento_identidad', texto: 'Documento de identidad', unico: true, formatos: FORMATOS_IDENTIDAD },
    { valor: 'formulario_inscripcion', texto: 'Formulario de inscripción', unico: true, formatos: FORMATOS_IDENTIDAD },
    { valor: 'documento_acudiente', texto: 'Documento del acudiente', unico: true, formatos: FORMATOS_IDENTIDAD },
    { valor: 'fotografia', texto: 'Fotografía', unico: true, formatos: FORMATOS_IDENTIDAD },
    { valor: 'consentimiento', texto: 'Consentimiento', unico: false, formatos: FORMATOS_OFICIO },
    { valor: 'certificado_ascenso', texto: 'Certificado de ascenso', unico: false, formatos: FORMATOS_OFICIO },
    { valor: 'competencia', texto: 'Competencia', unico: false, formatos: FORMATOS_HOJAS },
    { valor: 'preparacion_fisica', texto: 'Preparación física', unico: false, formatos: FORMATOS_HOJAS },
    { valor: 'otro', texto: 'Otro', unico: false, formatos: FORMATOS_HOJAS },
];
const tipoPorValor = new Map(TIPOS.map((t) => [t.valor, t]));

export function textoTipo(valor) {
    return tipoPorValor.get(valor)?.texto || valor;
}

// Validación evidente en el cliente (formato y 10 MB). La API valida de nuevo y su mensaje manda.
export function problemaArchivo(archivo, formatos) {
    if (!archivo) return 'Elige un archivo.';
    const extension = (archivo.name.split('.').pop() || '').toLowerCase();
    if (!formatos.includes(extension)) return `Formato no permitido. Usa: ${formatos.join(', ')}.`;
    if (archivo.size > TAMANO_MAXIMO) return 'El archivo supera 10 MB.';
    return null;
}

function aviso() {
    return crear('div', { atributos: { hidden: true } });
}

function errorEn(zona, error) {
    vaciar(zona);
    zona.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
}

export function tabla(titulo, encabezados, filas) {
    return crear('div', { clase: 'contenedor-tabla' },
        crear('table', {},
            crear('caption', { clase: 'solo-lectores', texto: titulo }),
            crear('thead', {}, crear('tr', {}, ...encabezados.map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            crear('tbody', {}, ...filas)));
}

function botonDescargar(etiqueta, descargarArchivo, avisoZona) {
    const boton = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Descargar', atributos: { type: 'button', 'aria-label': `Descargar ${etiqueta}` } });
    boton.addEventListener('click', async () => {
        limpiarAviso(avisoZona);
        try {
            await conBotonOcupado(boton, 'Descargando…', descargarArchivo);
        } catch (error) {
            mostrarAviso(avisoZona, error.message, 'error');
        }
    });
    return boton;
}

// Botón que abre el selector de archivo y, al elegir uno válido, llama alElegir(archivo).
export function botonReemplazar(etiqueta, formatos, avisoZona, alElegir) {
    const entrada = crear('input', { atributos: { type: 'file', hidden: true, accept: formatos.map((f) => `.${f}`).join(',') } });
    const boton = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Reemplazar', atributos: { type: 'button', 'aria-label': `Reemplazar ${etiqueta}` } });
    boton.addEventListener('click', () => entrada.click());
    entrada.addEventListener('change', async () => {
        const archivo = entrada.files[0];
        entrada.value = '';
        if (!archivo) return;
        const problema = problemaArchivo(archivo, formatos);
        if (problema) {
            mostrarAviso(avisoZona, problema, 'error');
            return;
        }
        limpiarAviso(avisoZona);
        try {
            await conBotonOcupado(boton, 'Subiendo…', () => alElegir(archivo));
        } catch (error) {
            mostrarAviso(avisoZona, error.message, 'error');
        }
    });
    return [boton, entrada];
}

export function botonEliminar(etiqueta, avisoZona, eliminar) {
    const boton = crear('button', { clase: 'boton boton-peligro boton-pequeno', texto: 'Eliminar', atributos: { type: 'button', 'aria-label': `Eliminar ${etiqueta}` } });
    boton.addEventListener('click', async () => {
        const aceptar = await confirmar({
            titulo: 'Eliminar documento',
            mensaje: [`Se eliminará «${etiqueta}» y su archivo.`, 'No se puede deshacer.'],
            textoAceptar: 'Eliminar',
            peligroso: true,
        });
        if (!aceptar) return;
        limpiarAviso(avisoZona);
        try {
            await eliminar();
        } catch (error) {
            mostrarAviso(avisoZona, error.message, 'error');
        }
    });
    return boton;
}

// Formulario de subida: tipo (si hay varios) + archivo. tipos: lista de TIPOS permitidos.
export function formularioSubida({ tipos, extra = null, textoBoton = 'Subir', alSubir }) {
    const avisoSubida = aviso();
    const selectorTipo = tipos ? campo({
        etiqueta: 'Tipo de documento', nombre: 'tipo_documento', tipo: 'select', valor: tipos[0]?.valor ?? '',
        opciones: tipos.map((t) => ({ valor: t.valor, texto: t.texto })),
    }) : null;
    const entrada = campo({ etiqueta: 'Archivo', nombre: 'archivo', tipo: 'file' });
    const ayuda = crear('small', { clase: 'ayuda' });
    const formatosActuales = () => (selectorTipo ? tipoPorValor.get(selectorTipo.control.value).formatos : FORMATOS_GENERALES);
    const actualizarAyuda = () => {
        const formatos = formatosActuales();
        ayuda.textContent = `Formatos: ${formatos.join(', ')}. Máximo 10 MB.`;
        entrada.control.setAttribute('accept', formatos.map((f) => `.${f}`).join(','));
    };
    if (selectorTipo) selectorTipo.control.addEventListener('change', actualizarAyuda);
    actualizarAyuda();
    entrada.envoltura.append(ayuda);

    const boton = crear('button', { clase: 'boton', texto: textoBoton, atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } },
        avisoSubida,
        crear('div', { clase: 'rejilla-campos' }, selectorTipo?.envoltura, extra?.envoltura, entrada.envoltura),
        crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(avisoSubida);
        const archivo = entrada.control.files[0];
        const problema = problemaArchivo(archivo, formatosActuales());
        if (problema) {
            mostrarAviso(avisoSubida, problema, 'error');
            return;
        }
        try {
            await conBotonOcupado(boton, 'Subiendo…', () => alSubir(archivo, selectorTipo?.control.value, extra?.control.value.trim()));
        } catch (error) {
            mostrarAviso(avisoSubida, error.message, 'error');
        }
    });
    return formulario;
}

// ===========================================================================
// Documentos generales del club (administrativo: gestiona; público: solo descarga)
// ===========================================================================
async function seccionGenerales(esAdministrativo) {
    const seccion = crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-generales' } },
        crear('h2', { texto: 'Documentos generales del club', atributos: { id: 'titulo-generales' } }));
    const avisoGenerales = aviso();
    const zona = crear('div');
    seccion.append(crear('p', { clase: 'ayuda', texto: 'Teorías de ascenso y otros documentos para todos los deportistas.' }), avisoGenerales, zona);

    const recargar = async () => {
        cargando(zona);
        let lista;
        try {
            lista = await documentos.listarGenerales();
        } catch (error) {
            errorEn(zona, error);
            return;
        }
        vaciar(zona);
        if (lista.length === 0) zona.append(crear('p', { texto: 'No hay documentos generales.' }));
        else {
            zona.append(tabla('Documentos generales', ['Título', 'Archivo', 'Fecha', 'Acciones'], lista.map((g) => {
                const acciones = crear('td', { clase: 'acciones-celda' },
                    botonDescargar(g.titulo, () => documentos.descargarGeneral(g.id, g.nombre_original), avisoGenerales));
                if (esAdministrativo) {
                    acciones.append(
                        ...botonReemplazar(g.titulo, FORMATOS_GENERALES, avisoGenerales, async (archivo) => {
                            await documentos.reemplazarGeneral(g.id, archivo);
                            notificar(`«${g.titulo}» reemplazado.`);
                            await recargar();
                        }),
                        botonEliminar(g.titulo, avisoGenerales, async () => {
                            await documentos.eliminarGeneral(g.id);
                            notificar(`«${g.titulo}» eliminado.`);
                            await recargar();
                        }),
                    );
                }
                return crear('tr', {},
                    crear('td', { texto: g.titulo }),
                    crear('td', { texto: g.nombre_original }),
                    crear('td', { texto: textoFecha(g.fecha_carga) }),
                    acciones);
            })));
        }
    };

    if (esAdministrativo) {
        const titulo = campo({ etiqueta: 'Título', nombre: 'titulo', atributos: { maxlength: 150 } });
        seccion.append(crear('h3', { texto: 'Subir documento general' }), formularioSubida({
            extra: titulo,
            alSubir: async (archivo, _tipo, textoTitulo) => {
                await documentos.subirGeneral(textoTitulo, archivo);
                notificar('Documento general subido.');
                titulo.control.value = '';
                await recargar();
            },
        }));
    }
    await recargar();
    return seccion;
}

// ===========================================================================
// Administrativo
// ===========================================================================
export async function mostrarDocumentos(contenedor, documento) {
    if (documento) await mostrarDeDeportista(contenedor, documento);
    else await mostrarInicioDocumentos(contenedor);
}

async function mostrarInicioDocumentos(contenedor) {
    const zonaFaltantes = crear('div');
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Documentos' })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-docs-deportista' } },
            crear('h2', { texto: 'Documentos de un deportista', atributos: { id: 'titulo-docs-deportista' } }),
            selectorDeportista({ textoBoton: 'Ver documentos', alElegir: (doc) => window.location.assign(`#documentos/${encodeURIComponent(doc)}`) })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-faltantes' } },
            crear('h2', { texto: 'Documentos faltantes de los pendientes de validación', atributos: { id: 'titulo-faltantes' } }),
            crear('p', { clase: 'ayuda', texto: 'Críticos: identidad, formulario y, si es menor, el del acudiente. No crítico: la fotografía.' }),
            zonaFaltantes),
    );
    contenedor.append(await seccionGenerales(true));

    cargando(zonaFaltantes);
    try {
        const lista = await documentos.faltantes();
        vaciar(zonaFaltantes);
        if (lista.length === 0) {
            zonaFaltantes.append(crear('p', { texto: 'No hay registros pendientes de validación.' }));
            return;
        }
        zonaFaltantes.append(tabla('Faltantes por deportista', ['Deportista', 'Faltan (críticos)', 'Faltan (no críticos)', 'Acciones'],
            lista.map((d) => crear('tr', {},
                crear('td', {}, crear('a', { texto: d.nombre, atributos: { href: `#deportista/${encodeURIComponent(d.documento)}` } }), crear('br'), crear('span', { clase: 'ayuda', texto: d.documento })),
                crear('td', { texto: d.criticos.length ? d.criticos.map(textoTipo).join(', ') : 'Ninguno' }),
                crear('td', { texto: d.no_criticos.length ? d.no_criticos.map(textoTipo).join(', ') : 'Ninguno' }),
                crear('td', {}, crear('a', { texto: 'Ver documentos', atributos: { href: `#documentos/${encodeURIComponent(d.documento)}` } }))))));
    } catch (error) {
        errorEn(zonaFaltantes, error);
    }
}

async function mostrarDeDeportista(contenedor, documento) {
    cargando(contenedor);
    const d = await deportistas.consultar(documento);
    vaciar(contenedor);
    const pendiente = d.estado_registro === 'pendiente_validacion';
    const avisoDocs = aviso();
    const zonaFaltantes = crear('div');
    const zonaLista = crear('div');

    const recargar = async () => {
        cargando(zonaLista);
        let lista;
        let faltantes;
        try {
            [lista, faltantes] = await Promise.all([documentos.listar(d.documento), documentos.faltantes(d.documento)]);
        } catch (error) {
            errorEn(zonaLista, error);
            return;
        }
        vaciar(zonaFaltantes);
        zonaFaltantes.append(faltantes.criticos.length === 0 && faltantes.no_criticos.length === 0
            ? crear('div', { clase: 'aviso aviso-exito', texto: 'Inscripción documental completa.' })
            : crear('div', { clase: faltantes.criticos.length ? 'aviso aviso-advertencia' : 'aviso aviso-info' },
                faltantes.criticos.length ? `Faltan (críticos): ${faltantes.criticos.map(textoTipo).join(', ')}. ` : '',
                faltantes.no_criticos.length ? `Faltan (no críticos): ${faltantes.no_criticos.map(textoTipo).join(', ')}.` : ''));

        vaciar(zonaLista);
        if (lista.length === 0) {
            zonaLista.append(crear('p', { texto: 'No hay documentos cargados.' }));
            return;
        }
        zonaLista.append(tabla(`Documentos de ${d.nombre}`, ['Tipo', 'Archivo', 'Fecha', 'Acciones'], lista.map((doc) => {
            const tipo = tipoPorValor.get(doc.tipo_documento);
            const etiqueta = `${textoTipo(doc.tipo_documento)} (${doc.nombre_original})`;
            return crear('tr', {},
                crear('td', { texto: textoTipo(doc.tipo_documento) }),
                crear('td', { texto: doc.nombre_original }),
                crear('td', { texto: textoFecha(doc.fecha_carga) }),
                crear('td', { clase: 'acciones-celda' },
                    botonDescargar(etiqueta, () => documentos.descargar(doc.id, doc.nombre_original), avisoDocs),
                    ...botonReemplazar(etiqueta, tipo ? tipo.formatos : FORMATOS_HOJAS, avisoDocs, async (archivo) => {
                        await documentos.reemplazar(doc.id, archivo);
                        notificar('Documento reemplazado.');
                        await recargar();
                    }),
                    botonEliminar(etiqueta, avisoDocs, async () => {
                        await documentos.eliminar(doc.id);
                        notificar('Documento eliminado.');
                        await recargar();
                    })));
        })));
    };

    contenedor.append(
        crear('p', { clase: 'miga' },
            crear('a', { texto: '← Ficha del deportista', atributos: { href: `#deportista/${encodeURIComponent(d.documento)}` } }),
            pendiente ? ' · ' : null,
            pendiente ? crear('a', { texto: 'Pendientes de validación', atributos: { href: '#pendientes' } }) : null),
        crear('div', { clase: 'encabezado-vista' },
            crear('div', {},
                crear('h1', { texto: `Documentos: ${d.nombre}` }),
                crear('p', { clase: 'ayuda' }, `Documento ${d.documento} `, pendiente ? insignia('Pendiente de validación', 'pendiente') : insignia('Validado', 'activo')))),
        zonaFaltantes,
        avisoDocs,
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-lista-docs' } },
            crear('h2', { texto: 'Documentos cargados', atributos: { id: 'titulo-lista-docs' } }),
            crear('p', { clase: 'ayuda', texto: 'Identidad, formulario, acudiente y fotografía admiten un solo archivo (para cambiarlo, «Reemplazar»). Los demás tipos admiten varios.' }),
            zonaLista),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-subir-doc' } },
            crear('h2', { texto: 'Subir documento', atributos: { id: 'titulo-subir-doc' } }),
            formularioSubida({
                tipos: TIPOS,
                alSubir: async (archivo, tipo) => {
                    await documentos.subir(d.documento, tipo, archivo);
                    notificar(`${textoTipo(tipo)} subido.`);
                    await recargar();
                },
            })),
    );
    await recargar();
}

// ===========================================================================
// Público (Mi perfil → Documentos)
// ===========================================================================
export async function mostrarDocumentosPropios(contenedor, documentoPropio) {
    const avisoDocs = aviso();
    const zonaLista = crear('div');
    const zonaSubida = crear('div');

    const recargar = async () => {
        cargando(zonaLista);
        let lista;
        try {
            lista = await documentos.listar();
        } catch (error) {
            errorEn(zonaLista, error);
            return;
        }
        vaciar(zonaLista);
        zonaLista.append(lista.length === 0
            ? crear('p', { texto: 'Aún no hay documentos cargados.' })
            : tabla('Mis documentos', ['Tipo', 'Archivo', 'Fecha', 'Acciones'], lista.map((doc) => crear('tr', {},
                crear('td', { texto: textoTipo(doc.tipo_documento) }),
                crear('td', { texto: doc.nombre_original }),
                crear('td', { texto: textoFecha(doc.fecha_carga) }),
                crear('td', {}, botonDescargar(textoTipo(doc.tipo_documento), () => documentos.descargar(doc.id, doc.nombre_original), avisoDocs))))));

        // Solo los cuatro tipos de un archivo que aún estén vacíos.
        const cargados = new Set(lista.map((doc) => doc.tipo_documento));
        const vacios = TIPOS.filter((t) => t.unico && !cargados.has(t.valor));
        vaciar(zonaSubida);
        if (vacios.length === 0) {
            zonaSubida.append(crear('p', { texto: 'Ya cargaste todos tus documentos. Para cambiar uno, comunícate con el club.' }));
            return;
        }
        zonaSubida.append(
            crear('p', { clase: 'ayuda', texto: 'Puedes subir los documentos que te falten. Para cambiar uno ya cargado, comunícate con el club.' }),
            formularioSubida({
                tipos: vacios,
                alSubir: async (archivo, tipo) => {
                    await documentos.subir(documentoPropio, tipo, archivo);
                    notificar(`${textoTipo(tipo)} subido.`);
                    await recargar();
                },
            }));
    };

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Mis documentos' })),
        avisoDocs,
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-mis-docs' } },
            crear('h2', { texto: 'Documentos cargados', atributos: { id: 'titulo-mis-docs' } }), zonaLista),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-subir-mios' } },
            crear('h2', { texto: 'Subir documentos', atributos: { id: 'titulo-subir-mios' } }), zonaSubida),
    );
    await recargar();
    contenedor.append(await seccionGenerales(false));
}
