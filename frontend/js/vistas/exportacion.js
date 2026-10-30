// Exportación (ámbito administrativo) — catálogo B.14 y A.8.
// Cada reporte se descarga con reportes.* (descargar() de api.js revisa el código y el tipo antes
// de guardar). Los errores de la API (400/404/422) se muestran tal cual en su tarjeta.
import { reportes, evaluaciones } from '../api.js';
import { crear, mostrarAviso, limpiarAviso, notificar, campo, conBotonOcupado, insignia, hoyBogota, sumarDias, anioActualBogota, textoFecha } from '../ui.js';
import { selectorDeportista } from './comunes.js';

const provisional = () => insignia('PROVISIONAL', 'pendiente');
const ESTADOS = [{ valor: '', texto: 'Todos' }, { valor: 'activo', texto: 'Activos' }, { valor: 'inactivo', texto: 'Inactivos' }];

// Tarjeta de un reporte: título, descripción, contenido extra (fuera del formulario, p. ej. el
// buscador, que tiene su propio <form>), campos y botón «Descargar».
function tarjeta({ titulo, descripcion, campos = [], marca = null, extra = null, descargarArchivo }) {
    const aviso = crear('div', { atributos: { hidden: true } });
    const boton = crear('button', { clase: 'boton', texto: 'Descargar', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true, 'aria-label': titulo } },
        campos.length ? crear('div', { clase: 'rejilla-campos' }, ...campos.map((c) => c.envoltura)) : null,
        aviso,
        crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        try {
            const nombre = await conBotonOcupado(boton, 'Generando…', descargarArchivo);
            notificar(`Descargado: ${nombre}`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return crear('section', { clase: 'tarjeta tarjeta-reporte' },
        crear('h2', {}, titulo, marca ? ' ' : null, marca),
        crear('p', { clase: 'ayuda', texto: descripcion }),
        extra,
        formulario);
}

export async function mostrarExportacion(contenedor) {
    const hoy = hoyBogota();
    const anioActual = anioActualBogota();

    const estadoDeportistas = campo({ etiqueta: 'Estado', nombre: 'estado', tipo: 'select', valor: '', opciones: ESTADOS });
    const desdeAsistencia = campo({ etiqueta: 'Desde', nombre: 'desde', tipo: 'date', valor: sumarDias(hoy, -90) });
    const hastaAsistencia = campo({ etiqueta: 'Hasta', nombre: 'hasta', tipo: 'date', valor: hoy, ayuda: 'Máximo 366 días.' });
    const estadoAsistencia = campo({ etiqueta: 'Estado', nombre: 'estado', tipo: 'select', valor: '', opciones: ESTADOS });
    const anios = [];
    for (let anio = anioActual + 1; anio >= 2020; anio -= 1) anios.push({ valor: String(anio), texto: String(anio) });
    const anioPagos = campo({ etiqueta: 'Año', nombre: 'anio', tipo: 'select', valor: String(anioActual), opciones: anios });
    const estadoPagos = campo({ etiqueta: 'Estado', nombre: 'estado', tipo: 'select', valor: '', opciones: ESTADOS });
    const desdeTorneos = campo({ etiqueta: 'Desde (opcional)', nombre: 'desde', tipo: 'date' });
    const hastaTorneos = campo({ etiqueta: 'Hasta (opcional)', nombre: 'hasta', tipo: 'date' });

    let sesiones = [];
    try {
        sesiones = await evaluaciones.listar();
    } catch {
        sesiones = [];
    }
    const sesion = campo({ etiqueta: 'Sesión de examen', nombre: 'sesion', tipo: 'select', valor: sesiones[0] ? String(sesiones[0].id) : '',
        opciones: sesiones.length
            ? sesiones.map((s) => ({ valor: String(s.id), texto: `${textoFecha(s.fecha)} · ${s.lugar} (${s.estado})` }))
            : [{ valor: '', texto: 'No hay sesiones' }] });

    let documentoFicha = null;
    const fichaElegida = crear('p', { clase: 'ayuda', texto: 'Ningún deportista elegido.', atributos: { 'aria-live': 'polite' } });
    const tarjetaFicha = tarjeta({
        titulo: 'Ficha de un deportista (PDF)',
        descripcion: 'Perfil, contacto, acudiente, último examen, últimos 5 torneos, asistencia y permanencia.',
        extra: crear('div', {},
            selectorDeportista({ textoBoton: 'Elegir', alElegir: (doc) => { documentoFicha = doc; fichaElegida.textContent = `Deportista elegido: ${doc}`; } }),
            fichaElegida),
        descargarArchivo: async () => {
            if (!documentoFicha) throw new Error('Elige un deportista con «Elegir».');
            return reportes.deportistaFicha(documentoFicha);
        },
    });

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Exportación' })),
        crear('p', { clase: 'nota-provisional' }, provisional(), ' El listado final de exportaciones está por validar con el club. Los archivos se generan al momento y no se guardan en el servidor (llevan datos de menores y de salud).'),
        crear('div', { clase: 'rejilla-reportes' },
            tarjetaFicha,
            tarjeta({
                titulo: 'Deportistas (Excel)', descripcion: 'Documento, nombre, nacimiento, edad, categoría, grado, estado, ingreso, teléfono y correo. Sin filtro: todos los validados.',
                campos: [estadoDeportistas], descargarArchivo: () => reportes.deportistas(estadoDeportistas.control.value || undefined),
            }),
            tarjeta({
                titulo: 'Planilla de asistencia (Excel)', marca: provisional(),
                descripcion: 'Formato propio estilo IMRDS: deportistas por fechas, condición de salud, totales y % de asistencia.',
                campos: [desdeAsistencia, hastaAsistencia, estadoAsistencia],
                descargarArchivo: () => reportes.asistencia(desdeAsistencia.control.value, hastaAsistencia.control.value, estadoAsistencia.control.value || undefined),
            }),
            tarjeta({
                titulo: 'Pagos (Excel)', descripcion: 'Planilla de 12 meses con semana de pago y matrícula, y la hoja de deudores del año en curso.',
                campos: [anioPagos, estadoPagos], descargarArchivo: () => reportes.pagos(anioPagos.control.value, estadoPagos.control.value || undefined),
            }),
            tarjeta({
                titulo: 'Torneos (Excel)', descripcion: 'Una fila por participación en torneos realizados y la hoja «Medallería». Sin notas de oponentes.',
                campos: [desdeTorneos, hastaTorneos], descargarArchivo: () => reportes.torneos(desdeTorneos.control.value || undefined, hastaTorneos.control.value || undefined),
            }),
            tarjeta({
                titulo: 'Sesión de examen (Excel)', marca: provisional(),
                descripcion: 'Las seis notas de cada participante, el promedio, el resultado (provisional), el grado y la entrega. Una sesión abierta sale marcada «EN CURSO».',
                campos: [sesion], descargarArchivo: () => reportes.sesionExamen(sesion.control.value),
            }),
        ),
    );
}
