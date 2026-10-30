// Carga masiva de deportistas por CSV — catálogo B.5 (importacion-deportistas).
// Tres momentos: 1) elegir el CSV; 2) «Previsualizar» (no escribe nada); 3) aprobar las filas
// «actualizar» y «Aplicar» con el MISMO archivo (se guarda en memoria entre los dos pasos).
import { importacion } from '../api.js';
import { crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, insignia, textoDato } from '../ui.js';

const TAMANO_MAXIMO = 1024 * 1024;
const CLASIFICACIONES = {
    nueva: { texto: 'Nueva', variante: 'activo' },
    actualizar: { texto: 'Actualizar', variante: 'pendiente' },
    sin_cambios: { texto: 'Sin cambios', variante: 'inactivo' },
    incompleta: { texto: 'Incompleta', variante: 'deuda' },
    invalida: { texto: 'Inválida', variante: 'deuda' },
    conflicto_correo: { texto: 'Conflicto de correo', variante: 'deuda' },
    duplicada: { texto: 'Duplicada', variante: 'deuda' },
};
const RESULTADOS = {
    creada: { texto: 'Creada', variante: 'activo' },
    actualizada: { texto: 'Actualizada', variante: 'activo' },
    omitida: { texto: 'Omitida', variante: 'inactivo' },
    rechazada: { texto: 'Rechazada', variante: 'deuda' },
    error: { texto: 'Error', variante: 'deuda' },
};
const etiqueta = (mapa, valor) => insignia(mapa[valor]?.texto ?? valor, mapa[valor]?.variante ?? 'inactivo');

function tabla(titulo, encabezados, filas) {
    return crear('div', { clase: 'contenedor-tabla' },
        crear('table', { clase: 'tabla-importacion' },
            crear('caption', { clase: 'solo-lectores', texto: titulo }),
            crear('thead', {}, crear('tr', {}, ...encabezados.map((t) => crear('th', { texto: t, atributos: { scope: 'col' } })))),
            crear('tbody', {}, ...filas)));
}

// Detalle de una fila: cambios (campo: actual → nuevo), faltantes o motivo.
function detalleFila(f) {
    const partes = [];
    if (f.cambios?.length) {
        partes.push(crear('ul', { clase: 'lista-cambios' }, ...f.cambios.map((c) =>
            crear('li', {}, crear('strong', { texto: c.campo }), `: ${textoDato(c.actual)} → ${textoDato(c.nuevo)}`))));
    }
    if (f.faltantes?.length) partes.push(crear('p', { texto: `Faltan: ${f.faltantes.join(', ')}` }));
    if (f.motivo) partes.push(crear('p', { texto: f.motivo }));
    return partes.length ? partes : ['—'];
}

