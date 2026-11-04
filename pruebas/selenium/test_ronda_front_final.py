"""Ronda del 04/11/2026 sobre el front final (bloques A, B y C + buscador de documentos generales).

Flujos 2a–2m del encargo. Selectores estables (id, name, data-prueba, aria-label) y resultados
comprobados contra la API. La mayoría corre solo en escritorio (marcador solo_escritorio); «Comparar»
corre también a 390 px. Datos ficticios; base aparte restablecida antes de cada prueba (conftest.py).
"""
from __future__ import annotations

import re
from datetime import timedelta
from pathlib import Path

import pytest
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC

import entorno
from utilidades import ADMINISTRATIVO1, CLAVE_PRUEBA, PUBLICO_DOC, PUBLICO_NOMBRE, Api, hoy_bogota

RAIZ = Path(__file__).resolve().parents[2]
PLANTILLA_EVENTOS = RAIZ / "frontend" / "recursos" / "plantilla_importar_eventos.csv"
PLANTILLA_DEPORTISTAS = RAIZ / "frontend" / "recursos" / "plantilla_carga_masiva_deportistas_PROVISIONAL.csv"
ARCHIVOS = Path(__file__).resolve().parent / "archivos"
SEDE_VALOR, SEDE_TEXTO = "Club Hwang Hon", "Club Hwang Hon — sede principal (Ciudad Verde)"

solo_escritorio = pytest.mark.solo_escritorio


@pytest.fixture
def api(servidor):
    return Api(servidor).ingresar_admin()


def sin_errores(nav):
    errores = nav.errores_js()
    assert not errores, f"Errores de JavaScript: {errores}"
    nav.sin_desborde_horizontal()


def abrir_details(nav, details):
    if details.get_attribute("open") is None:
        nav.clic(details.find_element(By.TAG_NAME, "summary"))


def menu_actual(nav) -> str | None:
    marcados = nav.driver.find_elements(By.CSS_SELECTOR, "#lista-menu [data-prueba^='menu-'][aria-current='page']")
    return marcados[0].get_attribute("data-prueba") if marcados else None


# 2a ============================================================== calendario: 5 tipos
@solo_escritorio
def test_2a_calendario_cinco_tipos(nav, api):
    nav.ingresar_admin()
    nav.menu("calendario")
    base = hoy_bogota() + timedelta(days=40)
    tipos = ["competencia", "evaluacion", "grado", "evento_extra", "reunion_administrativa"]
    creados = {}
    for i, tipo in enumerate(tipos):
        crear = next(d for d in nav.driver.find_elements(By.CSS_SELECTOR, "#contenido details.tarjeta")
                     if d.find_element(By.TAG_NAME, "summary").text.strip() == "Crear evento")
        abrir_details(nav, crear)
        formulario = crear.find_element(By.TAG_NAME, "form")
        nombre = f"Evento Selenium {tipo}"
        nav.rellenar("nombre", nombre, formulario)
        nav.rellenar("tipo", tipo, formulario)
        visibilidad = nav.campo("visibilidad", formulario)
        if tipo == "reunion_administrativa":
            assert visibilidad.get_attribute("disabled") is not None, "Reunión: la visibilidad debe quedar bloqueada"
            assert visibilidad.get_attribute("value") == "administrativa", "Reunión: visibilidad «solo administrativa»"
            nav.rellenar("confirmar_confidencial", True, formulario)
        else:
            assert visibilidad.get_attribute("disabled") is None
        fecha = (base + timedelta(days=i)).isoformat()
        nav.rellenar("fecha", fecha, formulario)
        nav.rellenar("lugar", "Sede de prueba", formulario)
        nav.enviar(formulario)
        creados[tipo] = (nombre, fecha)
        nav.esperar_que(lambda: any(e["nombre"] == nombre for e in api.llamar("GET", "calendario", "listar", desde=fecha, hasta=fecha)[1]["datos"]),
                        f"No se creó el evento {tipo}")
        nav.esperar_contenido()
    for tipo, (nombre, fecha) in creados.items():
        evento = next(e for e in api.llamar("GET", "calendario", "listar", desde=fecha, hasta=fecha)[1]["datos"] if e["nombre"] == nombre)
        assert evento["tipo"] == tipo, evento
        assert evento["visibilidad"] == ("administrativa" if tipo == "reunion_administrativa" else "publica"), evento
    # Editar la reunión recién creada: el tipo se ve y no se puede cambiar; la visibilidad sigue bloqueada.
    detalle = nav.visible(f"//details[contains(@class,'evento-calendario')][summary[contains(., 'Evento Selenium reunion_administrativa')]]", css=False)
    abrir_details(nav, detalle)
    editar = detalle.find_element(By.CSS_SELECTOR, "details.editar-evento")
    abrir_details(nav, editar)
    assert editar.find_element(By.CSS_SELECTOR, "[data-prueba='tipo-evento']").text.strip() != ""
    assert not editar.find_elements(By.CSS_SELECTOR, "[name='tipo']"), "Al editar no debe haber control para cambiar el tipo"
    assert nav.campo("visibilidad", editar).get_attribute("disabled") is not None
    sin_errores(nav)


