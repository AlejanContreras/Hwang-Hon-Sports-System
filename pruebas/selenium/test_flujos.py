"""Los 10 flujos clave del front, en escritorio y en celular (390x844).

Selectores estables (id, name, data-prueba, aria-label); el resultado de cada acción se
comprueba contra la API. Cada prueba arranca con la semilla recién cargada en la base APARTE
(conftest.py). Datos ficticios: deportista público 1000000101 (2014-03-12), pendiente 1000000102,
maestro@ (principal) y administrativo1@ (no principal), clave 12345678.
"""
from __future__ import annotations

from datetime import timedelta
from pathlib import Path

import pytest
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC

from utilidades import (ADMINISTRATIVO1, PENDIENTE_DOC, PUBLICO_DOC, PUBLICO_NOMBRE, Api, hoy_bogota, ultimo_sabado)

ARCHIVOS = Path(__file__).resolve().parent / "archivos"
PDF = ARCHIVOS / "documento_prueba.pdf"
CSV = ARCHIVOS / "carga_masiva.csv"


@pytest.fixture(scope="session", autouse=True)
def archivos_de_prueba():
    PDF.parent.mkdir(exist_ok=True)
    if not PDF.exists():
        PDF.write_bytes(b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")


@pytest.fixture
def api(servidor):
    return Api(servidor).ingresar_admin()


def comprobar_pantalla(nav):
    errores = nav.errores_js()
    assert not errores, f"Errores de JavaScript en la consola: {errores}"
    nav.sin_desborde_horizontal()


# 1 ============================================================ ingreso administrativo y público
def test_01_ingreso_administrativo_y_publico(nav):
    nav.abrir("index.html")
    nav.visible("#formulario-administrativo")
    nav.poner(nav.driver.find_element(By.ID, "correo"), "maestro@hwanghon.local")
    nav.poner(nav.driver.find_element(By.ID, "contrasena"), "equivocada")
    nav.enviar(nav.driver.find_element(By.ID, "formulario-administrativo"))
    assert nav.visible("#aviso-ingreso").text == "Correo o contraseña incorrectos."
    assert "index.html" in nav.driver.current_url

    nav.ingresar_admin()
    if nav.es_celular:
        assert not nav.driver.find_element(By.ID, "lista-menu").is_displayed(), "En celular el menú arranca plegado"
    nav.menu("deportistas")
    nav.visible(f"a[href='#deportista/{PUBLICO_DOC}']")
    comprobar_pantalla(nav)

    nav.driver.delete_all_cookies()
    nav.ingresar_publico(PENDIENTE_DOC, "2005-07-20", esperar_entrada=False)
    assert nav.visible("#aviso-ingreso").text == "Tu registro está en revisión."
    nav.ingresar_publico()
    claves = {a.get_attribute("data-prueba") for a in nav.driver.find_elements(By.CSS_SELECTOR, "#lista-menu [data-prueba^='menu-']")}
    assert "menu-perfil" in claves and not claves & {"menu-cuentas", "menu-exportacion", "menu-importar"}, claves
    comprobar_pantalla(nav)


# 2 ============================================================ crear, validar y rechazar deportista
def test_02_crear_validar_rechazar_deportista(nav, api):
    nav.ingresar_admin()
    nav.ir_a("deportista-nuevo")
    formulario = nav.formulario_de("fecha_nacimiento")
    for nombre, valor in {"documento": "9000000021", "nombre": "Lucía Prueba Selenium", "fecha_nacimiento": "2012-05-10",
                          "sexo": "femenino", "estatura": "1.45", "peso": "38.5", "sede_entrenamiento": "Club Hwang Hon",
                          "autorizacion_datos": True}.items():
        nav.rellenar(nombre, valor, formulario)
    for nombre, valor in {"acudiente_documento": "9000000921", "acudiente_nombre": "Acudiente Prueba Selenium",
                          "acudiente_relacion": "madre", "acudiente_telefono": "3000000921"}.items():
        nav.rellenar(nombre, valor, formulario)
    nav.enviar(formulario)
    nav.esperar(EC.url_contains("#deportista/9000000021"), mensaje="No llegó a la ficha del deportista creado")
    codigo, r = api.llamar("GET", "deportistas", "consultar", documento="9000000021")
    assert codigo == 200 and r["datos"]["acudiente"]["documento"] == "9000000921", r

    # Validar el pendiente de la semilla.
    codigo, r = api.llamar("GET", "deportistas", "consultar", documento=PENDIENTE_DOC)
    nombre_pendiente = r["datos"]["nombre"]
    nav.ir_a("pendientes")
    nav.pulsar_aria(f"Validar a {nombre_pendiente}")
    nav.aceptar_dialogo()
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento=PENDIENTE_DOC)[1]["datos"]["estado_registro"] == "validado",
                    "La validación no llegó a la API")

    # Rechazar un registro nuevo (autorregistro sin documentos): se elimina.
    codigo, r = Api(nav.base).llamar("POST", "autorregistro", "registrar", {
        "documento": "9000000041", "nombre": "Pendiente Rechazo Ficticio", "fecha_nacimiento": "2000-01-15",
        "sexo": "femenino", "estatura": 1.6, "peso": 55, "autorizacion_datos": True})
    assert codigo == 200, r
    nav.driver.refresh()
    nav.esperar_menu()
    nav.pulsar_aria("Rechazar a Pendiente Rechazo Ficticio")
    nav.aceptar_dialogo()
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento="9000000041")[0] == 404, "El rechazo no eliminó el registro")
    comprobar_pantalla(nav)


