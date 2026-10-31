#!/usr/bin/env python3
"""Entorno AISLADO de pruebas de HwangHon-Sports-System (solo datos ficticios).

Qué hace y por qué:
  * Base de datos APARTE (por defecto ``hwanghon_pruebas``): las pruebas la borran y la
    recrean con la semilla. Nunca toca ``hwanghon``, la base que John usa a mano
    (si alguien configura ``hwanghon`` como base de pruebas, el script se niega).
  * Copia de la aplicación en ``pruebas/entorno/app`` (backend/, frontend/, base_datos/ y
    pruebas/backend/): así los documentos subidos, las sesiones y los correos de prueba
    quedan en el Almacenamiento de la copia y no en el de XAMPP. La copia se rehace en
    cada corrida, de modo que siempre refleja el código actual. En la copia,
    ``Config/correo.php`` va SIEMPRE en modo ``archivo``: ninguna prueba envía un correo real.
  * Servidor de PHP (``php -S``) con ``router_aislado.php``: front y API en el mismo origen
    (http://127.0.0.1:8095/frontend/ y /backend/Public/), con DB_NAME apuntando a la base aparte.

Uso (desde la raíz del repositorio):
  python pruebas/herramientas/entorno.py preparar      # copia + base + semilla
  python pruebas/herramientas/entorno.py restablecer   # solo base + semilla + almacenamiento
  python pruebas/herramientas/entorno.py servidor      # preparar y dejar el servidor arriba (Ctrl+C para parar)

Variables de entorno (opcionales):
  HWANGHON_BD_PRUEBAS   nombre de la base aparte (por defecto hwanghon_pruebas)
  DB_HOST, DB_PORT, DB_USER, DB_PASS   conexión a MariaDB (por defecto localhost:3306, root sin clave)
  MYSQL_BIN, PHP_BIN    ejecutables (por defecto los de XAMPP en Windows si existen; si no, mysql y php)
  PUERTO_PRUEBAS        puerto del servidor aislado (por defecto 8095)
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

RAIZ_REPO = Path(__file__).resolve().parents[2]
DIR_PRUEBAS = RAIZ_REPO / "pruebas"
DIR_ENTORNO = DIR_PRUEBAS / "entorno"
DIR_APP = DIR_ENTORNO / "app"
ROUTER = Path(__file__).resolve().parent / "router_aislado.php"

BD_PRODUCCION_LOCAL = "hwanghon"  # la base de John: NUNCA se toca desde aquí
BD = os.environ.get("HWANGHON_BD_PRUEBAS", "hwanghon_pruebas")
PUERTO = int(os.environ.get("PUERTO_PRUEBAS", "8095"))
DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_PORT = os.environ.get("DB_PORT", "3306")
DB_USER = os.environ.get("DB_USER", "root")
DB_PASS = os.environ.get("DB_PASS", "")

ARCHIVOS_SEMILLA = ["esquema_hwanghon_v4.sql", "insercion_datos_prueba.sql"]
ARCHIVO_CUENTAS = "cuentas_prueba_locales.sql"


def _xampp(relativo: str) -> str | None:
    for base in (r"C:\Program Files\Xampp", r"C:\xampp"):
        ruta = Path(base) / relativo
        if ruta.exists():
            return str(ruta)
    return None


MYSQL_BIN = os.environ.get("MYSQL_BIN") or (_xampp(r"mysql\bin\mysql.exe") if os.name == "nt" else None) or "mysql"
PHP_BIN = os.environ.get("PHP_BIN") or (_xampp(r"php\php.exe") if os.name == "nt" else None) or "php"


def url_base() -> str:
    return f"http://127.0.0.1:{PUERTO}"


def _validar_nombre_bd() -> None:
    if not re.fullmatch(r"[A-Za-z0-9_]+", BD):
        sys.exit(f"Nombre de base de pruebas inválido: {BD!r}")
    if BD.lower() == BD_PRODUCCION_LOCAL:
        sys.exit("Se negó a usar la base 'hwanghon' (la que John usa a mano). Usa una base aparte, p. ej. hwanghon_pruebas.")


def _mysql(argumentos: list[str], entrada: bytes | None = None) -> None:
    comando = [MYSQL_BIN, f"-h{DB_HOST}", f"-P{DB_PORT}", f"-u{DB_USER}", "--default-character-set=utf8mb4"]
    if DB_PASS:
        comando.append(f"-p{DB_PASS}")
    resultado = subprocess.run(comando + argumentos, input=entrada, capture_output=True)
    if resultado.returncode != 0:
        raise RuntimeError(f"mysql falló ({' '.join(argumentos)}): {resultado.stderr.decode(errors='replace')}")


def sql_redirigido(texto: str) -> str:
    """Cambia la base 'hwanghon' de los scripts por la base aparte, sin tocar los archivos."""
    texto = re.sub(r"(CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+)`?hwanghon`?(?=\s|;)", rf"\g<1>{BD}", texto, flags=re.I)
    texto = re.sub(r"(^|\n)(\s*USE\s+)`?hwanghon`?\s*;", rf"\g<1>\g<2>{BD};", texto, flags=re.I)
    if re.search(r"(?<![@\w.])`?hwanghon`?\.`?[a-z_]+`?\s", texto) or re.search(r"(?i)\bUSE\s+`?hwanghon`?\s*;", texto):
        raise RuntimeError("Un script SQL referencia la base 'hwanghon' de una forma que no se puede redirigir.")
    return texto


def restablecer_bd(con_cuentas: bool = True) -> None:
    """Borra la base aparte y la recrea: esquema v4 + datos de prueba (+ cuentas locales)."""
    _validar_nombre_bd()
    _mysql(["-e", f"DROP DATABASE IF EXISTS `{BD}`"])
    archivos = ARCHIVOS_SEMILLA + ([ARCHIVO_CUENTAS] if con_cuentas else [])
    partes = []
    for nombre in archivos:
        texto = (RAIZ_REPO / "base_datos" / nombre).read_text(encoding="utf-8-sig")
        if nombre != ARCHIVOS_SEMILLA[0] and not re.search(r"(?i)\bUSE\s+", texto):
            texto = f"USE {BD};\n" + texto
        partes.append(sql_redirigido(texto))
    _mysql([], "\n".join(partes).encode("utf-8"))


def limpiar_almacenamiento() -> None:
    almacen = DIR_APP / "backend" / "Almacenamiento"
    for carpeta in ("documentos", "generales", "sesiones", "correos"):
        shutil.rmtree(almacen / carpeta, ignore_errors=True)


def preparar_copia() -> None:
    """Rehace pruebas/entorno/app con el código actual (sin el Almacenamiento real)."""
    if DIR_APP.exists():
        shutil.rmtree(DIR_APP)
    ignorar_almacen = shutil.ignore_patterns("sesiones", "documentos", "generales", "correos", "correo.php")
    shutil.copytree(RAIZ_REPO / "backend", DIR_APP / "backend", ignore=ignorar_almacen)
    shutil.copytree(RAIZ_REPO / "frontend", DIR_APP / "frontend")
    shutil.copytree(RAIZ_REPO / "base_datos", DIR_APP / "base_datos")
    shutil.copytree(RAIZ_REPO / "pruebas" / "backend", DIR_APP / "pruebas" / "backend",
                    ignore=shutil.ignore_patterns("salidas", "descargas_p11", "tmp_*.xlsx", "memoria.log"))
    # Correo SIEMPRE en modo archivo dentro de la copia (nunca SMTP real).
    plantilla = (RAIZ_REPO / "backend" / "Src" / "Config" / "correo.example.php").read_text(encoding="utf-8")
    plantilla = re.sub(r"'modo'\s*=>\s*'[^']*'", "'modo' => 'archivo'", plantilla)
    plantilla = re.sub(r"'clave_aplicacion'\s*=>\s*'[^']*'", "'clave_aplicacion' => ''", plantilla)
    plantilla = re.sub(r"'url_restablecimiento'\s*=>\s*'[^']*'",
                       f"'url_restablecimiento' => '{url_base()}/frontend/index.html'", plantilla)
    (DIR_APP / "backend" / "Src" / "Config" / "correo.php").write_text(plantilla, encoding="utf-8")
    (DIR_APP / "backend" / "Almacenamiento").mkdir(parents=True, exist_ok=True)


def entorno_php(extra: dict[str, str] | None = None) -> dict[str, str]:
    env = dict(os.environ)
    env.update({"DB_HOST": DB_HOST, "DB_PORT": DB_PORT, "DB_NAME": BD, "DB_USER": DB_USER, "DB_PASS": DB_PASS})
    env.update(extra or {})
    return env


def iniciar_servidor(puerto: int = PUERTO, registro: Path | None = None) -> subprocess.Popen:
    """Arranca php -S con el router aislado y espera a que la API responda."""
    import socket
    with socket.socket() as prueba:
        if prueba.connect_ex(("127.0.0.1", puerto)) == 0:
            raise RuntimeError(f"El puerto {puerto} ya está en uso (¿otro servidor de pruebas abierto?). Ciérralo o usa PUERTO_PRUEBAS.")
    registro = registro or (DIR_ENTORNO / f"servidor_{puerto}.log")
    registro.parent.mkdir(parents=True, exist_ok=True)
    salida = open(registro, "ab")
    proceso = subprocess.Popen(
        [PHP_BIN, "-d", "display_errors=0", "-d", "log_errors=1", "-d", "upload_max_filesize=40M",
         "-d", "post_max_size=40M", "-S", f"127.0.0.1:{puerto}", "-t", str(DIR_APP), str(ROUTER)],
        # Varios procesos atienden en paralelo (Linux/macOS); en Windows php -S atiende de a una petición.
        cwd=str(DIR_APP), env=entorno_php({} if os.name == "nt" else {"PHP_CLI_SERVER_WORKERS": os.environ.get("PHP_CLI_SERVER_WORKERS", "4")}),
        stdout=salida, stderr=subprocess.STDOUT,
    )
    url = f"http://127.0.0.1:{puerto}/backend/Public/api.php"
    for _ in range(100):
        if proceso.poll() is not None:
            raise RuntimeError(f"El servidor de PHP se cerró al arrancar; ver {registro}")
        try:
            with urllib.request.urlopen(url, timeout=2) as r:
                if r.status == 200:
                    return proceso
        except Exception:
            time.sleep(0.2)
    proceso.terminate()
    raise RuntimeError(f"El servidor no respondió en {url}; ver {registro}")


def detener_servidor(proceso: subprocess.Popen | None) -> None:
    if proceso and proceso.poll() is None:
        proceso.terminate()
        try:
            proceso.wait(timeout=10)
        except subprocess.TimeoutExpired:
            proceso.kill()


def preparar_todo() -> None:
    preparar_copia()
    restablecer_bd()
    limpiar_almacenamiento()


def main() -> None:
    orden = sys.argv[1] if len(sys.argv) > 1 else "preparar"
    if orden == "preparar":
        preparar_todo()
        print(f"Entorno listo: copia en {DIR_APP}, base {BD} con semilla y cuentas locales.")
    elif orden == "restablecer":
        restablecer_bd()
        limpiar_almacenamiento()
        print(f"Base {BD} restablecida (semilla + cuentas locales) y almacenamiento de la copia vacío.")
    elif orden == "servidor":
        preparar_todo()
        proceso = iniciar_servidor()
        print(f"Servidor aislado en {url_base()}/frontend/  (API: {url_base()}/backend/Public/api.php). Ctrl+C para parar.")
        try:
            proceso.wait()
        except KeyboardInterrupt:
            detener_servidor(proceso)
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
