#!/usr/bin/env python3
"""Capturas de pantalla para el manual de usuario (entorno aislado, datos FICTICIOS).

Uso:  python pruebas/herramientas/capturas_manual.py [--solo 01,23,24,33] [--escala 1]

  * Prepara la copia aislada (pruebas/entorno/app), restablece la base aparte hwanghon_pruebas
    con la semilla + cuentas locales y le agrega datos ficticios de ejemplo (más deportistas,
    asistencia, pagos, torneos con resultados, eventos y documentos).
  * Abre Chromium sin interfaz (sin barra del navegador), tema claro:
      - administrativo: 1366x768;
      - deportista / familias: 390x844 (emulación de celular).
  * Guarda NN_ambito_descripcion.png en recursos/documentos_cliente/manual/capturas/ (ignorada
    por Git) y escribe INDICE.md en la misma carpeta.
  * Si una pantalla no se puede capturar, queda anotada en el índice con el motivo.
No toca la base `hwanghon` ni el código de la aplicación.
"""
from __future__ import annotations

import argparse
import os
import shutil
import sys
import time
import traceback
from datetime import date, timedelta
from pathlib import Path

HERRAMIENTAS = Path(__file__).resolve().parent
RAIZ = HERRAMIENTAS.parents[1]
sys.path.insert(0, str(HERRAMIENTAS))
sys.path.insert(0, str(RAIZ / "pruebas" / "selenium"))

import entorno  # noqa: E402
from selenium import webdriver  # noqa: E402
from selenium.webdriver.chrome.service import Service  # noqa: E402
from selenium.webdriver.common.by import By  # noqa: E402
from selenium.webdriver.support import expected_conditions as EC  # noqa: E402
from utilidades import CLAVE_PRUEBA, MAESTRO, Navegador  # noqa: E402

SALIDA = RAIZ / "recursos" / "documentos_cliente" / "manual" / "capturas"
PDF = RAIZ / "pruebas" / "selenium" / "archivos" / "documento_prueba.pdf"
CSV = RAIZ / "pruebas" / "selenium" / "archivos" / "carga_masiva.csv"
SAMUEL, MATEO = "1000000101", "1000000105"

# ---------------------------------------------------------------------------------------------
# Datos ficticios de ejemplo (se suman a base_datos/insercion_datos_prueba.sql)
# ---------------------------------------------------------------------------------------------
DEPORTISTAS = [
    # documento, nombre, nacimiento, sexo, estatura, peso, grado, sede, acudiente, ingreso
    ("1000000104", "Valentina Moreno Díaz", "2016-06-02", "femenino", 1.32, 29.5, "Amarillo", "Club Hwang Hon", "1000000002", "2025-08-02"),
    ("1000000105", "Mateo Gutiérrez Rojas", "2010-09-18", "masculino", 1.68, 58.0, "Verde", "Club Hwang Hon", "1000000003", "2024-02-03"),
    ("1000000106", "Laura Camila Pérez Niño", "2000-02-11", "femenino", 1.63, 57.0, "Azul", "Club Hwang Hon", None, "2023-03-04"),
    ("1000000107", "Andrés Felipe Torres León", "1995-04-27", "masculino", 1.78, 74.0, "Rojo", "Club Hwang Hon", None, "2022-01-15"),
    ("1000000108", "Daniela Suárez Mejía", "2012-12-03", "femenino", 1.50, 41.0, "Naranja", "Escuela IMRDS", "1000000002", "2025-03-01"),
    ("1000000109", "Sebastián Castro Vega", "1988-08-14", "masculino", 1.74, 80.0, "Negro", "Escuela IMRDS", None, "2019-06-01"),
]
ACUDIENTES = [
    ("1000000002", "Patricia Díaz Moreno", "madre", "3000000004", "patricia.diaz@correoficticio.test"),
    ("1000000003", "Jorge Gutiérrez Mora", "padre", "3000000005", "jorge.gutierrez@correoficticio.test"),
]


def sabados(desde: date, hasta: date) -> list[date]:
    d = desde + timedelta(days=(5 - desde.weekday()) % 7)
    salida = []
    while d <= hasta:
        salida.append(d)
        d += timedelta(days=7)
    return salida


