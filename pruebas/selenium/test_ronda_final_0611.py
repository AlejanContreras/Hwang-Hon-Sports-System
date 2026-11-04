"""Ronda final corta (06/11/2026) sobre el front final.

Comprueba, en escritorio (1366x900) y celular (390x844) salvo lo marcado solo_escritorio:
  * DEF-02: ninguna fecha AAAA-MM-DD visible en Inicio y Calendario (administrativo y deportista);
    los eventos de una sesión de examen se muestran con DD/MM/AAAA.
  * OBS-04: «Datos insuficientes» no se sale de su caja a 390 px.
  * Estadísticas: las pestañas caben en una sola fila (misma altura y sin scroll de la tira).
  * Torneos: «Categoría de peso» es un select con solo las categorías de la edad y el sexo del
    participante (Infantil, Juvenil y Mayores; masculino y femenino, según el catálogo §A, fila
    `categoria_peso`) y conserva un valor guardado que no está en la lista.
  * Logo y favicon cargan; el fondo difuminado de la tarjeta no causa scroll horizontal a 390 px.
Datos ficticios; base aparte restablecida antes de cada prueba (conftest.py).
"""
from __future__ import annotations

import re
import urllib.request

import pytest
from selenium.webdriver.common.by import By

from utilidades import PENDIENTE_DOC, PUBLICO_DOC, Api, hoy_bogota

ISO = re.compile(r"\b(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b")
VISIBLE_ISO = r"""
const iso = /\b(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/;
const fuera = new Set(['INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'SCRIPT', 'STYLE', 'CODE', 'TITLE']);
const hallados = [];
const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
while (w.nextNode()) {
  const n = w.currentNode, p = n.parentElement;
  if (!p || fuera.has(p.tagName) || p.closest('code, datalist, [hidden]') || !iso.test(n.textContent)) continue;
  const r = p.getBoundingClientRect();
  if (r.width && r.height && getComputedStyle(p).visibility !== 'hidden') hallados.push(n.textContent.trim().slice(0, 80));
}
return hallados;
"""


def cortes(inicio: int, fin: int) -> list[str]:
    """Catálogo: «-X» hasta X kg (inclusive) cada 5 kg y un «+X» con el último corte."""
    return [f"-{kg}" for kg in range(inicio, fin + 1, 5)] + [f"+{fin}"]


# documentacion/api/catalogo_api.md §A, `categoria_peso` (calculada)
ESPERADAS = {
    ("infantil", "masculino"): cortes(20, 35), ("infantil", "femenino"): cortes(20, 35),
    ("juvenil", "masculino"): cortes(45, 75), ("juvenil", "femenino"): cortes(43, 73),
    ("mayores", "masculino"): cortes(55, 85), ("mayores", "femenino"): cortes(50, 80),
}
FUERA_DE_LISTA = "-7"
# Ejemplo de formato en la ayuda de «Importar eventos desde CSV»: justificado (describe el archivo).
EJEMPLO_CSV = "(ej. 2027-03-20 o 20/03/2027)"


@pytest.fixture
def api(servidor):
    return Api(servidor).ingresar_admin()


def texto_visible(nav) -> str:
    return nav.driver.execute_script("return document.body.innerText;")


# 1 ============================================================== DEF-02
def test_def02_sin_fechas_iso_en_inicio_y_calendario(nav, api):
    fecha = hoy_bogota()
    codigo, r = api.llamar("POST", "evaluaciones", "crear", {"fecha": fecha.isoformat(), "fecha_grado": fecha.isoformat(),
                                                           "lugar": "Club Hwang Hon", "participantes": [{"deportista_documento": PUBLICO_DOC}]})
    assert codigo == 201, r
    dd = fecha.strftime("%d/%m/%Y")
    hallados, vistos = [], []
    for ambito in ("administrativo", "deportista"):
        nav.driver.delete_all_cookies()
        nav.ingresar_admin() if ambito == "administrativo" else nav.ingresar_publico()
        for vista in ("inicio", "calendario"):
            nav.ir_a(vista)
            nav.esperar_contenido()
            if vista == "calendario":
                nav.esperar(lambda _: "Exámenes de ascenso" in texto_visible(nav), mensaje=f"{ambito}: el calendario no muestra el examen")
            hallados += [f"{ambito} #{vista}: «{t}»" for t in nav.driver.execute_script(VISIBLE_ISO) if EJEMPLO_CSV not in t]
            texto = texto_visible(nav)
            if f"Exámenes de ascenso {dd}" in texto or f"Grado {dd}" in texto:
                vistos.append(f"{ambito} #{vista}")
    assert not hallados, "DEF-02 sigue: fechas ISO visibles → " + "; ".join(hallados)
    assert any("calendario" in v for v in vistos), f"No se ve «Exámenes de ascenso {dd}» en ningún calendario"
    assert not nav.errores_js()


