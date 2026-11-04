#!/usr/bin/env python3
"""Entorno LIMPIO «desde cero» para probar el sistema a mano, como la primera vez real.

  python pruebas/herramientas/entorno_limpio.py crear     # base vacía + cuentas iniciales + servidor en el 8096
  python pruebas/herramientas/entorno_limpio.py iniciar   # vuelve a arrancar el servidor (p. ej. tras reiniciar el PC), sin borrar nada
  python pruebas/herramientas/entorno_limpio.py detener   # apaga el servidor, SIN borrar datos
  python pruebas/herramientas/entorno_limpio.py actualizar  # trae el código nuevo (backend y frontend) y conserva base y archivos
  python pruebas/herramientas/entorno_limpio.py borrar    # apaga el servidor y elimina la base y la copia

Qué hace «crear»:
  * Base NUEVA «hwanghon_limpia» solo con base_datos/esquema_hwanghon_v4.sql: sin datos de prueba ni
    cuentas locales. Nunca toca «hwanghon» (uso manual) ni «hwanghon_pruebas» (pruebas automáticas).
  * Copia de la app en pruebas/entorno/limpio/app (Git la ignora) con su PROPIO Almacenamiento vacío:
    lo que subas ahí (cédula, foto…) queda solo en esa copia. Correo en modo «archivo» (nunca envía).
  * Cuentas iniciales con backend/Instalacion/crear_cuentas_iniciales.php (de la copia): la fundadora
    «Aldrin» y «Desarrollador», con correos locales de prueba y la CLAVE_LOCAL de abajo.
    SOLO PARA ESTE ENTORNO LOCAL: en producción las cuentas se crean con el instalador y claves reales.
  * Servidor php -S en http://127.0.0.1:8096/frontend/ que queda corriendo en segundo plano.
"""
from __future__ import annotations

import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import entorno  # noqa: E402  (ejecutables, conexión y router del entorno aislado)

BD = "hwanghon_limpia"
PUERTO = 8096
DIR = entorno.DIR_ENTORNO / "limpio"
APP = DIR / "app"
PID = DIR / "servidor.pid"
URL = f"http://127.0.0.1:{PUERTO}"
CLAVE_LOCAL = "Limpia2026Local"  # contraseña LOCAL de prueba de las dos cuentas iniciales (nunca producción)
CUENTAS = [("aldrin@hwanghon.local", "Aldrin (prueba local)"), ("desarrollador@hwanghon.local", "")]  # "" = nombre por defecto


def mysql(argumentos: list[str], entrada: bytes | None = None) -> None:
    if BD in (entorno.BD_PRODUCCION_LOCAL, "hwanghon_pruebas"):
        sys.exit("Nombre de base reservado.")
    comando = [entorno.MYSQL_BIN, f"-h{entorno.DB_HOST}", f"-P{entorno.DB_PORT}", f"-u{entorno.DB_USER}", "--default-character-set=utf8mb4"]
    if entorno.DB_PASS:
        comando.append(f"-p{entorno.DB_PASS}")
    r = subprocess.run(comando + argumentos, input=entrada, capture_output=True)
    if r.returncode:
        sys.exit(f"mysql falló: {r.stderr.decode(errors='replace')}")


def env_php() -> dict[str, str]:
    env = entorno.entorno_php()
    env["DB_NAME"] = BD
    return env


def puerto_ocupado() -> bool:
    with socket.socket() as s:
        return s.connect_ex(("127.0.0.1", PUERTO)) == 0


def apagar_servidor() -> None:
    if PID.exists():
        pid = int(PID.read_text().strip() or 0)
        if pid:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(pid), "/T", "/F"], capture_output=True)
            else:
                try:
                    os.killpg(pid, signal.SIGTERM)
                except (ProcessLookupError, PermissionError):
                    pass
        PID.unlink()
        for _ in range(50):
            if not puerto_ocupado():
                break
            time.sleep(0.1)


def arrancar_servidor() -> None:
    if puerto_ocupado():
        sys.exit(f"El puerto {PUERTO} ya está en uso. Si es este entorno, ya está corriendo; si no, ciérralo primero.")
    registro = open(DIR / "servidor.log", "ab")
    extra = {"creationflags": subprocess.CREATE_NEW_PROCESS_GROUP | subprocess.DETACHED_PROCESS} if os.name == "nt" else {"start_new_session": True}
    proceso = subprocess.Popen(
        [entorno.PHP_BIN, "-d", "display_errors=0", "-d", "log_errors=1", "-d", "upload_max_filesize=40M", "-d", "post_max_size=40M",
         "-S", f"127.0.0.1:{PUERTO}", "-t", str(APP), str(entorno.ROUTER)],
        cwd=str(APP), env=env_php(), stdout=registro, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL, **extra)
    PID.write_text(str(proceso.pid))
    for _ in range(100):
        try:
            with urllib.request.urlopen(f"{URL}/backend/Public/api.php", timeout=2) as r:
                if r.status == 200:
                    return
        except Exception:
            time.sleep(0.2)
    sys.exit(f"El servidor no respondió; ver {DIR / 'servidor.log'}")


