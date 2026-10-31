#!/usr/bin/env python3
"""Prueba de humo para el hosting (p. ej. InfinityFree), SOLO lectura y SIN credenciales.

Uso:   python pruebas/herramientas/humo_hosting.py <URL_BASE>
       URL_BASE = carpeta donde están frontend/ y backend/, p. ej.
         https://hwanghon.infinityfreeapp.com
         http://localhost/HwangHon-Sports-System
         http://127.0.0.1:8095            (servidor aislado de pruebas)

Comprueba: que el front carga; que api.php responde JSON (200/400/401, nunca HTML ni 500);
que la ruta limpia responde igual que la forma oficial (mod_rewrite); que las carpetas internas
dan 403/404; y que la cookie de sesión lleva HttpOnly, SameSite y (con https) Secure.
No inicia sesión, no envía POST/PUT/DELETE ni escribe nada. Solo biblioteca estándar de Python.
Sale con código 1 si algo falla.
"""
from __future__ import annotations

import json
import ssl
import sys
import urllib.error
import urllib.request

AGENTE = "Mozilla/5.0 (humo_hosting HwangHon; solo lectura)"


class Respuesta:
    def __init__(self, codigo: int, cabeceras, cuerpo: bytes, error: str = ""):
        self.codigo, self.cabeceras, self.cuerpo, self.error = codigo, cabeceras, cuerpo, error

    @property
    def tipo(self) -> str:
        return (self.cabeceras.get("Content-Type", "") if self.cabeceras else "").lower()

    @property
    def texto(self) -> str:
        return self.cuerpo.decode("utf-8", errors="replace")

    def json(self):
        try:
            return json.loads(self.texto)
        except ValueError:
            return None

    def cookies(self) -> list[str]:
        return self.cabeceras.get_all("Set-Cookie") or [] if self.cabeceras else []


def pedir(url: str, cookie: str | None = None) -> Respuesta:
    cabeceras = {"User-Agent": AGENTE, "Accept": "application/json, text/html;q=0.9"}
    if cookie:
        cabeceras["Cookie"] = cookie
    peticion = urllib.request.Request(url, headers=cabeceras, method="GET")
    try:
        with urllib.request.urlopen(peticion, timeout=30, context=ssl.create_default_context()) as r:
            return Respuesta(r.status, r.headers, r.read(200_000))
    except urllib.error.HTTPError as e:
        return Respuesta(e.code, e.headers, e.read(200_000))
    except Exception as e:  # DNS, TLS, tiempo de espera…
        return Respuesta(0, None, b"", f"{type(e).__name__}: {e}")


def anti_bots(r: Respuesta) -> bool:
    """InfinityFree y similares responden a los scripts con una página que exige JavaScript (aes.js)."""
    t = r.texto.lower()
    return "aes.js" in t or "slowaes" in t or ("javascript" in t and "__test" in t)


def causa_no_json(r: Respuesta) -> str:
    if r.error:
        return f"Sin respuesta ({r.error}): revisa la URL, el DNS o el certificado."
    if anti_bots(r):
        return ("El hosting responde una página anti-bots que exige JavaScript (típico de InfinityFree): desde un script no se "
                "puede probar la API. El navegador sí la pasa; prueba el front a mano.")
    if r.codigo == 500:
        return "Error 500: mira el log de errores de PHP del hosting (versión de PHP 8, extensiones pdo_mysql/mbstring/fileinfo, Config/database.php)."
    if r.codigo == 404:
        return "404: la ruta no existe en el hosting (¿subiste backend/Public/? ¿la URL_BASE es la carpeta que contiene frontend/ y backend/?)."
    if "html" in r.tipo:
        return f"Responde HTML (código {r.codigo}) en vez de JSON: página de error del hosting o PHP mostrando errores (display_errors)."
    return f"Respuesta inesperada: código {r.codigo}, tipo «{r.tipo or 'sin Content-Type'}»."