# 3 ============================================================ tomar asistencia
def test_03_tomar_asistencia(nav, api):
    sabado = ultimo_sabado().isoformat()
    nav.ingresar_admin()
    nav.menu("asistencia")
    formulario = nav.visible("form[aria-label='Elegir fecha']")
    nav.rellenar("fecha", sabado, formulario)
    nav.enviar(formulario)
    nav.esperar(EC.url_contains(f"#asistencia/{sabado}"))
    casilla = nav.visible(f"#presente-{PUBLICO_DOC}")
    nav.poner(casilla, True)
    nav.enviar(casilla.find_element(By.XPATH, "./ancestor::form[1]"))
    nav.esperar_que(lambda: api.llamar("GET", "asistencia", "consultar", documento=PUBLICO_DOC, fecha=sabado)[0] == 200,
                    "La asistencia no quedó guardada")
    _, r = api.llamar("GET", "asistencia", "consultar", documento=PUBLICO_DOC, fecha=sabado)
    assert r["datos"]["asistio"] is True, r
    comprobar_pantalla(nav)


# 4 ============================================================ registrar pago
def test_04_registrar_pago(nav, api):
    anio = hoy_bogota().year
    nav.ingresar_admin()
    nav.menu("pagos")
    buscador = nav.formulario_de("deportista")
    nav.rellenar("deportista", PUBLICO_DOC, buscador)
    nav.enviar(buscador)
    casilla = nav.visible("input[type='checkbox'][id^='pagar-']")
    mes = int(casilla.get_attribute("value"))
    nav.poner(casilla, True)
    nav.poner(casilla.find_element(By.XPATH, "./ancestor::li[1]//select"), "2")
    nav.enviar(casilla.find_element(By.XPATH, "./ancestor::form[1]"))
    nav.esperar_que(lambda: any(p["tipo"] == "mensualidad" and p["mes"] == mes and p["semana"] == 2
                                for p in api.llamar("GET", "pagos", "listar", documento=PUBLICO_DOC, anio=anio)[1]["datos"]),
                    "El pago no quedó registrado")
    comprobar_pantalla(nav)


# 5 ============================================================ subir y descargar un documento
def test_05_subir_y_descargar_documento(nav, api):
    nav.ingresar_admin()
    nav.ir_a(f"documentos/{PUBLICO_DOC}")
    seccion = nav.visible("section[aria-labelledby='titulo-subir-doc']")
    nav.rellenar("tipo_documento", "formulario_inscripcion", seccion)
    nav.rellenar("archivo", PDF, seccion)
    nav.enviar(seccion.find_element(By.TAG_NAME, "form"))
    nav.esperar_que(lambda: "formulario_inscripcion" in [d["tipo_documento"] for d in
                                                         api.llamar("GET", "documentos", "listar", documento=PUBLICO_DOC)[1]["datos"]],
                    "El documento no quedó guardado")
    nav.clic(nav.visible("[aria-label^='Descargar Formulario de inscripción']"))
    archivo = nav.esperar_descarga(".pdf")
    assert archivo.read_bytes() == PDF.read_bytes(), "La descarga no es el mismo PDF que se subió"
    comprobar_pantalla(nav)