# 2 ============================================================== OBS-04
DESBORDE_DATOS = r"""
return [...document.querySelectorAll('#contenido *')].filter(e => !e.querySelector('*') && e.textContent.includes('Datos insuficientes'))
  .map(e => {
    const caja = e.closest('li, .tarjeta-dato, .tarjeta, article, section') || e.parentElement;
    const r = e.getBoundingClientRect(), c = caja.getBoundingClientRect();
    return {texto: e.textContent.trim(), sw: e.scrollWidth, cw: e.clientWidth, sale: r.right > c.right + 1 || r.left < c.left - 1,
            lineas: Math.round(r.height / parseFloat(getComputedStyle(e).lineHeight || r.height))};
  });
"""


@pytest.mark.parametrize("ruta", ["publico:inicio", "publico:estadisticas", f"admin:estadisticas/{PUBLICO_DOC}", "admin:asistencia"])
def test_obs04_datos_insuficientes_no_desborda(nav, ruta):
    ambito, vista = ruta.split(":")
    nav.ingresar_publico() if ambito == "publico" else nav.ingresar_admin()
    nav.ir_a(vista)
    nav.esperar_contenido()
    medidas = nav.driver.execute_script(DESBORDE_DATOS)
    malos = [m for m in medidas if m["sw"] > m["cw"] + 1 or m["sale"]]
    assert not malos, f"OBS-04 sigue en #{vista} ({nav.dispositivo}): {malos}"
    nav.sin_desborde_horizontal()
    if ruta == "publico:inicio":
        assert medidas, "Con la semilla, Inicio del deportista debería mostrar «Datos insuficientes»"


# 3 ============================================================== pestañas de Estadísticas
@pytest.mark.parametrize("ruta", ["publico:estadisticas", f"admin:estadisticas/{PUBLICO_DOC}"])
def test_pestanas_estadisticas_una_fila(nav, ruta):
    ambito, vista = ruta.split(":")
    nav.ingresar_publico() if ambito == "publico" else nav.ingresar_admin()
    nav.ir_a(vista)
    lista = nav.visible("[role='tablist']")
    datos = nav.driver.execute_script(
        "const t = arguments[0]; return {tops: [...t.querySelectorAll('[role=tab]')].map(b => Math.round(b.getBoundingClientRect().top)),"
        " nombres: [...t.querySelectorAll('[role=tab]')].map(b => b.textContent.trim()), sw: t.scrollWidth, cw: t.clientWidth};", lista)
    assert len(datos["tops"]) >= 2, datos
    assert len(set(datos["tops"])) == 1, f"Pestañas en más de una fila: {datos}"
    assert datos["sw"] <= datos["cw"] + 1, f"Las pestañas no caben (la tira tiene scroll): {datos}"
    nav.sin_desborde_horizontal()


# 4 ============================================================== select de categoría de peso
def opciones(control) -> list[str]:
    return [o.get_attribute("value") for o in control.find_elements(By.TAG_NAME, "option") if o.get_attribute("value")]


def formulario_participante(nav, documento):
    detalle = nav.visible(f"//details[contains(@class,'participante')][summary[contains(., '{documento}') and contains(., 'Combate individual')]]", css=False)
    if detalle.get_attribute("open") is None:
        nav.clic(detalle.find_element(By.TAG_NAME, "summary"))
    return detalle.find_element(By.TAG_NAME, "form")


