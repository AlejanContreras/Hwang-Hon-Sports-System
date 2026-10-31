#!/usr/bin/env python3
"""Genera la colección Postman (v2.1) de HwangHon-Sports-System desde el catálogo de la API.

Fuente (el contrato): documentacion/api/catalogo_api.md
  * B.15 (tabla resumen): las 95 acciones, su método y su ámbito. El generador falla si
    alguna acción queda sin petición en la colección.
  * Fichas B.1–B.14: los ejemplos reales (forma de `datos` que se comprueba en cada respuesta
    exitosa) y los mensajes de error documentados («…») que se comparan con los recibidos.

La secuencia de peticiones sigue la de pruebas/backend/catalogo/capturar.php (las mismas
llamadas reales con las que se escribió el catálogo), más casos de error y de sesión.

Uso:  python pruebas/postman/generar_coleccion.py
Salida: pruebas/postman/HwangHon_API.postman_collection.json
        pruebas/postman/entorno_aislado.postman_environment.json
        pruebas/postman/entorno_xampp.postman_environment.json
"""
from __future__ import annotations

import json
import re
import uuid
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
CATALOGO = RAIZ / "documentacion" / "api" / "catalogo_api.md"
SALIDA = Path(__file__).resolve().parent

# --------------------------------------------------------------------------- catálogo


def guillemets(texto: str) -> list[str]:
    """Textos entre «…», respetando comillas anidadas: «Para tu propia cuenta usa «cambiar mi contraseña».»"""
    salida, nivel, inicio = [], 0, 0
    for i, c in enumerate(texto):
        if c == "«":
            if nivel == 0:
                inicio = i + 1
            nivel += 1
        elif c == "»" and nivel:
            nivel -= 1
            if nivel == 0:
                salida.append(texto[inicio:i])
    return salida


def leer_catalogo() -> tuple[list[dict], dict[str, dict]]:
    texto = CATALOGO.read_text(encoding="utf-8")
    acciones = []
    tabla = texto.split("### B.15", 1)[1].split("## Parte C", 1)[0]
    for fila in re.finditer(r"^\| (\d+) \| ([\w-]+) \| (\w+) \| ([^|]+) \| ([^|]+) \|$", tabla, re.M):
        acciones.append({"n": int(fila[1]), "recurso": fila[2], "accion": fila[3],
                         "metodo": fila[4].split()[0], "ambito": fila[5].strip()})
    fichas: dict[str, dict] = {}
    partes = re.split(r"^#### `([\w-]+\.\w+)` — (.+)$", texto.split("## Parte B", 1)[1].split("### B.15", 1)[0], flags=re.M)
    for i in range(1, len(partes), 3):
        clave, cabecera, cuerpo = partes[i], partes[i + 1], partes[i + 2]
        ejemplos = []
        for m in re.finditer(r"Ejemplo real \(llamada como \*\*(.+?)\*\*\): `(\w+) api\.php\?([^`]+)`", cuerpo):
            resto = cuerpo[m.end():]
            r = re.search(r"Respuesta `(\d{3})`( \(archivo; cabeceras\))?:\s*```(\w+)\n(.*?)```", resto, re.S)
            if not r:
                continue
            ej = {"quien": m[1], "metodo": m[2], "consulta": m[3], "codigo": int(r[1]), "archivo": bool(r[2])}
            if not ej["archivo"]:
                limpio = re.sub(r'^\s*"… \(.*?\)",?\s*$\n?', "", r[4], flags=re.M)
                ej["respuesta"] = json.loads(re.sub(r",(\s*[\]}])", r"\1", limpio))
            ejemplos.append(ej)
        errores = []
        bloque = re.search(r"- \*\*Errores:\*\*(.*?)(?=\n- \*\*Notas|\n#### |\Z)", cuerpo, re.S)
        if bloque:
            for trozo in re.split(r"(?=`\d{3}`)", bloque[1]):
                codigo = re.match(r"`(\d{3})`", trozo)
                if codigo:
                    errores += [(int(codigo[1]), msg) for msg in guillemets(trozo)]
        fichas[clave] = {"cabecera": cabecera, "ejemplos": ejemplos, "errores": errores}
    return acciones, fichas


MENSAJES_COMUNES = [
    "No autenticado. Inicie sesión para continuar.", "Tu sesión ya no es válida.",
    "Acceso restringido al ámbito administrativo.", "No encontrado.",
    "Método no permitido. Se esperaba (GET|POST|PUT|DELETE).",
    "Solicitud rechazada: viene de otro sitio.", "Solicitud rechazada: origen no permitido.",
    "Tipo de contenido no soportado: envía el cuerpo como application/json.",
    "Cuerpo de la solicitud inválido: se esperaba JSON.", "Error interno del servidor.",
    # A.5: «403 del ámbito público en rutas administrativas». El catálogo documenta esta familia de mensajes
    # en varias fichas (eliminar documentos, próximo torneo, resumen de un torneo); se acepta para todas.
    r"^Solo el ámbito administrativo puede .+\.$",
]


MENSAJES_CATALOGO: set[str] = set()  # todos los «…» del catálogo (se llena en main)


def mensaje_a_regex(msg: str) -> str:
    """Patrones: N, X o x sueltos y … valen cualquier texto.
«Ya existe un deportista con el documento X.» -> ^Ya existe un deportista con el documento .+\\.$"""
    if msg in MENSAJES_COMUNES and "(GET|" in msg:
        return "^" + msg.replace(".", r"\.").replace(r"(GET|POST|PUT|DELETE)\.", "(GET|POST|PUT|DELETE)") + "$"
    if msg.startswith("^"):
        return msg  # ya es una expresión regular
    rx = re.escape(msg)
    rx = re.sub(r"(?<![\w\\])(N|X|x)(?![\w])", ".+", rx)
    rx = rx.replace("…", ".+").replace(r"\…", ".+")
    rx = re.sub(r'\\"[a-z_]+\\"', r'\\"[a-z_]+\\"', rx)  # «El campo "x" es obligatorio.»
    return "^" + rx + "$"


# --------------------------------------------------------------------------- pasos

SESIONES = {"admin": "cuenta principal ({{correo_admin}}, por defecto maestro@hwanghon.local)", "staff": "administrativo no principal (administrativo1@hwanghon.local)",
            "pub": "público (deportista 1000000101)", "reg": "permiso de autorregistro", "anon": "sin sesión"}


class Paso:
    def __init__(self, nombre, ses, metodo, accion, consulta="", cuerpo=None, multipart=None, codigo=200,
                 guardar=None, pruebas=None, cabeceras=None, crudo=None, ruta=None, ejemplo_quien=None, descripcion="", sin_claves=None):
        self.nombre, self.ses, self.metodo, self.accion = nombre, ses, metodo, accion
        self.consulta, self.cuerpo, self.multipart, self.codigo = consulta, cuerpo, multipart, codigo
        self.guardar = guardar or {}
        self.pruebas = pruebas or []
        self.cabeceras = cabeceras or {}
        self.crudo = crudo  # (content-type, texto) para cuerpos no JSON
        self.ruta = ruta    # URL explícita (forma por ruta o errores de ruta)
        self.ejemplo_quien = ejemplo_quien
        self.descripcion = descripcion
        self.sin_claves = sin_claves or []


def N(var: str) -> str:
    """Marcador para un número que viene de una variable de Postman (sin comillas en el JSON)."""
    return f"@@{var}@@"


PDF = "archivos/minimo.pdf"
VALENTINA = {
    "documento": "1000000150", "nombre": "Valentina Gómez Ficticia", "fecha_nacimiento": "2013-06-15", "lugar_nacimiento": "Soacha",
    "sexo": "femenino", "estatura": 1.48, "peso": 39.5, "telefono": "3000000150", "direccion": "Calle Ficticia 15",
    "correo": "familia.gomez@correoficticio.test", "discapacidad": None, "experiencia_previa": None,
    "sede_entrenamiento": "Club Hwang Hon", "autorizacion_datos": True,
    "acudiente": {"documento": "1000000950", "nombre": "Carolina Gómez Ficticia", "relacion": "madre", "telefono": "3000000950",
                  "correo": "familia.gomez@correoficticio.test"},
}
SIN_ACUDIENTE = {k: v for k, v in VALENTINA.items() if k != "acudiente"} | {"documento": "1000000151"}
CAL = ["teorico", "fisico", "combate", "rompimiento", "formas", "llaves"]
NOTAS = {"teorico": 8.5, "fisico": 7, "combate": 6.5, "rompimiento": 9, "formas": 8, "llaves": 7.5}