def sql_extra(hoy: date) -> str:
    q = lambda v: "NULL" if v is None else (str(v) if isinstance(v, (int, float)) else "'" + str(v).replace("'", "''") + "'")  # noqa: E731
    lineas = ["SET NAMES utf8mb4;", f"USE {entorno.BD};"]
    lineas.append("INSERT INTO acudiente_tutor (documento, nombre, relacion, telefono, correo) VALUES " +
                  ", ".join(f"({', '.join(q(x) for x in a)})" for a in ACUDIENTES) + ";")
    filas = []
    for doc, nombre, nac, sexo, est, peso, grado, sede, acu, ingreso in DEPORTISTAS:
        correo = nombre.split()[0].lower().replace("á", "a").replace("é", "e").replace("í", "i") + "." + doc[-3:] + "@correoficticio.test"
        filas.append(f"({q(doc)}, {q(nombre)}, {q(nac)}, 'Soacha', {q(sexo)}, {est}, {peso}, '30000{doc[-5:]}', 'Calle {doc[-2:]} # 10-{doc[-2:]}, Soacha', "
                     f"{q(correo)}, NULL, NULL, NULL, {q(sede)}, 1, {q(grado)}, {q(ingreso)}, 'activo', 'validado', {q(acu)}, NULL)")
    lineas.append("INSERT INTO deportista (documento, nombre, fecha_nacimiento, lugar_nacimiento, sexo, estatura, peso, telefono, direccion, correo, "
                  "discapacidad, experiencia_previa, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, "
                  "estado_registro, acudiente_documento, validado_por_correo) VALUES " + ", ".join(filas) + ";")
    # Un autorregistro más pendiente de validar (además de Juliana, de la semilla).
    lineas.append("INSERT INTO deportista (documento, nombre, fecha_nacimiento, lugar_nacimiento, sexo, estatura, peso, telefono, direccion, correo, "
                  "discapacidad, experiencia_previa, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, "
                  "estado_registro, acudiente_documento, validado_por_correo) VALUES ('1000000110', 'Tomás Herrera Quintero', '1999-01-21', 'Bogotá', "
                  "'masculino', 1.80, 76.0, '3000000110', 'Carrera 20 # 3-40, Soacha', 'tomas.herrera@correoficticio.test', NULL, NULL, NULL, "
                  f"'Club Hwang Hon', 1, NULL, {q(hoy.isoformat())}, 'activo', 'pendiente_validacion', NULL, NULL);")
    lineas.append("UPDATE deportista SET grado_actual = 'Amarillo' WHERE documento = '1000000101';")
    # Asistencia: sábados de junio a la fecha, patrón fijo (≈ 80 %).
    activos = ["1000000101"] + [d[0] for d in DEPORTISTAS]
    filas = []
    for i, s in enumerate(sabados(date(hoy.year, 6, 1), hoy - timedelta(days=1))):
        for j, doc in enumerate(activos):
            filas.append(f"({q(doc)}, {q(s.isoformat())}, {0 if (i * 3 + j) % 5 == 0 else 1})")
    lineas.append("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES " + ", ".join(filas) + ";")
    # Pagos: matrícula del año y mensualidades hasta el mes pasado (Samuel debe el mes en curso).
    filas = []
    for doc in activos:
        if doc != "1000000101":
            filas.append(f"({q(doc)}, 'matricula', {hoy.year}, 0, NULL)")
        for mes in range(1, hoy.month):
            if doc == "1000000101" and mes == 3:
                continue  # ya está en la semilla
            filas.append(f"({q(doc)}, 'mensualidad', {hoy.year}, {mes}, {1 + (mes % 3)})")
    lineas.append("INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES " + ", ".join(filas) + ";")
    # Eventos del mes en curso y torneo de octubre con resultados.
    f = lambda dia: date(hoy.year, hoy.month, dia).isoformat()  # noqa: E731
    lineas.append("INSERT INTO evento_calendario (id, nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES "
                  f"(20, 'Copa Ficticia Soacha 2026', 'competencia', '{hoy.year}-10-18', 'Coliseo Municipal de Soacha', 'publica', NULL), "
                  f"(21, 'Torneo Ficticio Distrital de Hapkido', 'competencia', {q(f(22))}, 'Coliseo El Salitre (ficticio)', 'publica', 'Inscripciones hasta el 15'), "
                  f"(22, 'Reunión de instructores', 'reunion_administrativa', {q(f(9))}, 'Sede Club Hwang Hon', 'administrativa', 'Planeación de diciembre'), "
                  f"(23, 'Clase abierta para familias', 'evento_extra', {q(f(14))}, 'Sede Club Hwang Hon', 'publica', NULL);")
    lineas.append("INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (20, 'Fitofan', 'departamental'), (21, 'Formulario de la liga', 'departamental');")
    lineas.append("INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, "
                  "resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES "
                  "('1000000101', 20, 'combate_individual', 'junior', '-50', 2, 'Final contra el club local', 'plata', 2, 1, NULL), "
                  "('1000000101', 20, 'formas', 'junior', NULL, 1, 'Mejor puntaje de la categoría', 'oro', NULL, NULL, NULL), "
                  "('1000000105', 20, 'combate_individual', 'juvenil', '-60', 3, NULL, 'bronce', 2, 1, NULL), "
                  "('1000000107', 20, 'combate_individual', 'mayores', '-75', 1, 'Invicto', 'oro', 3, 0, 'Rival zurdo en la final'), "
                  "('1000000106', 20, 'defensa_personal', 'mayores', NULL, 2, NULL, 'plata', NULL, NULL, NULL), "
                  "('1000000101', 21, 'combate_individual', 'junior', '-50', NULL, NULL, NULL, NULL, NULL, NULL), "
                  "('1000000105', 21, 'combate_individual', 'juvenil', '-60', NULL, NULL, NULL, NULL, NULL, NULL);")
    # Más candidatos en la sesión de examen de la semilla (abierta, con notas).
    lineas.append("INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, fecha_completado, nota, "
                  "criterios_evaluados, puntajes, valor_medible, aspectos_fallidos, observaciones, recomendaciones, aprobado) VALUES "
                  "(1, '1000000104', 'teorico', 'amarillo', 'completado', '2026-04-26', 9.00, NULL, NULL, NULL, NULL, NULL, NULL, 1), "
                  "(1, '1000000104', 'fisico', 'amarillo', 'completado', '2026-04-26', 8.00, NULL, NULL, 30.00, NULL, NULL, NULL, 1), "
                  "(1, '1000000104', 'combate', 'amarillo', 'pendiente', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);")
    # Documentos (los archivos se copian a la carpeta de la copia aislada).
    lineas.append("INSERT INTO documento (deportista_documento, tipo_documento, archivo, nombre_original, fecha_carga) VALUES "
                  "('1000000101', 'formulario_inscripcion', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf', 'formulario_inscripcion_samuel.pdf', '2025-02-01'), "
                  "('1000000101', 'consentimiento', 'b2c3d4e5f60718293a4b5c6d7e8f90a1.pdf', 'consentimiento_copa_soacha.pdf', '2026-10-10'), "
                  "('1000000101', 'certificado_ascenso', 'c3d4e5f60718293a4b5c6d7e8f90a1b2.pdf', 'certificado_amarillo.pdf', '2026-05-03');")
    lineas.append("INSERT INTO documento_general (titulo, archivo, nombre_original, fecha_carga) VALUES "
                  "('Reglamento interno del club', 'd4e5f60718293a4b5c6d7e8f90a1b2c3.pdf', 'reglamento_interno.pdf', '2026-02-01');")
    return "\n".join(lineas)


