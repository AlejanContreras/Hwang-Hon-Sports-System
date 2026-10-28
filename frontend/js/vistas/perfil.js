// Vistas de «Mi perfil» (ámbito público). Reutiliza la ficha, el porcentaje y el estado de
// pagos del ámbito administrativo; aquí solo se edita lo que la API permite al público.
import { deportistas, asistencia, pagos } from '../api.js';
import {
    crear, vaciar, mostrarAviso, limpiarAviso, notificar, campo, conBotonOcupado, cargando,
    textoFechaLarga, insignia, anioActualBogota,
} from '../ui.js';
import { seccionesFicha } from './deportistas.js';
import { pintarPorcentaje, campoVentana } from './asistencia.js';
import { resumenEstado, tablaMeses } from './pagos.js';

// Únicos campos que el público puede editar (catálogo B.3).
const CAMPOS_EDITABLES = [
    { nombre: 'peso', etiqueta: 'Peso (kg)', tipo: 'number', atributos: { step: '0.01', min: '0.01', max: '999.99', inputmode: 'decimal' } },
    { nombre: 'discapacidad', etiqueta: 'Condición de salud', ayuda: 'Discapacidad o condición que el club debe conocer.', atributos: { maxlength: 255 } },
    { nombre: 'telefono', etiqueta: 'Teléfono', tipo: 'tel', ayuda: 'Dígitos, espacios y + inicial.', atributos: { maxlength: 20 } },
    { nombre: 'direccion', etiqueta: 'Dirección', atributos: { maxlength: 150 } },
    { nombre: 'correo', etiqueta: 'Correo', tipo: 'email', atributos: { maxlength: 150 } },
];

// ===========================================================================
// Mis datos: ficha de solo lectura + formulario de los campos permitidos
// ===========================================================================
export async function mostrarDatos(contenedor, documento, alActualizar) {
    cargando(contenedor);
    const d = await deportistas.consultar(documento);
    vaciar(contenedor);

    const aviso = crear('div', { atributos: { hidden: true } });
    const controles = {};
    const rejilla = crear('div', { clase: 'rejilla-campos' });
    for (const definicion of CAMPOS_EDITABLES) {
        const { envoltura, control } = campo({ ...definicion, valor: d[definicion.nombre] });
        controles[definicion.nombre] = control;
        rejilla.append(envoltura);
    }
    const botonGuardar = crear('button', { clase: 'boton', texto: 'Guardar cambios', atributos: { type: 'submit' } });
    const formulario = crear('form', { clase: 'tarjeta', atributos: { novalidate: true, 'aria-labelledby': 'titulo-editar-perfil' } },
        crear('h2', { texto: 'Actualizar mis datos', atributos: { id: 'titulo-editar-perfil' } }),
        crear('p', { clase: 'ayuda', texto: 'Solo puedes cambiar estos datos. Para corregir cualquier otro, comunícate con el club.' }),
        aviso,
        rejilla,
        crear('div', { clase: 'acciones' }, botonGuardar),
    );

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        // Solo lo cambiado; un campo vaciado va como null (lo borra).
        const cambios = {};
        for (const { nombre, tipo } of CAMPOS_EDITABLES) {
            const texto = controles[nombre].value.trim();
            const nuevo = texto === '' ? null : tipo === 'number' ? Number(texto) : texto;
            const anterior = d[nombre] ?? null;
            const igual = typeof nuevo === 'number' && anterior !== null ? nuevo === Number(anterior) : nuevo === anterior;
            if (!igual) cambios[nombre] = nuevo;
        }
        if (Object.keys(cambios).length === 0) {
            mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
            return;
        }
        try {
            const actualizado = await conBotonOcupado(botonGuardar, 'Guardando…', () => deportistas.actualizar(d.documento, cambios));
            notificar('Tus datos se guardaron.');
            if (alActualizar) alActualizar(actualizado);
            await mostrarDatos(contenedor, documento, alActualizar);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Mis datos' })),
        formulario,
        ...seccionesFicha(d),
    );
}

// ===========================================================================
// Asistencia: porcentaje y lista de marcas propias
// ===========================================================================
export async function mostrarAsistenciaPropia(contenedor) {
    const resultado = crear('div', { atributos: { 'aria-live': 'polite' } });
    const ventana = campoVentana();
    ventana.control.addEventListener('change', () => pintarPorcentaje(resultado, null, ventana.control.value));
    const zonaMarcas = crear('div');

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Mi asistencia' })),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-mi-porcentaje' } },
            crear('h2', { texto: 'Porcentaje de asistencia', atributos: { id: 'titulo-mi-porcentaje' } }),
            crear('div', { clase: 'filtros' }, ventana.envoltura),
            resultado),
        crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-mis-marcas' } },
            crear('h2', { texto: 'Sesiones registradas', atributos: { id: 'titulo-mis-marcas' } }),
            zonaMarcas),
    );

    pintarPorcentaje(resultado, null, ventana.control.value);
    cargando(zonaMarcas);
    try {
        const marcas = await asistencia.listar();
        vaciar(zonaMarcas);
        if (marcas.length === 0) {
            zonaMarcas.append(crear('p', { texto: 'Aún no hay asistencia registrada.' }));
            return;
        }
        // La más reciente primero.
        const filas = [...marcas].reverse().map((m) => crear('tr', {},
            crear('td', { texto: textoFechaLarga(m.fecha) }),
            crear('td', {}, m.asistio ? insignia('Presente', 'activo') : insignia('Ausente', 'inactivo')),
        ));
        zonaMarcas.append(crear('div', { clase: 'contenedor-tabla' },
            crear('table', {},
                crear('caption', { clase: 'solo-lectores', texto: 'Mis marcas de asistencia' }),
                crear('thead', {}, crear('tr', {},
                    crear('th', { texto: 'Fecha', atributos: { scope: 'col' } }),
                    crear('th', { texto: 'Marca', atributos: { scope: 'col' } }))),
                crear('tbody', {}, ...filas))));
    } catch (error) {
        vaciar(zonaMarcas);
        zonaMarcas.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
    }
}

// ===========================================================================
// Pagos: estado de solo lectura con cambio de año
// ===========================================================================
export async function mostrarPagosPropios(contenedor) {
    const anioActual = anioActualBogota();
    const anios = [];
    for (let anio = anioActual + 1; anio >= 2020; anio -= 1) anios.push({ valor: String(anio), texto: String(anio) });
    const selectorAnio = campo({ etiqueta: 'Año', nombre: 'anio', tipo: 'select', valor: String(anioActual), opciones: anios });
    const zonaEstado = crear('div');

    const cargar = async () => {
        cargando(zonaEstado);
        try {
            const estado = await pagos.estado(undefined, selectorAnio.control.value);
            vaciar(zonaEstado);
            zonaEstado.append(resumenEstado(estado), tablaMeses(estado));
        } catch (error) {
            vaciar(zonaEstado);
            zonaEstado.append(crear('div', { clase: 'aviso aviso-error', texto: error.message, atributos: { role: 'alert' } }));
        }
    };
    selectorAnio.control.addEventListener('change', cargar);

    contenedor.append(
        crear('div', { clase: 'encabezado-vista' },
            crear('h1', { texto: 'Mis pagos' }),
            crear('div', { clase: 'campo' }, selectorAnio.envoltura)),
        crear('p', { clase: 'ayuda', texto: 'Solo consulta. Si ves un error en tus pagos, comunícate con el club.' }),
        zonaEstado,
    );
    await cargar();
}