CARPETAS: list[tuple[str, str, list[Paso]]] = [
    ("00 Salud de la API", "GET api.php sin parámetros: comprobación de salud (A.1).", [
        Paso("Salud: API activa", "anon", "GET", None, pruebas=["pm.expect(r.datos.mensaje).to.eql('HwangHon-Sports-System API activa.');"]),
    ]),
    ("01 auth — sesiones por ámbito", "Abre las sesiones que usan las demás carpetas: cuenta principal (admin), administrativo no principal (staff) y público. "
     "Cada sesión se guarda en su propia variable (ses_admin, ses_staff, ses_pub, ses_reg) y se envía en la cabecera Cookie; "
     "las peticiones «sin sesión» no envían cookie.", [
        Paso("Ingreso administrativo (cuenta principal)", "admin", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "{{correo_admin}}", "contrasena": "{{clave_prueba}}"},
             pruebas=["pm.expect(r.datos.es_administrador_principal).to.eql(true);", "pm.expect(pm.collectionVariables.get('ses_admin')).to.not.be.empty;"], ejemplo_quien="sin sesión"),
        Paso("Ingreso administrativo con clave errada → 401", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "{{correo_admin}}", "contrasena": "equivocada"}, codigo=401),
        Paso("Ingreso público (documento + fecha de nacimiento)", "pub", "POST", "auth.iniciarSesionPublica", cuerpo={"documento": "1000000101", "fecha_nacimiento": "2014-03-12"},
             pruebas=["pm.expect(r.datos.ambito).to.eql('publica');"]),
        Paso("Ingreso público con registro pendiente → 403", "anon", "POST", "auth.iniciarSesionPublica", cuerpo={"documento": "1000000102", "fecha_nacimiento": "2005-07-20"}, codigo=403),
        Paso("Ingreso público con fecha errada → 401", "anon", "POST", "auth.iniciarSesionPublica", cuerpo={"documento": "1000000101", "fecha_nacimiento": "2000-01-01"}, codigo=401),
        Paso("Ingreso público con fecha mal formada → 400", "anon", "POST", "auth.iniciarSesionPublica", cuerpo={"documento": "1000000101", "fecha_nacimiento": "12/03/2014"}, codigo=400),
        Paso("Sesión actual (administrativo)", "admin", "GET", "auth.sesionActual", ejemplo_quien="administrativo", pruebas=["pm.expect(r.datos.ambito).to.eql('administrativa');"]),
        Paso("Sesión actual (público)", "pub", "GET", "auth.sesionActual", ejemplo_quien="público", pruebas=["pm.expect(r.datos.ambito).to.eql('publica');"]),
        Paso("Sesión actual sin sesión → 401", "anon", "GET", "auth.sesionActual", codigo=401),
        Paso("Ingreso administrativo (no principal)", "staff", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "administrativo1@hwanghon.local", "contrasena": "{{clave_prueba}}"},
             pruebas=["pm.expect(r.datos.es_administrador_principal).to.eql(false);"]),
        Paso("Solicitar recuperación (correo no registrado: misma respuesta)", "anon", "POST", "auth.solicitarRecuperacionContrasena", cuerpo={"correo": "no.registrado@hwanghon.local"}),
        Paso("Restablecer contraseña con el token de prueba", "anon", "POST", "auth.restablecerContrasena", cuerpo={"token": "{{token_recuperacion}}", "contrasena_nueva": "{{clave_prueba}}"},
             descripcion="El token lo deja ejecutar_newman.py en la base aparte (fixture, igual que capturar.php). Sin el ejecutor, esta petición da 400."),
        Paso("Restablecer con el mismo token (ya usado) → 400", "anon", "POST", "auth.restablecerContrasena", cuerpo={"token": "{{token_recuperacion}}", "contrasena_nueva": "{{clave_prueba}}"}, codigo=400),
        Paso("Tras el restablecimiento, la sesión anterior del staff cae → 401", "staff", "GET", "auth.sesionActual", codigo=401,
             pruebas=["pm.expect(r.mensaje).to.eql('Tu sesión ya no es válida.');"]),
        Paso("Ingreso administrativo (no principal) otra vez", "staff", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "administrativo1@hwanghon.local", "contrasena": "{{clave_prueba}}"}),
        Paso("Cambiar contraseña con clave actual errada → 400", "staff", "POST", "auth.cambiarContrasena", cuerpo={"contrasena_actual": "otra", "contrasena_nueva": "NuevaClave2026"}, codigo=400),
        Paso("Cambiar mi contraseña (staff)", "staff", "POST", "auth.cambiarContrasena", cuerpo={"contrasena_actual": "{{clave_prueba}}", "contrasena_nueva": "NuevaClave2026"}),
        Paso("La sesión de quien cambia su contraseña sigue viva", "staff", "GET", "auth.sesionActual", ejemplo_quien="administrativo"),
        Paso("Cambiar contraseña desde el ámbito público → 403", "pub", "POST", "auth.cambiarContrasena", cuerpo={"contrasena_actual": "x", "contrasena_nueva": "NuevaClave2026"}, codigo=403),
        Paso("Bloqueo: fallo 1 de 4 → 401", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "bloqueo.postman@hwanghon.local", "contrasena": "mala"}, codigo=401),
        Paso("Bloqueo: fallo 2 de 4 → 401", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "bloqueo.postman@hwanghon.local", "contrasena": "mala"}, codigo=401),
        Paso("Bloqueo: fallo 3 de 4 → 401", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "bloqueo.postman@hwanghon.local", "contrasena": "mala"}, codigo=401),
        Paso("Bloqueo: cuarto fallo seguido → 429", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "bloqueo.postman@hwanghon.local", "contrasena": "mala"}, codigo=429),
        Paso("Bloqueo: durante el bloqueo sigue en 429", "anon", "POST", "auth.iniciarSesionAdministrativa", cuerpo={"correo": "bloqueo.postman@hwanghon.local", "contrasena": "mala"}, codigo=429),
    ]),
    ("02 usuarios-administrativos (cuenta principal)", "Solo las cuentas principales gestionan cuentas (RN-T.11).", [
        Paso("Listar cuentas", "admin", "GET", "usuarios-administrativos.listar"),
        Paso("Listar cuentas desde una cuenta no principal → 403", "staff", "GET", "usuarios-administrativos.listar", codigo=403),
        Paso("Listar cuentas desde el ámbito público → 403", "pub", "GET", "usuarios-administrativos.listar", codigo=403),
        Paso("Listar cuentas sin sesión → 401", "anon", "GET", "usuarios-administrativos.listar", codigo=401),
        Paso("Consultar cuenta", "admin", "GET", "usuarios-administrativos.consultar", "correo=administrativo1@hwanghon.local"),
        Paso("Crear cuenta", "admin", "POST", "usuarios-administrativos.crear", cuerpo={"correo": "secretaria.prueba@hwanghon.local", "nombre": "Secretaria de Prueba", "rol": "secretaria", "contrasena": "ClaveInicial1"}, codigo=201),
        Paso("Crear cuenta repetida → 409", "admin", "POST", "usuarios-administrativos.crear", cuerpo={"correo": "secretaria.prueba@hwanghon.local", "nombre": "Otra", "contrasena": "ClaveInicial1"}, codigo=409),
        Paso("Crear cuenta con campo desconocido → 400", "admin", "POST", "usuarios-administrativos.crear", cuerpo={"correo": "otra.prueba@hwanghon.local", "nombre": "Otra", "contrasena": "ClaveInicial1", "x": 1}, codigo=400,
             pruebas=["pm.expect(r.mensaje).to.match(/^Campos no (reconocidos|permitidos): x\\.$/);"],
             descripcion="A.2 documenta «Campos no reconocidos: x.» y la ficha de usuarios-administrativos «Campos no permitidos: x.»: se aceptan ambos (ver INFORME)."),
        Paso("Actualizar cuenta", "admin", "PUT", "usuarios-administrativos.actualizar", "correo=secretaria.prueba@hwanghon.local", cuerpo={"nombre": "Secretaria Prueba Editada", "rol": "tesoreria"}),
        Paso("Desactivar cuenta", "admin", "PUT", "usuarios-administrativos.desactivar", "correo=secretaria.prueba@hwanghon.local"),
        Paso("Desactivar la propia cuenta → 409", "admin", "PUT", "usuarios-administrativos.desactivar", "correo={{correo_admin}}", codigo=409),
        Paso("Reactivar cuenta", "admin", "PUT", "usuarios-administrativos.reactivar", "correo=secretaria.prueba@hwanghon.local"),
        Paso("Restablecer contraseña de otra cuenta", "admin", "PUT", "usuarios-administrativos.restablecerContrasena", "correo=secretaria.prueba@hwanghon.local", cuerpo={"contrasena_nueva": "ClaveNueva2026"}),
        Paso("Restablecer la propia contraseña → 409", "admin", "PUT", "usuarios-administrativos.restablecerContrasena", "correo={{correo_admin}}", cuerpo={"contrasena_nueva": "ClaveNueva2026"}, codigo=409),
    ]),
    ("03 deportistas", "Lectura por ámbito, alta con acudiente, validación de un pendiente y edición pública limitada.", [
        Paso("Listar (administrativo)", "admin", "GET", "deportistas.listar", ejemplo_quien="administrativo"),
        Paso("Listar pendientes de validación", "admin", "GET", "deportistas.listar", "estado_registro=pendiente_validacion", ejemplo_quien="administrativo",
             pruebas=["r.datos.forEach(d => pm.expect(d.estado_registro).to.eql('pendiente_validacion'));"]),
        Paso("Listar (público: solo el propio)", "pub", "GET", "deportistas.listar", ejemplo_quien="público",
             pruebas=["pm.expect(r.datos.length).to.eql(1);", "pm.expect(r.datos[0].documento).to.eql('1000000101');"]),
        Paso("Listar sin sesión → 401", "anon", "GET", "deportistas.listar", codigo=401),
        Paso("Consultar (administrativo)", "admin", "GET", "deportistas.consultar", "documento=1000000101", ejemplo_quien="administrativo"),
        Paso("Consultar (público, lo suyo)", "pub", "GET", "deportistas.consultar", "documento=1000000101", ejemplo_quien="público", sin_claves=["validado_por_correo"],
             descripcion="El catálogo: el público no recibe validado_por_correo."),
        Paso("Consultar (público, ajeno) → 404", "pub", "GET", "deportistas.consultar", "documento=1000000103", codigo=404),
        Paso("Buscar", "admin", "GET", "deportistas.buscar", "texto=rey"),
        Paso("Buscar con 1 carácter → 400", "admin", "GET", "deportistas.buscar", "texto=r", codigo=400),
        Paso("Buscar desde el ámbito público → 403", "pub", "GET", "deportistas.buscar", "texto=rey", codigo=403),
        Paso("Crear deportista menor con acudiente", "admin", "POST", "deportistas.crear", cuerpo=VALENTINA, codigo=201,
             pruebas=["pm.expect(r.datos.documento).to.eql('1000000150');"]),
        Paso("Crear deportista repetido → 409", "admin", "POST", "deportistas.crear", cuerpo=VALENTINA, codigo=409),
        Paso("Crear menor sin acudiente → 400", "admin", "POST", "deportistas.crear", cuerpo=SIN_ACUDIENTE, codigo=400),
        Paso("Actualizar (administrativo)", "admin", "PUT", "deportistas.actualizar", "documento=1000000150", cuerpo={"peso": 41.2, "grado_actual": "Blanco"}, ejemplo_quien="administrativo"),
        Paso("Validar un registro pendiente", "admin", "PUT", "deportistas.actualizar", "documento=1000000102", cuerpo={"estado_registro": "validado"}, ejemplo_quien="administrativo",
             pruebas=["pm.expect(r.datos.estado_registro).to.eql('validado');"]),
        Paso("Actualizar (público: peso y teléfono)", "pub", "PUT", "deportistas.actualizar", "documento=1000000101", cuerpo={"peso": 46.0, "telefono": "3000000002"}, ejemplo_quien="público"),
        Paso("Actualizar (público: campo no permitido) → 403", "pub", "PUT", "deportistas.actualizar", "documento=1000000101", cuerpo={"nombre": "Otro"}, codigo=403),
    ]),
    ("04 autorregistro (sin sesión → permiso temporal)", "registrar abre un permiso de 2 h (ses_reg) para cargar documentos; confirmar lo cierra.", [
        Paso("Registrar (abre el permiso)", "reg", "POST", "autorregistro.registrar", cuerpo={"documento": "1000000160", "nombre": "Andrés Registro Ficticio", "fecha_nacimiento": "1999-02-10",
             "sexo": "masculino", "estatura": 1.75, "peso": 72, "telefono": "3000000160", "correo": "andres.registro@correoficticio.test", "autorizacion_datos": True, "sitio_web": ""}),
        Paso("Registrar sin autorización de datos → 400", "anon", "POST", "autorregistro.registrar", cuerpo={"documento": "1000000161", "nombre": "Sin Autorización", "fecha_nacimiento": "1999-02-10",
             "sexo": "masculino", "estatura": 1.7, "peso": 70, "autorizacion_datos": False}, codigo=400),
        Paso("El permiso de autorregistro no es una cuenta → 401", "reg", "GET", "deportistas.listar", codigo=401),
        Paso("Subir documento de identidad", "reg", "POST", "autorregistro.subirDocumento", multipart={"tipo_documento": "documento_identidad", "archivo": ("cedula.pdf", PDF)}, codigo=201, guardar={"id_reg_doc": "r.datos.id"}),
        Paso("Reemplazar documento", "reg", "POST", "autorregistro.reemplazarDocumento", "id={{id_reg_doc}}", multipart={"archivo": ("cedula_legible.pdf", PDF)}),
        Paso("Subir fotografía", "reg", "POST", "autorregistro.subirDocumento", multipart={"tipo_documento": "fotografia", "archivo": ("foto.pdf", PDF)}, codigo=201, guardar={"id_reg_foto": "r.datos.id"}),
        Paso("Eliminar documento", "reg", "DELETE", "autorregistro.eliminarDocumento", "id={{id_reg_foto}}"),
        Paso("Listar documentos del registro", "reg", "GET", "autorregistro.listarDocumentos", pruebas=["pm.expect(JSON.stringify(r.datos)).to.include('documento_identidad');"]),
        Paso("Confirmar (cierra el permiso)", "reg", "POST", "autorregistro.confirmar"),
        Paso("Subir con el permiso cerrado → 403", "reg", "POST", "autorregistro.subirDocumento", multipart={"tipo_documento": "fotografia", "archivo": ("foto.pdf", PDF)}, codigo=403),
        Paso("Subir sin permiso (sin sesión) → 403", "anon", "POST", "autorregistro.subirDocumento", multipart={"tipo_documento": "fotografia", "archivo": ("foto.pdf", PDF)}, codigo=403),
        Paso("Rechazar el registro: eliminar pendiente con documentos", "admin", "DELETE", "deportistas.eliminar", "documento=1000000160"),
        Paso("Registrar otro (sin documentos)", "anon", "POST", "autorregistro.registrar", cuerpo={"documento": "1000000162", "nombre": "Registro Sin Documentos", "fecha_nacimiento": "2000-08-08",
             "sexo": "femenino", "estatura": 1.6, "peso": 54, "autorizacion_datos": True}),
        Paso("Eliminar pendiente", "admin", "DELETE", "deportistas.eliminar", "documento=1000000162"),
        Paso("Eliminar un deportista validado → 409", "admin", "DELETE", "deportistas.eliminar", "documento=1000000103", codigo=409),
    ]),
    ("05 documentos", "Documentos del deportista (uno por tipo para identidad/formulario/acudiente/foto) y documentos generales del club.", [
        Paso("Subir documento del deportista", "admin", "POST", "documentos.subir", multipart={"documento": "1000000150", "tipo_documento": "formulario_inscripcion", "archivo": ("formulario_valentina.pdf", PDF)},
             codigo=201, guardar={"id_doc": "r.datos.id"}),
        Paso("Subir otro del mismo tipo → 409", "admin", "POST", "documentos.subir", multipart={"documento": "1000000150", "tipo_documento": "formulario_inscripcion", "archivo": ("otro.pdf", PDF)}, codigo=409),
        Paso("Subir con formato no permitido → 400", "admin", "POST", "documentos.subir", multipart={"documento": "1000000150", "tipo_documento": "fotografia", "archivo": ("foto.csv", "archivos/no_permitido.csv")}, codigo=400),
        Paso("Listar documentos (administrativo)", "admin", "GET", "documentos.listar", "documento=1000000150", ejemplo_quien="administrativo"),
        Paso("Listar documentos (público)", "pub", "GET", "documentos.listar", ejemplo_quien="público"),
        Paso("Consultar documento", "admin", "GET", "documentos.consultar", "id={{id_doc}}"),
        Paso("Consultar documento ajeno desde el público → 404", "pub", "GET", "documentos.consultar", "id={{id_doc}}", codigo=404),
        Paso("Reemplazar documento", "admin", "POST", "documentos.reemplazar", "id={{id_doc}}", multipart={"archivo": ("formulario_valentina_firmado.pdf", PDF)}),
        Paso("Descargar documento", "admin", "GET", "documentos.descargar", "id={{id_doc}}"),
        Paso("Descargar documento inexistente → 404", "admin", "GET", "documentos.descargar", "id=1", codigo=404),
        Paso("Faltantes de un deportista", "admin", "GET", "documentos.faltantes", "documento=1000000150", ejemplo_quien="administrativo"),
        Paso("Faltantes de todos los pendientes", "admin", "GET", "documentos.faltantes"),
        Paso("Eliminar documento", "admin", "DELETE", "documentos.eliminar", "id={{id_doc}}"),
        Paso("Eliminar desde el público → 403", "pub", "DELETE", "documentos.eliminar", "id=1", codigo=403),
        Paso("Subir documento general", "admin", "POST", "documentos.subirGeneral", multipart={"titulo": "Teoría para cinturón amarillo", "archivo": ("teoria_amarillo.pdf", PDF)}, codigo=201, guardar={"id_general": "r.datos.id"}),
        Paso("Listar generales (público)", "pub", "GET", "documentos.listarGenerales"),
        Paso("Descargar general (público)", "pub", "GET", "documentos.descargarGeneral", "id={{id_general}}"),
        Paso("Reemplazar general", "admin", "POST", "documentos.reemplazarGeneral", "id={{id_general}}", multipart={"titulo": "Teoría para cinturón amarillo (2026)", "archivo": ("teoria_amarillo_v2.pdf", PDF)}),
        Paso("Eliminar general", "admin", "DELETE", "documentos.eliminarGeneral", "id={{id_general}}"),
    ]),
    ("06 asistencia", "Sábados de entrenamiento; las fechas se calculan en cada corrida (zona America/Bogota).", [
        Paso("Pantalla de toma de asistencia", "admin", "GET", "asistencia.listarPantalla", "fecha={{sab1}}"),
        Paso("Tomar asistencia", "admin", "POST", "asistencia.tomar", cuerpo={"fecha": "{{sab1}}", "presentes": ["1000000101", "1000000103"]}, codigo=201),
        Paso("Tomar de nuevo la misma fecha → 409", "admin", "POST", "asistencia.tomar", cuerpo={"fecha": "{{sab1}}", "presentes": []}, codigo=409),
        Paso("Tomar en un día que no es sábado → 400", "admin", "POST", "asistencia.tomar", cuerpo={"fecha": "{{lunes}}", "presentes": []}, codigo=400),
        Paso("Listar por fecha", "admin", "GET", "asistencia.listar", "fecha={{sab1}}", ejemplo_quien="administrativo"),
        Paso("Listar (público)", "pub", "GET", "asistencia.listar", ejemplo_quien="público"),
        Paso("Consultar una marca", "admin", "GET", "asistencia.consultar", "documento=1000000101&fecha={{sab1}}"),
        Paso("Resumen del día", "admin", "GET", "asistencia.resumenDia"),
        Paso("Corregir una marca", "admin", "PUT", "asistencia.corregir", "documento=1000000102&fecha={{sab1}}", cuerpo={"asistio": True}),
        Paso("Porcentaje (administrativo, todo)", "admin", "GET", "asistencia.porcentaje", "documento=1000000101&ventana=todo", ejemplo_quien="administrativo"),
        Paso("Porcentaje (público)", "pub", "GET", "asistencia.porcentaje", ejemplo_quien="público"),
        Paso("Eliminar una marca", "admin", "DELETE", "asistencia.eliminarMarca", "documento=1000000102&fecha={{sab1}}"),
        Paso("Tomar otra fecha", "admin", "POST", "asistencia.tomar", cuerpo={"fecha": "{{sab2}}", "presentes": []}, codigo=201),
        Paso("Eliminar la fecha completa", "admin", "DELETE", "asistencia.eliminarFecha", "fecha={{sab2}}"),
        Paso("Eliminar otra vez la fecha → 404", "admin", "DELETE", "asistencia.eliminarFecha", "fecha={{sab2}}", codigo=404),
        Paso("Tomar asistencia desde el público → 403", "pub", "POST", "asistencia.tomar", cuerpo={"fecha": "{{sab1}}", "presentes": []}, codigo=403),
    ]),
    ("07 pagos", "Mensualidades por semana de corte y matrícula.", [
        Paso("Registrar mensualidades", "admin", "POST", "pagos.registrar", cuerpo={"documento": "1000000150", "tipo": "mensualidad", "anio": N("anio"), "pagos": [{"mes": 10, "semana": 2}, {"mes": 11, "semana": 2}]},
             codigo=201, guardar={"id_pago": "r.datos[1].id"}, ejemplo_quien="administrativo"),
        Paso("Registrar matrícula", "admin", "POST", "pagos.registrar", cuerpo={"documento": "1000000150", "tipo": "matricula", "anio": N("anio")}, codigo=201),
        Paso("Registrar un mes ya pagado → 409", "admin", "POST", "pagos.registrar", cuerpo={"documento": "1000000150", "tipo": "mensualidad", "anio": N("anio"), "pagos": [{"mes": 10, "semana": 1}]}, codigo=409),
        Paso("Registrar con semana fuera de rango → 400", "admin", "POST", "pagos.registrar", cuerpo={"documento": "1000000150", "tipo": "mensualidad", "anio": N("anio"), "pagos": [{"mes": 12, "semana": 5}]}, codigo=400),
        Paso("Estado (administrativo)", "admin", "GET", "pagos.estado", "documento=1000000101&anio={{anio}}", ejemplo_quien="administrativo"),
        Paso("Estado (público)", "pub", "GET", "pagos.estado", ejemplo_quien="público"),
        Paso("Deudores", "admin", "GET", "pagos.deudores"),
        Paso("Deudores desde el público → 403", "pub", "GET", "pagos.deudores", codigo=403),
        Paso("Listar pagos", "admin", "GET", "pagos.listar", "documento=1000000150&anio={{anio}}"),
        Paso("Consultar pago", "admin", "GET", "pagos.consultar", "id={{id_pago}}"),
        Paso("Anular pago", "admin", "DELETE", "pagos.anular", "id={{id_pago}}"),
        Paso("Anular pago ya anulado → 404", "admin", "DELETE", "pagos.anular", "id={{id_pago}}", codigo=404),
    ]),
    ("08 calendario", "Eventos propios del calendario y vista con cumpleaños y vencimientos.", [
        Paso("Listar eventos (público)", "pub", "GET", "calendario.listar", "desde={{anio}}-04-01&hasta={{anio}}-06-30"),
        Paso("Consultar evento", "admin", "GET", "calendario.consultar", "id=3"),
        Paso("Consultar evento administrativo desde el público → 404", "pub", "GET", "calendario.consultar", "id=3", codigo=404),
        Paso("Crear evento", "admin", "POST", "calendario.crear", cuerpo={"nombre": "Clausura de fin de año", "tipo": "evento_extra", "fecha": "{{anio}}-12-12", "lugar": "Sede principal", "visibilidad": "publica",
             "observaciones": "Traer uniforme"}, codigo=201, guardar={"id_evento": "r.datos.id"}),
        Paso("Crear evento sin visibilidad → 400", "admin", "POST", "calendario.crear", cuerpo={"nombre": "Reunión de junta", "tipo": "reunion_administrativa", "fecha": "{{anio}}-11-20", "lugar": "Oficina"}, codigo=400),
        Paso("Actualizar evento", "admin", "PUT", "calendario.actualizar", "id={{id_evento}}", cuerpo={"fecha": "{{anio}}-12-13", "observaciones": "Traer uniforme completo"}),
        Paso("Actualizar evento de otro módulo → 409", "admin", "PUT", "calendario.actualizar", "id=1", cuerpo={"fecha": "{{anio}}-05-17"}, codigo=409),
        Paso("Vista (administrativo)", "admin", "GET", "calendario.vista", "desde={{anio}}-03-01&hasta={{anio}}-03-31", ejemplo_quien="administrativo"),
        Paso("Vista (público)", "pub", "GET", "calendario.vista", "desde={{anio}}-03-01&hasta={{anio}}-03-31", ejemplo_quien="público"),
        Paso("Vista de más de 366 días → 400", "admin", "GET", "calendario.vista", "desde={{anio_menos2}}-01-01&hasta={{anio}}-12-31", codigo=400),
        Paso("Importar CSV", "admin", "POST", "calendario.importar", multipart={"archivo": ("eventos.csv", "archivos/eventos_calendario.csv")}),
    ]),
    ("09 competencias (torneos)", "Crear torneo, inscribir, resultados y sugerencia de categoría.", [
        Paso("Listar torneos (administrativo)", "admin", "GET", "competencias.listar", ejemplo_quien="administrativo"),
        Paso("Listar torneos (público)", "pub", "GET", "competencias.listar", ejemplo_quien="público"),
        Paso("Consultar torneo (administrativo)", "admin", "GET", "competencias.consultar", "id=1", ejemplo_quien="administrativo"),
        Paso("Consultar torneo (público)", "pub", "GET", "competencias.consultar", "id=1", ejemplo_quien="público"),
        Paso("Crear torneo", "admin", "POST", "competencias.crear", cuerpo={"tipo_torneo": "departamental", "plataforma": "Fitofan",
             "evento": {"nombre": "Copa Departamental Ficticia", "fecha": "{{mas40}}", "lugar": "Coliseo de Soacha", "visibilidad": "publica", "observaciones": None}},
             codigo=201, guardar={"id_torneo": "r.datos.evento_id"}),
        Paso("Crear torneo sobre un evento que no es competencia → 422", "admin", "POST", "competencias.crear", cuerpo={"tipo_torneo": "nacional", "evento_id": 4}, codigo=422),
        Paso("Próximo torneo", "admin", "GET", "competencias.proximo"),
        Paso("Próximo torneo desde el público → 403", "pub", "GET", "competencias.proximo", codigo=403),
        Paso("Actualizar tipo de torneo", "admin", "PUT", "competencias.actualizar", "id={{id_torneo}}", cuerpo={"tipo_torneo": "nacional"}),
        Paso("Actualizar fecha, lugar y visibilidad", "admin", "PUT", "competencias.actualizar", "id={{id_torneo}}", cuerpo={"fecha": "{{mas45}}", "lugar": "Coliseo Municipal de Soacha", "visibilidad": "administrativa"}),
        Paso("Actualizar el nombre → 400", "admin", "PUT", "competencias.actualizar", "id={{id_torneo}}", cuerpo={"nombre": "Otro nombre"}, codigo=400),
        Paso("Sugerencia de categoría", "admin", "GET", "competencias.sugerencia", "deportista_documento=1000000150&competencia_evento_id={{id_torneo}}&modalidad=combate_individual"),
        Paso("Inscribir participantes", "admin", "POST", "competencias.inscribir", cuerpo={"competencia_evento_id": N("id_torneo"), "participantes": [
             {"deportista_documento": "1000000150", "modalidad": "combate_individual"}, {"deportista_documento": "1000000101", "modalidad": "formas"}]}, codigo=201),
        Paso("Inscribir repetido → 409", "admin", "POST", "competencias.inscribir", cuerpo={"competencia_evento_id": N("id_torneo"), "participantes": [
             {"deportista_documento": "1000000150", "modalidad": "combate_individual"}]}, codigo=409),
        Paso("Registrar resultado (torneo pasado)", "admin", "PUT", "competencias.actualizarParticipacion", "deportista_documento=1000000101&competencia_evento_id=1&modalidad=formas",
             cuerpo={"resultado": "Primer lugar en formas", "notas_oponente": "Sin oponente directo"}),
        Paso("Mover un torneo con resultados a fecha futura → 422", "admin", "PUT", "competencias.actualizar", "id=1", cuerpo={"fecha": "{{mas10}}"}, codigo=422),
        Paso("Puesto en un torneo futuro → 422", "admin", "PUT", "competencias.actualizarParticipacion", "deportista_documento=1000000150&competencia_evento_id={{id_torneo}}&modalidad=combate_individual", cuerpo={"puesto": 1}, codigo=422),
        Paso("Quitar participante", "admin", "DELETE", "competencias.quitarParticipante", "deportista_documento=1000000101&competencia_evento_id={{id_torneo}}&modalidad=formas"),
    ]),
    ("10 evaluaciones (exámenes de ascenso)", "Sesión de examen completa: crear, inscribir, calificar las 6 modalidades, cerrar, entrega de cinturón, reabrir.", [
        Paso("Candidatos", "admin", "GET", "evaluaciones.candidatos", "fecha={{menos3}}"),
        Paso("Crear sesión de examen", "admin", "POST", "evaluaciones.crear", cuerpo={"fecha": "{{menos3}}", "fecha_grado": "{{hoy}}", "lugar": "Sede principal", "participantes": [{"deportista_documento": "1000000150"}]},
             codigo=201, guardar={"id_sesion": "r.datos.id"}),
        Paso("Inscribir en la sesión", "admin", "POST", "evaluaciones.inscribir", "sesion_id={{id_sesion}}", cuerpo={"participantes": [{"deportista_documento": "1000000103", "grado_evaluado": "Verde"}]}, codigo=201),
        Paso("Actualizar participante", "admin", "PUT", "evaluaciones.actualizarParticipante", "sesion_id={{id_sesion}}&deportista_documento=1000000103", cuerpo={"grado_evaluado": "Azul"}),
        Paso("Quitar participante", "admin", "DELETE", "evaluaciones.quitarParticipante", "sesion_id={{id_sesion}}&deportista_documento=1000000103"),
        Paso("Actualizar sesión", "admin", "PUT", "evaluaciones.actualizar", "id={{id_sesion}}", cuerpo={"lugar": "Coliseo municipal"}),
        Paso("Cerrar con modalidades pendientes → 409", "admin", "PUT", "evaluaciones.cerrar", "id={{id_sesion}}", codigo=409),
    ] + [
        Paso(f"Calificar {m}", "admin", "PUT", "evaluaciones.calificar", f"sesion_id={{{{id_sesion}}}}&deportista_documento=1000000150&modalidad={m}",
             cuerpo={"nota": NOTAS[m]} | ({"valor_medible": 42, "observaciones": "Buena resistencia", "recomendaciones": "Trabajar flexibilidad"} if m == "fisico" else {}))
        for m in CAL
    ] + [
        Paso("Calificar con nota 11 → 400", "admin", "PUT", "evaluaciones.calificar", "sesion_id={{id_sesion}}&deportista_documento=1000000150&modalidad=teorico", cuerpo={"nota": 11}, codigo=400),
        Paso("Listar sesiones (administrativo)", "admin", "GET", "evaluaciones.listar", ejemplo_quien="administrativo"),
        Paso("Listar (público)", "pub", "GET", "evaluaciones.listar", ejemplo_quien="público"),
        Paso("Cerrar sesión de examen", "admin", "PUT", "evaluaciones.cerrar", "id={{id_sesion}}"),
        Paso("Consultar sesión", "admin", "GET", "evaluaciones.consultar", "id={{id_sesion}}"),
        Paso("Confirmar entrega de cinturón", "admin", "PUT", "evaluaciones.confirmarEntregaCinturon", "sesion_id={{id_sesion}}&deportista_documento=1000000150", cuerpo={}),
        Paso("Confirmar otra vez → 409", "admin", "PUT", "evaluaciones.confirmarEntregaCinturon", "sesion_id={{id_sesion}}&deportista_documento=1000000150", cuerpo={}, codigo=409),
        Paso("Reabrir sesión", "admin", "PUT", "evaluaciones.reabrir", "id={{id_sesion}}"),
        Paso("Cerrar de nuevo", "admin", "PUT", "evaluaciones.cerrar", "id={{id_sesion}}"),
        Paso("Crear sesión vacía", "admin", "POST", "evaluaciones.crear", cuerpo={"fecha": "{{mas20}}", "fecha_grado": "{{mas27}}", "lugar": "Sede principal"}, codigo=201, guardar={"id_sesion_vacia": "r.datos.id"}),
        Paso("Eliminar sesión vacía", "admin", "DELETE", "evaluaciones.eliminar", "id={{id_sesion_vacia}}"),
        Paso("Eliminar sesión con participantes → 409", "admin", "DELETE", "evaluaciones.eliminar", "id={{id_sesion}}", codigo=409),
    ]),
    ("11 historial", "Pestaña «Registros».", [
        Paso("Torneos (administrativo)", "admin", "GET", "historial.torneos", ejemplo_quien="administrativo"),
        Paso("Torneos (público)", "pub", "GET", "historial.torneos", ejemplo_quien="público"),
        Paso("Ascensos", "admin", "GET", "historial.ascensos", "deportista=1000000150"),
        Paso("Permanencia", "admin", "GET", "historial.permanencia"),
        Paso("Rango de fechas invertido → 400", "admin", "GET", "historial.torneos", "desde=2026-05-01&hasta=2026-04-01", codigo=400),
    ]),
    ("12 estadisticas", "Pestaña «Gráficas».", [
        Paso("Estadísticas del deportista (administrativo)", "admin", "GET", "estadisticas.deportista", "deportista=1000000150", ejemplo_quien="administrativo"),
        Paso("Estadísticas del deportista (público)", "pub", "GET", "estadisticas.deportista", ejemplo_quien="público"),
        Paso("Estadísticas de torneo", "admin", "GET", "estadisticas.torneo", "id=1"),
        Paso("Estadísticas de torneo desde el público → 403", "pub", "GET", "estadisticas.torneo", "id=1", codigo=403),
    ]),
    ("13 reportes (descargas)", "Responden el archivo (PDF o Excel), no JSON (A.8).", [
        Paso("Ficha del deportista (PDF)", "admin", "GET", "reportes.deportistaFicha", "deportista=1000000150"),
        Paso("Deportistas activos (Excel)", "admin", "GET", "reportes.deportistas", "estado=activo"),
        Paso("Planilla de asistencia (Excel)", "admin", "GET", "reportes.asistencia", "desde={{anio}}-01-01&hasta={{hoy}}"),
        Paso("Planilla de más de 366 días → 422", "admin", "GET", "reportes.asistencia", "desde={{anio_menos2}}-01-01&hasta={{hoy}}", codigo=422),
        Paso("Pagos del año (Excel)", "admin", "GET", "reportes.pagos", "anio={{anio}}"),
        Paso("Torneos (Excel)", "admin", "GET", "reportes.torneos"),
        Paso("Sesión de examen (PDF)", "admin", "GET", "reportes.sesionExamen", "id={{id_sesion}}"),
        Paso("Reporte desde el público → 403", "pub", "GET", "reportes.deportistas", codigo=403),
        Paso("Reporte sin sesión → 401", "anon", "GET", "reportes.deportistas", codigo=401),
    ]),
    ("14 importacion-deportistas (carga masiva CSV)", "Previsualizar no guarda nada; aplicar crea y actualiza solo lo confirmado.", [
        Paso("Previsualizar CSV", "admin", "POST", "importacion-deportistas.previsualizar", multipart={"archivo": ("carga.csv", "archivos/carga_deportistas.csv")}),
        Paso("Aplicar CSV (confirmando la actualización)", "admin", "POST", "importacion-deportistas.aplicar", multipart={"confirmar_actualizacion": "1000000103", "archivo": ("carga.csv", "archivos/carga_deportistas.csv")}),
        Paso("Previsualizar CSV sin columnas obligatorias → 400", "admin", "POST", "importacion-deportistas.previsualizar", multipart={"archivo": ("vacio.csv", "archivos/carga_incompleta.csv")}, codigo=400),
        Paso("Previsualizar desde el público → 403", "pub", "POST", "importacion-deportistas.previsualizar", multipart={"archivo": ("carga.csv", "archivos/carga_deportistas.csv")}, codigo=403),
    ]),
    ("15 errores comunes, forma por ruta y CSRF", "Errores de A.1/A.4/A.5 y equivalencia de la forma por ruta (…/Public/<recurso>/<accion>).", [
        Paso("Método distinto → 405", "admin", "POST", "deportistas.listar", codigo=405),
        Paso("Acción inexistente → 404", "admin", "GET", "deportistas.noExiste", codigo=404, pruebas=["pm.expect(r.mensaje).to.eql('Acción no reconocida: noExiste');"]),
        Paso("Recurso inexistente → 404", "admin", "GET", "inventado.listar", codigo=404, pruebas=["pm.expect(r.mensaje).to.eql('Recurso no reconocido: inventado');"]),
        Paso("Falta la acción → 400", "admin", "GET", None, "recurso=deportistas", codigo=400, ruta="{{base_url}}/api.php?recurso=deportistas",
             pruebas=["pm.expect(r.mensaje).to.eql('Falta el parámetro \"accion\".');"]),
        Paso("Parámetro en lista → 400", "admin", "GET", "deportistas.consultar", "documento[]=1", codigo=400),
        Paso("Cuerpo que no es JSON → 415", "admin", "POST", "calendario.crear", crudo=("text/plain", '{"nombre":"x"}'), codigo=415),
        Paso("JSON mal formado → 400", "admin", "POST", "calendario.crear", crudo=("application/json", '{"nombre":'), codigo=400,
             pruebas=["pm.expect(r.mensaje).to.eql('Cuerpo de la solicitud inválido: se esperaba JSON.');"]),
        Paso("CSRF: Origin ajeno → 403", "admin", "POST", "auth.cerrarSesion", cabeceras={"Origin": "http://sitio-ajeno.example"}, codigo=403,
             pruebas=["pm.expect(r.mensaje).to.eql('Solicitud rechazada: origen no permitido.');"]),
        Paso("CSRF: Sec-Fetch-Site cross-site → 403", "admin", "POST", "auth.cerrarSesion", cabeceras={"Sec-Fetch-Site": "cross-site"}, codigo=403,
             pruebas=["pm.expect(r.mensaje).to.eql('Solicitud rechazada: viene de otro sitio.');"]),
        Paso("Ruta limpia: deportistas/consultar (equivale a la forma oficial)", "admin", "GET", "deportistas.consultar", "documento=1000000101", ruta="{{base_url}}/deportistas/consultar?documento=1000000101",
             ejemplo_quien="administrativo", pruebas=["pm.expect(r.datos.documento).to.eql('1000000101');"]),
        Paso("Ruta limpia: api.php/deportistas/consultar", "admin", "GET", "deportistas.consultar", "documento=1000000101", ruta="{{base_url}}/api.php/deportistas/consultar?documento=1000000101", ejemplo_quien="administrativo"),
        Paso("Ruta limpia sin acción → 404", "admin", "GET", None, codigo=404, ruta="{{base_url}}/deportistas",
             pruebas=["pm.expect(r.mensaje).to.eql('Acción no reconocida: falta la acción en la ruta (recurso/accion).');"]),
        Paso("Ruta limpia con segmentos de más → 404", "admin", "GET", None, codigo=404, ruta="{{base_url}}/deportistas/consultar/extra",
             pruebas=["pm.expect(r.mensaje).to.eql('Acción no reconocida: consultar/extra');"]),
    ]),
    ("16 al final: acciones que cambian estados", "Van al final porque cambian el estado de deportistas (como en capturar.php).", [
        Paso("Revisar inactivos", "admin", "POST", "asistencia.revisarInactivos"),
    ]),
    ("17 cierre de sesión y sesión vencida (401)", "Cerrar sesión deja la cookie sin valor: la siguiente petición responde 401. "
     "Una sesión invalidada por el cambio de contraseña hecho por la cuenta principal también.", [
        Paso("Cuenta principal restablece la contraseña del staff", "admin", "PUT", "usuarios-administrativos.restablecerContrasena", "correo=administrativo1@hwanghon.local", cuerpo={"contrasena_nueva": "ClaveNueva2026"}),
        Paso("La sesión abierta del staff cae → 401", "staff", "GET", "deportistas.listar", codigo=401, pruebas=["pm.expect(r.mensaje).to.eql('Tu sesión ya no es válida.');"]),
        Paso("Cerrar sesión (público)", "pub", "POST", "auth.cerrarSesion"),
        Paso("Tras cerrar, el público ya no tiene sesión → 401", "pub", "GET", "auth.sesionActual", codigo=401),
        Paso("Cerrar sesión (administrativo)", "admin", "POST", "auth.cerrarSesion"),
        Paso("Tras cerrar, el administrativo recibe 401 en una ruta autenticada", "admin", "GET", "deportistas.listar", codigo=401,
             pruebas=["pm.expect(r.mensaje).to.eql('No autenticado. Inicie sesión para continuar.');"]),
        Paso("Cerrar sesión sin sesión (idempotente)", "anon", "POST", "auth.cerrarSesion"),
    ]),
]

