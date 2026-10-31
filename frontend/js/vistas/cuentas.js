// Cuentas — «Mi cuenta» (B.1: cambiar contraseña y cerrar sesión) y, para la cuenta principal,
// las cuentas del club (B.2).
import { usuariosAdministrativos, auth } from '../api.js';
import { crear, vaciar, mostrarAviso, limpiarAviso, notificar, confirmar, campo, conBotonOcupado, cargando, textoDato, insignia } from '../ui.js';
import { cerrarSesion } from '../sesion.js';
import { crear as crearIcono } from '../componentes/iconos.js';

const MINIMO_CONTRASENA = 8;

function avisoNuevo() {
    return crear('div', { atributos: { hidden: true } });
}

function campoContrasena(etiqueta, nombre, autocompletar = 'new-password') {
    return campo({
        etiqueta, nombre, tipo: 'password',
        ayuda: autocompletar === 'new-password' ? `Mínimo ${MINIMO_CONTRASENA} caracteres.` : '',
        atributos: { autocomplete: autocompletar, minlength: MINIMO_CONTRASENA },
    });
}

// ===========================================================================
// Cuentas (#cuentas): «Mi cuenta» para cualquier cuenta administrativa y, debajo, «Cuentas del
// club» solo para la cuenta principal (las demás ni la piden: la API les daría 403).
// ===========================================================================
export async function mostrarCuentas(contenedor, sesion) {
    contenedor.append(
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Cuentas' })),
        seccionMiCuenta(sesion));
    if (!sesion.es_administrador_principal) return;

    const zona = crear('div', { atributos: { id: 'cuentas-del-club' } });
    contenedor.append(
        crear('h2', { clase: 'titulo-seccion', texto: 'Cuentas del club' }),
        crear('p', { clase: 'ayuda', texto: 'Las cuentas nunca se borran: se desactivan. Las cuentas principales no se desactivan desde la aplicación.' }),
        zona);

    const recargar = async () => {
        cargando(zona);
        let lista;
        try {
            lista = await usuariosAdministrativos.listar();
        } catch (error) {
            vaciar(zona);
            zona.append(crear('div', { clase: 'aviso aviso-advertencia', texto: error.message, atributos: { role: 'alert' } }));
            return;
        }
        vaciar(zona);
        zona.append(
            crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-lista-cuentas' } },
                crear('h3', { texto: `Cuentas (${lista.length})`, atributos: { id: 'titulo-lista-cuentas' } }),
                ...lista.map((cuenta) => tarjetaCuenta(cuenta, sesion, recargar))),
            formularioCrear(recargar));
        // El rol de la cuenta propia solo se conoce con la lista (sesionActual no lo trae).
        const propia = lista.find((c) => c.correo === sesion.identificador);
        const rol = document.getElementById('mi-cuenta-rol');
        if (propia && rol) rol.textContent = textoDato(propia.rol);
    };
    await recargar();
}

// «Mi cuenta»: nombre, correo, rol, cambiar contraseña y cerrar sesión.
function seccionMiCuenta(sesion) {
    const salir = crear('button', { clase: 'boton boton-secundario', atributos: { type: 'button', id: 'boton-cerrar-sesion', 'data-prueba': 'cerrar-sesion' } },
        crearIcono('log-out', { tamano: 18 }), crear('span', { texto: 'Cerrar sesión' }));
    salir.addEventListener('click', () => {
        salir.disabled = true;
        cerrarSesion();
    });
    const lista = crear('dl', { clase: 'lista-datos' },
        crear('div', { clase: 'par' }, crear('dt', { texto: 'Nombre' }), crear('dd', { texto: sesion.nombre })),
        crear('div', { clase: 'par' }, crear('dt', { texto: 'Correo' }), crear('dd', { texto: sesion.identificador })),
        crear('div', { clase: 'par' }, crear('dt', { texto: 'Rol' }), crear('dd', {
            texto: sesion.es_administrador_principal ? '…' : 'Cuenta administrativa', atributos: { id: 'mi-cuenta-rol' },
        })),
        crear('div', { clase: 'par' }, crear('dt', { texto: 'Tipo' }), crear('dd', {}, sesion.es_administrador_principal ? insignia('Principal', 'pendiente') : 'No principal')));
    return crear('section', { clase: 'tarjeta', atributos: { 'aria-labelledby': 'titulo-mi-cuenta', id: 'mi-cuenta' } },
        crear('h2', { texto: 'Mi cuenta', atributos: { id: 'titulo-mi-cuenta' } }),
        lista,
        crear('div', { clase: 'acciones' },
            crear('a', { clase: 'boton', texto: 'Cambiar mi contraseña', atributos: { href: '#mi-contrasena', 'data-prueba': 'cambiar-contrasena' } }),
            salir));
}

