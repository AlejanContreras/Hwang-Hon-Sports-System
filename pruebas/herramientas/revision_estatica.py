#!/usr/bin/env python3
"""Revisión estática del front (sin navegador). Uso: python pruebas/herramientas/revision_estatica.py

Revisa frontend/js/**/*.js y frontend/*.html:
  1. Nada de innerHTML, outerHTML, insertAdjacentHTML ni document.write (riesgo de XSS).
  2. Nada de la palabra «Juangón» (ni «Juangon»).
  3. Fechas ISO (AAAA-MM-DD) escritas como texto literal en las vistas. Se ignoran comentarios,
     expresiones regulares y los marcadores de formato («AAAA-MM-DD»). Las que quedan se listan
     para revisarlas a mano: la comprobación definitiva es la del navegador
     (pruebas/selenium/test_vistas.py busca fechas ISO en el texto VISIBLE de cada vista).
Sale con código 1 si hay hallazgos de 1 o 2.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2] / "frontend"
PELIGROSAS = re.compile(r"\b(innerHTML|outerHTML|insertAdjacentHTML)\b|document\s*\.\s*write(ln)?\s*\(")
JUANGON = re.compile(r"juang[oó]n", re.I)
ISO = re.compile(r"\b(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b")
COMENTARIO_JS = re.compile(r"//.*$|/\*.*?\*/", re.S)


def sin_comentarios(texto: str, es_js: bool) -> str:
    """Quita comentarios conservando los saltos de línea (para reportar el número de línea)."""
    patron = COMENTARIO_JS if es_js else re.compile(r"<!--.*?-->", re.S)
    return patron.sub(lambda m: "\n" * m.group(0).count("\n"), texto)


def main() -> int:
    archivos = sorted(RAIZ.glob("js/**/*.js")) + sorted(RAIZ.glob("*.html"))
    graves, isos = [], []
    for archivo in archivos:
        texto = archivo.read_text(encoding="utf-8")
        limpio = sin_comentarios(texto, archivo.suffix == ".js")
        relativo = archivo.relative_to(RAIZ.parent).as_posix()
        for n, linea in enumerate(limpio.splitlines(), 1):
            if PELIGROSAS.search(linea):
                graves.append(f"{relativo}:{n}: API que inserta HTML → {linea.strip()[:120]}")
            if ISO.search(linea) and "AAAA" not in linea and "new RegExp" not in linea and not linea.strip().startswith(("/", "*")):
                isos.append(f"{relativo}:{n}: {linea.strip()[:120]}")
        for n, linea in enumerate(texto.splitlines(), 1):  # «Juangón» también en comentarios
            if JUANGON.search(linea):
                graves.append(f"{relativo}:{n}: contiene «Juangón» → {linea.strip()[:120]}")
    print(f"Revisión estática: {len(archivos)} archivos de frontend/")
    print(f"  innerHTML/outerHTML/insertAdjacentHTML/document.write y «Juangón»: {len(graves)} hallazgo(s)")
    for g in graves:
        print(f"    {g}")
    print(f"  Fechas ISO literales para revisar (no siempre son texto visible): {len(isos)}")
    for i in isos:
        print(f"    {i}")
    return 1 if graves else 0


if __name__ == "__main__":
    sys.exit(main())