# 2b ============================================================== calendario: importar CSV
@solo_escritorio
def test_2b_calendario_importar_plantilla(nav, api):
    nav.ingresar_admin()
    nav.menu("calendario")
    importar = nav.visible("//details[summary[normalize-space(.)='Importar eventos desde CSV']]", css=False)
    abrir_details(nav, importar)
    ayuda = nav.visible("[data-prueba='ayuda-importar-eventos']")
    formulario = ayuda.find_element(By.XPATH, "./ancestor::form[1]")
    nav.rellenar("archivo", PLANTILLA_EVENTOS, formulario)
    nav.enviar(formulario)
    nombres = [l.split(";")[0] for l in PLANTILLA_EVENTOS.read_text(encoding="utf-8-sig").splitlines()[1:] if l.strip()]
    nav.esperar_que(lambda: {e["nombre"] for e in api.llamar("GET", "calendario", "listar", desde="2027-01-01", hasta="2027-12-31")[1]["datos"]} >= set(nombres),
                    "Los eventos de la plantilla no quedaron en la API")
    eventos = api.llamar("GET", "calendario", "listar", desde="2027-01-01", hasta="2027-12-31")[1]["datos"]
    assert {e["tipo"] for e in eventos if e["nombre"] in nombres} == {"competencia", "evaluacion", "grado", "reunion_administrativa", "evento_extra"}
    sin_errores(nav)