function tarjetaCuenta(cuenta, sesion, recargar) {
    const propia = cuenta.correo === sesion.identificador;
    const aviso = avisoNuevo();
    const resumen = crear('summary', {},
        crear('strong', { texto: cuenta.nombre }), propia ? ' (tú)' : '', ` · ${cuenta.correo} · ${textoDato(cuenta.rol)} `,
        cuenta.es_administrador_principal ? insignia('Principal', 'pendiente') : null, ' ',
        cuenta.activa ? insignia('Activa', 'activo') : insignia('Desactivada', 'inactivo'));

    // Editar nombre, rol y correo (solo lo cambiado).
    const c = {
        nombre: campo({ etiqueta: 'Nombre', nombre: 'nombre', valor: cuenta.nombre, atributos: { maxlength: 150 } }),
        rol: campo({ etiqueta: 'Rol', nombre: 'rol', valor: cuenta.rol, ayuda: 'Texto libre, p. ej. secretaría. Opcional.', atributos: { maxlength: 30 } }),
        correo: campo({ etiqueta: 'Correo', nombre: 'correo', tipo: 'email', valor: cuenta.correo, atributos: { maxlength: 150 } }),
    };
    const botonGuardar = crear('button', { clase: 'boton boton-pequeno', texto: 'Guardar cambios', atributos: { type: 'submit' } });
    const formEditar = crear('form', { atributos: { novalidate: true, 'aria-label': `Editar ${cuenta.nombre}` } },
        crear('div', { clase: 'rejilla-campos' }, c.nombre.envoltura, c.rol.envoltura, c.correo.envoltura),
        crear('div', { clase: 'acciones' }, botonGuardar));
    formEditar.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const cambios = {};
        for (const nombre of Object.keys(c)) {
            const v = c[nombre].control.value.trim();
            const nuevo = v === '' ? null : v;
            if (nuevo !== (cuenta[nombre] ?? null)) cambios[nombre] = nuevo;
        }
        if (!Object.keys(cambios).length) return mostrarAviso(aviso, 'No hay cambios para guardar.', 'info');
        try {
            await conBotonOcupado(botonGuardar, 'Guardando…', () => usuariosAdministrativos.actualizar(cuenta.correo, cambios));
            notificar('Cuenta actualizada.');
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });

    // Desactivar o reactivar (con confirmación). La API rechaza la propia y las principales.
    const desactivar = cuenta.activa;
    const botonEstado = crear('button', {
        clase: desactivar ? 'boton boton-peligro boton-pequeno' : 'boton boton-secundario boton-pequeno',
        texto: desactivar ? 'Desactivar' : 'Reactivar',
        atributos: { type: 'button', 'aria-label': `${desactivar ? 'Desactivar' : 'Reactivar'} la cuenta de ${cuenta.nombre}` },
    });
    botonEstado.addEventListener('click', async () => {
        const aceptar = await confirmar({
            titulo: desactivar ? 'Desactivar cuenta' : 'Reactivar cuenta',
            mensaje: desactivar
                ? [`${cuenta.nombre} (${cuenta.correo}) no podrá ingresar y su sesión abierta caerá en la siguiente acción.`]
                : [`${cuenta.nombre} podrá volver a ingresar con su contraseña.`],
            textoAceptar: desactivar ? 'Desactivar' : 'Reactivar',
            peligroso: desactivar,
        });
        if (!aceptar) return;
        limpiarAviso(aviso);
        try {
            await conBotonOcupado(botonEstado, 'Guardando…', () => (desactivar ? usuariosAdministrativos.desactivar(cuenta.correo) : usuariosAdministrativos.reactivar(cuenta.correo)));
            notificar(desactivar ? 'Cuenta desactivada.' : 'Cuenta reactivada.');
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });

    // Restablecer la contraseña de OTRA cuenta (cierra sus sesiones).
    const nueva = campoContrasena('Contraseña nueva', 'contrasena_nueva');
    const botonRestablecer = crear('button', { clase: 'boton boton-secundario boton-pequeno', texto: 'Restablecer contraseña', atributos: { type: 'submit' } });
    const formRestablecer = crear('form', { atributos: { novalidate: true, 'aria-label': `Restablecer contraseña de ${cuenta.nombre}` } },
        crear('p', { clase: 'ayuda', texto: 'Sirve cuando el correo de recuperación no llega. Cierra las sesiones abiertas de esa cuenta.' }),
        crear('div', { clase: 'rejilla-campos' }, nueva.envoltura),
        crear('div', { clase: 'acciones' }, botonRestablecer));
    formRestablecer.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        if (nueva.control.value.length < MINIMO_CONTRASENA) return mostrarAviso(aviso, `La contraseña nueva debe tener al menos ${MINIMO_CONTRASENA} caracteres.`, 'error');
        const aceptar = await confirmar({ titulo: 'Restablecer contraseña', mensaje: [`Se cambiará la contraseña de ${cuenta.nombre} y se cerrarán sus sesiones abiertas.`], textoAceptar: 'Restablecer' });
        if (!aceptar) return undefined;
        try {
            await conBotonOcupado(botonRestablecer, 'Guardando…', () => usuariosAdministrativos.restablecerContrasena(cuenta.correo, nueva.control.value));
            nueva.control.value = '';
            notificar(`Contraseña de ${cuenta.nombre} restablecida.`);
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });

    return crear('details', { clase: 'participante' }, resumen,
        crear('div', {},
            aviso,
            crear('p', { clase: 'ayuda', texto: `Creada por: ${textoDato(cuenta.creado_por_correo)}` }),
            crear('h3', { clase: 'titulo-modalidad', texto: 'Datos' }), formEditar,
            crear('h3', { clase: 'titulo-modalidad', texto: 'Contraseña' }), formRestablecer,
            crear('h3', { clase: 'titulo-modalidad', texto: 'Estado' }), crear('div', { clase: 'acciones' }, botonEstado)));
}