ARCHIVOS_DOC = {"documentos": ["7f3a9c1e5b2d4a6f8e0c1b3d5f7a9c2e.pdf", "2b4d6f8a0c1e3a5c7e9b1d3f5a7c9e0b.pdf", "9e1c3a5f7b9d0f2a4c6e8b0d2f4a6c8e.pdf",
                                "a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf", "b2c3d4e5f60718293a4b5c6d7e8f90a1.pdf", "c3d4e5f60718293a4b5c6d7e8f90a1b2.pdf"],
                "generales": ["4c6e8a0b2d4f6a8c0e2b4d6f8a0c2e4b.pdf", "d4e5f60718293a4b5c6d7e8f90a1b2c3.pdf"]}


def preparar_datos() -> None:
    entorno.preparar_copia()
    entorno.restablecer_bd()
    entorno.limpiar_almacenamiento()
    entorno._mysql([], sql_extra(date.today()).encode("utf-8"))
    almacen = entorno.DIR_APP / "backend" / "Almacenamiento"
    for carpeta, nombres in ARCHIVOS_DOC.items():
        (almacen / carpeta).mkdir(parents=True, exist_ok=True)
        for n in nombres:
            shutil.copyfile(PDF, almacen / carpeta / n)


# ---------------------------------------------------------------------------------------------
# Navegador
# ---------------------------------------------------------------------------------------------
def navegador(base: str, celular: bool, escala: float) -> Navegador:
    opciones = webdriver.ChromeOptions()
    for a in ("--headless=new", "--no-sandbox", "--disable-dev-shm-usage", "--lang=es-CO", "--hide-scrollbars",
              "--disable-search-engine-choice-screen", "--disable-features=AutofillServerCommunication,PasswordLeakDetection"):
        opciones.add_argument(a)
    if os.environ.get("CHROME_BINARY"):
        opciones.binary_location = os.environ["CHROME_BINARY"]
    ancho, alto = (390, 844) if celular else (1366, 768)
    if celular:
        opciones.add_experimental_option("mobileEmulation", {
            "deviceMetrics": {"width": ancho, "height": alto, "pixelRatio": escala, "touch": True},
            "userAgent": "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36"})
    else:
        opciones.add_argument(f"--window-size={ancho},{alto}")
    opciones.add_experimental_option("prefs", {"intl.accept_languages": "es-CO,es", "credentials_enable_service": False,
                                               "profile.password_manager_enabled": False})
    opciones.set_capability("goog:loggingPrefs", {"browser": "ALL"})
    # Idioma del navegador en español (Colombia): así <input type=date> muestra dd/mm/aaaa y el
    # botón de archivo dice «Elegir archivo» (en Linux sin interfaz lo decide LANGUAGE/LANG).
    idioma = {**os.environ, "LANGUAGE": "es_CO:es", "LANG": "es_CO.UTF-8", "LC_ALL": "es_CO.UTF-8"}
    ruta = os.environ.get("CHROMEDRIVER")
    servicio = Service(ruta, env=idioma) if ruta else Service(env=idioma)
    driver = webdriver.Chrome(options=opciones, service=servicio)
    if not celular:  # viewport exacto, sin barra del navegador
        driver.execute_cdp_cmd("Emulation.setDeviceMetricsOverride", {"width": ancho, "height": alto, "deviceScaleFactor": escala, "mobile": False})
    driver.execute_cdp_cmd("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-color-scheme", "value": "light"}]})
    return Navegador(driver, base, "celular" if celular else "escritorio", Path("."))