def copiar_app() -> None:
    raiz = entorno.RAIZ_REPO
    shutil.copytree(raiz / "backend", APP / "backend", ignore=entorno.ignorar_privados)
    shutil.copytree(raiz / "frontend", APP / "frontend")
    plantilla = (raiz / "backend" / "Src" / "Config" / "correo.example.php").read_text(encoding="utf-8")
    plantilla = re.sub(r"'modo'\s*=>\s*'[^']*'", "'modo' => 'archivo'", plantilla)
    plantilla = re.sub(r"'clave_aplicacion'\s*=>\s*'[^']*'", "'clave_aplicacion' => ''", plantilla)
    plantilla = re.sub(r"'url_restablecimiento'\s*=>\s*'[^']*'", f"'url_restablecimiento' => '{URL}/frontend/recuperar.html'", plantilla)
    (APP / "backend" / "Src" / "Config" / "correo.php").write_text(plantilla, encoding="utf-8")
    (APP / "backend" / "Almacenamiento").mkdir(parents=True, exist_ok=True)


def crear_cuentas() -> None:
    respuestas = "".join(f"{correo}\n{nombre}\n{CLAVE_LOCAL}\n{CLAVE_LOCAL}\n" for correo, nombre in CUENTAS)
    r = subprocess.run([entorno.PHP_BIN, str(APP / "backend" / "Instalacion" / "crear_cuentas_iniciales.php")],
                       input=respuestas.encode(), capture_output=True, env=env_php(), cwd=str(APP))
    creadas = r.stdout.decode(errors="replace").count("Cuenta creada.")
    if r.returncode or creadas != len(CUENTAS):
        sys.exit(f"crear_cuentas_iniciales.php no creó las {len(CUENTAS)} cuentas (creó {creadas}).\n{r.stderr.decode(errors='replace')[-800:]}")


def actualizar() -> None:
    """Recopia backend/ y frontend/ del repositorio a la copia, conservando su Almacenamiento (archivos
    subidos, sesiones) y su correo.php; la base hwanghon_limpia no se toca. Luego arranca el servidor."""
    if not APP.exists():
        sys.exit("No hay entorno limpio: usa «crear».")
    apagar_servidor()
    respaldo = DIR / "_almacenamiento"
    shutil.rmtree(respaldo, ignore_errors=True)
    shutil.move(str(APP / "backend" / "Almacenamiento"), str(respaldo))
    correo = (APP / "backend" / "Src" / "Config" / "correo.php").read_bytes()
    shutil.rmtree(APP / "backend")
    shutil.rmtree(APP / "frontend")
    copiar_app()
    shutil.rmtree(APP / "backend" / "Almacenamiento")
    shutil.move(str(respaldo), str(APP / "backend" / "Almacenamiento"))
    (APP / "backend" / "Src" / "Config" / "correo.php").write_bytes(correo)
    arrancar_servidor()
    print(f"Código actualizado (datos y archivos conservados). Servidor en {URL}/frontend/")


def crear() -> None:
    if DIR.exists() or puerto_ocupado():
        borrar(silencioso=True)
    DIR.mkdir(parents=True)
    mysql(["-e", f"DROP DATABASE IF EXISTS `{BD}`"])
    esquema = (entorno.RAIZ_REPO / "base_datos" / "esquema_hwanghon_v4.sql").read_text(encoding="utf-8-sig")
    esquema = re.sub(r"(CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+)`?hwanghon`?(?=\s|;)", rf"\g<1>{BD}", esquema, flags=re.I)
    esquema = re.sub(r"(^|\n)(\s*USE\s+)`?hwanghon`?\s*;", rf"\g<1>\g<2>{BD};", esquema, flags=re.I)
    mysql([], esquema.encode("utf-8"))
    copiar_app()
    crear_cuentas()
    arrancar_servidor()
    print(f"""
Entorno limpio listo (base {BD}, sin datos; copia en {APP}).
  Abre:        {URL}/frontend/
  Cuentas:     {CUENTAS[0][0]}  (fundadora «Aldrin», principal)
               {CUENTAS[1][0]}  (Desarrollador, principal)
  Contraseña:  {CLAVE_LOCAL}   (LOCAL de prueba, ambas cuentas; nunca en producción)
Para apagarlo y borrarlo:  python pruebas/herramientas/entorno_limpio.py borrar""")


def borrar(silencioso: bool = False) -> None:
    apagar_servidor()
    mysql(["-e", f"DROP DATABASE IF EXISTS `{BD}`"])
    shutil.rmtree(DIR, ignore_errors=True)
    if not silencioso:
        print(f"Entorno limpio borrado: servidor apagado, base {BD} y copia {DIR} eliminadas.")


def main() -> None:
    orden = sys.argv[1] if len(sys.argv) > 1 else ""
    if orden == "crear":
        crear()
    elif orden == "borrar":
        borrar()
    elif orden == "detener":
        apagar_servidor()
        print("Servidor del entorno limpio apagado. Datos y archivos conservados (vuelve con «iniciar»).")
    elif orden == "actualizar":
        actualizar()
    elif orden == "iniciar":
        if not APP.exists():
            sys.exit("No hay entorno limpio: usa «crear».")
        arrancar_servidor()
        print(f"Servidor del entorno limpio en {URL}/frontend/")
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
