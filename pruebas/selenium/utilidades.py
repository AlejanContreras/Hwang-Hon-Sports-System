"""Ayudas para las pruebas E2E. Selectores ESTABLES: id, atributo name de los campos,
data-prueba y aria-label. Los resultados se comprueban contra la API, no contra textos de pantalla."""
from __future__ import annotations

import http.cookiejar
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from selenium.common.exceptions import (ElementClickInterceptedException, ElementNotInteractableException,
                                        StaleElementReferenceException)
from selenium.webdriver.common.by import By
from selenium.webdriver.remote.webelement import WebElement
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait

CLAVE_PRUEBA = "12345678"  # contraseña de las cuentas FICTICIAS de base_datos/cuentas_prueba_locales.sql
MAESTRO = "maestro@hwanghon.local"                  # cuenta principal
ADMINISTRATIVO1 = "administrativo1@hwanghon.local"  # no principal
PUBLICO_DOC, PUBLICO_FECHA, PUBLICO_NOMBRE = "1000000101", "2014-03-12", "Samuel Reyes Ortiz"
PENDIENTE_DOC = "1000000102"
BOGOTA = timezone(timedelta(hours=-5))
ESPERA = 15


def hoy_bogota() -> date:
    return datetime.now(BOGOTA).date()


def ultimo_sabado() -> date:
    """El sábado anterior a hoy (como capturar.php): siempre pasado, nunca hoy."""
    d = hoy_bogota() - timedelta(days=1)
    while d.weekday() != 5:
        d -= timedelta(days=1)
    return d


def literal_xpath(texto: str) -> str:
    if "'" not in texto:
        return f"'{texto}'"
    return '"' + texto + '"'


class Api:
    """Cliente mínimo de la API (urllib + cookies) para preparar y COMPROBAR datos fuera del navegador."""

    def __init__(self, base: str):
        self.base = base + "/backend/Public/api.php"
        self.abridor = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def llamar(self, metodo: str, recurso: str, accion: str, cuerpo=None, **parametros):
        consulta = urllib.parse.urlencode({"recurso": recurso, "accion": accion, **parametros})
        datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
        peticion = urllib.request.Request(f"{self.base}?{consulta}", data=datos, method=metodo,
                                          headers={"Content-Type": "application/json"} if datos else {})
        try:
            with self.abridor.open(peticion, timeout=30) as r:
                return r.status, json.loads(r.read() or b"null")
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read() or b"null")

    def ingresar_admin(self, correo=MAESTRO, clave=CLAVE_PRUEBA):
        codigo, r = self.llamar("POST", "auth", "iniciarSesionAdministrativa", {"correo": correo, "contrasena": clave})
        assert codigo == 200, r
        return self


