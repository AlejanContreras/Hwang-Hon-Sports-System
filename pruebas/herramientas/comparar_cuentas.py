#!/usr/bin/env python3
"""Compara la cuenta principal maestro@ con otra cuenta (por defecto desarrollador@) en las 95 acciones.

Corre la colección Postman completa dos veces, cada una sobre la base aparte recién restablecida:
la sesión «ses_admin» entra primero con maestro@hwanghon.local y después con la otra cuenta.
Luego compara, petición por petición, el código HTTP y las comprobaciones de Newman.
Las peticiones que usan otras sesiones (público, autorregistro, sin sesión, staff) quedan igual
en las dos corridas y sirven de control.

Uso (desde la raíz del repositorio):
  python pruebas/herramientas/comparar_cuentas.py [correo]     # por defecto desarrollador@hwanghon.local
Resultado: tabla en pantalla y pruebas/resultados/comparacion_cuentas.txt
"""
from __future__ import annotations

import hashlib
import http.cookiejar
import json
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import entorno  # noqa: E402

PRUEBAS = Path(__file__).resolve().parents[1]
RESULTADOS = PRUEBAS / "resultados"
BASE = "maestro@hwanghon.local"


def correr(correo: str) -> dict:
    sufijo = "consulta" if correo == BASE else f"consulta_{correo.split('@')[0]}"
    subprocess.run([sys.executable, str(PRUEBAS / "postman" / "ejecutar_newman.py"), "--correo-admin", correo],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    datos = json.loads((RESULTADOS / f"newman_{sufijo}.json").read_text(encoding="utf-8"))
    filas = {}
    for i, e in enumerate(datos["run"]["executions"]):
        consulta = {q["key"]: q.get("value") for q in e["request"]["url"].get("query", [])}
        accion = f"{consulta.get('recurso', '?')}.{consulta.get('accion', '?')}"
        fallas = sorted(a["assertion"] for a in e.get("assertions", []) if a.get("error"))
        filas[(i, e["item"]["name"])] = {"accion": accion, "codigo": (e.get("response") or {}).get("code"), "fallas": fallas}
    return filas


def extras(correo: str) -> dict[str, int]:
    """Acciones administrativas que la colección hace con otra sesión: se llaman directo con `correo`.
    Base recién restablecida para cada cuenta. Devuelve acción -> código HTTP."""
    entorno.restablecer_bd()
    entorno.limpiar_almacenamiento()
    token = "8" * 64  # ficticio, solo en la base aparte
    vence = (datetime.now(timezone(timedelta(hours=-5))) + timedelta(days=1)).strftime("%Y-%m-%d 00:00:00")
    entorno._mysql([entorno.BD, "-e", f"UPDATE usuario_administrativo SET token_recuperacion = '{hashlib.sha256(token.encode()).hexdigest()}', "
                    f"token_recuperacion_expira = '{vence}' WHERE correo = '{correo}'"])
    servidor = entorno.iniciar_servidor()
    abridor = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    api = f"{entorno.url_base()}/backend/Public/api.php"

    def llamar(metodo, recurso, accion, cuerpo=None, multipart=None, **q):
        url = f"{api}?{urllib.parse.urlencode({'recurso': recurso, 'accion': accion, **q})}"
        cab, datos = {}, None
        if multipart:
            lim = "----humo"
            partes = [f'--{lim}\r\nContent-Disposition: form-data; name="titulo"\r\n\r\nTeoría de prueba\r\n'.encode(),
                      f'--{lim}\r\nContent-Disposition: form-data; name="archivo"; filename="teoria.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()
                      + b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" + f"\r\n--{lim}--\r\n".encode()]
            datos, cab = b"".join(partes), {"Content-Type": f"multipart/form-data; boundary={lim}"}
        elif cuerpo is not None:
            datos, cab = json.dumps(cuerpo).encode(), {"Content-Type": "application/json"}
        try:
            with abridor.open(urllib.request.Request(url, data=datos, method=metodo, headers=cab), timeout=30) as r:
                cuerpo_r = r.read()
                return r.status, (json.loads(cuerpo_r) if "json" in r.headers.get("Content-Type", "") else None)
        except urllib.error.HTTPError as e:
            return e.code, None

    try:
        r = {}
        r["auth.restablecerContrasena (token propio)"] = llamar("POST", "auth", "restablecerContrasena", {"token": token, "contrasena_nueva": "OtraClave2026"})[0]
        r["auth.iniciarSesionAdministrativa (clave restablecida)"] = llamar("POST", "auth", "iniciarSesionAdministrativa", {"correo": correo, "contrasena": "OtraClave2026"})[0]
        r["auth.cambiarContrasena"] = llamar("POST", "auth", "cambiarContrasena", {"contrasena_actual": "OtraClave2026", "contrasena_nueva": "12345678"})[0]
        r["auth.sesionActual (tras cambiar su clave)"] = llamar("GET", "auth", "sesionActual")[0]
        codigo, general = llamar("POST", "documentos", "subirGeneral", multipart=True)
        r["documentos.subirGeneral"] = codigo
        r["documentos.listarGenerales"] = llamar("GET", "documentos", "listarGenerales")[0]
        r["documentos.descargarGeneral"] = llamar("GET", "documentos", "descargarGeneral", id=(general or {}).get("datos", {}).get("id", 0))[0]
        anio = datetime.now().year
        r["calendario.listar"] = llamar("GET", "calendario", "listar", desde=f"{anio}-01-01", hasta=f"{anio}-12-31")[0]
        return r
    finally:
        entorno.detener_servidor(servidor)


def main() -> int:
    otra = sys.argv[1] if len(sys.argv) > 1 else "desarrollador@hwanghon.local"
    coleccion = json.loads((PRUEBAS / "postman" / "HwangHon_API.postman_collection.json").read_text(encoding="utf-8"))
    acciones_admin = set()
    for carpeta in coleccion["item"]:
        for item in carpeta["item"]:
            if any(h["key"] == "X-Sesion-Prueba" and h["value"] == "ses_admin" for h in item["request"]["header"]):
                q = {p["key"]: p["value"] for p in item["request"]["url"].get("query", [])}
                if "recurso" in q and "accion" in q:
                    acciones_admin.add(f"{q['recurso']}.{q['accion']}")

    a, b = correr(BASE), correr(otra)
    diferencias = [(clave, a[clave], b.get(clave)) for clave in a if b.get(clave) is None
                   or (a[clave]["codigo"], a[clave]["fallas"]) != (b[clave]["codigo"], b[clave]["fallas"])]
    fallas_a = sum(len(v["fallas"]) for v in a.values())
    fallas_b = sum(len(v["fallas"]) for v in b.values())

    lineas = [
        f"Comparación de cuentas: {BASE} (A) frente a {otra} (B)",
        f"Peticiones: {len(a)} en A, {len(b)} en B · comprobaciones fallidas: A={fallas_a}, B={fallas_b}",
        f"Acciones distintas ejecutadas con la sesión administrativa (ses_admin): {len(acciones_admin)}",
        "",
    ]
    if not diferencias:
        lineas.append("SIN DIFERENCIAS: todas las peticiones dan el mismo código y las mismas comprobaciones con las dos cuentas.")
    else:
        lineas.append(f"{len(diferencias)} peticiones difieren:")
        for (i, nombre), x, y in diferencias:
            lineas.append(f"  #{i + 1} {nombre} [{x['accion']}]: A={x['codigo']} {x['fallas'] or ''} · B={y and y['codigo']} {(y or {}).get('fallas') or ''}")
    ea, eb = extras(BASE), extras(otra)
    lineas += ["", "Acciones administrativas que la colección hace con otra sesión (llamadas directas con cada cuenta):"]
    for accion in ea:
        marca = "igual" if ea[accion] == eb[accion] else "DIFERENTE"
        lineas.append(f"  {accion}: A={ea[accion]} · B={eb[accion]} · {marca}")
    distintos_extra = [k for k in ea if ea[k] != eb[k]]
    texto = "\n".join(lineas)
    print(texto)
    RESULTADOS.mkdir(exist_ok=True)
    (RESULTADOS / "comparacion_cuentas.txt").write_text(texto + "\n", encoding="utf-8")
    return 1 if diferencias or fallas_b or distintos_extra else 0


if __name__ == "__main__":
    sys.exit(main())
