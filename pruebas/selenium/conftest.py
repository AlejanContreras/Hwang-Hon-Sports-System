"""Configuración de las pruebas end-to-end (Selenium + pytest) del front de HwangHon-Sports-System.

Entorno AISLADO (ver pruebas/herramientas/entorno.py):
  * una sola vez por corrida: copia de la app en pruebas/entorno/app y servidor php -S en
    http://127.0.0.1:8095 (front y API en el mismo origen), con la base aparte hwanghon_pruebas;
  * antes de CADA prueba: base restablecida con la semilla + cuentas locales y almacenamiento vacío.

Cada prueba corre dos veces: «escritorio» (1366x900) y «celular» (viewport 390x844 con emulación
móvil de Chrome). Variables opcionales: SELENIUM_VISIBLE=1 (ver el navegador), CHROMEDRIVER y
CHROME_BINARY (rutas; si no, Selenium Manager las resuelve), PUERTO_PRUEBAS.
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

import pytest
from selenium import webdriver
from selenium.webdriver.chrome.service import Service

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI.parent / "herramientas"))
sys.path.insert(0, str(AQUI))
import entorno  # noqa: E402
from utilidades import Navegador  # noqa: E402

DISPOSITIVOS = {
    "escritorio": {"ancho": 1366, "alto": 900, "movil": False},
    "celular": {"ancho": 390, "alto": 844, "movil": True},
}


def pytest_addoption(parser):
    parser.addoption("--dispositivo", action="append", choices=list(DISPOSITIVOS), help="Limitar a escritorio o celular (por defecto ambos).")


def pytest_generate_tests(metafunc):
    if "dispositivo" in metafunc.fixturenames:
        elegidos = metafunc.config.getoption("dispositivo") or list(DISPOSITIVOS)
        metafunc.parametrize("dispositivo", elegidos, scope="function")


@pytest.fixture(scope="session")
def servidor():
    entorno.preparar_copia()
    entorno.restablecer_bd()
    entorno.limpiar_almacenamiento()
    proceso = entorno.iniciar_servidor()
    yield entorno.url_base()
    entorno.detener_servidor(proceso)


@pytest.fixture(autouse=True)
def semilla(servidor):
    """Semilla recién cargada antes de cada prueba (las pruebas no dependen entre sí)."""
    entorno.restablecer_bd()
    entorno.limpiar_almacenamiento()
    yield


@pytest.fixture
def carpeta_descargas(tmp_path):
    carpeta = tmp_path / "descargas"
    carpeta.mkdir()
    return carpeta


@pytest.fixture
def nav(servidor, dispositivo, carpeta_descargas, request):
    datos = DISPOSITIVOS[dispositivo]
    opciones = webdriver.ChromeOptions()
    if os.environ.get("SELENIUM_VISIBLE") != "1":
        opciones.add_argument("--headless=new")
    opciones.add_argument("--no-sandbox")
    opciones.add_argument("--disable-dev-shm-usage")
    opciones.add_argument("--lang=es-CO")
    opciones.add_argument("--disable-search-engine-choice-screen")
    opciones.add_argument("--disable-background-networking")
    opciones.add_argument("--disable-features=AutofillServerCommunication,PasswordLeakDetection")
    if os.environ.get("CHROME_BINARY"):
        opciones.binary_location = os.environ["CHROME_BINARY"]
    if datos["movil"]:
        opciones.add_experimental_option("mobileEmulation", {
            "deviceMetrics": {"width": datos["ancho"], "height": datos["alto"], "pixelRatio": 3.0, "touch": True},
            "userAgent": "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36",
        })
    else:
        opciones.add_argument(f"--window-size={datos['ancho']},{datos['alto']}")
    opciones.add_experimental_option("prefs", {
        "download.default_directory": str(carpeta_descargas),
        "download.prompt_for_download": False,
        "safebrowsing.enabled": True,
        "intl.accept_languages": "es-CO,es",
        "credentials_enable_service": False,
        "profile.password_manager_enabled": False,
        "profile.password_manager_leak_detection": False,
    })
    opciones.set_capability("goog:loggingPrefs", {"browser": "ALL"})
    servicio = Service(os.environ["CHROMEDRIVER"]) if os.environ.get("CHROMEDRIVER") else Service()
    driver = webdriver.Chrome(options=opciones, service=servicio)
    driver.execute_cdp_cmd("Browser.setDownloadBehavior", {"behavior": "allow", "downloadPath": str(carpeta_descargas), "eventsEnabled": True})
    navegador = Navegador(driver, servidor, dispositivo, carpeta_descargas)
    yield navegador
    # Evidencia: captura de pantalla si la prueba falló.
    informe = getattr(request.node, "informe_llamada", None)
    if informe is not None and informe.failed:
        capturas = AQUI.parent / "resultados" / "capturas"
        capturas.mkdir(parents=True, exist_ok=True)
        nombre = request.node.name.replace("[", "_").replace("]", "").replace("/", "_")
        try:
            driver.save_screenshot(str(capturas / f"{nombre}.png"))
        except Exception:
            pass
    driver.quit()


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    resultado = yield
    informe = resultado.get_result()
    if informe.when == "call":
        item.informe_llamada = informe