class Navegador:
    def __init__(self, driver, base: str, dispositivo: str, descargas: Path):
        self.driver = driver
        self.base = base
        self.dispositivo = dispositivo
        self.descargas = descargas

    @property
    def es_celular(self) -> bool:
        return self.dispositivo == "celular"

    # ------------------------------------------------------------------ localizar
    def abrir(self, pagina: str) -> None:
        self.driver.get(f"{self.base}/frontend/{pagina}")

    def esperar(self, condicion, tiempo: float = ESPERA, mensaje: str = ""):
        return WebDriverWait(self.driver, tiempo, ignored_exceptions=(StaleElementReferenceException,)).until(condicion, mensaje)

    def visible(self, selector: str, dentro: WebElement | None = None, tiempo: float = ESPERA, css: bool = True) -> WebElement:
        """Primer elemento visible (CSS por defecto; css=False para XPath)."""
        modo = By.CSS_SELECTOR if css else By.XPATH

        def buscar(_):
            for e in (dentro or self.driver).find_elements(modo, selector):
                try:
                    if e.is_displayed():
                        return e
                except StaleElementReferenceException:
                    continue
            return False
        return self.esperar(buscar, tiempo, f"No apareció un elemento visible: {selector}")

    def campo(self, nombre: str, dentro: WebElement | None = None) -> WebElement:
        """Campo por su atributo name (estable: es el nombre del dato de la API)."""
        controles = (dentro or self.driver).find_elements(By.CSS_SELECTOR, f"[name='{nombre}']")
        visibles = [c for c in controles if c.is_displayed() or c.get_attribute("type") in ("file", "checkbox")]
        assert visibles, f"No hay un campo name={nombre}"
        return visibles[0]

    def formulario_de(self, nombre_campo: str, dentro: WebElement | None = None) -> WebElement:
        return self.campo(nombre_campo, dentro).find_element(By.XPATH, "./ancestor::form[1]")

    # ------------------------------------------------------------------ actuar
    def clic(self, elemento: WebElement) -> None:
        for intento in range(6):
            try:
                self.driver.execute_script("arguments[0].scrollIntoView({block: 'center', inline: 'nearest'});", elemento)
                self.esperar(lambda _: elemento.is_enabled(), 10, "El control sigue deshabilitado")
                elemento.click()
                return
            except (ElementClickInterceptedException, ElementNotInteractableException):
                if intento == 5:
                    raise
                time.sleep(0.3)

    def rellenar(self, nombre: str, valor, dentro: WebElement | None = None) -> WebElement:
        control = self.campo(nombre, dentro)
        self.poner(control, valor)
        return control

    def poner(self, control: WebElement, valor) -> None:
        etiqueta = control.tag_name.lower()
        tipo = (control.get_attribute("type") or "").lower()
        if etiqueta == "select":
            Select(control).select_by_value(str(valor))
        elif tipo == "checkbox":
            if control.is_selected() != bool(valor):
                self.driver.execute_script("arguments[0].click();", control)
        elif tipo == "file":
            control.send_keys(str(Path(valor).resolve()))
        elif tipo in ("date", "number"):
            # El formato de escritura de <input type=date> depende del idioma: se fija el valor y se
            # disparan los mismos eventos que al escribir.
            self.driver.execute_script(
                "arguments[0].value = arguments[1];"
                "arguments[0].dispatchEvent(new Event('input', {bubbles: true}));"
                "arguments[0].dispatchEvent(new Event('change', {bubbles: true}));", control, str(valor))
        else:
            self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", control)
            control.clear()
            control.send_keys(str(valor))

    def enviar(self, formulario: WebElement) -> None:
        self.clic(formulario.find_element(By.CSS_SELECTOR, "button[type='submit']"))

    def pulsar_aria(self, etiqueta: str, dentro: WebElement | None = None) -> None:
        self.clic(self.visible(f"[aria-label={json.dumps(etiqueta)}]", dentro))

    def aceptar_dialogo(self) -> None:
        """Diálogo de confirmación de ui.js: [Cancelar, Aceptar]; se pulsa el último."""
        dialogo = self.visible("dialog[open]")
        self.clic(dialogo.find_elements(By.CSS_SELECTOR, ".acciones button")[-1])
        self.esperar(lambda d: not d.find_elements(By.CSS_SELECTOR, "dialog[open]"), mensaje="El diálogo no se cerró")

    def esperar_que(self, condicion, mensaje: str, tiempo: float = ESPERA):
        """Espera a que una condición de la API se cumpla (la vista guarda de forma asíncrona)."""
        return self.esperar(lambda _: condicion() or False, tiempo, mensaje)

    # ------------------------------------------------------------------ sesión y navegación
    def ingresar_admin(self, correo: str = MAESTRO, clave: str = CLAVE_PRUEBA) -> None:
        self.abrir("index.html")
        formulario = self.visible("#formulario-administrativo")
        self.poner(self.driver.find_element(By.ID, "correo"), correo)
        self.poner(self.driver.find_element(By.ID, "contrasena"), clave)
        self.enviar(formulario)
        self.esperar(EC.url_contains("administrativo.html"), mensaje="No entró al panel administrativo")
        self.esperar_menu()

    def ingresar_publico(self, documento: str = PUBLICO_DOC, fecha: str = PUBLICO_FECHA, esperar_entrada: bool = True) -> None:
        self.abrir("index.html")
        self.visible("#formulario-administrativo")
        self.clic(self.driver.find_element(By.ID, "pestana-publica"))
        formulario = self.visible("#formulario-publico")
        self.poner(self.driver.find_element(By.ID, "documento"), documento)
        self.poner(self.driver.find_element(By.ID, "fecha-nacimiento"), fecha)
        self.enviar(formulario)
        if esperar_entrada:
            self.esperar(EC.url_contains("publico.html"), mensaje="No entró a la página pública")
            self.esperar_menu()

    def esperar_menu(self) -> None:
        self.esperar(lambda d: d.find_elements(By.CSS_SELECTOR, "#lista-menu a[data-prueba^='menu-']"), mensaje="No se armó el menú lateral")
        self.esperar_contenido()

    def menu(self, clave: str) -> None:
        """Menú lateral por data-prueba="menu-<clave>"; en celular primero abre el menú plegable."""
        if self.es_celular:
            boton = self.driver.find_element(By.ID, "boton-menu")
            assert boton.is_displayed(), "En celular el botón del menú debe estar visible"
            if boton.get_attribute("aria-expanded") != "true":
                self.clic(boton)
        self.clic(self.visible(f"#lista-menu [data-prueba='menu-{clave}']"))
        self.esperar(lambda d: clave in d.current_url.split("#")[-1] or d.find_elements(By.CSS_SELECTOR, f"[data-prueba='menu-{clave}'][aria-current='page']"))
        self.esperar_contenido()

    def ir_a(self, ruta: str) -> None:
        self.driver.execute_script("window.location.hash = arguments[0];", ruta)
        self.esperar_contenido()

    def esperar_contenido(self) -> None:
        self.esperar(lambda d: not d.find_elements(By.CSS_SELECTOR, "#contenido .cargando"), mensaje="La vista sigue cargando")

    def aviso_de_error(self, tiempo: float = ESPERA) -> WebElement:
        return self.visible(".aviso-error, .aviso-advertencia", tiempo=tiempo)

    # ------------------------------------------------------------------ comprobaciones
    def esperar_descarga(self, extension: str, tiempo: float = 30) -> Path:
        def listo(_):
            archivos = [p for p in self.descargas.iterdir() if p.suffix.lower() == extension and p.stat().st_size > 0]
            parciales = [p for p in self.descargas.iterdir() if p.suffix in (".crdownload", ".tmp")]
            return archivos[0] if archivos and not parciales else False
        return self.esperar(listo, tiempo, f"No se descargó ningún {extension}")

    def sin_desborde_horizontal(self) -> None:
        ancho = self.driver.execute_script("return [document.documentElement.scrollWidth, window.innerWidth];")
        assert ancho[0] <= ancho[1] + 1, f"Desborde horizontal en {self.dispositivo}: {ancho[0]}px de contenido en {ancho[1]}px"

    def errores_js(self) -> list[str]:
        """Errores de JavaScript en la consola (no cuenta las respuestas 4xx esperadas de la API)."""
        try:
            registros = self.driver.get_log("browser")
        except Exception:
            return []
        return [r["message"] for r in registros if r["level"] == "SEVERE"
                and "Failed to load resource: the server responded with a status of 4" not in r["message"]]