# 6 ============================================================ crear torneo e inscribir
def test_06_crear_torneo_e_inscribir(nav, api):
    fecha = (hoy_bogota() + timedelta(days=60)).isoformat()
    nav.ingresar_admin()
    nav.menu("torneos")
    nav.ir_a("torneo-nuevo")
    formulario = nav.formulario_de("tipo_torneo")
    for nombre, valor in {"nombre": "Copa Selenium Ficticia", "fecha": fecha, "lugar": "Coliseo de prueba", "tipo_torneo": "departamental"}.items():
        nav.rellenar(nombre, valor, formulario)
    nav.enviar(formulario)
    nav.esperar(EC.url_matches(r"#torneo/\d+$"), mensaje="No llegó a la página del torneo")
    id_torneo = nav.driver.current_url.rsplit("/", 1)[-1]

    seccion = nav.visible("section[aria-labelledby='titulo-inscribir']")
    nav.rellenar("modalidad", "formas", seccion)
    nav.rellenar("deportista", PUBLICO_DOC, seccion)
    nav.enviar(nav.formulario_de("deportista", seccion))
    nav.visible(f"[aria-label='Quitar de la lista a {PUBLICO_DOC}']", seccion)
    nav.clic(seccion.find_elements(By.CSS_SELECTOR, ":scope > .acciones > button")[-1])
    nav.esperar_que(lambda: [(p["deportista_documento"], p["modalidad"]) for p in
                             api.llamar("GET", "competencias", "consultar", id=id_torneo)[1]["datos"]["participantes"]] == [(PUBLICO_DOC, "formas")],
                    "La inscripción no quedó en el torneo")
    comprobar_pantalla(nav)


# 7 ============================================================ crear sesión de examen y calificar
def test_07_sesion_examen_y_calificar(nav, api):
    nav.ingresar_admin()
    nav.menu("ascensos")
    nav.ir_a("ascenso-nuevo")
    formulario = nav.formulario_de("fecha_grado")
    nav.rellenar("fecha", (hoy_bogota() - timedelta(days=3)).isoformat(), formulario)
    nav.rellenar("fecha_grado", hoy_bogota().isoformat(), formulario)
    nav.rellenar("lugar", "Sede de prueba", formulario)
    nav.clic(formulario.find_element(By.CSS_SELECTOR, ".acciones button[type='button']"))  # ver candidatos
    nav.poner(nav.visible(f"#candidato-{PUBLICO_DOC}"), True)
    nav.enviar(formulario)
    nav.esperar(EC.url_matches(r"#ascenso/\d+$"), mensaje="No llegó a la sesión de examen")
    id_sesion = nav.driver.current_url.rsplit("/", 1)[-1]

    boton = nav.driver.find_element(By.CSS_SELECTOR, f"[aria-label='Guardar Teórico de {PUBLICO_NOMBRE}']")
    tarjeta = boton.find_element(By.XPATH, "./ancestor::details[1]")
    if tarjeta.get_attribute("open") is None:
        nav.clic(tarjeta.find_element(By.TAG_NAME, "summary"))
    nav.rellenar("nota", "8.5", boton.find_element(By.XPATH, "./ancestor::form[1]"))
    nav.clic(boton)

    def nota_teorico():
        sesion = api.llamar("GET", "evaluaciones", "consultar", id=id_sesion)[1]["datos"]
        participante = next(p for p in sesion["participantes"] if p["deportista_documento"] == PUBLICO_DOC)
        return next(m for m in participante["modalidades"] if m["modalidad"] == "teorico")["nota"]
    nav.esperar_que(lambda: nota_teorico() == 8.5, "La nota no quedó guardada")
    comprobar_pantalla(nav)


