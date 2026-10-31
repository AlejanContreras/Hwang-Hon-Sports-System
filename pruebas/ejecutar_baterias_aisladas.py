#!/usr/bin/env python3
"""Corre las 21 baterías de pruebas/backend/ SIN modificarlas, pero contra la base APARTE.

Desde el 31/10/2026 (R-01) ejecutar_todo.sh se NIEGA a correr si no lo lanza este ejecutor
(PRUEBAS_AISLADAS=1, DB_NAME=hwanghon_pruebas y BACKEND_PRUEBAS = la copia aislada). Aquí:
  1. se rehace la copia pruebas/entorno/app (backend, frontend, base_datos y pruebas/backend);
  2. se arrancan los tres servidores que piden las baterías (8090, 8091 con post_max_size=1M,
     8092 con ORIGENES_PERMITIDOS) desde la copia y con DB_NAME=hwanghon_pruebas;
  3. se corre el ejecutar_todo.sh DE LA COPIA con un cliente mysql envoltorio que cambia
     «hwanghon» por la base aparte en los scripts SQL (los archivos no se tocan);
  4. URL_APACHE apunta al servidor aislado (router_aislado.php, puerto 8095), que imita las
     reglas de Apache. Ver la nota del INFORME: la verificación de mod_rewrite REAL sigue
     necesitando XAMPP, y esa parte de las baterías escribe en la base «hwanghon».

Uso (desde la raíz del repositorio; en Windows, con Git Bash instalado):
  python pruebas/ejecutar_baterias_aisladas.py
Salidas: pruebas/resultados/baterias/<batería>.txt y resumen.txt
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI / "herramientas"))
import entorno  # noqa: E402

RESULTADOS = AQUI / "resultados" / "baterias"


def envoltorio_mysql() -> Path:
    """Cliente mysql que redirige «hwanghon» a la base aparte (argumentos y SQL por la entrada estándar)."""
    # Va en la carpeta temporal: ejecutar_todo.sh usa $MYSQL sin comillas y la ruta del proyecto en XAMPP
    # («C:\\Program Files\\…») tiene espacios.
    ruta = Path(tempfile.gettempdir()) / "hwanghon_mysql_aislado.sh"
    if " " in ruta.as_posix():
        print(f"Aviso: la carpeta temporal tiene espacios ({ruta}); define TMP/TEMP sin espacios si falla.", file=sys.stderr)
    clave = f' -p"{entorno.DB_PASS}"' if entorno.DB_PASS else ""
    mysql = entorno.MYSQL_BIN.replace("\\", "/")
    ruta.write_text(f"""#!/bin/bash
# Generado por ejecutar_baterias_aisladas.py: NUNCA toca la base 'hwanghon'.
BD="{entorno.BD}"
args=()
con_e=0
for a in "$@"; do
  [ "$a" = "-e" ] && con_e=1
  case "$a" in
    hwanghon) args+=("$BD") ;;
    *) args+=("${{a//DATABASE IF EXISTS hwanghon/DATABASE IF EXISTS $BD}}") ;;
  esac
done
if [ "$con_e" = 1 ] || [ -t 0 ]; then
  exec "{mysql}" -h"{entorno.DB_HOST}" -P"{entorno.DB_PORT}" -u"{entorno.DB_USER}"{clave} "${{args[@]}}"
fi
sed -e "s/CREATE DATABASE IF NOT EXISTS hwanghon\\b/CREATE DATABASE IF NOT EXISTS $BD/I" -e "s/^USE hwanghon;/USE $BD;/I" \\
  | "{mysql}" -h"{entorno.DB_HOST}" -P"{entorno.DB_PORT}" -u"{entorno.DB_USER}"{clave} "${{args[@]}}"
