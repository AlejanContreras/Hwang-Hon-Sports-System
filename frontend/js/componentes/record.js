// Récord de combates en un solo formato para todo el front: «G-P» en números grandes con la
// etiqueta «Récord». Lo usan «Mi perfil», el dashboard del deportista y la ficha del administrativo.
// Los datos salen de deportistas.consultar (combates_ganados y combates_perdidos); no se piden aparte.
// Empates: el modelo de datos hoy NO los tiene. Si algún día la API envía combates_empatados > 0,
// el formato pasa a «G-P-E» sin más cambios; mientras no llegue, no aparece.
import { crear } from '../ui.js';

// «3-0», «3-0-1» (solo con empates > 0) o null si no hay combates.
export function textoRecord(ganados, perdidos, empatados = null) {
    const g = Number(ganados) || 0;
    const p = Number(perdidos) || 0;
    const e = Number(empatados) || 0;
    if (g + p + e === 0) return null;
    return e > 0 ? `${g}-${p}-${e}` : `${g}-${p}`;
}

// Descripción para lectores de pantalla («3 ganados, 0 perdidos»).
function descripcionRecord(ganados, perdidos, empatados) {
    const partes = [`${Number(ganados) || 0} ganados`, `${Number(perdidos) || 0} perdidos`];
    if ((Number(empatados) || 0) > 0) partes.push(`${Number(empatados)} empatados`);
    return partes.join(', ');
}

// Bloque visual. deportista: objeto con combates_ganados / combates_perdidos (/ combates_empatados).
// porcentaje: opcional (estadisticas.deportista.record.porcentaje_victorias); null o ausente → no se muestra.
export function bloqueRecord(deportista, { porcentaje = null, id = null } = {}) {
    const ganados = deportista?.combates_ganados;
    const perdidos = deportista?.combates_perdidos;
    const empatados = deportista?.combates_empatados ?? null;
    const texto = textoRecord(ganados, perdidos, empatados);
    const atributos = { 'data-prueba': 'record-combates', ...(id ? { id } : {}) };
    if (texto === null) {
        return crear('div', { clase: 'record-combates sin-combates', atributos }, crear('span', { clase: 'valor-record-vacio', texto: 'Sin combates' }));
    }
    return crear('div', { clase: 'record-combates', atributos },
        crear('span', { clase: 'etiqueta-record', texto: 'Récord' }),
        // «3-0» es visual; los lectores de pantalla oyen «3 ganados, 0 perdidos».
        crear('span', { clase: 'valor-record', texto, atributos: { 'aria-hidden': 'true' } }),
        crear('span', { clase: 'solo-lectores', texto: descripcionRecord(ganados, perdidos, empatados) }),
        porcentaje === null || porcentaje === undefined
            ? null
            : crear('span', { clase: 'porcentaje-record', texto: `${Number(porcentaje).toLocaleString('es-CO')} % de victorias` }));
}