def main() -> int:
    if len(sys.argv) != 2 or not sys.argv[1].startswith(("http://", "https://")):
        print(__doc__)
        return 2
    base = sys.argv[1].rstrip("/")
    api = f"{base}/backend/Public/api.php"
    filas: list[tuple[str, bool, str]] = []

    def anotar(nombre: str, ok: bool, causa: str = "") -> None:
        filas.append((nombre, ok, "" if ok else causa))

    # 1) Front
    r = pedir(f"{base}/frontend/index.html")
    ok = r.codigo == 200 and "html" in r.tipo and "formulario-administrativo" in r.texto
    anotar("Front: frontend/index.html carga", ok,
           causa_no_json(r) if r.codigo != 200 or anti_bots(r) else "Carga, pero no es la pantalla de ingreso del sistema (¿otra página o versión?).")
    r = pedir(f"{base}/frontend/js/api.js")
    anotar("Front: frontend/js/api.js carga", r.codigo == 200 and "BASE_API" in r.texto,
           causa_no_json(r) if r.codigo != 200 else "Responde, pero no es el api.js del sistema.")

    # 2) API responde JSON (lectura, sin credenciales)
    casos = [
        ("API: salud (api.php)", api, 200),
        ("API: deportistas.listar sin sesión → 401 JSON", f"{api}?recurso=deportistas&accion=listar", 401),
        ("API: sin «accion» → 400 JSON", f"{api}?recurso=deportistas", 400),
    ]
    oficial = None
    for nombre, url, esperado in casos:
        r = pedir(url)
        cuerpo = r.json() if "json" in r.tipo else None
        ok = r.codigo == esperado and isinstance(cuerpo, dict) and "exito" in cuerpo
        anotar(nombre, ok, causa_no_json(r) if cuerpo is None else f"JSON con código {r.codigo} (se esperaba {esperado}): {r.texto[:120]}")
        if esperado == 401:
            oficial = (r.codigo, cuerpo)

    # 3) Ruta limpia (mod_rewrite) igual que la forma oficial
    r = pedir(f"{base}/backend/Public/deportistas/listar")
    cuerpo = r.json() if "json" in r.tipo else None
    if cuerpo is not None and oficial and (r.codigo, cuerpo) == oficial:
        anotar("Ruta limpia: backend/Public/deportistas/listar = forma oficial", True)
    elif r.codigo == 404 and cuerpo is None:
        anotar("Ruta limpia: backend/Public/deportistas/listar = forma oficial", False,
               "404 en HTML: mod_rewrite NO funciona en este hosting (o no se subió backend/Public/.htaccess). "
               "Pon USAR_RUTA_LIMPIA = false en frontend/js/api.js: la forma oficial sí funciona.")
    else:
        anotar("Ruta limpia: backend/Public/deportistas/listar = forma oficial", False,
               causa_no_json(r) if cuerpo is None else f"Responde distinto a la forma oficial: {r.codigo} {r.texto[:120]}")

    # 4) Carpetas internas sin acceso web
    internas = [
        "backend/Src/", "backend/Src/Config/database.php", "backend/Config/", "backend/Almacenamiento/",
        "backend/Almacenamiento/sesiones/", "base_datos/esquema_hwanghon_v4.sql", "base_datos/cuentas_prueba_locales.sql",
    ]
    for ruta in internas:
        r = pedir(f"{base}/{ruta}")
        filtra = "<?php" in r.texto or "CREATE TABLE" in r.texto or "INSERT INTO" in r.texto or "Index of" in r.texto
        ok = r.codigo in (403, 404) and not filtra
        if filtra:
            causa = f"¡EXPONE contenido interno (código {r.codigo})! Falta el .htaccess «Require all denied» de esa carpeta o el hosting lo ignora; no la subas a la carpeta pública."
        elif r.codigo == 0:
            causa = f"Sin respuesta ({r.error})."
        else:
            causa = f"Responde {r.codigo} en vez de 403/404: revisa el .htaccess de esa carpeta."
        anotar(f"Protegida: {ruta}", ok, causa)

    # 5) Cookie de sesión: con una cookie inventada, sesionActual la descarta reenviándola con los mismos atributos.
    r = pedir(f"{api}?recurso=auth&accion=sesionActual", cookie="HWANGHON_SESION=humo0000000000000000000000")
    sesion = [c for c in r.cookies() if c.startswith("HWANGHON_SESION=")]
    if not sesion:
        motivo = causa_no_json(r) if "json" not in r.tipo else "La API no devolvió la cookie HWANGHON_SESION (¿otra versión del backend?)."
        for atributo in ("HttpOnly", "SameSite", "Secure"):
            anotar(f"Cookie de sesión: {atributo}", False, motivo)
    else:
        cookie = sesion[0].lower()
        anotar("Cookie de sesión: HttpOnly", "httponly" in cookie, "Sin HttpOnly: revisa Utils/Sesion.php (session_set_cookie_params).")
        anotar("Cookie de sesión: SameSite", "samesite=lax" in cookie or "samesite=strict" in cookie, "Sin SameSite=Lax: revisa Utils/Sesion.php.")
        if base.startswith("https://"):
            anotar("Cookie de sesión: Secure", "secure" in cookie.replace("samesite", ""),
                   "Sin Secure con HTTPS: PHP no ve la conexión como HTTPS (el hosting termina TLS en un proxy). "
                   "Si el hosting envía X-Forwarded-Proto, activa TRUST_PROXY en Config/seguridad.php.")
        else:
            filas.append(("Cookie de sesión: Secure", True, "no aplica: la URL es http (Secure solo se exige con https)"))

    # Tabla
    ancho = max(len(n) for n, _, _ in filas)
    print(f"\nHumo del hosting — {base}\n")
    print(f"{'Comprobación'.ljust(ancho)}  Resultado")
    print(f"{'-' * ancho}  ---------")
    for nombre, ok, causa in filas:
        print(f"{nombre.ljust(ancho)}  {'OK   ' if ok else 'FALLA'}" + (f"  → {causa}" if causa else ""))
    fallas = sum(1 for _, ok, _ in filas if not ok)
    print(f"\n{len(filas) - fallas} OK, {fallas} FALLA")
    return 1 if fallas else 0


if __name__ == "__main__":
    sys.exit(main())
