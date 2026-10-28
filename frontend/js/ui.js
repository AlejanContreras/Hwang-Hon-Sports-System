// Utilidades de pantalla. Todo dato que viene de la API se pinta con textContent
// o con elementos creados: nunca con innerHTML (hay datos de menores y de salud).

// crear('p', { clase: 'nota', texto: 'Hola', atributos: { id: 'x' }, eventos: { click: fn } }, hijo1, hijo2)
// Los hijos pueden ser nodos o textos (se agregan como texto, sin interpretar HTML).
export function crear(etiqueta, opciones = {}, ...hijos) {
    const elemento = document.createElement(etiqueta);
    if (opciones.clase) elemento.className = opciones.clase;
    if (opciones.texto !== undefined && opciones.texto !== null) elemento.textContent = String(opciones.texto);
    for (const [nombre, valor] of Object.entries(opciones.atributos || {})) {
        if (valor === false || valor === null || valor === undefined) continue;
        elemento.setAttribute(nombre, valor === true ? '' : String(valor));
    }
    for (const [evento, funcion] of Object.entries(opciones.eventos || {})) {
        elemento.addEventListener(evento, funcion);
    }
    agregarHijos(elemento, hijos);
    return elemento;
}

function agregarHijos(elemento, hijos) {
    for (const hijo of hijos.flat()) {
        if (hijo === null || hijo === undefined || hijo === false) continue;
        elemento.append(hijo instanceof Node ? hijo : document.createTextNode(String(hijo)));
    }
}

export function vaciar(nodo) {
    nodo.replaceChildren();
}

// ---------------------------------------------------------------------------
// Avisos
// ---------------------------------------------------------------------------

// tipo: 'error' | 'exito' | 'info'. Los errores se anuncian de inmediato a los lectores de pantalla.
export function mostrarAviso(contenedor, texto, tipo = 'info') {
    vaciar(contenedor);
    if (!texto) {
        contenedor.hidden = true;
        return;
    }
    contenedor.hidden = false;
    contenedor.className = `aviso aviso-${tipo}`;
    contenedor.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    contenedor.textContent = texto;
}

export function limpiarAviso(contenedor) {
    mostrarAviso(contenedor, '');
}

// Aviso flotante breve para confirmar acciones (p. ej. «Deportista actualizado»).
export function notificar(texto, tipo = 'exito') {
    let zona = document.getElementById('zona-notificaciones');
    if (!zona) {
        zona = crear('div', { atributos: { id: 'zona-notificaciones', 'aria-live': 'polite' } });
        document.body.append(zona);
    }
    const nota = crear('div', { clase: `notificacion aviso-${tipo}`, texto });
    zona.append(nota);
    setTimeout(() => nota.remove(), 5000);
}

// ---------------------------------------------------------------------------
// Diálogo de confirmación (devuelve una promesa con true/false)
// ---------------------------------------------------------------------------
export function confirmar({ titulo, mensaje, textoAceptar = 'Aceptar', peligroso = false }) {
    return new Promise((resolver) => {
        const idTitulo = `dialogo-titulo-${Date.now()}`;
        const botonCancelar = crear('button', { clase: 'boton boton-secundario', texto: 'Cancelar', atributos: { type: 'button' } });
        const botonAceptar = crear('button', {
            clase: peligroso ? 'boton boton-peligro' : 'boton',
            texto: textoAceptar,
            atributos: { type: 'button' },
        });
        const dialogo = crear('dialog', { clase: 'dialogo', atributos: { 'aria-labelledby': idTitulo } },
            crear('h2', { texto: titulo, atributos: { id: idTitulo } }),
            ...[].concat(mensaje).map((parrafo) => crear('p', { texto: parrafo })),
            crear('div', { clase: 'acciones' }, botonCancelar, botonAceptar),
        );

        let respuesta = false;
        botonCancelar.addEventListener('click', () => dialogo.close());
        botonAceptar.addEventListener('click', () => {
            respuesta = true;
            dialogo.close();
        });
        dialogo.addEventListener('close', () => {
            dialogo.remove();
            resolver(respuesta);
        });

        document.body.append(dialogo);
        dialogo.showModal();
        botonCancelar.focus();
    });
}

// ---------------------------------------------------------------------------
// Formularios
// ---------------------------------------------------------------------------
let contadorCampos = 0;