# 2c ============================================================== ascensos: 2 candidatos y «Guardar todas»
@solo_escritorio
def test_2c_ascensos_guardar_todas(nav, api):
    api.llamar("PUT", "deportistas", "actualizar", {"estado_registro": "validado"}, documento="1000000102")
    nav.ingresar_admin()
    nav.menu("ascensos")
    nav.ir_a("ascenso-nuevo")
    formulario = nav.formulario_de("fecha_grado")
    lugar = nav.campo("lugar", formulario)
    textos = [o.text for o in lugar.find_elements(By.TAG_NAME, "option")]
    assert lugar.tag_name == "select" and SEDE_TEXTO in textos and "Otro lugar…" in textos, textos
    nav.rellenar("fecha", (hoy_bogota() - timedelta(days=3)).isoformat(), formulario)
    nav.rellenar("fecha_grado", hoy_bogota().isoformat(), formulario)
    nav.rellenar("lugar", SEDE_VALOR, formulario)
    nav.clic(formulario.find_element(By.CSS_SELECTOR, ".acciones button[type='button']"))
    casillas = nav.esperar(lambda d: [c for c in d.find_elements(By.CSS_SELECTOR, "input[id^='candidato-']") if c.is_displayed()][:2] or False)
    assert len(casillas) == 2, "Se necesitan 2 candidatos"
    assert nav.visible("//label[normalize-space(.)='Grado al que asciende:']", css=False)
    documentos = [c.get_attribute("value") for c in casillas]
    for c in casillas:
        nav.poner(c, True)
    nav.enviar(formulario)
    nav.esperar(EC.url_matches(r"#ascenso/\d+$"))
    id_sesion = nav.driver.current_url.rsplit("/", 1)[-1]
    assert api.llamar("GET", "evaluaciones", "consultar", id=id_sesion)[1]["datos"]["lugar"] == SEDE_VALOR
    # Notas en las dos tarjetas (abiertas o cerradas) y «Guardar todas las notas».
    notas = {documentos[0]: "8.5", documentos[1]: "7"}
    for doc, nota in notas.items():
        nombre = api.llamar("GET", "deportistas", "consultar", documento=doc)[1]["datos"]["nombre"]
        boton = nav.driver.find_element(By.CSS_SELECTOR, f"[aria-label='Guardar Teórico de {nombre}']")
        nav.poner(boton.find_element(By.XPATH, "./ancestor::form[1]").find_element(By.CSS_SELECTOR, "[name='nota']"), nota)
    nav.clic(nav.visible("[data-prueba='guardar-todas-notas']"))

    def notas_guardadas():
        sesion = api.llamar("GET", "evaluaciones", "consultar", id=id_sesion)[1]["datos"]
        return {p["deportista_documento"]: next(m["nota"] for m in p["modalidades"] if m["modalidad"] == "teorico") for p in sesion["participantes"]}
    nav.esperar_que(lambda: notas_guardadas() == {d: float(n) for d, n in notas.items()}, "«Guardar todas las notas» no guardó las dos notas")
    sin_errores(nav)


# 2d ============================================================== carga masiva con la plantilla PROVISIONAL
@solo_escritorio
def test_2d_carga_masiva_plantilla(nav, api):
    documentos = [l.split(";")[0] for l in PLANTILLA_DEPORTISTAS.read_text(encoding="utf-8-sig").splitlines()[1:] if l.strip()]
    nav.ingresar_admin()
    nav.menu("importar")
    formulario = nav.visible("form[aria-label='Elegir y previsualizar el CSV']")
    nav.rellenar("archivo", PLANTILLA_DEPORTISTAS, formulario)
    nav.enviar(formulario)
    aplicar = nav.visible("button.boton-exito")
    assert all(api.llamar("GET", "deportistas", "consultar", documento=d)[0] == 404 for d in documentos), "Previsualizar no debe guardar"
    nav.clic(aplicar)
    nav.aceptar_dialogo()
    nav.esperar_que(lambda: all(api.llamar("GET", "deportistas", "consultar", documento=d)[0] == 200 for d in documentos), "Aplicar no creó los deportistas")
    sin_errores(nav)