""", encoding="utf-8", newline="\n")
    return ruta


def servidor_bateria(puerto: int, extra_ini: list[str], extra_env: dict[str, str]) -> subprocess.Popen:
    publico = entorno.DIR_APP / "backend" / "Public"
    registro = open(entorno.DIR_ENTORNO / f"servidor_{puerto}.log", "ab")
    return subprocess.Popen(
        # Límites de subida como el php.ini de XAMPP (40M); la batería de documentos sube un archivo de 10 MB.
        [entorno.PHP_BIN, "-d", "display_errors=0", "-d", "log_errors=1", "-d", "error_reporting=-1",
         "-d", "upload_max_filesize=40M", "-d", "post_max_size=40M", *extra_ini,
         "-S", f"127.0.0.1:{puerto}", str(entorno.DIR_APP / "pruebas" / "backend" / "router_pruebas.php")],
        cwd=str(entorno.DIR_APP), stdout=registro, stderr=subprocess.STDOUT,
        env=entorno.entorno_php({"RUTA_PUBLIC": str(publico).replace("\\", "/"), **extra_env}))


def main() -> int:
    # En Windows se prefiere Git Bash: el bash.exe de System32 es el de WSL y no ve las rutas de Windows.
    candidatos = [os.environ.get("BASH_BIN"), r"C:\Program Files\Git\bin\bash.exe", r"C:\Program Files (x86)\Git\bin\bash.exe"]
    bash = next((c for c in candidatos if c and Path(c).exists()), None) or shutil.which("bash")
    if not bash:
        print("Hace falta bash (en Windows, Git Bash) para ejecutar_todo.sh.", file=sys.stderr)
        return 2
    entorno.preparar_todo()
    wrapper = envoltorio_mysql()
    memoria = entorno.DIR_APP / "pruebas" / "backend" / "memoria.log"
    procesos = [
        servidor_bateria(8090, ["-d", "memory_limit=128M"], {"MEMORIA_LOG": str(memoria).replace("\\", "/")}),
        servidor_bateria(8091, ["-d", "post_max_size=1M"], {}),
        servidor_bateria(8092, [], {"ORIGENES_PERMITIDOS": "http://front.example:5173"}),
    ]
    aislado = entorno.iniciar_servidor()
    try:
        time.sleep(1.5)
        env = entorno.entorno_php({
            "URL_BASE": "http://127.0.0.1:8090", "URL_1M": "http://127.0.0.1:8091", "URL_LISTA": "http://127.0.0.1:8092",
            "URL_APACHE": f"{entorno.url_base()}/backend/Public",
            "MEMORIA_LOG": str(memoria).replace("\\", "/"),
            "MYSQL": f"bash {wrapper.as_posix()}",
            "SALTAR_RECUPERACION": os.environ.get("SALTAR_RECUPERACION", ""),  # la copia usa correo en modo archivo
            # Guardas de ejecutar_todo.sh (R-01): solo corre con estas tres.
            "PRUEBAS_AISLADAS": "1",
            "BACKEND_PRUEBAS": (entorno.DIR_APP / "backend").as_posix(),
        })
        resultado = subprocess.run([bash, str(entorno.DIR_APP / "pruebas" / "backend" / "ejecutar_todo.sh")],
                                   cwd=str(entorno.DIR_APP), env=env, capture_output=True, stdin=subprocess.DEVNULL, text=True, encoding="utf-8", errors="replace")
        print(resultado.stdout)
        if resultado.stderr.strip():
            print(resultado.stderr, file=sys.stderr)
        RESULTADOS.mkdir(parents=True, exist_ok=True)
        for salida in (entorno.DIR_APP / "pruebas" / "backend" / "salidas").glob("*.txt"):
            shutil.copy(salida, RESULTADOS / salida.name)
        (RESULTADOS / "resumen.txt").write_text(resultado.stdout, encoding="utf-8")
        lineas = [l for l in resultado.stdout.splitlines() if ": exit " in l]
        fallos = [l for l in lineas if not re.search(r": exit 0 \| === RESULTADO: \d+ OK, 0 fallos ===", l)]
        if fallos:
            print("\nBaterías con fallos o sin resultado:\n  " + "\n  ".join(fallos))
        return 1 if resultado.returncode or fallos or not lineas else 0
    finally:
        for p in procesos:
            entorno.detener_servidor(p)
        entorno.detener_servidor(aislado)
        # Deja la base aparte con la semilla (no la de John).
        entorno.restablecer_bd()


if __name__ == "__main__":
    sys.exit(main())