# --------------------------------------------------------------------------- scripts comunes

PRE_COLECCION = r"""
// 1) Fechas relativas en hora de Bogotá (UTC-5, sin horario de verano), como capturar.php.
const DIA = 86400000;
const hoyBog = new Date(Date.now() - 5 * 3600000); hoyBog.setUTCHours(0, 0, 0, 0);
const f = d => d.toISOString().slice(0, 10);
const mas = n => f(new Date(hoyBog.getTime() + n * DIA));
let sab = new Date(hoyBog.getTime() - DIA);
while (sab.getUTCDay() !== 6) sab = new Date(sab.getTime() - DIA);
const dow = hoyBog.getUTCDay();               // lunes de esta semana (nunca sábado)
const lunes = new Date(hoyBog.getTime() - ((dow + 6) % 7) * DIA);
const anio = hoyBog.getUTCFullYear();
Object.entries({hoy: f(hoyBog), anio: String(anio), anio_menos2: String(anio - 2), sab1: f(sab), sab2: f(new Date(sab.getTime() - 7 * DIA)),
  lunes: f(lunes), menos3: mas(-3), mas10: mas(10), mas20: mas(20), mas27: mas(27), mas40: mas(40), mas45: mas(45)})
  .forEach(([k, v]) => pm.collectionVariables.set(k, v));

// 2) Sesión por ámbito: la cabecera X-Sesion-Prueba dice qué cookie enviar (ses_admin, ses_staff, ses_pub, ses_reg)
//    y se quita antes de enviar. Sin esa cabecera ("sin sesión") no va ninguna cookie.
const marca = pm.request.headers.get('X-Sesion-Prueba');
pm.request.headers.remove('X-Sesion-Prueba');
pm.request.headers.remove('Cookie');
pm.variables.set('_sesion', marca || '');
if (marca) {
  const valor = pm.collectionVariables.get(marca);
  if (valor) pm.request.headers.add({key: 'Cookie', value: 'HWANGHON_SESION=' + valor});
}

// 3) Forma de la URL: forma_url = "consulta" (oficial, api.php?recurso=…&accion=…) o "ruta" (…/Public/<recurso>/<accion>).
if ((pm.variables.get('forma_url') || 'consulta') === 'ruta') {
  const q = pm.request.url.query;
  const rec = q.find(p => p.key === 'recurso'), acc = q.find(p => p.key === 'accion');
  const ruta = pm.request.url.path || [];
  if (rec && acc && ruta.length && ruta[ruta.length - 1] === 'api.php') {
    pm.request.url.path = ruta.slice(0, -1).concat([encodeURIComponent(rec.value), encodeURIComponent(acc.value)]);
    q.remove('recurso'); q.remove('accion');
  }
}
"""