# 2e ============================================================== comparar (escritorio y 390 px)
def test_2e_comparar_deportistas(nav, api):
    nav.ingresar_admin()
    for modulo in ("estadisticas", "historial"):
        nav.menu(modulo)
        nav.clic(nav.visible("[data-prueba='pestana-comparar']"))
        nav.esperar(EC.url_contains(f"#{modulo}-comparar"))
        nav.esperar_contenido()
        assert menu_actual(nav) == f"menu-{modulo}", f"El panel debe resaltar {modulo} en Comparar"
        for columna, doc in (("comparar-a", PUBLICO_DOC), ("comparar-b", "1000000103")):
            zona = nav.visible(f"[data-prueba='{columna}']")
            buscador = nav.formulario_de("deportista", zona)
            nav.rellenar("deportista", doc, buscador)
            nav.enviar(buscador)
        nombre_b = api.llamar("GET", "deportistas", "consultar", documento="1000000103")[1]["datos"]["nombre"]
        nav.esperar(lambda d: PUBLICO_NOMBRE in d.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-a']").text
                    and nombre_b in d.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-b']").text, mensaje="No cargaron las dos columnas")
        a = nav.driver.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-a']").rect
        b = nav.driver.find_element(By.CSS_SELECTOR, "[data-prueba='comparar-b']").rect
        if nav.es_celular:
            assert b["y"] >= a["y"] + a["height"] - 1, f"A 390 px las columnas deben ir apiladas: {a} {b}"
        else:
            assert abs(a["y"] - b["y"]) < 2 and b["x"] > a["x"], f"En escritorio las columnas van lado a lado: {a} {b}"
        sin_errores(nav)
    # El público no ve «Comparar».
    nav.driver.delete_all_cookies()
    nav.ingresar_publico()
    for modulo in ("estadisticas", "historial"):
        nav.menu(modulo)
        nav.visible("[data-prueba='pestana-graficas']")
        assert not nav.driver.find_elements(By.CSS_SELECTOR, "[data-prueba='pestana-comparar']"), "El público no debe ver «Comparar»"
    sin_errores(nav)


# 2f ============================================================== estadísticas/historial: selector, nombre, volver, panel
@solo_escritorio
def test_2f_estadisticas_historial(nav, api):
    nav.ingresar_admin()
    nav.ir_a(f"deportista/{PUBLICO_DOC}")
    nav.clic(nav.visible(f"#contenido a[href='#estadisticas/{PUBLICO_DOC}']"))
    nav.esperar_contenido()
    nav.visible("[data-prueba='volver-ficha']")
    nav.esperar(lambda d: PUBLICO_NOMBRE in d.find_element(By.ID, "deportista-consultado").text, mensaje="No se ve el nombre del deportista")
    assert menu_actual(nav) == "menu-estadisticas"
    nav.clic(nav.visible("[data-prueba='pestana-registros']"))
    nav.esperar(EC.url_contains("#historial"))
    nav.esperar_contenido()
    assert menu_actual(nav) == "menu-historial", "En Registros el panel debe resaltar Historial"
    nav.visible("[data-prueba='volver-ficha']")
    # Llegando por el menú (no desde la ficha): sin «← Volver a la ficha».
    nav.menu("deportistas")
    nav.menu("estadisticas")
    assert not [e for e in nav.driver.find_elements(By.CSS_SELECTOR, "[data-prueba='volver-ficha']") if e.is_displayed()]
    buscador = nav.formulario_de("deportista")
    nav.rellenar("deportista", "1000000103", buscador)
    nav.enviar(buscador)
    nombre = api.llamar("GET", "deportistas", "consultar", documento="1000000103")[1]["datos"]["nombre"]
    nav.esperar(lambda d: nombre in d.find_element(By.ID, "deportista-consultado").text, mensaje="El selector no muestra el nombre elegido")
    sin_errores(nav)
    # Deportista: el panel también sigue la pestaña.
    nav.driver.delete_all_cookies()
    nav.ingresar_publico()
    nav.menu("estadisticas")
    assert menu_actual(nav) == "menu-estadisticas"
    nav.clic(nav.visible("[data-prueba='pestana-registros']"))
    nav.esperar(EC.url_contains("#historial"))
    nav.esperar_contenido()
    assert menu_actual(nav) == "menu-historial"
    sin_errores(nav)


# 2g ============================================================== menor sin acudiente
def _bloque_obligatorio(nav, formulario) -> bool:
    nota = formulario.find_element(By.CSS_SELECTOR, "[data-prueba='acudiente-obligatorio']")
    requeridos = [nav.campo(n, formulario).get_attribute("required") is not None for n in ("acudiente_documento", "acudiente_nombre", "acudiente_relacion")]
    assert all(requeridos) == nota.is_displayed() and (all(requeridos) or not any(requeridos)), "Nota y campos obligatorios no coinciden"
    return nota.is_displayed()


@solo_escritorio
def test_2g_acudiente_obligatorio(nav, api):
    nav.ingresar_admin()
    # Crear
    nav.ir_a("deportista-nuevo")
    f = nav.formulario_de("fecha_nacimiento")
    for n, v in {"documento": "9000000061", "nombre": "Menor Selenium Sin Acudiente", "sexo": "masculino", "estatura": "1.40", "peso": "35"}.items():
        nav.rellenar(n, v, f)
    nav.rellenar("fecha_nacimiento", "2013-01-10", f)
    assert _bloque_obligatorio(nav, f), "Menor: el bloque del acudiente debe ser obligatorio"
    nav.enviar(f)
    nav.aviso_de_error()
    assert api.llamar("GET", "deportistas", "consultar", documento="9000000061")[0] == 404, "No debe enviarse sin acudiente"
    nav.rellenar("fecha_nacimiento", "1995-01-10", f)
    assert not _bloque_obligatorio(nav, f), "Mayor de edad: el bloque vuelve a opcional"
    nav.rellenar("fecha_nacimiento", "2013-01-10", f)
    for n, v in {"acudiente_documento": "9000000961", "acudiente_nombre": "Acudiente Selenium", "acudiente_relacion": "madre"}.items():
        nav.rellenar(n, v, f)
    nav.enviar(f)
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento="9000000061")[0] == 200, "Con acudiente sí debe crearse")
    # Editar (menor con acudiente): sin acudiente no se envía.
    nav.ir_a(f"deportista-editar/{PUBLICO_DOC}")
    f = nav.formulario_de("fecha_nacimiento")
    assert _bloque_obligatorio(nav, f)
    for n in ("acudiente_documento", "acudiente_nombre", "acudiente_relacion", "acudiente_telefono", "acudiente_correo"):
        nav.rellenar(n, "", f) if nav.campo(n, f).get_attribute("value") else None
    nav.enviar(f)
    nav.aviso_de_error()
    assert api.llamar("GET", "deportistas", "consultar", documento=PUBLICO_DOC)[1]["datos"]["acudiente"] is not None
    nav.rellenar("fecha_nacimiento", "1990-03-12", f)
    assert not _bloque_obligatorio(nav, f)
    sin_errores(nav)
    # Registro público
    nav.driver.delete_all_cookies()
    nav.abrir("registro.html")
    f = nav.formulario_de("fecha_nacimiento")
    for n, v in {"documento": "9000000071", "nombre": "Registro Menor Selenium", "sexo": "femenino", "estatura": "1.30", "peso": "30",
                 "autorizacion_datos": True}.items():
        nav.rellenar(n, v, f)
    nav.rellenar("fecha_nacimiento", "2015-05-05", f)
    assert _bloque_obligatorio(nav, f)
    nav.enviar(f)
    nav.aviso_de_error()
    assert api.llamar("GET", "deportistas", "consultar", documento="9000000071")[0] == 404
    nav.rellenar("fecha_nacimiento", "1999-05-05", f)
    assert not _bloque_obligatorio(nav, f)
    nav.rellenar("fecha_nacimiento", "2015-05-05", f)
    for n, v in {"acudiente_documento": "9000000971", "acudiente_nombre": "Acudiente Registro Selenium", "acudiente_relacion": "padre"}.items():
        nav.rellenar(n, v, f)
    nav.enviar(f)
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento="9000000071")[0] == 200, "El registro con acudiente no llegó")
    sin_errores(nav)