@pytest.mark.solo_escritorio
def test_select_categoria_peso_por_edad_y_sexo(nav, api):
    # Samuel (masculino) se inscribe en combate; Juliana (femenino, ya inscrita en combate en la
    # semilla) recibe un valor guardado fuera de la lista.
    assert api.llamar("POST", "competencias", "inscribir", {"competencia_evento_id": 1, "participantes": [
        {"deportista_documento": PUBLICO_DOC, "modalidad": "combate_individual"}]})[0] == 201
    clave = {"deportista_documento": PENDIENTE_DOC, "competencia_evento_id": 1, "modalidad": "combate_individual"}
    codigo, r = api.llamar("PUT", "competencias", "actualizarParticipacion", {"categoria_peso": FUERA_DE_LISTA}, **clave)
    assert codigo == 200, r
    torneo = api.llamar("GET", "competencias", "consultar", id=1)[1]["datos"]
    guardado = {p["deportista_documento"]: p["categoria_peso"] for p in torneo["participantes"] if p["modalidad"] == "combate_individual"}
    assert guardado[PENDIENTE_DOC] == FUERA_DE_LISTA, guardado
    nav.ingresar_admin()
    nav.ir_a("torneo/1")
    fallos = []
    for documento, sexo in ((PUBLICO_DOC, "masculino"), (PENDIENTE_DOC, "femenino")):
        form = formulario_participante(nav, documento)
        peso = nav.campo("categoria_peso", form)
        assert peso.tag_name == "select", "«Categoría de peso» debe ser un select"
        for edad in ("infantil", "juvenil", "mayores"):
            nav.rellenar("categoria_edad", edad, form)
            # Un valor ya guardado que no está en la lista se conserva como opción extra (al final).
            previo = guardado.get(documento)
            esperada = ESPERADAS[(edad, sexo)] + ([previo] if previo and previo not in ESPERADAS[(edad, sexo)] else [])
            try:
                nav.esperar(lambda _: opciones(peso) == esperada, tiempo=5)
            except Exception:
                fallos.append(f"{sexo} {edad}: {opciones(peso)} ≠ {esperada}")
            if previo and peso.get_attribute("value") != previo:
                fallos.append(f"{sexo} {edad}: no conserva el valor guardado {previo} (muestra «{peso.get_attribute('value')}»)")
    assert not fallos, "\n".join(fallos)
    # Guardar otro campo no borra el valor fuera de lista.
    form = formulario_participante(nav, PENDIENTE_DOC)
    nav.rellenar("resultado", "Prueba 06/11", form)
    nav.enviar(form)

    def participacion():
        t = api.llamar("GET", "competencias", "consultar", id=1)[1]["datos"]
        return next(p for p in t["participantes"] if p["deportista_documento"] == PENDIENTE_DOC and p["modalidad"] == "combate_individual")
    nav.esperar_que(lambda: participacion()["resultado"] == "Prueba 06/11", "No se guardó el resultado")
    assert participacion()["categoria_peso"] == FUERA_DE_LISTA, participacion()
    assert not nav.errores_js()


# 5 ============================================================== logo, favicon y fondo de la tarjeta
RECURSOS = ["recursos/favicon.ico", "recursos/favicon.png", "recursos/logo_hwanghon.png", "recursos/logo_hwanghon_difuminado.png"]


@pytest.mark.solo_escritorio
def test_recursos_logo_y_favicon_responden(servidor):
    for r in RECURSOS:
        with urllib.request.urlopen(f"{servidor}/frontend/{r}", timeout=10) as resp:
            assert resp.status == 200 and resp.headers.get_content_type().startswith("image/") and len(resp.read()) > 100, r


ICONOS = "return [...document.querySelectorAll('link[rel~=icon]')].map(l => l.href);"
FONDO = "return getComputedStyle(document.querySelector('main'), '::before').backgroundImage;"


@pytest.mark.parametrize("pagina", ["index.html", "registro.html", "recuperar.html", "administrativo.html", "publico.html"])
def test_logo_favicon_y_fondo(nav, pagina):
    if pagina == "administrativo.html":
        nav.ingresar_admin()
    elif pagina == "publico.html":
        nav.ingresar_publico()
    else:
        nav.abrir(pagina)
        nav.visible("main")
    iconos = nav.driver.execute_script(ICONOS)
    assert any(i.endswith("favicon.ico") for i in iconos) and any(i.endswith("favicon.png") for i in iconos), iconos
    for i in iconos:
        estado = nav.driver.execute_async_script(
            "const listo = arguments[1]; fetch(arguments[0]).then(r => listo(r.status)).catch(e => listo(String(e)));", i)
        assert estado == 200, f"{i} → {estado}"
    if pagina in ("administrativo.html", "publico.html"):
        logo = nav.visible("[data-prueba='logo-club']")
        nav.esperar(lambda _: nav.driver.execute_script("return arguments[0].complete && arguments[0].naturalWidth > 0;", logo),
                    mensaje="El logo del encabezado no cargó")
    else:
        fondo = nav.driver.execute_script(FONDO)
        assert "logo_hwanghon" in fondo, f"Sin logo de fondo detrás de la tarjeta: {fondo}"
        url = re.search(r'url\("?([^")]+)', fondo).group(1)
        estado = nav.driver.execute_async_script(
            "const listo = arguments[1]; fetch(arguments[0]).then(r => listo(r.status)).catch(e => listo(String(e)));", url)
        assert estado == 200, f"Fondo {url} → {estado}"
    ancho = nav.driver.execute_script("return [document.documentElement.scrollWidth, window.innerWidth];")
    assert ancho[0] <= ancho[1] + 1, f"Scroll horizontal en {pagina} ({nav.dispositivo}): {ancho[0]}px en {ancho[1]}px"
    assert not nav.errores_js()