TEST_COLECCION = r"""
// Guarda la cookie nueva (inicio de sesión, renovación) o la borra (cierre) en la variable de esa sesión.
const usada = pm.variables.get('_sesion');
if (usada) {
  pm.response.headers.each(h => {
    if (h.key.toLowerCase() !== 'set-cookie') return;
    const m = /^HWANGHON_SESION=([^;]*)/.exec(h.value);
    if (!m) return;
    const borrada = m[1] === '' || m[1] === 'deleted' || /expires=Thu, 01[- ]Jan[- ]1970/i.test(h.value) || /Max-Age=0\b/i.test(h.value);
    pm.collectionVariables.set(usada, borrada ? '' : m[1]);
  });
}
// Nunca un 500 ni detalles técnicos.
pm.test('Sin error interno (500)', () => pm.expect(pm.response.code).to.not.eql(500));
"""


def js(valor) -> str:
    return json.dumps(valor, ensure_ascii=False)


def forma_de(datos):
    """Forma mínima de `datos` según el ejemplo del catálogo."""
    if isinstance(datos, dict):
        return {"tipo": "objeto", "claves": list(datos.keys())}
    if isinstance(datos, list):
        primero = next((x for x in datos if isinstance(x, dict)), None)
        return {"tipo": "lista", "claves": list(primero.keys()) if primero else []}
    if datos is None:
        return {"tipo": "nulo"}
    return {"tipo": type(datos).__name__}