# 2h ============================================================== sede como <select>
@solo_escritorio
def test_2h_sede_select(nav, api):
    def revisar_select(formulario, esperado_valor):
        sede = nav.campo("sede_entrenamiento", formulario)
        assert sede.tag_name == "select", "La sede debe ser un <select>"
        opciones = {o.get_attribute("value"): o.text for o in sede.find_elements(By.TAG_NAME, "option")}
        assert opciones.get(SEDE_VALOR) == SEDE_TEXTO, opciones
        assert sede.get_attribute("value") == esperado_valor, (sede.get_attribute("value"), esperado_valor)
        return sede

    nav.ingresar_admin()
    nav.ir_a("deportista-nuevo")
    f = nav.formulario_de("fecha_nacimiento")
    revisar_select(f, nav.campo("sede_entrenamiento", f).get_attribute("value"))
    for n, v in {"documento": "9000000081", "nombre": "Adulto Sede Selenium", "fecha_nacimiento": "1990-02-02", "sexo": "masculino",
                 "estatura": "1.70", "peso": "70", "sede_entrenamiento": SEDE_VALOR}.items():
        nav.rellenar(n, v, f)
    nav.enviar(f)
    nav.esperar_que(lambda: api.llamar("GET", "deportistas", "consultar", documento="9000000081")[0] == 200, "No se creó")
    assert api.llamar("GET", "deportistas", "consultar", documento="9000000081")[1]["datos"]["sede_entrenamiento"] == SEDE_VALOR
    # Editar uno con otra sede: se conserva.
    nav.ir_a("deportista-editar/1000000102")
    f = nav.formulario_de("fecha_nacimiento")
    sede = revisar_select(f, "Escuela IMRDS")
    assert "Escuela IMRDS" in [o.get_attribute("value") for o in sede.find_elements(By.TAG_NAME, "option")]
    # Registro público
    nav.driver.delete_all_cookies()
    nav.abrir("registro.html")
    f = nav.formulario_de("fecha_nacimiento")
    revisar_select(f, nav.campo("sede_entrenamiento", f).get_attribute("value"))
    sin_errores(nav)


