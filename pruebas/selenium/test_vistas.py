"""Recorrido de TODAS las vistas de los dos ámbitos (ronda 04/11/2026).

Por vista, en escritorio (1366x900) y celular (390x844):
  * sin errores de JavaScript en la consola;
  * sin desborde horizontal (en celular);
  * sin fechas ISO (AAAA-MM-DD) en el texto VISIBLE (se excluyen <input>, <option>, <code>).
Además (solo informativo, no hace fallar): capturas del panel principal administrativo y del
deportista en claro y oscuro → pruebas/resultados/visual/, y contraste de color con axe-core si
está disponible (variable AXE_JS o pruebas/selenium/node_modules/axe-core/axe.min.js) →
pruebas/resultados/visual/contraste.json. El cinturón marrón franja negra se comprueba por sus
colores (cuerpo marrón, nudo y puntas negros) y el negro puro (todo negro).
"""
from __future__ import annotations

import json
import os
import re
from datetime import timedelta
from pathlib import Path

import pytest
from selenium.webdriver.common.by import By

from utilidades import PUBLICO_DOC, Api, hoy_bogota, ultimo_sabado

VISUAL = Path(__file__).resolve().parents[1] / "resultados" / "visual"
AXE = Path(os.environ.get("AXE_JS") or Path(__file__).resolve().parent / "node_modules" / "axe-core" / "axe.min.js")
MARRON, NEGRO = "#7a4a24", "#1a1a1a"
# DEF-02 (INFORME): los eventos que crea el backend al abrir una sesión de examen se llaman
# «Exámenes de ascenso AAAA-MM-DD» / «Grado AAAA-MM-DD» (EvaluacionService) y el front los muestra.
DEF02 = re.compile(r"(Exámenes de ascenso|Grado) (19|20)\d{2}-\d{2}-\d{2}")
# Ejemplo de formato en la ayuda de «Importar eventos desde CSV»: justificado (describe el archivo).
EJEMPLO_CSV = "(ej. 2027-03-20 o 20/03/2027)"
TEXTO_DESBORDADO = r"""
return [...document.querySelectorAll('#contenido *')].filter(e => e.clientWidth > 0 && e.scrollWidth > e.clientWidth + 1
  && getComputedStyle(e).overflowX === 'visible' && !e.querySelector('*')).map(e => `«${e.textContent.trim().slice(0, 40)}» ${e.scrollWidth}px en ${e.clientWidth}px`);
"""

VISIBLE_ISO = r"""
const iso = /\b(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/;
const fuera = new Set(['INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'SCRIPT', 'STYLE', 'CODE', 'TITLE']);
const hallados = [];
const recorrido = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
while (recorrido.nextNode()) {
  const n = recorrido.currentNode, p = n.parentElement;
  if (!p || fuera.has(p.tagName) || p.closest('code, datalist, [hidden]')) continue;
  if (!iso.test(n.textContent)) continue;
  const r = p.getBoundingClientRect();
  if (r.width === 0 || r.height === 0 || getComputedStyle(p).visibility === 'hidden') continue;
  hallados.push(n.textContent.trim().slice(0, 80));
}
return hallados;
"""


def preparar_datos(base: str) -> dict:
    api = Api(base).ingresar_admin()
    sesion = api.llamar("POST", "evaluaciones", "crear", {"fecha": (hoy_bogota() - timedelta(days=3)).isoformat(),
                                                          "fecha_grado": hoy_bogota().isoformat(), "lugar": "Club Hwang Hon",
                                                          "participantes": [{"deportista_documento": PUBLICO_DOC}]})[1]["datos"]
    api.llamar("POST", "asistencia", "tomar", {"fecha": ultimo_sabado().isoformat(), "presentes": [PUBLICO_DOC]})
    return {"sesion": sesion["id"]}


def vistas_admin(datos) -> list[str]:
    return ["inicio", "deportistas", "pendientes", f"deportista/{PUBLICO_DOC}", "deportista-nuevo", f"deportista-editar/{PUBLICO_DOC}",
            "asistencia", f"asistencia/{ultimo_sabado().isoformat()}", "pagos", f"pagos/{PUBLICO_DOC}", "documentos", f"documentos/{PUBLICO_DOC}",
            "torneos", "torneo/1", "torneo-nuevo", "calendario", "ascensos", f"ascenso/{datos['sesion']}", "ascenso-nuevo",
            "exportacion", "importar", "importar/deportistas", "cuentas", "mi-contrasena", "estadisticas", f"estadisticas/{PUBLICO_DOC}",
            "historial", "estadisticas-comparar", "historial-comparar"]


VISTAS_PUBLICO = ["inicio", "perfil", "asistencia", "pagos", "documentos", "ascensos", "estadisticas", "historial", "calendario"]


