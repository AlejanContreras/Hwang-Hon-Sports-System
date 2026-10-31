#!/usr/bin/env python3
"""Corre la colección Postman con Newman contra el entorno AISLADO (base hwanghon_pruebas).

Pasos: rehace la copia de la app y la base aparte con la semilla, deja los archivos de prueba
y el token ficticio de recuperación, arranca el servidor aislado, ejecuta Newman y lo apaga.

Uso (desde la raíz del repositorio):
  python pruebas/postman/ejecutar_newman.py                 # forma oficial (api.php?recurso=…&accion=…)
  python pruebas/postman/ejecutar_newman.py --forma ruta    # la misma colección con la forma por ruta
  python pruebas/postman/ejecutar_newman.py --correo-admin desarrollador@hwanghon.local   # la colección con otra cuenta principal
  python pruebas/postman/ejecutar_newman.py --solo-preparar # deja base, archivos y servidor listos para Postman (Ctrl+C para parar)

Requisitos: Node.js con newman (npm install -g newman). Resultados en pruebas/resultados/.
"""
from __future__ import annotations

import argparse
import hashlib
import shutil
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI.parent / "herramientas"))
import entorno  # noqa: E402

COLECCION = AQUI / "HwangHon_API.postman_collection.json"
RESULTADOS = AQUI.parent / "resultados"
TOKEN = "7" * 64  # ficticio, el mismo de capturar.php
BOGOTA = timezone(timedelta(hours=-5))


def preparar_archivos() -> None:
    """Archivos que sube la colección (rutas relativas a pruebas/postman/)."""
    carpeta = AQUI / "archivos"
    carpeta.mkdir(exist_ok=True)
    anio = datetime.now(BOGOTA).year
    (carpeta / "minimo.pdf").write_bytes(b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")
    (carpeta / "no_permitido.csv").write_bytes(b"a;b\n1;2\n")
    (carpeta / "eventos_calendario.csv").write_text(
        "nombre;tipo;fecha;lugar;visibilidad;observaciones\n"
        f"Torneo interclubes;competencia;14/11/{anio};Coliseo municipal;publica;\n"
        f"Clausura de fin de año;evento_extra;{anio}-12-13;Sede principal;publica;\n"
        f"Taller de árbitros;evento_extra;31/02/{anio};Sede;publica;\n", encoding="utf-8")
    (carpeta / "carga_deportistas.csv").write_bytes(
        "﻿documento;nombre;fecha_nacimiento;sexo;estatura;peso;correo;acudiente_documento;acudiente_nombre;acudiente_relacion\n"
        "1000000170;Mateo Importado Ficticio;10/05/2012;M;1,45;38;;1000000970;Laura Importada Ficticia;madre\n"
        "1000000103;Fabián Esteban Rincón Peña;;;;76,5;;;;\n"
        "1000000171;Sin Peso Ficticio;01/01/1990;F;1,6;;;;;\n".encode("utf-8"))
    (carpeta / "carga_incompleta.csv").write_text("nombre;peso\nAna;50\n", encoding="utf-8")


def fixture_token() -> None:
    """Igual que capturar.php: deja un token de recuperación ficticio para administrativo1 (solo en la base aparte)."""
    vence = (datetime.now(BOGOTA) + timedelta(days=1)).strftime("%Y-%m-%d 00:00:00")
    entorno._mysql([entorno.BD, "-e",
                    f"UPDATE usuario_administrativo SET token_recuperacion = '{hashlib.sha256(TOKEN.encode()).hexdigest()}', "
                    f"token_recuperacion_expira = '{vence}' WHERE correo = 'administrativo1@hwanghon.local'"])


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--forma", choices=["consulta", "ruta"], default="consulta")
    parser.add_argument("--solo-preparar", action="store_true")
    parser.add_argument("--correo-admin", default="maestro@hwanghon.local",
                        help="Cuenta PRINCIPAL para ses_admin (p. ej. desarrollador@hwanghon.local)")
    args = parser.parse_args()

    entorno.preparar_todo()
    fixture_token()
    preparar_archivos()
    servidor = entorno.iniciar_servidor()
    try:
        if args.solo_preparar:
            print(f"Listo para Postman: base_url = {entorno.url_base()}/backend/Public · archivos en {AQUI / 'archivos'}. Ctrl+C para parar.")
            servidor.wait()
            return 0
        newman = shutil.which("newman") or shutil.which("newman.cmd")
        if not newman:
            print("No se encontró newman. Instálalo con: npm install -g newman", file=sys.stderr)
            return 2
        RESULTADOS.mkdir(exist_ok=True)
        sufijo = args.forma if args.correo_admin == "maestro@hwanghon.local" else f"{args.forma}_{args.correo_admin.split('@')[0]}"
        comando = [newman, "run", str(COLECCION), "-e", str(AQUI / "entorno_aislado.postman_environment.json"),
                   "--env-var", f"base_url={entorno.url_base()}/backend/Public", "--env-var", f"forma_url={args.forma}",
                   "--env-var", f"correo_admin={args.correo_admin}",
                   "--working-dir", str(AQUI), "--reporters", "cli,junit,json",
                   "--reporter-junit-export", str(RESULTADOS / f"newman_{sufijo}.xml"),
                   "--reporter-json-export", str(RESULTADOS / f"newman_{sufijo}.json"),
                   "--color", "off", "--disable-unicode"]
        return subprocess.run(comando).returncode
    except KeyboardInterrupt:
        return 0
    finally:
        entorno.detener_servidor(servidor)


if __name__ == "__main__":
    sys.exit(main())