export function mostrarImportacion(contenedor) {
    // Estado: archivo elegido y la previsualización que le corresponde.
    let archivoActual = null;
    let previsualizado = false;
    const aprobaciones = new Map(); // documento → casilla

    const aviso = crear('div', { atributos: { hidden: true } });
    const zonaPrevia = crear('div');
    const zonaResultado = crear('div');
    const entrada = campo({ etiqueta: 'Archivo CSV', nombre: 'archivo', tipo: 'file', atributos: { accept: '.csv,text/csv' }, ayuda: 'Máximo 1 MB y 1.000 filas. UTF-8 o Windows-1252, separado por ; o ,' });
    const botonPrevisualizar = crear('button', { clase: 'boton', texto: 'Previsualizar', atributos: { type: 'submit' } });
    const botonAplicar = crear('button', { clase: 'boton boton-exito', texto: 'Aplicar', atributos: { type: 'button', disabled: true } });

    // Cambiar de archivo invalida la previsualización: hay que volver a previsualizar.
    entrada.control.addEventListener('change', () => {
        archivoActual = entrada.control.files[0] || null;
        previsualizado = false;
        aprobaciones.clear();
        botonAplicar.disabled = true;
        vaciar(zonaPrevia);
        vaciar(zonaResultado);
        limpiarAviso(aviso);
    });

    const formulario = crear('form', { atributos: { novalidate: true, 'aria-label': 'Elegir y previsualizar el CSV' } },
        crear('div', { clase: 'rejilla-campos' }, entrada.envoltura),
        crear('div', { clase: 'acciones' }, botonPrevisualizar));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        vaciar(zonaResultado);
        if (!archivoActual) return mostrarAviso(aviso, 'Elige un archivo CSV.', 'error');
        if (!archivoActual.name.toLowerCase().endsWith('.csv')) return mostrarAviso(aviso, 'El archivo debe ser .csv (en Excel: «Guardar como» → CSV).', 'error');
        if (archivoActual.size > TAMANO_MAXIMO) return mostrarAviso(aviso, 'El archivo supera 1 MB.', 'error');
        try {
            const previa = await conBotonOcupado(botonPrevisualizar, 'Previsualizando…', () => importacion.previsualizar(archivoActual));
            pintarPrevia(previa);
            previsualizado = true;
            botonAplicar.disabled = false;
        } catch (error) {
            // 400 (encabezado, vacío, no es CSV), 413 (más de 1 MB) y 422 (más de 1.000 filas): tal cual.
            vaciar(zonaPrevia);
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });

    function pintarPrevia(previa) {
        vaciar(zonaPrevia);
        aprobaciones.clear();
        const r = previa.resumen;
        const filas = previa.filas.map((f) => {
            let celdaAprobar = crear('td', { texto: '—' });
            if (f.clasificacion === 'actualizar') {
                const id = `aprobar-${f.linea}`;
                const casilla = crear('input', { atributos: { type: 'checkbox', id } });
                aprobaciones.set(f.documento, casilla);
                celdaAprobar = crear('td', {}, crear('div', { clase: 'campo-casilla' }, casilla,
                    crear('label', { texto: 'Aprobar', atributos: { for: id } })));
            }
            return crear('tr', {},
                crear('td', { texto: String(f.linea), atributos: { 'data-etiqueta': 'Línea' } }),
                crear('td', {}, crear('strong', { texto: textoDato(f.documento) }), crear('br'), crear('span', { texto: textoDato(f.nombre) })),
                crear('td', {}, etiqueta(CLASIFICACIONES, f.clasificacion)),
                crear('td', {}, ...detalleFila(f)),
                celdaAprobar);
        });
        const marcarTodas = (valor) => { for (const c of aprobaciones.values()) c.checked = valor; };
        zonaPrevia.append(crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-previa' } },
            crear('h2', { texto: '2. Previsualización (no se guardó nada)', atributos: { id: 'titulo-previa' } }),
            crear('p', { clase: 'ayuda', texto: `Archivo: ${previa.archivo.codificacion} · separador «${previa.archivo.separador}» · ${previa.archivo.filas} fila(s).` }),
            previa.avisos.length ? crear('ul', { clase: 'aviso aviso-advertencia' }, ...previa.avisos.map((a) => crear('li', { texto: a }))) : null,
            crear('p', {}, `Total ${r.total}: `, ...Object.keys(CLASIFICACIONES).filter((k) => r[k]).flatMap((k) => [etiqueta(CLASIFICACIONES, k), ` ${r[k]}  `])),
            r.actualizar ? crear('div', { clase: 'acciones' },
                crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Aprobar todas las actualizaciones', atributos: { type: 'button' }, eventos: { click: () => marcarTodas(true) } }),
                crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Quitar todas', atributos: { type: 'button' }, eventos: { click: () => marcarTodas(false) } })) : null,
            tabla('Filas del archivo', ['Línea', 'Deportista', 'Clasificación', 'Detalle', 'Aprobar'], filas),
            crear('p', { clase: 'ayuda', texto: 'Las filas «Nueva» se crean siempre al aplicar; las «Actualizar» solo si las apruebas; las demás se rechazan u omiten. Una celda vacía nunca borra un dato existente.' }),
            crear('h2', { texto: '3. Aplicar' }),
            crear('div', { clase: 'acciones' }, botonAplicar)));
    }

    botonAplicar.addEventListener('click', async () => {
        if (!archivoActual || !previsualizado) return mostrarAviso(aviso, 'Previsualiza el archivo antes de aplicar.', 'error');
        const aprobados = [...aprobaciones.entries()].filter(([, c]) => c.checked).map(([doc]) => doc);
        const aceptar = await confirmar({
            titulo: 'Aplicar la carga masiva',
            mensaje: ['Se crearán las filas nuevas y se actualizarán las aprobadas. Puede incluir datos de menores de edad.',
                `Actualizaciones aprobadas: ${aprobados.length ? aprobados.join(', ') : 'ninguna'}.`],
            textoAceptar: 'Aplicar',
        });
        if (!aceptar) return undefined;
        limpiarAviso(aviso);
        try {
            const resultado = await conBotonOcupado(botonAplicar, 'Aplicando…', () => importacion.aplicar(archivoActual, aprobados));
            // Aplicado: para repetir hay que previsualizar de nuevo (los datos ya cambiaron).
            previsualizado = false;
            botonAplicar.disabled = true;
            pintarResultado(resultado);
            notificar('Carga masiva aplicada.');
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });

    function pintarResultado(resultado) {
        vaciar(zonaResultado);
        const r = resultado.resumen;
        zonaResultado.append(crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-resultado' } },
            crear('h2', { texto: 'Resultado', atributos: { id: 'titulo-resultado' } }),
            crear('p', { clase: 'aviso aviso-exito', atributos: { role: 'status' }, texto: `Creadas: ${r.creadas} · Actualizadas: ${r.actualizadas} · Omitidas: ${r.omitidas} · Rechazadas: ${r.rechazadas} · Errores: ${r.errores}` }),
            resultado.avisos?.length ? crear('ul', { clase: 'aviso aviso-advertencia' }, ...resultado.avisos.map((a) => crear('li', { texto: a }))) : null,
            tabla('Resultado por fila', ['Línea', 'Documento', 'Clasificación', 'Resultado', 'Motivo'], resultado.filas.map((f) => crear('tr', {},
                crear('td', { texto: String(f.linea) }),
                crear('td', {}, f.resultado === 'creada' || f.resultado === 'actualizada'
                    ? crear('a', { texto: f.documento, atributos: { href: `#deportista/${encodeURIComponent(f.documento)}` } })
                    : textoDato(f.documento)),
                crear('td', {}, etiqueta(CLASIFICACIONES, f.clasificacion)),
                crear('td', {}, etiqueta(RESULTADOS, f.resultado)),
                crear('td', { texto: textoDato(f.motivo) }))))));
        zonaResultado.scrollIntoView({ block: 'start' });
    }

    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Deportistas', atributos: { href: '#deportistas' } })),
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Carga masiva de deportistas' })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-elegir-csv' } },
            crear('h2', { texto: '1. Elegir el archivo', atributos: { id: 'titulo-elegir-csv' } }),
            crear('p', { clase: 'nota-provisional' }, insignia('PROVISIONAL', 'pendiente'), ' Plantilla pendiente de validar con el Maestro Aldrin: ',
                crear('a', { texto: 'descargar plantilla CSV de ejemplo', atributos: { href: 'recursos/plantilla_carga_masiva_deportistas_PROVISIONAL.csv', download: 'plantilla_carga_masiva_deportistas_PROVISIONAL.csv' } }),
                ' (datos ficticios). Ábrela en Excel, llénala y guárdala como CSV.'),
            aviso,
            formulario),
        zonaPrevia,
        zonaResultado);
}