# 2i ============================================================== recuperación de contraseña de punta a punta
@solo_escritorio
def test_2i_recuperacion_contrasena(nav, servidor):
    correos = entorno.DIR_APP / "backend" / "Almacenamiento" / "correos"
    nav.abrir("index.html")
    nav.visible("[data-prueba='olvide-contrasena']")
    for _ in range(4):  # cuarto fallo seguido → 429
        nav.poner(nav.driver.find_element(By.ID, "correo"), ADMINISTRATIVO1)
        nav.poner(nav.driver.find_element(By.ID, "contrasena"), "equivocada")
        nav.enviar(nav.driver.find_element(By.ID, "formulario-administrativo"))
        nav.esperar(lambda d: d.find_element(By.ID, "aviso-ingreso").is_displayed())
    assert "Demasiados intentos" in nav.driver.find_element(By.ID, "aviso-ingreso").text
    enlace = nav.visible("[data-prueba='olvide-contrasena']")  # sigue visible tras el bloqueo
    nav.clic(enlace)
    nav.esperar(EC.url_contains("recuperar.html"))
    nav.poner(nav.visible("#correo-recuperar"), ADMINISTRATIVO1)
    nav.enviar(nav.driver.find_element(By.ID, "formulario-solicitar"))
    archivo = nav.esperar(lambda _: next(iter(sorted(correos.glob("*.eml"))), False) if correos.exists() else False, mensaje="No se escribió el correo")
    url = re.search(r"https?://\S+token=[0-9a-fA-F]+", archivo.read_text(encoding="utf-8")).group(0)
    assert url.startswith(f"{servidor}/frontend/recuperar.html"), f"El enlace debe ir a frontend/recuperar.html: {url}"
    nav.driver.get(url)
    nav.visible("#zona-restablecer")
    nav.poner(nav.driver.find_element(By.ID, "contrasena-nueva"), "Recuperada2026")
    nav.poner(nav.driver.find_element(By.ID, "contrasena-repetida"), "Recuperada2026")
    nav.enviar(nav.driver.find_element(By.ID, "formulario-restablecer"))
    nav.esperar(lambda d: d.find_element(By.ID, "aviso-recuperar").is_displayed(), mensaje="Sin respuesta al restablecer")
    assert Api(servidor).llamar("POST", "auth", "iniciarSesionAdministrativa", {"correo": ADMINISTRATIVO1, "contrasena": CLAVE_PRUEBA})[0] == 401
    nav.ingresar_admin(ADMINISTRATIVO1, "Recuperada2026")
    sin_errores(nav)