function formularioCrear(recargar) {
    const aviso = avisoNuevo();
    const c = {
        correo: campo({ etiqueta: 'Correo', nombre: 'correo', tipo: 'email', obligatorio: true, atributos: { maxlength: 150, autocomplete: 'off' } }),
        nombre: campo({ etiqueta: 'Nombre', nombre: 'nombre', obligatorio: true, atributos: { maxlength: 150 } }),
        rol: campo({ etiqueta: 'Rol', nombre: 'rol', ayuda: 'Opcional.', atributos: { maxlength: 30 } }),
    };
    const contrasena = campoContrasena('Contraseña inicial', 'contrasena');
    const boton = crear('button', { clase: 'boton', texto: 'Crear cuenta', atributos: { type: 'submit' } });
    const formulario = crear('form', { atributos: { novalidate: true } }, aviso,
        crear('div', { clase: 'rejilla-campos' }, c.correo.envoltura, c.nombre.envoltura, c.rol.envoltura, contrasena.envoltura),
        crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        const datos = { correo: c.correo.control.value.trim(), nombre: c.nombre.control.value.trim(), contrasena: contrasena.control.value };
        const rol = c.rol.control.value.trim();
        if (rol) datos.rol = rol;
        try {
            const creada = await conBotonOcupado(boton, 'Creando…', () => usuariosAdministrativos.crear(datos));
            notificar(`Cuenta de ${creada.nombre} creada.`);
            recargar();
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
    });
    return crear('details', { clase: 'tarjeta' }, crear('summary', { texto: 'Crear cuenta' }),
        crear('p', { clase: 'ayuda', texto: 'La cuenta nace activa y no principal. Comparte la contraseña inicial por un medio seguro.' }), formulario);
}

// ===========================================================================
// Cambiar mi contraseña (#mi-contrasena) — cualquier cuenta administrativa
// ===========================================================================
export function mostrarCambiarContrasena(contenedor) {
    const aviso = avisoNuevo();
    const actual = campoContrasena('Contraseña actual', 'contrasena_actual', 'current-password');
    const nueva = campoContrasena('Contraseña nueva', 'contrasena_nueva');
    const repetir = campoContrasena('Repite la contraseña nueva', 'contrasena_repetida');
    const boton = crear('button', { clase: 'boton', texto: 'Cambiar contraseña', atributos: { type: 'submit' } });
    const formulario = crear('form', { clase: 'tarjeta formulario-angosto', atributos: { novalidate: true, 'aria-label': 'Cambiar mi contraseña' } },
        crear('p', { clase: 'ayuda', texto: 'Tu sesión sigue abierta; las demás sesiones de tu cuenta (otros equipos) se cierran.' }),
        aviso, actual.envoltura, nueva.envoltura, repetir.envoltura, crear('div', { clase: 'acciones' }, boton));
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarAviso(aviso);
        if (nueva.control.value !== repetir.control.value) return mostrarAviso(aviso, 'Las dos contraseñas nuevas no coinciden.', 'error');
        try {
            await conBotonOcupado(boton, 'Cambiando…', () => auth.cambiarContrasena(actual.control.value, nueva.control.value));
            formulario.reset();
            mostrarAviso(aviso, 'Contraseña cambiada.', 'exito');
        } catch (error) {
            mostrarAviso(aviso, error.message, 'error');
        }
        return undefined;
    });
    contenedor.append(
        crear('p', { clase: 'miga' }, crear('a', { texto: '← Cuentas', atributos: { href: '#cuentas' } })),
        crear('div', { clase: 'encabezado-vista' }, crear('h1', { texto: 'Cambiar mi contraseña' })), formulario);
    actual.control.focus();
}