ARCHIVO_EXT = {"reportes.deportistaFicha": "application/pdf", "reportes.sesionExamen": "spreadsheetml",
               "reportes.deportistas": "spreadsheetml", "reportes.asistencia": "spreadsheetml", "reportes.pagos": "spreadsheetml",
               "reportes.torneos": "spreadsheetml", "documentos.descargar": "application/pdf", "documentos.descargarGeneral": "application/pdf"}


def pruebas_de(paso: Paso, fichas: dict) -> list[str]:
    lineas = [f"pm.test({js('HTTP ' + str(paso.codigo))}, () => pm.response.to.have.status({paso.codigo}));"]
    ficha = fichas.get(paso.accion or "", {"ejemplos": [], "errores": []})
    es_archivo = paso.accion in ARCHIVO_EXT and paso.codigo < 300
    if es_archivo:
        lineas += [
            f"pm.test('Archivo: Content-Type esperado', () => pm.expect(pm.response.headers.get('Content-Type') || '').to.include({js(ARCHIVO_EXT[paso.accion])}));",
            "pm.test('Archivo: Content-Disposition attachment con nombre', () => pm.expect(pm.response.headers.get('Content-Disposition') || '').to.match(/^attachment; filename=\"[^\"]+\"; filename\\*=UTF-8''/));",
            "pm.test('Archivo: X-Content-Type-Options nosniff', () => pm.expect(pm.response.headers.get('X-Content-Type-Options')).to.eql('nosniff'));",
            "pm.test('Archivo: no vacío', () => pm.expect(pm.response.responseSize).to.be.above(0));",
        ]
        return lineas
    lineas.append("let r = {}; try { r = pm.response.json(); } catch (e) { r = {}; }")
    lineas.append("pm.test('Respuesta JSON del formato estándar', () => { pm.expect(pm.response.headers.get('Content-Type') || '').to.include('application/json'); pm.expect(r).to.have.property('exito'); });")
    if paso.codigo < 300:
        lineas.append("pm.test('exito = true con datos', () => { pm.expect(r.exito).to.eql(true); pm.expect(r).to.have.property('datos'); });")
        candidatos = [e for e in ficha["ejemplos"] if e["codigo"] == paso.codigo and not e["archivo"]]
        if paso.ejemplo_quien:
            candidatos = [e for e in candidatos if e["quien"] == paso.ejemplo_quien] or candidatos
        mios = {kv.split("=", 1)[0] for kv in paso.consulta.split("&") if kv}
        candidatos.sort(key=lambda e: -len(mios ^ ({kv.split("=", 1)[0] for kv in e["consulta"].split("&")} - {"recurso", "accion"})))
        candidatos.reverse()
        if candidatos:
            forma = forma_de(candidatos[0]["respuesta"].get("datos"))
            if forma["tipo"] in ("objeto", "lista"):
                forma["claves"] = [c for c in forma["claves"] if c not in paso.sin_claves]
            for c in paso.sin_claves:
                lineas.append(f"pm.test({js('El campo ' + c + ' no se expone en este ámbito')}, () => pm.expect(r.datos).to.not.have.property({js(c)}));")
            if forma["tipo"] == "objeto":
                lineas.append(f"pm.test('Forma de datos según el catálogo (objeto con {len(forma['claves'])} campos)', () => {{ pm.expect(r.datos).to.be.an('object'); "
                              f"pm.expect(r.datos).to.include.all.keys({js(forma['claves'])}); }});")
            elif forma["tipo"] == "lista":
                lineas.append("pm.test('Forma de datos según el catálogo (lista)', () => { pm.expect(r.datos).to.be.an('array'); "
                              + (f"if (r.datos.length && typeof r.datos[0] === 'object') pm.expect(r.datos[0]).to.include.all.keys({js(forma['claves'])}); " if forma["claves"] else "")
                              + "});")
            elif forma["tipo"] == "nulo":
                lineas.append("pm.test('datos puede ser null según el catálogo', () => pm.expect(r).to.have.property('datos'));")
    else:
        lineas.append("pm.test('exito = false con mensaje en español', () => { pm.expect(r.exito).to.eql(false); pm.expect(r.mensaje).to.be.a('string').and.not.be.empty; });")
        if paso.codigo != 400:  # los 400 de validación genérica no se enumeran en el catálogo
            documentados = sorted({mensaje_a_regex(m) for c, m in ficha["errores"] if c == paso.codigo} | {mensaje_a_regex(m) for m in MENSAJES_COMUNES}
                                  | {mensaje_a_regex(m) for m in MENSAJES_CATALOGO})
            lineas.append(f"const documentados = {js(documentados)};")
            lineas.append("pm.test('Mensaje documentado en el catálogo', () => pm.expect(documentados.some(x => new RegExp(x).test(r.mensaje)), "
                          "'Mensaje no documentado: ' + r.mensaje).to.eql(true));")
    for var, expr in paso.guardar.items():
        lineas.append(f"try {{ pm.collectionVariables.set({js(var)}, String({expr})); }} catch (e) {{ pm.collectionVariables.set({js(var)}, ''); }}")
    for p in paso.pruebas:
        lineas.append(f"pm.test({js('Comprobación propia: ' + p[:60])}, () => {{ {p} }});")
    return lineas