// Crea un campo con su <label> asociada. tipo: text, email, date, number, select, textarea, checkbox.
export function campo({ etiqueta, nombre, tipo = 'text', valor = '', obligatorio = false, opciones = [], ayuda = '', atributos = {} }) {
    contadorCampos += 1;
    const id = `campo-${nombre}-${contadorCampos}`;
    let control;

    if (tipo === 'select') {
        control = crear('select', { atributos: { id, name: nombre, required: obligatorio, ...atributos } });
        for (const opcion of opciones) {
            control.append(crear('option', { texto: opcion.texto, atributos: { value: opcion.valor } }));
        }
        control.value = valor ?? '';
    } else if (tipo === 'textarea') {
        control = crear('textarea', { atributos: { id, name: nombre, required: obligatorio, rows: 3, ...atributos } });
        control.value = valor ?? '';
    } else if (tipo === 'checkbox') {
        control = crear('input', { atributos: { id, name: nombre, type: 'checkbox', ...atributos } });
        control.checked = Boolean(valor);
    } else {
        control = crear('input', { atributos: { id, name: nombre, type: tipo, required: obligatorio, ...atributos } });
        control.value = valor ?? '';
    }

    let idAyuda = null;
    if (ayuda) {
        idAyuda = `${id}-ayuda`;
        control.setAttribute('aria-describedby', idAyuda);
    }

    const textoEtiqueta = obligatorio ? [etiqueta, crear('span', { clase: 'obligatorio', texto: ' *', atributos: { 'aria-hidden': 'true' } })] : [etiqueta];
    const etiquetaNodo = crear('label', { atributos: { for: id } }, ...textoEtiqueta);

    const envoltura = tipo === 'checkbox'
        ? crear('div', { clase: 'campo campo-casilla' }, control, etiquetaNodo)
        : crear('div', { clase: 'campo' }, etiquetaNodo, control);
    if (ayuda) envoltura.append(crear('small', { clase: 'ayuda', texto: ayuda, atributos: { id: idAyuda } }));
    return { envoltura, control };
}

// Mientras se espera a la API el botón queda deshabilitado y muestra un texto de espera.
export async function conBotonOcupado(boton, textoEspera, tarea) {
    const textoOriginal = boton.textContent;
    boton.disabled = true;
    boton.textContent = textoEspera;
    try {
        return await tarea();
    } finally {
        boton.disabled = false;
        boton.textContent = textoOriginal;
    }
}

// ---------------------------------------------------------------------------
// Presentación de datos
// ---------------------------------------------------------------------------

// null o '' se muestra como «—»; los booleanos como Sí / No.
export function textoDato(valor) {
    if (valor === null || valor === undefined || valor === '') return '—';
    if (valor === true) return 'Sí';
    if (valor === false) return 'No';
    return String(valor);
}

// Los números pueden venir sin decimales (A.2): 60 y 60.0 son lo mismo.
export function textoNumero(valor, unidad = '') {
    if (valor === null || valor === undefined) return '—';
    const texto = Number(valor).toLocaleString('es-CO', { maximumFractionDigits: 2 });
    return unidad ? `${texto} ${unidad}` : texto;
}

// Fecha AAAA-MM-DD → DD/MM/AAAA, sin pasar por Date (evita corrimientos de zona horaria).
export function textoFecha(fecha) {
    if (!fecha) return '—';
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fecha);
    return partes ? `${partes[3]}/${partes[2]}/${partes[1]}` : fecha;
}

export const GRADOS = ['Blanco', 'Amarillo', 'Naranja', 'Verde', 'Azul', 'Rojo', 'Violeta', 'Marrón', 'Marrón franja negra', 'Negro'];

// grado_actual null = Ingresado (A.7). Se respeta lo guardado, solo se capitaliza.
export function textoGrado(grado) {
    if (!grado) return 'Ingresado';
    return grado.charAt(0).toLocaleUpperCase('es') + grado.slice(1);
}

export function textoEstado(estado) {
    return estado === 'activo' ? 'Activo' : estado === 'inactivo' ? 'Inactivo' : textoDato(estado);
}

export function textoEstadoRegistro(estadoRegistro) {
    if (estadoRegistro === 'pendiente_validacion') return 'Pendiente de validación';
    if (estadoRegistro === 'validado') return 'Validado';
    return textoDato(estadoRegistro);
}

export function textoSexo(sexo) {
    return sexo === 'masculino' ? 'Masculino' : sexo === 'femenino' ? 'Femenino' : textoDato(sexo);
}

export function insignia(texto, variante) {
    return crear('span', { clase: `insignia insignia-${variante}`, texto });
}

// Para buscar sin distinguir mayúsculas ni tildes.
export function normalizarTexto(texto) {
    return String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

// Lista de definición (dt/dd) a partir de pares [etiqueta, valor].
export function listaDatos(pares) {
    const lista = crear('dl', { clase: 'lista-datos' });
    for (const [etiqueta, valor] of pares) {
        lista.append(
            crear('div', { clase: 'par' },
                crear('dt', { texto: etiqueta }),
                crear('dd', {}, valor instanceof Node ? valor : textoDato(valor)),
            ),
        );
    }
    return lista;
}

export function cargando(contenedor, texto = 'Cargando…') {
    vaciar(contenedor);
    contenedor.append(crear('p', { clase: 'cargando', texto, atributos: { role: 'status' } }));
}
