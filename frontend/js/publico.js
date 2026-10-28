// Página del ámbito público («Mi perfil»). En F1 solo muestra la sesión y permite salir.
import { exigirSesion, cerrarSesion } from './sesion.js';

document.getElementById('boton-salir').addEventListener('click', (evento) => {
    evento.currentTarget.disabled = true;
    cerrarSesion();
});

async function iniciar() {
    const sesion = await exigirSesion('publica');
    if (!sesion) return;
    document.getElementById('nombre-usuario').textContent = sesion.nombre;
}

iniciar();