def cuerpo_json(cuerpo) -> str:
    texto = json.dumps(cuerpo, ensure_ascii=False, indent=2)
    return re.sub(r'"@@(\w+)@@"', r"{{\1}}", texto)


def peticion(paso: Paso, fichas: dict) -> dict:
    cabeceras = []
    if paso.ses != "anon":
        cabeceras.append({"key": "X-Sesion-Prueba", "value": "ses_" + paso.ses, "description": f"Sesión usada: {SESIONES[paso.ses]} (la quita el script de la colección)"})
    for k, v in paso.cabeceras.items():
        cabeceras.append({"key": k, "value": v})
    if paso.ruta:
        crudo = paso.ruta
    elif paso.accion:
        recurso, accion = paso.accion.split(".")
        crudo = "{{base_url}}/api.php?recurso=" + recurso + "&accion=" + accion + ("&" + paso.consulta if paso.consulta else "")
    else:
        crudo = "{{base_url}}/api.php" + ("?" + paso.consulta if paso.consulta else "")
    base, _, consulta = crudo.partition("?")
    url = {"raw": crudo, "host": ["{{base_url}}"], "path": [s for s in base.replace("{{base_url}}", "").split("/") if s]}
    if consulta:
        url["query"] = [{"key": kv.split("=", 1)[0], "value": kv.split("=", 1)[1] if "=" in kv else ""} for kv in consulta.split("&")]
    req: dict = {"method": paso.metodo, "header": cabeceras, "url": url}
    if paso.multipart:
        datos = []
        for k, v in paso.multipart.items():
            if isinstance(v, tuple):
                datos.append({"key": k, "type": "file", "src": v[1], "description": f"Nombre sugerido: {v[0]}"})
            else:
                datos.append({"key": k, "type": "text", "value": v})
        req["body"] = {"mode": "formdata", "formdata": datos}
    elif paso.crudo:
        cabeceras.append({"key": "Content-Type", "value": paso.crudo[0]})
        req["body"] = {"mode": "raw", "raw": paso.crudo[1]}
    elif paso.cuerpo is not None:
        cabeceras.append({"key": "Content-Type", "value": "application/json"})
        req["body"] = {"mode": "raw", "raw": cuerpo_json(paso.cuerpo), "options": {"raw": {"language": "json"}}}
    ficha = fichas.get(paso.accion or "")
    req["description"] = (f"{paso.accion} — {ficha['cabecera']}\n\n" if ficha else "") + f"Sesión: {SESIONES[paso.ses]}. Código esperado: {paso.codigo}.\n\n{paso.descripcion}".strip()
    return {
        "name": paso.nombre,
        "event": [{"listen": "test", "script": {"type": "text/javascript", "exec": pruebas_de(paso, fichas)}}],
        "protocolProfileBehavior": {"disableCookies": True},
        "request": req,
    }


