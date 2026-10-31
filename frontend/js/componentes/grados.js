// Color del cinturón por grado (la lista de grados es la de ui.js). Un grado sin color definido
// (o «Ingresado») usa un neutro. claro: el color es muy claro y necesita contorno para leerse.
import { GRADOS, normalizarTexto, textoGrado } from '../ui.js';

const COLORES = {
    blanco: { color: '#ffffff', claro: true },
    amarillo: { color: '#f2c400', claro: true },
    naranja: { color: '#f08a00', claro: false },
    verde: { color: '#2e9d4a', claro: false },
    azul: { color: '#1f5fbf', claro: false },
    rojo: { color: '#c62828', claro: false },
    violeta: { color: '#7b3fb3', claro: false },
    marron: { color: '#7a4a24', claro: false },
    'marron franja negra': { color: '#7a4a24', claro: false },
    negro: { color: '#1a1a1a', claro: false, oscuro: true },
};
const NEUTRO = { color: '#9aa3ad', claro: false };

export function colorDeGrado(grado) {
    const clave = normalizarTexto(grado ?? '');
    return COLORES[clave] || NEUTRO;
}

export function nombreDeGrado(grado) {
    const oficial = GRADOS.find((g) => normalizarTexto(g) === normalizarTexto(grado ?? ''));
    return oficial || textoGrado(grado);
}