def revisar(nav, nombre: str, problemas: list[str], contraste: dict, tema: str = "claro", conocidos: list | None = None,
            internos: list | None = None) -> None:
    nav.esperar_contenido()
    for e in nav.errores_js():
        problemas.append(f"{nombre}: error JS → {e[:160]}")
    if nav.es_celular:
        ancho = nav.driver.execute_script("return [document.documentElement.scrollWidth, window.innerWidth];")
        if ancho[0] > ancho[1] + 1:
            problemas.append(f"{nombre}: desborde horizontal {ancho[0]}px en {ancho[1]}px")
    for texto in nav.driver.execute_script(VISIBLE_ISO):
        if DEF02.search(texto):
            if conocidos is not None:
                conocidos.append(f"{nombre}: «{texto}»")
        elif EJEMPLO_CSV not in texto:
            problemas.append(f"{nombre}: fecha ISO visible «{texto}»")
    if nav.es_celular and internos is not None:
        internos += [f"{nombre}: {t}" for t in nav.driver.execute_script(TEXTO_DESBORDADO)]
    if AXE.exists():
        nav.driver.execute_script(AXE.read_text(encoding="utf-8"))
        r = nav.driver.execute_async_script(
            "const listo = arguments[arguments.length - 1];"
            "axe.run(document, {runOnly: {type: 'rule', values: ['color-contrast']}}).then(r => listo(r.violations.map(v => "
            "v.nodes.map(n => ({sel: n.target.join(' '), detalle: (n.any[0] || {}).message || ''})))));")
        nodos = [n for grupo in r for n in grupo]
        if nodos:
            contraste[f"{nav.dispositivo} · {tema} · {nombre}"] = nodos


def poner_tema(nav, tema: str) -> None:
    nav.driver.execute_script("localStorage.setItem('hwanghon_tema', arguments[0]);", tema)
    nav.driver.refresh()
    nav.esperar_menu()


@pytest.mark.parametrize("ambito", ["administrativo", "deportista"])
def test_todas_las_vistas(nav, servidor, ambito):
    datos = preparar_datos(servidor)
    VISUAL.mkdir(parents=True, exist_ok=True)
    problemas: list[str] = []
    contraste: dict = {}
    conocidos: list[str] = []
    internos: list[str] = []
    if ambito == "administrativo":
        nav.ingresar_admin()
        vistas = vistas_admin(datos)
    else:
        nav.ingresar_publico()
        vistas = VISTAS_PUBLICO
    for tema in ("claro", "oscuro"):
        poner_tema(nav, tema)
        for vista in vistas:
            nav.ir_a(vista)
            nav.esperar_contenido()
            revisar(nav, f"#{vista}", problemas, contraste, tema, conocidos, internos if tema == "claro" else None)
            if vista == "inicio":
                nav.driver.save_screenshot(str(VISUAL / f"inicio_{ambito}_{nav.dispositivo}_{tema}.png"))
    poner_tema(nav, "automatico")
    if ambito == "deportista":  # páginas sin sesión
        nav.driver.delete_all_cookies()
        for pagina in ("index.html", "registro.html", "recuperar.html"):
            nav.abrir(pagina)
            nav.driver.implicitly_wait(0)
            revisar(nav, pagina, problemas, contraste)
    archivo = VISUAL / "contraste.json"
    previo = json.loads(archivo.read_text(encoding="utf-8")) if archivo.exists() else {}
    previo.update(contraste)
    archivo.write_text(json.dumps(previo, ensure_ascii=False, indent=1), encoding="utf-8")
    (VISUAL / f"texto_desbordado_{ambito}_{nav.dispositivo}.txt").write_text("\n".join(sorted(set(internos))) + "\n", encoding="utf-8")
    assert not problemas, "\n".join(problemas)
    if conocidos:
        pytest.xfail(f"DEF-02: fechas ISO en nombres de eventos creados por el backend ({len(set(conocidos))} casos)")


@pytest.mark.solo_escritorio
def test_cinturon_marron_franja_negra(nav, servidor):
    api = Api(servidor).ingresar_admin()
    VISUAL.mkdir(parents=True, exist_ok=True)
    resultados = {}
    for grado, cuerpo, nudo in (("Marrón franja negra", MARRON, NEGRO), ("Negro", NEGRO, NEGRO)):
        assert api.llamar("PUT", "deportistas", "actualizar", {"grado_actual": grado}, documento=PUBLICO_DOC)[0] == 200
        nav.driver.delete_all_cookies()
        nav.ingresar_publico()
        for vista in ("inicio", "perfil"):
            nav.ir_a(vista)
            svg = nav.visible("svg.cinturon-grado")
            colores = nav.driver.execute_script(
                "const s = arguments[0]; return {cuerpo: s.querySelector('rect:not(.nudo)').getAttribute('fill'),"
                " nudo: s.querySelector('.nudo').getAttribute('fill'), puntas: [...s.querySelectorAll('.punta')].map(p => p.getAttribute('fill'))};", svg)
            resultados[(grado, vista)] = colores
            assert colores["cuerpo"].lower() == cuerpo, f"{grado} en {vista}: cuerpo {colores}"
            assert colores["nudo"].lower() == nudo and all(p.lower() == nudo for p in colores["puntas"]), f"{grado} en {vista}: nudo/colas {colores}"
            nav.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", svg)
            svg.screenshot(str(VISUAL / f"cinturon_{'marron_franja_negra' if 'franja' in grado else 'negro'}_{vista}.png"))