# 2j ============================================================== miga de carga masiva
@solo_escritorio
def test_2j_miga_carga_masiva(nav):
    nav.ingresar_admin()
    nav.menu("deportistas")
    nav.clic(nav.visible("#contenido a[href='#importar/deportistas']"))
    nav.esperar_contenido()
    nav.visible("#contenido .miga a[href='#deportistas']")
    nav.menu("inicio") if nav.driver.find_elements(By.CSS_SELECTOR, "[data-prueba='menu-inicio']") else nav.ir_a("inicio")
    nav.menu("importar")
    assert not nav.driver.find_elements(By.CSS_SELECTOR, "#contenido .miga a[href='#deportistas']"), "Sin venir de Deportistas no hay miga"
    sin_errores(nav)


# 2k ============================================================== documentos generales: buscador y título propuesto
@solo_escritorio
def test_2k_documentos_generales(nav, api, tmp_path):
    archivo = tmp_path / "Llave Naranja n3.pdf"
    archivo.write_bytes(b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")
    nav.ingresar_admin()
    nav.menu("documentos")
    seccion = nav.visible("section[aria-labelledby='titulo-generales']")
    titulo = nav.campo("titulo", seccion)
    ayuda = seccion.find_element(By.ID, titulo.get_attribute("aria-describedby"))
    assert "Recomendación" in ayuda.text, "Debe verse la recomendación de nombre"
    formulario = titulo.find_element(By.XPATH, "./ancestor::form[1]")
    nav.rellenar("archivo", archivo, formulario)
    assert titulo.get_attribute("value") == "Llave Naranja n3", "El título se propone desde el nombre del archivo"
    nav.enviar(formulario)
    nav.esperar_que(lambda: any(g["titulo"] == "Llave Naranja n3" for g in api.llamar("GET", "documentos", "listarGenerales")[1]["datos"]), "No se subió")
    for t in ("Teoría Amarillo", "Forma Ingresado Cruz 1"):  # dos más por la API, para tener con qué filtrar
        _subir_general(api, t)

    def filas_visibles(nav_):
        sec = nav_.visible("section[aria-labelledby='titulo-generales']")
        return [tr.text for tr in sec.find_elements(By.CSS_SELECTOR, "tbody tr") if tr.is_displayed()]

    for ambito in ("admin", "publico"):
        if ambito == "publico":
            nav.driver.delete_all_cookies()
            nav.ingresar_publico()
            nav.menu("documentos")
        else:
            nav.driver.refresh()
            nav.esperar_menu()
        buscador = nav.visible("[data-prueba='buscar-generales']")
        titulos = [g["titulo"] for g in api.llamar("GET", "documentos", "listarGenerales")[1]["datos"]]
        coinciden = lambda t: [x for x in titulos if t in normalizar(x)]  # noqa: E731
        nav.esperar(lambda _: len(filas_visibles(nav)) == len(titulos), mensaje="Deben listarse todos los generales")
        buscador.clear(); buscador.send_keys("t")       # 1 letra: no filtra
        assert len(filas_visibles(nav)) == len(titulos)
        for texto, clave in (("TEO", "teo"), ("naranja", "naranja"), ("CRUZ", "cruz")):  # sin tildes ni mayúsculas
            buscador.clear(); buscador.send_keys(texto)
            esperado = coinciden(clave)
            assert esperado, f"Ningún título con «{clave}» para probar"
            nav.esperar(lambda _: len(filas_visibles(nav)) == len(esperado), mensaje=f"Filtro «{texto}» ({ambito}): esperados {esperado}")
        sin_errores(nav)


def normalizar(texto: str) -> str:
    import unicodedata
    return "".join(c for c in unicodedata.normalize("NFD", texto.lower()) if unicodedata.category(c) != "Mn")


def _subir_general(api, titulo):
    import urllib.request
    limite = "----selenium"
    cuerpo = (f'--{limite}\r\nContent-Disposition: form-data; name="titulo"\r\n\r\n{titulo}\r\n'
              f'--{limite}\r\nContent-Disposition: form-data; name="archivo"; filename="g.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode() \
        + b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" + f"\r\n--{limite}--\r\n".encode()
    peticion = urllib.request.Request(f"{api.base}?recurso=documentos&accion=subirGeneral", data=cuerpo, method="POST",
                                      headers={"Content-Type": f"multipart/form-data; boundary={limite}"})
    with api.abridor.open(peticion) as r:
        assert r.status == 201


# 2l ============================================================== torneos: etiquetas, buscador legible y categoría de peso
@solo_escritorio
def test_2l_torneos(nav, api):
    codigo, r = api.llamar("POST", "competencias", "inscribir", {"competencia_evento_id": 1, "participantes": [
        {"deportista_documento": PUBLICO_DOC, "modalidad": "combate_individual"}]})
    assert codigo == 201, r
    nav.ingresar_admin()
    nav.ir_a("torneo-nuevo")
    f = nav.formulario_de("plataforma")
    etiqueta = f.find_element(By.CSS_SELECTOR, f"label[for='{nav.campo('plataforma', f).get_attribute('id')}']").text
    assert etiqueta.startswith("Plataforma de inscripción"), etiqueta
    nav.ir_a("torneo/1")
    seccion = nav.visible("section[aria-labelledby='titulo-inscribir']")
    buscar = nav.campo("deportista", seccion)
    nav.poner(buscar, f"{PUBLICO_DOC} — Samuel Reyes Or")   # 10 dígitos + ~15 caracteres
    ancho = nav.driver.execute_script("return [arguments[0].scrollWidth, arguments[0].clientWidth];", buscar)
    assert ancho[0] <= ancho[1], f"El buscador corta «10 dígitos + 15 caracteres»: {ancho}"
    # Categoría de peso: desde el 06/11 es un <select> por categoría de edad y sexo (ver
    # test_ronda_final_0611.py para las listas); aquí solo se guarda una opción y se verifica en la API.
    detalle = nav.visible(f"//details[contains(@class,'participante')][summary[contains(., '{PUBLICO_DOC}') and contains(., 'Combate individual')]]", css=False)
    abrir_details(nav, detalle)
    form = detalle.find_element(By.TAG_NAME, "form")
    for n in ("puesto", "medalla", "resultado"):
        control = nav.campo(n, form)
        assert control.get_attribute("aria-describedby"), f"«{n}» debe tener ayuda"
    nav.rellenar("categoria_edad", "juvenil", form)
    peso = nav.campo("categoria_peso", form)
    nav.esperar(lambda _: "-45" in [o.get_attribute("value") for o in peso.find_elements(By.TAG_NAME, "option")],
                mensaje="El select de peso no ofrece -45 para juvenil masculino")
    nav.poner(peso, "-45")
    nav.enviar(form)

    def categoria():
        t = api.llamar("GET", "competencias", "consultar", id=1)[1]["datos"]
        return next(p["categoria_peso"] for p in t["participantes"] if p["deportista_documento"] == PUBLICO_DOC and p["modalidad"] == "combate_individual")
    nav.esperar_que(lambda: categoria() == "-45", "La categoría -45 no se guardó")
    sin_errores(nav)


# 2m ============================================================== DEF-01 (3 de 3)
@solo_escritorio
def test_2m_def01_tres_de_tres(nav, servidor):
    principal = Api(servidor).ingresar_admin()
    clave = CLAVE_PRUEBA
    for intento in range(3):
        nav.driver.delete_all_cookies()
        nav.ingresar_admin(ADMINISTRATIVO1, clave)
        nav.menu("deportistas")
        clave = f"ClaveDef01-{intento}"
        assert principal.llamar("PUT", "usuarios-administrativos", "restablecerContrasena", {"contrasena_nueva": clave}, correo=ADMINISTRATIVO1)[0] == 200
        nav.clic(nav.visible("#lista-menu [data-prueba='menu-asistencia']"))  # Asistencia: peticiones paralelas
        nav.esperar(EC.url_contains("index.html"))
        texto = nav.visible("#aviso-ingreso").text
        assert texto == "Tu sesión ya no es válida.", f"Intento {intento + 1}: «{texto}»"
