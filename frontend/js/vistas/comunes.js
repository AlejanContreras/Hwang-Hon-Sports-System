// Piezas de pantalla compartidas por varias vistas del ámbito administrativo.
import { deportistas } from '../api.js';
import { crear, vaciar, campo, mostrarAviso, limpiarAviso } from '../ui.js';

const SEPARADOR = ' — ';

// Buscador de deportista con autocompletado (deportistas.buscar, desde 2 caracteres).
// Al enviar llama alElegir(documento). Acepta escribir el documento completo sin elegir sugerencia.
export function selectorDeportista({ etiqueta = 'Deportista', textoBoton = 'Ver', alElegir }) {
    const idLista = `sugerencias-deportista-${Math.random().toString(36).slice(2, 8)}`;
    const { envoltura, control } = campo({
        etiqueta, nombre: 'deportista', tipo: 'search',
        ayuda: 'Escribe al menos 2 letras del nombre o el inicio del documento.',
        atributos: { list: idLista, autocomplete: 'off' },
    });
    const sugerencias = crear('datalist', { atributos: { id: idLista } });
    const aviso = crear('div', { atributos: { hidden: true } });
    const boton = crear('button', { clase: 'boton', texto: textoBoton, atributos: { type: 'submit' } });

    let temporizador = null;
    let ultimaBusqueda = '';
    control.addEventListener('input', () => {
        clearTimeout(temporizador);
        const texto = control.value.trim();
        if (texto.length < 2 || texto.includes(SEPARADOR.trim())) return;
        temporizador = setTimeout(async () => {
            if (texto === ultimaBusqueda) return;
            ultimaBusqueda = texto;
            try {
                const lista = await deportistas.buscar(texto);
                vaciar(sugerencias);
                for (const d of lista) {
                    sugerencias.append(crear('option', { atributos: { value: `${d.documento}${SEPARADOR}${d.nombre}` } }));
                }
            } catch {
                // el autocompletado es una ayuda: si falla, se puede escribir el documento
            }
        }, 250);
    });

    const formulario = crear('form', { clase: 'formulario-en-linea', atributos: { role: 'search' } },
        envoltura, sugerencias, crear('div', { clase: 'campo' }, boton));
    formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        const documento = control.value.split(SEPARADOR)[0].trim();
        if (!documento) {
            mostrarAviso(aviso, 'Escribe o elige un deportista.', 'error');
            control.focus();
            return;
        }
        limpiarAviso(aviso);
        alElegir(documento);
    });
    return crear('div', {}, formulario, aviso);
}