# 8 ============================================================ exportar un reporte
def test_08_exportar_reporte(nav):
    nav.ingresar_admin()
    nav.menu("exportacion")
    formulario = nav.visible("form[aria-label='Deportistas (Excel)']")
    nav.rellenar("estado", "activo", formulario)
    nav.enviar(formulario)
    archivo = nav.esperar_descarga(".xlsx")
    assert archivo.read_bytes()[:2] == b"PK", "El Excel descargado no es un .xlsx válido"
    comprobar_pantalla(nav)


# 9 ============================================================ carga masiva CSV
def test_09_carga_masiva_csv(nav, api):
    nav.ingresar_admin()
    nav.menu("importar")
    formulario = nav.visible("form[aria-label='Elegir y previsualizar el CSV']")
    nav.rellenar("archivo", CSV, formulario)
    assert not [b for b in nav.driver.find_elements(By.CSS_SELECTOR, "button.boton-exito") if b.is_displayed() and b.is_enabled()], \
        "No se debe poder aplicar antes de previsualizar"
    nav.enviar(formulario)
    aplicar = nav.visible("button.boton-exito")
    assert api.llamar("GET", "deportistas", "consultar", documento="9000000011")[0] == 404, "Previsualizar no debe guardar nada"
    nav.clic(aplicar)
    nav.aceptar_dialogo()
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento="9000000012")[0] == 200, "La carga no creó los deportistas")
    _, r = api.llamar("GET", "deportistas", "consultar", documento="9000000012")
    assert r["datos"]["acudiente"]["documento"] == "9000000912", r
    assert api.llamar("GET", "deportistas", "consultar", documento="9000000011")[0] == 200
    comprobar_pantalla(nav)


# 10 =========================================================== Cuentas (Mi cuenta y cuentas del club) y cerrar sesión
def test_10_cuentas_y_cerrar_sesion(nav, api):
    # Cuenta no principal: ve «Mi cuenta» pero no «Cuentas del club».
    nav.ingresar_admin(ADMINISTRATIVO1)
    nav.menu("cuentas")
    nav.visible("#mi-cuenta")
    nav.visible("#mi-cuenta [data-prueba='cambiar-contrasena']")
    assert not nav.driver.find_elements(By.ID, "cuentas-del-club"), "Una cuenta no principal no debe ver las cuentas del club"
    nav.clic(nav.visible("[data-prueba='cerrar-sesion']"))
    nav.esperar(EC.url_contains("index.html"), mensaje="Cerrar sesión no volvió al ingreso")
    nav.abrir("administrativo.html#deportistas")  # sin sesión, el panel devuelve al ingreso
    nav.esperar(EC.url_contains("index.html"))

    # Cuenta principal: crea una cuenta en «Cuentas del club».
    nav.ingresar_admin()
    nav.menu("cuentas")
    zona = nav.visible("#cuentas-del-club")
    crear = zona.find_elements(By.CSS_SELECTOR, ":scope > details")[-1]
    if crear.get_attribute("open") is None:
        nav.clic(crear.find_element(By.TAG_NAME, "summary"))
    formulario = crear.find_element(By.TAG_NAME, "form")
    for nombre, valor in {"correo": "secretaria.selenium@hwanghon.local", "nombre": "Secretaria Selenium", "contrasena": "ClaveSelenium1"}.items():
        nav.rellenar(nombre, valor, formulario)
    nav.enviar(formulario)
    nav.esperar_que(lambda: api.llamar("GET", "usuarios-administrativos", "consultar", correo="secretaria.selenium@hwanghon.local")[0] == 200,
                    "La cuenta no quedó creada")

    # Deportista: «Cerrar sesión» está en «Mi perfil».
    nav.clic(nav.visible("[data-prueba='cerrar-sesion']"))
    nav.esperar(EC.url_contains("index.html"))
    nav.ingresar_publico()
    nav.menu("perfil")
    nav.clic(nav.visible("[data-prueba='cerrar-sesion']"))
    nav.esperar(EC.url_contains("index.html"), mensaje="Cerrar sesión (público) no volvió al ingreso")
    comprobar_pantalla(nav)