def main() -> None:
    acciones, fichas = leer_catalogo()
    MENSAJES_CATALOGO.update(m for m in guillemets(CATALOGO.read_text(encoding="utf-8")) if len(m) > 12)
    assert len(acciones) == 95, f"El catálogo debería listar 95 acciones (lee {len(acciones)})"
    cubiertas = {p.accion for _, _, pasos in CARPETAS for p in pasos if p.accion}
    faltan = [f"{a['recurso']}.{a['accion']}" for a in acciones if f"{a['recurso']}.{a['accion']}" not in cubiertas]
    if faltan:
        raise SystemExit(f"Acciones del catálogo sin petición en la colección: {faltan}")
    metodos = {f"{a['recurso']}.{a['accion']}": a["metodo"] for a in acciones}
    for _, _, pasos in CARPETAS:
        for p in pasos:
            if p.accion in metodos and p.codigo != 405 and p.metodo != metodos[p.accion]:
                raise SystemExit(f"Método distinto al del catálogo en «{p.nombre}»: {p.metodo} vs {metodos[p.accion]}")
    total = sum(len(p) for _, _, p in CARPETAS)
    coleccion = {
        "info": {
            "_postman_id": str(uuid.uuid5(uuid.NAMESPACE_URL, "hwanghon-sports-system/api")),
            "name": "HwangHon-Sports-System API (95 acciones)",
            "description": (
                "Colección generada desde documentacion/api/catalogo_api.md por pruebas/postman/generar_coleccion.py — no editar a mano.\n\n"
                f"{total} peticiones que cubren las 95 acciones de los 14 recursos, en el orden de capturar.php (las llamadas reales del catálogo), "
                "más casos de error 400/401/403/404/405/409/415/422/429, CSRF y forma por ruta.\n\n"
                "Variables: base_url (…/backend/Public), forma_url (consulta | ruta), correo_admin (cuenta principal: maestro@ por defecto), clave_prueba (contraseña de las cuentas ficticias), "
                "token_recuperacion (fixture que deja ejecutar_newman.py).\n\n"
                "Requiere la base APARTE recién restablecida (python pruebas/herramientas/entorno.py restablecer). Datos 100 % ficticios."),
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "event": [
            {"listen": "prerequest", "script": {"type": "text/javascript", "exec": PRE_COLECCION.strip().split("\n")}},
            {"listen": "test", "script": {"type": "text/javascript", "exec": TEST_COLECCION.strip().split("\n")}},
        ],
        "variable": [
            {"key": "base_url", "value": "http://127.0.0.1:8095/backend/Public"},
            {"key": "forma_url", "value": "consulta", "description": "consulta = api.php?recurso=…&accion=… (oficial) · ruta = …/Public/<recurso>/<accion>"},
            {"key": "correo_admin", "value": "maestro@hwanghon.local", "description": "Cuenta PRINCIPAL que usa ses_admin (maestro@ o desarrollador@)"},
            {"key": "clave_prueba", "value": "12345678", "description": "Contraseña de las cuentas FICTICIAS de cuentas_prueba_locales.sql"},
            {"key": "token_recuperacion", "value": "7" * 64, "description": "Token ficticio que ejecutar_newman.py deja en la base aparte"},
        ] + [{"key": f"ses_{s}", "value": ""} for s in ("admin", "staff", "pub", "reg")],
        "item": [{"name": nombre, "description": desc, "item": [peticion(p, fichas) for p in pasos]} for nombre, desc, pasos in CARPETAS],
    }
    (SALIDA / "HwangHon_API.postman_collection.json").write_text(json.dumps(coleccion, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    for nombre, url, desc in (("entorno_aislado", "http://127.0.0.1:8095/backend/Public", "Servidor aislado (entorno.py) con la base hwanghon_pruebas. RECOMENDADO."),
                              ("entorno_xampp", "http://localhost/HwangHon-Sports-System/backend/Public", "Apache de XAMPP: usa la base 'hwanghon' de John. NO correr la colección completa aquí: la modifica.")):
        entorno = {"id": str(uuid.uuid5(uuid.NAMESPACE_URL, nombre)), "name": f"HwangHon — {nombre}", "_postman_variable_scope": "environment",
                   "values": [{"key": "base_url", "value": url, "enabled": True}, {"key": "forma_url", "value": "consulta", "enabled": True}],
                   "_descripcion": desc}
        (SALIDA / f"{nombre}.postman_environment.json").write_text(json.dumps(entorno, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Colección: {total} peticiones en {len(CARPETAS)} carpetas; 95/95 acciones del catálogo cubiertas.")


if __name__ == "__main__":
    main()