def tema_claro(nav: Navegador) -> None:
    nav.driver.execute_script("try { localStorage.setItem('hwanghon_tema', 'claro'); } catch (e) {}")


ERRORES_VISIBLES = """
return [...document.querySelectorAll('.aviso-error, .aviso.error, [role=alert]')]
  .filter(e => e.offsetParent !== null && e.textContent.trim()).map(e => e.textContent.trim().slice(0, 120));
"""


class Capturador:
    def __init__(self, solo: set[str] | None):
        self.solo = solo
        self.indice: list[dict] = []

    def hacer(self, nav: Navegador, numero: str, ambito: str, descripcion: str, pantalla: str, ruta: str, datos: str, accion) -> None:
        archivo = f"{numero}_{ambito}_{descripcion}.png"
        fila = {"n": numero, "archivo": archivo, "pantalla": pantalla, "ruta": ruta, "datos": datos, "nota": ""}
        self.indice.append(fila)
        if self.solo and numero not in self.solo:
            fila["nota"] = "(no se recapturó en esta corrida)"
            return
        try:
            accion()
            if numero != "02":  # en 02 el foco en el enlace es a propósito
                nav.driver.execute_script("document.activeElement && document.activeElement.blur && document.activeElement.blur();")
            time.sleep(0.6)  # gráficas y transiciones
            errores = nav.driver.execute_script(ERRORES_VISIBLES)
            if errores:
                fila["nota"] = "Aviso visible: " + " | ".join(errores)
            nav.driver.save_screenshot(str(SALIDA / archivo))
            print(f"  ✔ {archivo}" + (f"  ({fila['nota']})" if fila["nota"] else ""))
        except Exception as e:  # se anota en el índice, no se omite en silencio
            fila["archivo"] = f"— ({archivo} no se generó)"
            fila["nota"] = f"NO SE PUDO CAPTURAR: {type(e).__name__}: {str(e).splitlines()[0][:200] if str(e) else ''}"
            print(f"  ✘ {archivo}: {fila['nota']}")
            traceback.print_exc(limit=2)

    def escribir_indice(self, hoy: date) -> None:
        lineas = [
            "# Capturas para el manual de usuario — índice",
            "",
            f"- **Generadas:** {hoy.strftime('%d/%m/%Y')} con `pruebas/herramientas/capturas_manual.py` (Selenium + Chromium sin interfaz, tema claro, sin barra del navegador).",
            "- **Entorno:** copia aislada `pruebas/entorno/app` y base aparte `hwanghon_pruebas` con la semilla de pruebas y datos de ejemplo **ficticios**. Nada del hosting ni datos reales.",
            "- **Tamaño:** administrativo 1366×768; deportista / familias 390×844 (emulación de celular).",
            "- **Cuentas usadas:** `maestro@hwanghon.local` (cuenta principal, ficticia) y el deportista ficticio 1000000101 (Samuel Reyes Ortiz, 12/03/2014).",
            "- La «Ruta» es la del front: `administrativo.html#<ruta>` o `publico.html#<ruta>`.",
            "",
            "| N.º | Archivo | Pantalla | Ruta | Datos de ejemplo que se ven | Nota |",
            "|---|---|---|---|---|---|",
        ]
        for f in self.indice:
            lineas.append(f"| {f['n']} | {f['archivo']} | {f['pantalla']} | `{f['ruta']}` | {f['datos']} | {f['nota']} |")
        lineas += [
            "",
            "## Versión del front y recapturas pendientes",
            "",
            "- Se capturó el front del **árbol de trabajo sin commit** de John (encima de `c2f181e`).",
            "- **E1 (ojito):** ya está en ingreso, recuperar y Cuentas. Se ve en **01** y **23**.",
            "- **D3 (fecha con listas día / mes / año):** ya está en el ingreso público y se ve en **24**. **Falta en `registro.html`:** la **33** muestra todavía el selector de fecha del navegador.",
            "- Cuando el front entregue E1 y D3 completos, se recapturan **01, 23, 24 y 33**:",
            "",
            "  `python pruebas/herramientas/capturas_manual.py --solo 01,23,24,33`",
        ]
        (SALIDA / "INDICE.md").write_text("\n".join(lineas) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------------------------
# Pantallas
# ---------------------------------------------------------------------------------------------
def arriba(nav):
    nav.driver.execute_script("window.scrollTo(0, 0); const c = document.getElementById('contenido'); if (c) c.scrollTop = 0;")


def mostrar(nav, elemento, bloque="start"):
    nav.driver.execute_script(f"arguments[0].scrollIntoView({{block: '{bloque}'}}); window.scrollBy(0, -12);", elemento)


def vista(nav, ruta):
    nav.ir_a(ruta)
    nav.esperar_contenido()
    arriba(nav)


def administrativo(cap: Capturador, base: str, escala: float, hoy: date) -> None:
    nav = navegador(base, False, escala)
    d = nav.driver
    try:
        nav.abrir("index.html")
        tema_claro(nav)

        def p01():
            nav.abrir("index.html")
            nav.visible("#formulario-administrativo")
            nav.poner(d.find_element(By.ID, "correo"), MAESTRO)
            nav.poner(d.find_element(By.ID, "contrasena"), CLAVE_PRUEBA)
            d.execute_script("document.activeElement.blur();")
        cap.hacer(nav, "01", "admin", "ingreso", "Ingreso, pestaña «Personal del club»", "index.html",
                  "Correo maestro@hwanghon.local y contraseña oculta", p01)

        def p02():
            nav.abrir("index.html")
            nav.visible("#formulario-administrativo")
            nav.poner(d.find_element(By.ID, "correo"), MAESTRO)
            enlace = d.find_element(By.ID, "enlace-olvido")
            d.execute_script("arguments[0].focus();", enlace)
        cap.hacer(nav, "02", "admin", "ingreso_olvide_contrasena", "Ingreso con el enlace «¿Olvidaste tu contraseña?» resaltado", "index.html",
                  "Correo escrito; enlace con el foco", p02)

        def p03():
            nav.abrir("recuperar.html")
            campo = nav.visible("#correo-recuperar")
            nav.poner(campo, MAESTRO)
            d.execute_script("document.activeElement.blur();")
        cap.hacer(nav, "03", "admin", "recuperar_contrasena", "Recuperar contraseña (pedir el enlace por correo)", "recuperar.html",
                  "Correo maestro@hwanghon.local", p03)

        nav.ingresar_admin()
        tema_claro(nav)
        d.refresh()
        nav.esperar_menu()

        cap.hacer(nav, "04", "admin", "inicio", "Inicio: accesos rápidos, próximas fechas y calendario", "inicio",
                  "Eventos del mes (reunión de instructores, clase abierta, torneo distrital)", lambda: vista(nav, "inicio"))
        cap.hacer(nav, "05", "admin", "deportistas", "Lista de deportistas con filtros", "deportistas",
                  "8 deportistas ficticios validados y 2 pendientes, con grado y sede", lambda: vista(nav, "deportistas"))

        def p06():
            vista(nav, "deportista-nuevo")
            f = nav.formulario_de("nombre")
            for n, v in {"documento": "1000000120", "nombre": "Sofía Martínez Ruiz", "lugar_nacimiento": "Soacha", "sexo": "femenino",
                         "estatura": "1.58", "peso": "50"}.items():
                nav.rellenar(n, v, f)
            nav.rellenar("fecha_nacimiento", "2003-05-14", f)
            d.execute_script("document.activeElement.blur();")
            arriba(nav)
        cap.hacer(nav, "06", "admin", "deportista_nuevo", "Formulario de nuevo deportista", "deportista-nuevo",
                  "Sofía Martínez Ruiz, 1000000120, 14/05/2003 (sin guardar)", p06)
        cap.hacer(nav, "07", "admin", "ficha_deportista", "Ficha de un deportista con récord de combates", f"deportista/{SAMUEL}",
                  "Samuel Reyes Ortiz: datos personales, contacto y club; cinturón amarillo y récord 2-1", lambda: vista(nav, f"deportista/{SAMUEL}"))
        cap.hacer(nav, "08", "admin", "pendientes", "Deportistas pendientes de validar", "pendientes",
                  "Juliana Andrea Castillo Vargas y Tomás Herrera Quintero", lambda: vista(nav, "pendientes"))
        ultimo_sabado = hoy - timedelta(days=(hoy.weekday() - 5) % 7 or 7)
        cap.hacer(nav, "09", "admin", "asistencia_dia", "Asistencia de un día (sábado)", f"asistencia/{ultimo_sabado.isoformat()}",
                  f"Lista del {ultimo_sabado.strftime('%d/%m/%Y')} con presentes y ausentes", lambda: vista(nav, f"asistencia/{ultimo_sabado.isoformat()}"))
        cap.hacer(nav, "10", "admin", "pagos_deportista", "Pagos de un deportista", f"pagos/{SAMUEL}",
                  "Samuel: al día, matrícula pagada, mensualidades de enero a octubre; próximo vencimiento 08/11/2026", lambda: vista(nav, f"pagos/{SAMUEL}"))
        cap.hacer(nav, "11", "admin", "documentos_deportista", "Documentos de un deportista", f"documentos/{SAMUEL}",
                  "Registro civil, formulario de inscripción, consentimiento y certificado de ascenso", lambda: vista(nav, f"documentos/{SAMUEL}"))
        cap.hacer(nav, "12", "admin", "torneos", "Torneos (lista)", "torneos",
                  "Copa Ficticia Hwang Hon, Copa Ficticia Soacha y Torneo Ficticio Distrital", lambda: vista(nav, "torneos"))

        cap.hacer(nav, "13", "admin", "torneo_detalle", "Detalle de un torneo con resultados", "torneo/20",
                  "Copa Ficticia Soacha 2026: oro, plata y bronce, combates ganados/perdidos", lambda: vista(nav, "torneo/20"))
        cap.hacer(nav, "14", "admin", "sesion_examen", "Ascensos: sesión de examen con candidatos y notas", "ascenso/1",
                  "Sesión del 26/04/2026: Samuel y Valentina, notas de teórico y físico", lambda: vista(nav, "ascenso/1"))
        cap.hacer(nav, "15", "admin", "calendario_mensual", "Calendario, vista mensual", "calendario",
                  "Mes en curso con reunión, clase abierta y torneo", lambda: vista(nav, "calendario"))

        def p16():
            vista(nav, "calendario")
            crear = nav.visible("//details[summary[normalize-space()='Crear evento']]", css=False)
            if crear.get_attribute("open") is None:
                nav.clic(crear.find_element(By.TAG_NAME, "summary"))
            f = crear.find_element(By.TAG_NAME, "form")
            nav.rellenar("nombre", "Clase de defensa personal para mujeres", f)
            nav.rellenar("tipo", "evento_extra", f)
            nav.rellenar("fecha", date(hoy.year, hoy.month, 28).isoformat(), f)
            nav.rellenar("lugar", "Sede Club Hwang Hon", f)
            d.execute_script("document.activeElement.blur();")
            mostrar(nav, crear)
        cap.hacer(nav, "16", "admin", "evento_formulario", "Formulario para crear un evento del calendario", "calendario (sección «Crear evento»)",
                  "«Clase de defensa personal para mujeres», evento, día 28 del mes (sin guardar)", p16)

        def p17():
            vista(nav, f"estadisticas/{SAMUEL}")
            nav.visible("[data-prueba='pestana-graficas'][aria-selected='true']")
            time.sleep(0.5)
            arriba(nav)
        cap.hacer(nav, "17", "admin", "estadisticas_graficas", "Estadísticas (gráficas) de un deportista", f"estadisticas/{SAMUEL}",
                  "Samuel: asistencia por mes, medallas y exámenes", p17)

        def p18():
            vista(nav, "estadisticas-comparar")
            for columna, doc in (("comparar-a", SAMUEL), ("comparar-b", MATEO)):
                zona = nav.visible(f"[data-prueba='{columna}']")
                buscador = nav.formulario_de("deportista", zona)
                nav.rellenar("deportista", doc, buscador)
                nav.enviar(buscador)
            nav.esperar(lambda dr: "Mateo" in dr.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-b']").text
                        and "Samuel" in dr.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-a']").text, mensaje="No cargó la comparación")
            d.execute_script("document.activeElement.blur();")
            arriba(nav)
        cap.hacer(nav, "18", "admin", "estadisticas_comparar", "Estadísticas comparando dos deportistas", "estadisticas-comparar",
                  "Samuel Reyes Ortiz frente a Mateo Gutiérrez Rojas", p18)
        cap.hacer(nav, "19", "admin", "historial", "Historial (registros) de un deportista", f"historial/{SAMUEL}",
                  "Samuel: torneos, exámenes y asistencia", lambda: vista(nav, f"historial/{SAMUEL}"))
        cap.hacer(nav, "20", "admin", "exportacion", "Exportación de reportes", "exportacion", "Tipos de reporte disponibles", lambda: vista(nav, "exportacion"))

        def p21():
            vista(nav, "importar")
            f = nav.visible("form[aria-label='Elegir y previsualizar el CSV']")
            nav.rellenar("archivo", CSV, f)
            nav.enviar(f)
            nav.visible("button.boton-exito")
            arriba(nav)
        cap.hacer(nav, "21", "admin", "carga_masiva_previsualizacion", "Carga masiva: previsualización de un CSV", "importar",
                  "CSV de ejemplo con 2 filas ficticias (un adulto y una menor con acudiente), sin aplicar", p21)
        cap.hacer(nav, "22", "admin", "cuentas", "Cuentas administrativas", "cuentas",
                  "Maestro (prueba) como cuenta principal, Desarrollador y cuentas del club ficticias", lambda: vista(nav, "cuentas"))

        def p23():
            vista(nav, "mi-contrasena")
            f = nav.formulario_de("contrasena_nueva")
            nav.rellenar("contrasena_actual", CLAVE_PRUEBA, f)
            nav.rellenar("contrasena_nueva", "ClaveNueva2026", f)
            ojos = f.find_elements(By.CSS_SELECTOR, ".boton-ver-contrasena")
            if not ojos:
                raise RuntimeError("no hay botón de ojo (E1) en «Cambiar mi contraseña»")
            nav.clic(f.find_element(By.CSS_SELECTOR, "[data-prueba='ver-contrasena_nueva']") if f.find_elements(By.CSS_SELECTOR, "[data-prueba='ver-contrasena_nueva']") else ojos[-1])
            arriba(nav)
        cap.hacer(nav, "23", "admin", "cambiar_mi_contrasena", "Cambiar mi contraseña, con el botón de ojo", "mi-contrasena",
                  "Contraseña actual oculta; la nueva («ClaveNueva2026», ficticia) visible con el ojo", p23)
    finally:
        d.quit()


def deportista(cap: Capturador, base: str, escala: float) -> None:
    nav = navegador(base, True, escala)
    d = nav.driver
    try:
        nav.abrir("index.html")
        tema_claro(nav)

        def p24():
            nav.abrir("index.html")
            nav.visible("#formulario-administrativo")
            nav.clic(d.find_element(By.ID, "pestana-publica"))
            nav.visible("#formulario-publico")
            nav.poner(d.find_element(By.ID, "documento"), SAMUEL)
            nav.poner(d.find_element(By.ID, "fecha-nacimiento"), "2014-03-12")
            d.execute_script("document.activeElement.blur();")
        cap.hacer(nav, "24", "deportista", "ingreso_publico", "Ingreso público, pestaña «Deportista o familia»", "index.html",
                  "Documento 1000000101 y fecha 12/03/2014", p24)

        nav.ingresar_publico()
        tema_claro(nav)
        d.refresh()
        nav.esperar_menu()
        for numero, desc, pantalla, ruta, datos in (
            ("25", "inicio", "Inicio del deportista", "inicio", "Samuel: cinturón amarillo, récord 2-1, asistencia y próximo torneo"),
            ("26", "perfil", "Mi perfil", "perfil", "Datos de Samuel y de su acudiente"),
            ("27", "asistencia", "Mi asistencia", "asistencia", "Sábados desde junio, con porcentaje"),
            ("28", "pagos", "Mis pagos", "pagos", "Al día; próximo vencimiento 08/11/2026 y mensualidades pagadas"),
        ):
            cap.hacer(nav, numero, "deportista", desc, pantalla, ruta, datos, lambda r=ruta: vista(nav, r))

        def p29():
            vista(nav, "documentos")
            archivo = d.find_elements(By.CSS_SELECTOR, "#contenido input[type='file']")
            if not archivo:
                raise RuntimeError("no hay formulario de subida en «Mis documentos»")
            f = archivo[0].find_element(By.XPATH, "ancestor::form")
            tipo = f.find_elements(By.CSS_SELECTOR, "select[name='tipo_documento']")
            if tipo:
                nav.poner(tipo[0], "fotografia")
            archivo[0].send_keys(str(PDF.resolve()))
            mostrar(nav, f, "end")
        cap.hacer(nav, "29", "deportista", "documentos", "Mis documentos, con el formulario de subida", "documentos",
                  "Documentos de Samuel y un archivo elegido para subir (sin enviar)", p29)
        for numero, desc, pantalla, ruta, datos in (
            ("30", "ascensos", "Mis ascensos", "ascensos", "Examen de amarillo del 26/04/2026 con sus notas"),
            ("31", "estadisticas", "Mis estadísticas", "estadisticas", "Gráficas de Samuel"),
            ("32", "calendario", "Calendario", "calendario", "Eventos públicos del mes"),
        ):
            cap.hacer(nav, numero, "deportista", desc, pantalla, ruta, datos, lambda r=ruta: vista(nav, r))

        def p33():
            d.delete_all_cookies()
            nav.abrir("registro.html")
            f = nav.formulario_de("fecha_nacimiento")
            for n, v in {"documento": "1000000130", "nombre": "Camilo Andrés Ríos Peña", "lugar_nacimiento": "Soacha", "sexo": "masculino",
                         "estatura": "1.72", "peso": "68"}.items():
                nav.rellenar(n, v, f)
            nav.rellenar("fecha_nacimiento", "2001-09-30", f)
            d.execute_script("document.activeElement.blur();")
            arriba(nav)
        cap.hacer(nav, "33", "deportista", "autorregistro", "Formulario de autorregistro", "registro.html",
                  "Camilo Andrés Ríos Peña, 1000000130, 30/09/2001 (sin enviar)", p33)
    finally:
        d.quit()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--solo", help="Números a recapturar, separados por coma (ej. 01,23,24,33)")
    parser.add_argument("--escala", type=float, default=1.0, help="Factor de escala (1 = 1366x768 / 390x844 píxeles exactos; 2 = doble nitidez)")
    args = parser.parse_args()
    solo = {n.strip().zfill(2) for n in args.solo.split(",")} if args.solo else None
    SALIDA.mkdir(parents=True, exist_ok=True)
    hoy = date.today()
    print("Preparando la copia aislada y los datos ficticios…")
    preparar_datos()
    proceso = entorno.iniciar_servidor()
    cap = Capturador(solo)
    try:
        base = entorno.url_base()
        administrativo(cap, base, args.escala, hoy)
        deportista(cap, base, args.escala)
    finally:
        entorno.detener_servidor(proceso)
    cap.escribir_indice(hoy)
    fallidas = [f for f in cap.indice if f["nota"].startswith("NO SE PUDO")]
    print(f"\n{len(cap.indice) - len(fallidas)} capturas, {len(fallidas)} sin capturar. Carpeta: {SALIDA}")
    return 1 if fallidas else 0


if __name__ == "__main__":
    sys.exit(main())
