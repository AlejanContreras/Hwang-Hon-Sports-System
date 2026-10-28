# Catálogo de la API — HwangHon Sports System

Guía para escribir el front **sin leer el backend**. Documenta lo que existe hoy (95 acciones en 14 recursos); no propone cambios.

- **Ejemplos reales.** Cada ejemplo de petición y respuesta se copió de una llamada real hecha el 26/10/2026 contra la base cargada con `base_datos/esquema_hwanghon_v4.sql`, `insercion_datos_prueba.sql` y `cuentas_prueba_locales.sql` (datos ficticios). Las fechas de los ejemplos dependen del día en que se capturaron: «hoy» era 2026-10-26.
- **Listas recortadas.** Si una lista tiene más de 3 elementos, el ejemplo muestra 2 y una línea `"… (N elementos más, omitidos en este ejemplo)"`. Esa línea no la envía la API.
- **Quién llama.** Cada ficha dice qué sesión hace falta: **sin sesión**, **público** (cuenta de un deportista), **administrativo** (cualquier cuenta del club), **cuenta principal** (administrativo con `es_administrador_principal = true`) o **permiso de autorregistro** (el permiso temporal que abre `autorregistro.registrar`).

---

## Parte A — Generalidades

### A.1 URL y forma de la ruta

- **Punto de entrada único:** `…/backend/Public/api.php`. En XAMPP local: `http://localhost/HwangHon-Sports-System/backend/Public/api.php`.
- **Forma:** `api.php?recurso=<recurso>&accion=<accion>[&otros parámetros]`. Ejemplo: `api.php?recurso=deportistas&accion=consultar&documento=1000000101`.
- **Método HTTP:** cada acción exige el suyo (GET para leer, POST para crear o enviar, PUT para cambiar, DELETE para borrar). Con otro método responde `405` «Método no permitido. Se esperaba GET.».
- **Comprobación de salud:** `GET api.php` sin parámetros responde `{"exito":true,"datos":{"mensaje":"HwangHon-Sports-System API activa."}}`.
- **Errores de ruta:**
  - sin `accion`: `400` «Falta el parámetro "accion".»;
  - recurso que no existe: `404` «Recurso no reconocido: x»;
  - acción que no existe: `404` «Acción no reconocida: x».
- **Parámetros de la URL:** siempre son texto simple. Una lista (`?documento[]=1`) o un texto con UTF-8 inválido responde `400`. Los números van como texto (`id=3`).
- **Dónde van los datos:**
  - lecturas y filtros: en la URL;
  - altas y cambios: cuerpo JSON (`Content-Type: application/json`);
  - subida de archivos: `multipart/form-data`.

### A.2 Formato JSON

- **Éxito:** `{"exito": true, "datos": …}`. `datos` puede ser un objeto, una lista o `null` (ej. `competencias.proximo` sin torneos próximos).
- **Error:** `{"exito": false, "mensaje": "…"}`. El mensaje está en español y se puede mostrar tal cual al usuario.
- **Nombres:** siempre `snake_case` en español, tanto en lo que entra como en lo que sale (`fecha_nacimiento`, `es_administrador_principal`).
- **Fechas:** `AAAA-MM-DD` estricto. `2026-2-3`, `03/02/2026`, un salto de línea al final o un byte de control responden `400`. La única excepción son los CSV, que también aceptan `DD/MM/AAAA`.
- **Fecha y hora:** solo `autorregistro` devuelve una (`vigente_hasta`), en ISO 8601 con zona: `2026-10-26T11:20:51-05:00`.
- **Zona horaria:** todo «hoy», «futuro», «vencido» o «sábado» se calcula con la hora de **America/Bogota**, no con la del navegador ni la del servidor.
- **Números:** decimales como número JSON. Un `5.0` sale como `5`; el front no debe depender de que traiga decimales.
- **Booleanos y nulos:** booleanos JSON reales (`true`/`false`). `null` significa «sin dato»; en las acciones de actualización parcial, enviar `null` en un campo opcional lo borra.
- **Campos desconocidos:** en altas y actualizaciones, un campo que la acción no reconoce responde `400` nombrándolo (ej. «Campos no reconocidos: x.»). No se ignoran en silencio.

### A.3 Autenticación y sesión

- **Dos ámbitos:**
  - **administrativo:** cuentas del club, con correo y contraseña. Acceso a todo; solo las cuentas principales gestionan cuentas.
  - **público:** el deportista o su familia entra con el **documento y la fecha de nacimiento del deportista**. Solo ve lo suyo y edita peso, condición de salud, teléfono, dirección y correo.
- **Iniciar sesión:** `auth.iniciarSesionAdministrativa` o `auth.iniciarSesionPublica`. La respuesta trae la cookie **`HWANGHON_SESION`**: `HttpOnly` (el JavaScript no la lee), `SameSite=Lax`, `Path=/`, y `Secure` cuando el sitio va por HTTPS. El navegador la envía sola; el front no la maneja.
- **Al recargar la página:** `auth.sesionActual` dice si hay sesión, de qué ámbito y de quién (`401` = no hay sesión).
- **Cerrar sesión:** `auth.cerrarSesion`.
- **Vigencia por inactividad:** 2 horas en el ámbito administrativo y 30 minutos en el público. Cada petición renueva el plazo. Al vencer, cualquier ruta autenticada responde `401` «No autenticado. Inicie sesión para continuar.»: el front debe volver a la pantalla de ingreso.
- **Bloqueo por intentos:** 4 fallos seguidos con el mismo correo o documento bloquean 15 minutos (`429` «Demasiados intentos fallidos. Intenta de nuevo en N minutos.»). Durante el bloqueo ni la clave correcta entra.
- **Sesiones que caen solas:** la siguiente petición responde `401` «Tu sesión ya no es válida.» cuando:
  - la cuenta administrativa se desactiva;
  - la contraseña cambia por cualquier vía (`auth.cambiarContrasena`, `usuarios-administrativos.restablecerContrasena`, recuperación por correo), porque la sesión guarda una huella de la contraseña. Quien cambia su propia contraseña conserva su sesión: se renueva con la huella nueva. Las demás sesiones de esa cuenta caen.
- **Registro pendiente:** con datos correctos, un deportista pendiente de validación recibe `403` «Tu registro está en revisión.» y no entra.
- **Permiso de autorregistro:** `autorregistro.registrar` abre, en la misma cookie, un permiso temporal de **2 horas** para cargar los documentos de ese registro. No es una cuenta: cualquier otra ruta autenticada responde `401`.

### A.4 Reglas de origen (CSRF) y tipo de contenido

- **El front debe servirse desde el MISMO origen que la API** (mismo esquema, host y puerto; p. ej., ambos desde `http://localhost/HwangHon-Sports-System/`). La API no envía cabeceras CORS, así que un front en otro origen no puede leer las respuestas ni usar la cookie.
- **Toda petición que no sea GET** pasa estas comprobaciones antes de la acción:
  - si el navegador envía `Sec-Fetch-Site: cross-site` o `same-site`: `403` «Solicitud rechazada: viene de otro sitio.»;
  - si llega `Origin` y su `host:puerto` no es el `Host` de la petición ni está en la lista blanca `ORIGENES_PERMITIDOS` (en `backend/Src/Config/seguridad.php` o en la variable de entorno del mismo nombre, vacía por defecto): `403` «Solicitud rechazada: origen no permitido.»;
  - si no llega ninguna de las dos cabeceras (curl, scripts), se acepta.
- **Las lecturas (GET) no se bloquean.**
- **Cuerpo JSON:** debe ir con `Content-Type: application/json` (se acepta `; charset=utf-8`). Otro tipo responde `415` «Tipo de contenido no soportado: envía el cuerpo como application/json.». Las subidas `multipart/form-data` no pasan por el 415, pero sí por las comprobaciones de origen.
- **Con `fetch`:** el valor por defecto `credentials: 'same-origin'` basta para que viaje la cookie.

### A.5 Códigos HTTP

| Código | Cuándo sale | Mensaje (ejemplos reales) |
|---|---|---|
| 200 | Éxito de una lectura, una actualización o una acción. | — |
| 201 | Éxito de un alta (crear, registrar, subir, inscribir, tomar asistencia). | — |
| 400 | Dato inválido: falta un campo, formato o rango incorrecto, campo no reconocido, parámetro en lista o con UTF-8 inválido, JSON mal formado. | «El campo "fecha_nacimiento" es obligatorio.», «Cuerpo de la solicitud inválido: se esperaba JSON.» |
| 401 | Sin sesión, sesión vencida o invalidada, credenciales erradas. | «No autenticado. Inicie sesión para continuar.», «Tu sesión ya no es válida.», «Correo o contraseña incorrectos.» |
| 403 | El ámbito no tiene permiso, registro pendiente, regla CSRF, permiso de autorregistro ausente o vencido. | «Acceso restringido al ámbito administrativo.», «Solo las cuentas principales pueden gestionar las cuentas administrativas.» |
| 404 | No existe, o no es visible para esa sesión (el público nunca sabe si existe algo ajeno). | «No encontrado.», «Deportista no encontrado.» |
| 405 | Método HTTP distinto del que exige la acción. | «Método no permitido. Se esperaba POST.» |
| 409 | Conflicto con el estado actual: duplicado, ya hecho, sesión cerrada, evento de otro módulo. | «Ya existe un deportista con el documento X.» |
| 413 | Cuerpo o archivo demasiado grande (CSV de deportistas de más de 1 MB, formulario de autorregistro de más de 128 KB). | «La solicitud es demasiado grande.» |
| 415 | Cuerpo que no es `application/json`. | «Tipo de contenido no soportado: …» |
| 422 | Dato válido pero no procesable ahora: rango demasiado largo, demasiadas filas, torneo futuro, fecha de entrega futura. | «El rango de la planilla de asistencia no puede superar 366 días.» |
| 429 | Bloqueo de inicio de sesión o límite del autorregistro por IP. | «Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.» |
| 500 | Error interno. Nunca trae detalles técnicos (van al log del servidor). | «Error interno del servidor.» |

**Errores comunes a todas las acciones** (no se repiten en cada ficha): `401` sin sesión (salvo las rutas sin sesión), `403` del ámbito público en rutas administrativas, `405` por método, `400` por parámetros en lista o con UTF-8 inválido, `403` por las reglas de origen, `415` por el tipo de cuerpo y `500`.

### A.6 Límites

| Qué | Límite | Respuesta al superarlo |
|---|---|---|
| Archivo de documento del deportista | 10 MB; formatos según el tipo (A.7) | `400` |
| Documento general del club | 10 MB; pdf, doc, docx, xls, xlsx, jpg, jpeg, png, webp | `400` |
| Petición completa (límite de PHP, `post_max_size`) | 40 MB en el XAMPP local; depende del hosting | `400` |
| Formulario de autorregistro | 128 KB | `413` |
| CSV de carga masiva de deportistas | 1 MB y 1.000 filas | `413` / `422` |
| CSV del calendario | 10 MB y 500 filas | `400` |
| Inscribir en un torneo / en una sesión de examen | 50 participantes por petición | `400` |
| Planilla de asistencia (reporte) | 366 días | `422` |
| Vista del calendario | 366 días | `400` |
| Autorregistro | 10 registros por hora por IP; permiso de documentos de 2 h | `429` / `403` |
| Inicio de sesión | 4 fallos seguidos → 15 minutos de bloqueo | `429` |
| Recuperación de contraseña | Enlace válido 1 hora; mientras esté vigente no se genera otro | (misma respuesta genérica) |
| Textos cortos | documento 20; nombre 150; lugar 150; dirección 150; correo 150; lugar_nacimiento 100; sede_entrenamiento 100; teléfono 20 (dígitos, espacios y `+` inicial); grado_actual 30; discapacidad 255; rol 30; título 150; plataforma 50; categoria_peso 30; resultado 255 | `400` |
| Textos largos (columnas TEXT) | observaciones de eventos, notas_oponente y los cinco textos de examen: 65.000 bytes (una tilde ocupa 2); experiencia_previa: 16.000 caracteres | `400` |
| Contraseña nueva | mínimo 8 caracteres y máximo 72 bytes | `400` |
| Números | estatura > 0 y ≤ 99.99; peso > 0 y ≤ 999.99; nota 1–10 (2 decimales); valor_medible 0–9999.99; puesto 1–99; combates 0–127; año 2020 hasta el año siguiente; mes 1–12; semana 1–4 | `400` |

### A.7 Enumeraciones (valores exactos)

| Campo | Valores |
|---|---|
| Ámbito | `administrativa`, `publica` |
| `sexo` | `masculino`, `femenino` |
| `estado` (deportista) | `activo`, `inactivo` |
| `estado_registro` | `pendiente_validacion`, `validado` |
| `tipo_documento` | `documento_identidad`, `formulario_inscripcion`, `documento_acudiente`, `fotografia` (un solo archivo por tipo); `consentimiento`, `certificado_ascenso`, `competencia`, `preparacion_fisica`, `otro` (varios por tipo, solo los carga el club) |
| Formatos por tipo de documento | identidad, formulario, acudiente y fotografía: pdf, jpg, jpeg, png, webp · consentimiento y certificado_ascenso: pdf, doc, docx · competencia, preparacion_fisica y otro: pdf, doc, docx, xls, xlsx |
| `tipo` (evento de calendario) | `competencia`, `evaluacion`, `grado`, `reunion_administrativa`, `evento_extra` |
| `visibilidad` | `administrativa`, `publica` |
| `tipo_torneo` | `nacional`, `internacional`, `departamental` |
| `modalidad` (competencia) | `combate_individual`, `combate_equipos`, `defensa_personal`, `salto_alto`, `salto_largo`, `formas` (solo las dos de combate llevan combates y categoría de peso) |
| `medalla` | `oro`, `plata`, `bronce`, `ninguna` |
| `categoria_edad` (participación) | `infantil`, `junior`, `juvenil`, `mayores`, `senior`, `master`. El campo calculado `categoria_edad` de un deportista sale con nombre para mostrar: `Infantil` (8–11), `Junior` (12–14), `Juvenil` (15–17), `Mayores` (18–35), `Sénior` (36–45), `Máster` (46+); menores de 8 años: `null` |
| `categoria_peso` (calculada) | Texto `-X` (hasta X kg, inclusive) o `+X` (por encima del último corte). Tablas: Infantil 20/25/30/35 (PROPUESTA); Junior M 40–60, F 38–58; Juvenil M 45–75, F 43–73 (oficiales Fedecolhap 2024); Mayores, Sénior y Máster M 55–85, F 50–80 |
| Modalidades de examen | `teorico`, `fisico`, `combate`, `rompimiento`, `formas`, `llaves` (siempre las seis, en este orden) |
| Grados | `Ingresado`, `Blanco`, `Amarillo`, `Naranja`, `Verde`, `Azul`, `Rojo`, `Violeta`, `Marrón`, `Marrón franja negra`, `Negro` (`grado_actual` null = Ingresado) |
| `estado` (sesión de examen) | `abierta`, `cerrada` |
| `estado` (modalidad de examen) | `pendiente`, `completado` |
| `confirmado_por` (entrega de cinturón) | `administrativo`, `deportista` |
| `tipo` (pago) | `mensualidad` (mes 1–12 y semana 1–4), `matricula` (mes 0, sin semana) |
| Ventana del % de asistencia | `30`, `90` (por defecto), `todo` |
| Clasificación de una fila del CSV | `nueva`, `actualizar`, `sin_cambios`, `incompleta`, `invalida`, `conflicto_correo`, `duplicada` |
| Resultado al aplicar el CSV | `creada`, `actualizada`, `omitida`, `rechazada`, `error` |

### A.8 Descargas (reportes y documentos)

- **Qué devuelven:** las acciones de descarga (`documentos.descargar`, `documentos.descargarGeneral` y las seis de `reportes`) responden el **archivo**, no JSON, con estas cabeceras:
  - `Content-Type`: `application/pdf`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, o el del documento;
  - `Content-Disposition: attachment; filename="nombre_ascii"; filename*=UTF-8''nombre%20real`: el navegador guarda con el nombre real, con tildes;
  - `Content-Length` exacto y `X-Content-Type-Options: nosniff`.
- **Errores:** si algo falla, la respuesta es el JSON de error habitual (`exito: false`), así que el front debe revisar el código o el `Content-Type` antes de guardar.
- **Nombres de los reportes:** llevan la fecha de Bogotá, p. ej. `deportistas_activos_2026-10-26.xlsx`, `ficha_1000000150_2026-10-26.pdf`.
- **No se guardan:** los reportes se generan en el momento y nunca quedan en el servidor (llevan datos de menores y de salud).
- **Cómo descargar desde el front:** basta un enlace normal (`<a href="api.php?recurso=reportes&accion=…">`; la cookie viaja sola), o `fetch` y `blob()` si se quiere mostrar el error.

### A.9 Qué NO hay

- **Paginación:** todas las listas vienen completas, ordenadas por la API.
- **CORS:** el front va en el mismo origen (A.4).
- **Notificaciones:** no hay push, websockets ni correos automáticos. El único correo es el enlace de recuperación de contraseña.
- **Borrados que las reglas no permiten:**
  - no se borran deportistas validados (se pasan a inactivo), ni cuentas (se desactivan), ni eventos del calendario, ni torneos (solo se quita un participante);
  - no hay estado «rechazado» para un autorregistro: se elimina.

---
## Parte B — Fichas por acción

Cada ficha indica: método y quién puede llamarla; parámetros de URL; cuerpo; respuesta con un ejemplo real; errores propios (los comunes están en A.5); y notas para el front.

---

### B.1 `auth` — inicio y cierre de sesión (7 acciones)

#### `auth.iniciarSesionAdministrativa` — POST · sin sesión

- **Cuerpo:** `correo` (texto, obligatorio, correo válido de máximo 150; se pasa a minúsculas) y `contrasena` (texto, obligatorio, se compara tal cual).
- **Respuesta:** `ambito`, `correo`, `nombre` y `es_administrador_principal`. Además fija la cookie `HWANGHON_SESION`.

Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionAdministrativa`

Cuerpo:

```json
{
    "correo": "maestro@hwanghon.local",
    "contrasena": "12345678"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "ambito": "administrativa",
        "correo": "maestro@hwanghon.local",
        "nombre": "Maestro (prueba)",
        "es_administrador_principal": true
    }
}
```

- **Errores:**
  - `401` «Correo o contraseña incorrectos.»: el mismo mensaje si el correo no existe, la clave es errada o la cuenta está desactivada;
  - `429` «Demasiados intentos fallidos. Intenta de nuevo en N minutos.» al cuarto fallo seguido;
  - `400` si falta un campo o el correo no es válido.
- **Notas:** el mensaje nunca revela si el correo existe. Con `es_administrador_principal = true` el front muestra la gestión de cuentas.

#### `auth.iniciarSesionPublica` — POST · sin sesión

- **Cuerpo:** `documento` (texto o número, obligatorio, máximo 20) y `fecha_nacimiento` (`AAAA-MM-DD`, obligatoria: la **del deportista**, también cuando entra el acudiente).
- **Respuesta:** `ambito`, `documento` y `nombre`, más la cookie.

Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionPublica`

Cuerpo:

```json
{
    "documento": "1000000101",
    "fecha_nacimiento": "2014-03-12"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "ambito": "publica",
        "documento": "1000000101",
        "nombre": "Samuel Reyes Ortiz"
    }
}
```

- **Errores:**
  - `401` «Documento o fecha de nacimiento incorrectos.»;
  - `403` «Tu registro está en revisión.»: registro pendiente de validación, sin sesión y sin contar como fallo;
  - `429` por bloqueo;
  - `400` por formato.
- **Notas:** un deportista **inactivo** sí entra.

#### `auth.cerrarSesion` — POST · cualquier sesión (o ninguna)

- **Cuerpo:** ninguno.
- **Respuesta:** `{"sesion_cerrada": true}`. Es idempotente: sin sesión responde lo mismo.

Ejemplo real (llamada como **público**): `POST api.php?recurso=auth&accion=cerrarSesion`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "sesion_cerrada": true
    }
}
```

#### `auth.sesionActual` — GET · administrativo o público

- **Respuesta:**
  - administrativo: `ambito`, `identificador` (correo), `nombre` y `es_administrador_principal`;
  - público: `ambito`, `identificador` (documento) y `nombre`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=auth&accion=sesionActual`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "ambito": "administrativa",
        "identificador": "maestro@hwanghon.local",
        "nombre": "Maestro (prueba)",
        "es_administrador_principal": true
    }
}
```

Ejemplo real (llamada como **público**): `GET api.php?recurso=auth&accion=sesionActual`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "ambito": "publica",
        "identificador": "1000000101",
        "nombre": "Samuel Reyes Ortiz"
    }
}
```

- **Errores:** `401` «No autenticado. Inicie sesión para continuar.».
- **Notas:** llamarla al cargar el front para saber si ya hay sesión y de qué ámbito.

#### `auth.cambiarContrasena` — POST · administrativo

- **Cuerpo:** `contrasena_actual` (obligatoria) y `contrasena_nueva` (8 caracteres a 72 bytes).
- **Respuesta:** `{"contrasena_cambiada": true}`.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=auth&accion=cambiarContrasena`

Cuerpo:

```json
{
    "contrasena_actual": "12345678",
    "contrasena_nueva": "NuevaClave2026"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "contrasena_cambiada": true
    }
}
```

- **Errores:**
  - `400` «La contraseña actual no es correcta.»;
  - `403` «Solo las cuentas administrativas tienen contraseña.»;
  - `400` si la nueva no cumple la longitud.
- **Notas:**
  - la sesión actual sigue (con un id de sesión nuevo) y **las demás sesiones de esa cuenta caen** con `401`;
  - invalida cualquier enlace de recuperación pendiente.

#### `auth.solicitarRecuperacionContrasena` — POST · sin sesión

- **Cuerpo:** `correo` (obligatorio).
- **Respuesta:** siempre el mismo mensaje, exista o no la cuenta.

Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=solicitarRecuperacionContrasena`

Cuerpo:

```json
{
    "correo": "no.registrado@hwanghon.local"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "mensaje": "Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña."
    }
}
```

- **Notas:**
  - si la cuenta existe y está activa, el servidor envía, **después** de responder, un correo con el enlace `url_restablecimiento?token=<64 hex>` (la URL se configura en `Config/correo.php`), válido 1 hora;
  - mientras haya un enlace vigente no se genera otro;
  - el front solo muestra el mensaje.

#### `auth.restablecerContrasena` — POST · sin sesión

- **Cuerpo:** `token` (64 caracteres hexadecimales, el del enlace) y `contrasena_nueva`.
- **Respuesta:** `{"contrasena_restablecida": true}`. **No** inicia sesión.

Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=restablecerContrasena`

Cuerpo:

```json
{
    "token": "7777777777777777777777777777777777777777777777777777777777777777",
    "contrasena_nueva": "12345678"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "contrasena_restablecida": true
    }
}
```

- **Errores:** `400` «El enlace no es válido o ya venció.»: token mal formado, inexistente, vencido o ya usado.
- **Notas:** borra el bloqueo de intentos de ese correo y cierra las sesiones abiertas de la cuenta. La pantalla de restablecer lee `token` de la URL del enlace.

---

### B.2 `usuarios-administrativos` — cuentas del club (7 acciones)

Todas exigen sesión administrativa de una **cuenta principal** (`es_administrador_principal = true`). Cualquier otra cuenta administrativa recibe `403` «Solo las cuentas principales pueden gestionar las cuentas administrativas.».

Una cuenta tiene: `correo`, `nombre`, `rol` (texto libre o null), `es_administrador_principal`, `activa` y `creado_por_correo`. **Nunca** se devuelven la contraseña ni el token de recuperación.

#### `usuarios-administrativos.listar` — GET · cuenta principal

- **Respuesta:** todas las cuentas (activas y desactivadas), por nombre y correo.

Ejemplo real (llamada como **cuenta principal**): `GET api.php?recurso=usuarios-administrativos&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "correo": "administrativo1@hwanghon.local",
            "nombre": "Administrativo 1 (prueba)",
            "rol": "administrador",
            "es_administrador_principal": false,
            "activa": true,
            "creado_por_correo": "maestro@hwanghon.local"
        },
        {
            "correo": "admin.fundador@hwanghonficticio.test",
            "nombre": "Carlos Andrés Gómez Ruiz",
            "rol": "administrador",
            "es_administrador_principal": true,
            "activa": true,
            "creado_por_correo": null
        },
        "… (5 elementos más, omitidos en este ejemplo)"
    ]
}
```

#### `usuarios-administrativos.consultar` — GET · cuenta principal

- **URL:** `correo` (obligatorio).
- **Respuesta:** la cuenta. Si no existe: `404` «No encontrado.».

Ejemplo real (llamada como **cuenta principal**): `GET api.php?recurso=usuarios-administrativos&accion=consultar&correo=administrativo1@hwanghon.local`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "correo": "administrativo1@hwanghon.local",
        "nombre": "Administrativo 1 (prueba)",
        "rol": "administrador",
        "es_administrador_principal": false,
        "activa": true,
        "creado_por_correo": "maestro@hwanghon.local"
    }
}
```

#### `usuarios-administrativos.crear` — POST · cuenta principal

- **Cuerpo:**
  - `correo`: obligatorio, único;
  - `nombre`: obligatorio, máximo 150;
  - `rol`: opcional, máximo 30;
  - `contrasena`: obligatoria, la contraseña inicial, de 8 caracteres a 72 bytes.
- **Respuesta (201):** la cuenta creada. Nace activa, sin rol principal y con `creado_por_correo` = quien la crea.

Ejemplo real (llamada como **cuenta principal**): `POST api.php?recurso=usuarios-administrativos&accion=crear`

Cuerpo:

```json
{
    "correo": "secretaria.prueba@hwanghon.local",
    "nombre": "Secretaria de Prueba",
    "rol": "secretaria",
    "contrasena": "ClaveInicial1"
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "correo": "secretaria.prueba@hwanghon.local",
        "nombre": "Secretaria de Prueba",
        "rol": "secretaria",
        "es_administrador_principal": false,
        "activa": true,
        "creado_por_correo": "maestro@hwanghon.local"
    }
}
```

- **Errores:** `409` «Ya existe una cuenta con el correo secretaria.prueba@hwanghon.local.»; `400` «Campos no permitidos: x.».

#### `usuarios-administrativos.actualizar` — PUT · cuenta principal

- **URL:** `correo`.
- **Cuerpo (parcial):** `nombre`, `rol` y `correo` (el correo nuevo).
- **Respuesta:** la cuenta actualizada.

Ejemplo real (llamada como **cuenta principal**): `PUT api.php?recurso=usuarios-administrativos&accion=actualizar&correo=secretaria.prueba@hwanghon.local`

Cuerpo:

```json
{
    "nombre": "Secretaria Prueba Editada",
    "rol": "tesoreria"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "correo": "secretaria.prueba@hwanghon.local",
        "nombre": "Secretaria Prueba Editada",
        "rol": "tesoreria",
        "es_administrador_principal": false,
        "activa": true,
        "creado_por_correo": "maestro@hwanghon.local"
    }
}
```

- **Errores:**
  - `400` «No se recibió ningún campo para actualizar.»;
  - `409` «No puedes cambiar tu propio correo: la sesión lo usa para identificarte. Pide a la otra cuenta principal que lo cambie.»;
  - `409` por un correo ya usado;
  - `404` «Cuenta no encontrada.».
- **Notas:** no cambia la contraseña, `activa` ni `es_administrador_principal`.

#### `usuarios-administrativos.desactivar` — PUT · cuenta principal

- **URL:** `correo`.
- **Respuesta:** la cuenta con `activa: false`. Es idempotente.

Ejemplo real (llamada como **cuenta principal**): `PUT api.php?recurso=usuarios-administrativos&accion=desactivar&correo=secretaria.prueba@hwanghon.local`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "correo": "secretaria.prueba@hwanghon.local",
        "nombre": "Secretaria Prueba Editada",
        "rol": "tesoreria",
        "es_administrador_principal": false,
        "activa": false,
        "creado_por_correo": "maestro@hwanghon.local"
    }
}
```

- **Errores:** `409` «No puedes desactivar tu propia cuenta.»; `409` «Una cuenta principal no se puede desactivar desde la aplicación.».
- **Notas:** la sesión abierta de esa cuenta cae en su siguiente petición. Las cuentas nunca se borran.

#### `usuarios-administrativos.reactivar` — PUT · cuenta principal

- **URL:** `correo`.
- **Respuesta:** la cuenta con `activa: true`. Es idempotente.

Ejemplo real (llamada como **cuenta principal**): `PUT api.php?recurso=usuarios-administrativos&accion=reactivar&correo=secretaria.prueba@hwanghon.local`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "correo": "secretaria.prueba@hwanghon.local",
        "nombre": "Secretaria Prueba Editada",
        "rol": "tesoreria",
        "es_administrador_principal": false,
        "activa": true,
        "creado_por_correo": "maestro@hwanghon.local"
    }
}
```

#### `usuarios-administrativos.restablecerContrasena` — PUT · cuenta principal

- **URL:** `correo` (de OTRA cuenta).
- **Cuerpo:** `contrasena_nueva`.
- **Respuesta:** `{"contrasena_restablecida": true}`.

Ejemplo real (llamada como **cuenta principal**): `PUT api.php?recurso=usuarios-administrativos&accion=restablecerContrasena&correo=secretaria.prueba@hwanghon.local`

Cuerpo:

```json
{
    "contrasena_nueva": "ClaveNueva2026"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "contrasena_restablecida": true
    }
}
```

- **Errores:** `409` «Para tu propia cuenta usa «cambiar mi contraseña».».
- **Notas:** borra el enlace de recuperación y el bloqueo de esa cuenta, y **cierra sus sesiones abiertas**. Sirve cuando el correo de recuperación no llega.

---

### B.3 `deportistas` (6 acciones)

**Ficha de un deportista** (los nombres coinciden con las columnas):

- `documento`, `nombre`, `fecha_nacimiento`, `lugar_nacimiento`, `sexo`, `estatura` (m), `peso` (kg);
- `telefono`, `direccion`, `correo`, `discapacidad` (condición de salud), `experiencia_previa`, `sede_entrenamiento`;
- `autorizacion_datos`, `grado_actual` (null = Ingresado), `fecha_ingreso`, `estado`, `estado_registro`, `acudiente_documento`;
- `validado_por_correo`: **solo en el ámbito administrativo**;
- calculados, nunca se envían: `edad`, `categoria_edad` y `categoria_peso`.

#### `deportistas.listar` — GET · administrativo o público

- **URL (solo administrativo, filtros opcionales y exactos):** `estado`, `estado_registro`, `sede_entrenamiento`, `grado_actual` y `acudiente_documento`. Otro filtro: `400` «Filtros no permitidos: x.».
- **Respuesta:**
  - administrativo: todos los que cumplen el filtro (incluye pendientes e inactivos), por nombre y documento;
  - público: **una lista con solo su propio registro**; ignora los filtros.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000103",
            "nombre": "Fabián Esteban Rincón Peña",
            "fecha_nacimiento": "1998-11-05",
            "lugar_nacimiento": "Bogotá",
            "sexo": "masculino",
            "estatura": 1.75,
            "peso": 78.5,
            "telefono": null,
            "direccion": null,
            "correo": "fabian.rincon@correoficticio.test",
            "discapacidad": null,
            "experiencia_previa": "Taekwondo en el Club Ficticio Dragón, Bogotá (Colombia), maestro Pedro Ficticio Ruiz; retiro en 2022 por cambio de residencia; último grado amarillo, graduado en 2021",
            "sede_entrenamiento": "Club Hwang Hon",
            "autorizacion_datos": true,
            "grado_actual": "amarillo",
            "fecha_ingreso": "2023-06-10",
            "estado": "inactivo",
            "estado_registro": "validado",
            "acudiente_documento": null,
            "validado_por_correo": "staff.instructor@hwanghonficticio.test",
            "edad": 27,
            "categoria_edad": "Mayores",
            "categoria_peso": "-80"
        },
        {
            "documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "fecha_nacimiento": "2005-07-20",
            "lugar_nacimiento": null,
            "sexo": "femenino",
            "estatura": 1.68,
            "peso": 60,
            "telefono": "3000000002",
            "direccion": "Carrera 4 # 5-06, Soacha",
            "correo": "juliana.castillo@correoficticio.test",
            "discapacidad": null,
            "experiencia_previa": null,
            "sede_entrenamiento": "Escuela IMRDS",
            "autorizacion_datos": true,
            "grado_actual": null,
            "fecha_ingreso": "2026-01-15",
            "estado": "activo",
            "estado_registro": "pendiente_validacion",
            "acudiente_documento": null,
            "validado_por_correo": null,
            "edad": 21,
            "categoria_edad": "Mayores",
            "categoria_peso": "-60"
        },
        {
            "documento": "1000000101",
            "nombre": "Samuel Reyes Ortiz",
            "fecha_nacimiento": "2014-03-12",
            "lugar_nacimiento": "Soacha",
            "sexo": "masculino",
            "estatura": 1.55,
            "peso": 45.3,
            "telefono": "3000000001",
            "direccion": "Calle 1 # 2-03, Soacha",
            "correo": "marcela.reyes@correoficticio.test",
            "discapacidad": null,
            "experiencia_previa": null,
            "sede_entrenamiento": "Club Hwang Hon",
            "autorizacion_datos": true,
            "grado_actual": null,
            "fecha_ingreso": "2025-02-01",
            "estado": "activo",
            "estado_registro": "validado",
            "acudiente_documento": "1000000001",
            "validado_por_correo": null,
            "edad": 12,
            "categoria_edad": "Junior",
            "categoria_peso": "-50"
        }
    ]
}
```

Lista de pendientes de validación:

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=listar&estado_registro=pendiente_validacion`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "fecha_nacimiento": "2005-07-20",
            "lugar_nacimiento": null,
            "sexo": "femenino",
            "estatura": 1.68,
            "peso": 60,
            "telefono": "3000000002",
            "direccion": "Carrera 4 # 5-06, Soacha",
            "correo": "juliana.castillo@correoficticio.test",
            "discapacidad": null,
            "experiencia_previa": null,
            "sede_entrenamiento": "Escuela IMRDS",
            "autorizacion_datos": true,
            "grado_actual": null,
            "fecha_ingreso": "2026-01-15",
            "estado": "activo",
            "estado_registro": "pendiente_validacion",
            "acudiente_documento": null,
            "validado_por_correo": null,
            "edad": 21,
            "categoria_edad": "Mayores",
            "categoria_peso": "-60"
        }
    ]
}
```

Público (sin `validado_por_correo`):

Ejemplo real (llamada como **público**): `GET api.php?recurso=deportistas&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000101",
            "nombre": "Samuel Reyes Ortiz",
            "fecha_nacimiento": "2014-03-12",
            "lugar_nacimiento": "Soacha",
            "sexo": "masculino",
            "estatura": 1.55,
            "peso": 45.3,
            "telefono": "3000000001",
            "direccion": "Calle 1 # 2-03, Soacha",
            "correo": "marcela.reyes@correoficticio.test",
            "discapacidad": null,
            "experiencia_previa": null,
            "sede_entrenamiento": "Club Hwang Hon",
            "autorizacion_datos": true,
            "grado_actual": null,
            "fecha_ingreso": "2025-02-01",
            "estado": "activo",
            "estado_registro": "validado",
            "acudiente_documento": "1000000001",
            "edad": 12,
            "categoria_edad": "Junior",
            "categoria_peso": "-50"
        }
    ]
}
```

- **Notas:** `listar` no trae el récord ni el acudiente (para eso, `consultar`).

#### `deportistas.consultar` — GET · administrativo o público

- **URL:** `documento` (obligatorio).
- **Respuesta:** la ficha más `combates_ganados` y `combates_perdidos` (récord acumulado de torneos) y `acudiente` (`{documento, nombre, relacion, telefono, correo}` o null).

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=consultar&documento=1000000101`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000101",
        "nombre": "Samuel Reyes Ortiz",
        "fecha_nacimiento": "2014-03-12",
        "lugar_nacimiento": "Soacha",
        "sexo": "masculino",
        "estatura": 1.55,
        "peso": 45.3,
        "telefono": "3000000001",
        "direccion": "Calle 1 # 2-03, Soacha",
        "correo": "marcela.reyes@correoficticio.test",
        "discapacidad": null,
        "experiencia_previa": null,
        "sede_entrenamiento": "Club Hwang Hon",
        "autorizacion_datos": true,
        "grado_actual": null,
        "fecha_ingreso": "2025-02-01",
        "estado": "activo",
        "estado_registro": "validado",
        "acudiente_documento": "1000000001",
        "validado_por_correo": null,
        "edad": 12,
        "categoria_edad": "Junior",
        "categoria_peso": "-50",
        "combates_ganados": 0,
        "combates_perdidos": 0,
        "acudiente": {
            "documento": "1000000001",
            "nombre": "Marcela Reyes Ortiz",
            "relacion": "madre",
            "telefono": "3000000001",
            "correo": "marcela.reyes@correoficticio.test"
        }
    }
}
```

- **Diferencias por ámbito:** el público solo consulta su propio documento y no recibe `validado_por_correo`. Con un documento ajeno responde `404` «No encontrado.», igual que si no existiera.

#### `deportistas.buscar` — GET · administrativo

- **URL:** `texto` (mínimo 2 caracteres).
- **Respuesta:** hasta 20 deportistas cuyo documento **empieza** por el texto o cuyo nombre lo **contiene** (sin distinguir mayúsculas ni tildes), con `documento`, `nombre`, `estado`, `estado_registro` y `grado_actual`. Incluye inactivos y pendientes.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=buscar&texto=rey`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000101",
            "nombre": "Samuel Reyes Ortiz",
            "estado": "activo",
            "estado_registro": "validado",
            "grado_actual": null
        }
    ]
}
```

- **Errores:** `400` «Escribe al menos 2 caracteres para buscar.».
- **Notas:** pensada para el autocompletado de cualquier formulario que pida un deportista.

#### `deportistas.crear` — POST · administrativo

- **Cuerpo:**

  | Campo | Tipo | Regla |
  |---|---|---|
  | `documento` | texto o número | obligatorio, máximo 20, único |
  | `nombre` | texto | obligatorio, máximo 150 |
  | `fecha_nacimiento` | fecha | obligatoria, no futura |
  | `lugar_nacimiento` | texto | opcional, máximo 100 |
  | `sexo` | enumeración | obligatorio: `masculino` / `femenino` |
  | `estatura` | número | obligatorio, > 0 y ≤ 99.99 (metros) |
  | `peso` | número | obligatorio, > 0 y ≤ 999.99 (kg) |
  | `telefono` | texto | opcional, máximo 20, dígitos, espacios y `+` inicial |
  | `direccion` | texto | opcional, máximo 150 |
  | `correo` | texto | opcional, correo válido (puede repetirse entre hermanos) |
  | `discapacidad` | texto | opcional, máximo 255 |
  | `experiencia_previa` | texto | opcional, máximo 16.000 caracteres |
  | `sede_entrenamiento` | texto | opcional, máximo 100 |
  | `autorizacion_datos` | booleano | opcional, por defecto `false` |
  | `grado_actual` | texto | opcional, máximo 30 |
  | `fecha_ingreso` | fecha | opcional, por defecto hoy; no futura ni anterior al nacimiento |
  | `estado` | enumeración | opcional, por defecto `activo` |
  | `acudiente` | objeto | **obligatorio si es menor de 18**: `documento` (obligatorio, distinto del deportista), `nombre` y `relacion` (obligatorios si el acudiente es nuevo), `telefono` y `correo` (opcionales) |

- **Respuesta (201):** la ficha completa, como `consultar`. Queda `validado` (`validado_por_correo` null: lo cargó el club).

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=deportistas&accion=crear`

Cuerpo:

```json
{
    "documento": "1000000150",
    "nombre": "Valentina Gómez Ficticia",
    "fecha_nacimiento": "2013-06-15",
    "lugar_nacimiento": "Soacha",
    "sexo": "femenino",
    "estatura": 1.48,
    "peso": 39.5,
    "telefono": "3000000150",
    "direccion": "Calle Ficticia 15",
    "correo": "familia.gomez@correoficticio.test",
    "discapacidad": null,
    "experiencia_previa": null,
    "sede_entrenamiento": "Club Hwang Hon",
    "autorizacion_datos": true,
    "acudiente": {
        "documento": "1000000950",
        "nombre": "Carolina Gómez Ficticia",
        "relacion": "madre",
        "telefono": "3000000950",
        "correo": "familia.gomez@correoficticio.test"
    }
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000150",
        "nombre": "Valentina Gómez Ficticia",
        "fecha_nacimiento": "2013-06-15",
        "lugar_nacimiento": "Soacha",
        "sexo": "femenino",
        "estatura": 1.48,
        "peso": 39.5,
        "telefono": "3000000150",
        "direccion": "Calle Ficticia 15",
        "correo": "familia.gomez@correoficticio.test",
        "discapacidad": null,
        "experiencia_previa": null,
        "sede_entrenamiento": "Club Hwang Hon",
        "autorizacion_datos": true,
        "grado_actual": null,
        "fecha_ingreso": "2026-10-28",
        "estado": "activo",
        "estado_registro": "validado",
        "acudiente_documento": "1000000950",
        "validado_por_correo": null,
        "edad": 13,
        "categoria_edad": "Junior",
        "categoria_peso": "-43",
        "combates_ganados": 0,
        "combates_perdidos": 0,
        "acudiente": {
            "documento": "1000000950",
            "nombre": "Carolina Gómez Ficticia",
            "relacion": "madre",
            "telefono": "3000000950",
            "correo": "familia.gomez@correoficticio.test"
        }
    }
}
```

- **Errores:**
  - `409` «Ya existe un deportista con el documento 1000000150.»;
  - `400` «El deportista es menor de edad: se requiere un acudiente.»;
  - `400` por cada campo inválido, nombrándolo;
  - `400` «Campos no reconocidos: x.».
- **Notas:** un acudiente que ya existe (mismo documento) se **reutiliza sin modificarlo**. Así varios hermanos comparten acudiente.

#### `deportistas.actualizar` — PUT · administrativo o público

- **URL:** `documento`.
- **Cuerpo (parcial; lo no enviado no cambia):**
  - **público:** solo `peso`, `discapacidad`, `telefono`, `direccion` y `correo`, y solo de su propio registro;
  - **administrativo:** cualquier campo de `crear` salvo `documento`, más `estado_registro` (solo de `pendiente_validacion` a `validado`, lo que **registra al validador**) y `acudiente`:
    - `null` lo quita (solo si es mayor de edad);
    - un objeto sin `documento` (o con el del acudiente actual) edita al actual;
    - un objeto con otro `documento` lo reasigna (reutiliza uno existente o lo crea).
- **Respuesta:** la ficha actualizada.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=deportistas&accion=actualizar&documento=1000000150`

Cuerpo:

```json
{
    "peso": 41.2,
    "grado_actual": "Blanco"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000150",
        "nombre": "Valentina Gómez Ficticia",
        "fecha_nacimiento": "2013-06-15",
        "lugar_nacimiento": "Soacha",
        "sexo": "femenino",
        "estatura": 1.48,
        "peso": 41.2,
        "telefono": "3000000150",
        "direccion": "Calle Ficticia 15",
        "correo": "familia.gomez@correoficticio.test",
        "discapacidad": null,
        "experiencia_previa": null,
        "sede_entrenamiento": "Club Hwang Hon",
        "autorizacion_datos": true,
        "grado_actual": "Blanco",
        "fecha_ingreso": "2026-10-28",
        "estado": "activo",
        "estado_registro": "validado",
        "acudiente_documento": "1000000950",
        "validado_por_correo": null,
        "edad": 13,
        "categoria_edad": "Junior",
        "categoria_peso": "-43",
        "combates_ganados": 0,
        "combates_perdidos": 0,
        "acudiente": {
            "documento": "1000000950",
            "nombre": "Carolina Gómez Ficticia",
            "relacion": "madre",
            "telefono": "3000000950",
            "correo": "familia.gomez@correoficticio.test"
        }
    }
}
```

Validar un registro pendiente:

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=deportistas&accion=actualizar&documento=1000000102`

Cuerpo:

```json
{
    "estado_registro": "validado"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000102",
        "nombre": "Juliana Andrea Castillo Vargas",
        "fecha_nacimiento": "2005-07-20",
        "lugar_nacimiento": null,
        "sexo": "femenino",
        "estatura": 1.68,
        "peso": 60,
        "telefono": "3000000002",
        "direccion": "Carrera 4 # 5-06, Soacha",
        "correo": "juliana.castillo@correoficticio.test",
        "discapacidad": null,
        "experiencia_previa": null,
        "sede_entrenamiento": "Escuela IMRDS",
        "autorizacion_datos": true,
        "grado_actual": null,
        "fecha_ingreso": "2026-01-15",
        "estado": "activo",
        "estado_registro": "validado",
        "acudiente_documento": null,
        "validado_por_correo": "maestro@hwanghon.local",
        "edad": 21,
        "categoria_edad": "Mayores",
        "categoria_peso": "-60",
        "combates_ganados": 2,
        "combates_perdidos": 1,
        "acudiente": null
    }
}
```

Público:

Ejemplo real (llamada como **público**): `PUT api.php?recurso=deportistas&accion=actualizar&documento=1000000101`

Cuerpo:

```json
{
    "peso": 46,
    "telefono": "3000000002"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000101",
        "nombre": "Samuel Reyes Ortiz",
        "fecha_nacimiento": "2014-03-12",
        "lugar_nacimiento": "Soacha",
        "sexo": "masculino",
        "estatura": 1.55,
        "peso": 46,
        "telefono": "3000000002",
        "direccion": "Calle 1 # 2-03, Soacha",
        "correo": "marcela.reyes@correoficticio.test",
        "discapacidad": null,
        "experiencia_previa": null,
        "sede_entrenamiento": "Club Hwang Hon",
        "autorizacion_datos": true,
        "grado_actual": null,
        "fecha_ingreso": "2025-02-01",
        "estado": "activo",
        "estado_registro": "validado",
        "acudiente_documento": "1000000001",
        "edad": 12,
        "categoria_edad": "Junior",
        "categoria_peso": "-50",
        "combates_ganados": 0,
        "combates_perdidos": 0,
        "acudiente": {
            "documento": "1000000001",
            "nombre": "Marcela Reyes Ortiz",
            "relacion": "madre",
            "telefono": "3000000001",
            "correo": "marcela.reyes@correoficticio.test"
        }
    }
}
```

- **Errores:**
  - `403` «Campos que no puede editar: nombre.» (público con otro campo);
  - `400` «No se recibió ningún campo para actualizar.»;
  - `409` «Un registro validado no puede volver a pendiente de validación.»;
  - `400` «El deportista es menor de edad y no tiene acudiente asignado.»;
  - `404` si no existe o es ajeno.
- **Notas:** el estado `inactivo` lo pone el botón «Revisar inactivos» o el administrativo. Marcar al deportista presente lo reactiva solo.

#### `deportistas.eliminar` — DELETE · administrativo

- **URL:** `documento`.
- **Respuesta:** `{"eliminado": true}`. Solo para registros **pendientes de validación** (así se «rechaza» un autorregistro).
- **Qué borra:** en una sola transacción, sus documentos (p. ej. los subidos en el autorregistro), el deportista y su acudiente si queda sin deportistas. Los archivos de esos documentos se borran del disco **después** de confirmar la transacción; si algo falla antes, no se borra nada (ni filas ni archivos).

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=deportistas&accion=eliminar&documento=1000000162`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

Con documentos cargados:

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=deportistas&accion=eliminar&documento=1000000160`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

- **Errores:**
  - `409` «Solo se eliminan registros pendientes de validación. Para un deportista validado, cambie su estado a inactivo.»;
  - `409` «El registro ya tiene datos en otros módulos (pagos, asistencia, torneos o exámenes): no se puede eliminar.»: el pendiente ya tiene asistencia, pagos, torneos o exámenes; esos datos no se borran en silencio;
  - `404`.

---

### B.4 `autorregistro` — inscripción pública (6 acciones)

Ruta **sin sesión**. Las cinco acciones de documentos necesitan el **permiso de autorregistro** que abre `registrar` en la cookie (2 horas, solo para ese documento). Sin él, o tras `confirmar`, responden `403` «No hay un registro abierto para tus documentos: ya se confirmó o venció. Comunícate con el club.».

#### `autorregistro.registrar` — POST · sin sesión

- **Cuerpo:** los campos de `deportistas.crear` (incluido `acudiente` si es menor), con dos diferencias:
  - `autorizacion_datos` **debe ser `true`**;
  - si el acudiente se envía, `nombre` y `relacion` son obligatorios aunque ya exista.
- **Campos ignorados:** si llegan, el servidor ignora `estado`, `estado_registro`, `validado_por_correo`, `validado_por`, `grado_actual` y `fecha_ingreso`. El registro queda pendiente, activo, sin grado y con ingreso hoy.
- **Campo trampa:** `sitio_web`. El formulario lo lleva **oculto y vacío**; si llega con valor, la respuesta es la misma pero no se crea nada.
- **Respuesta (200):** solo un mensaje y el estado de la carga de documentos, sin datos ni ids.

Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=autorregistro&accion=registrar`

Cuerpo:

```json
{
    "documento": "1000000160",
    "nombre": "Andrés Registro Ficticio",
    "fecha_nacimiento": "1999-02-10",
    "sexo": "masculino",
    "estatura": 1.75,
    "peso": 72,
    "telefono": "3000000160",
    "correo": "andres.registro@correoficticio.test",
    "autorizacion_datos": true,
    "sitio_web": ""
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "mensaje": "Registro recibido. Queda en revisión hasta que el club lo valide; mientras tanto puedes cargar tus documentos.",
        "documentos": {
            "carga_habilitada": true,
            "vigente_hasta": "2026-10-28T08:59:57-05:00",
            "tipos_permitidos": [
                "documento_identidad",
                "formulario_inscripcion",
                "… (2 elementos más, omitidos en este ejemplo)"
            ],
            "cargados": []
        }
    }
}
```

- **Errores:**
  - `400` «Debes aceptar la autorización de tratamiento de datos para registrarte.»;
  - `400` por campos inválidos o vacíos, o JSON mal formado;
  - `413` si el cuerpo supera 128 KB;
  - `429` «Se recibieron demasiados registros desde esta conexión. Intenta de nuevo más tarde.» (10 por hora por IP).
- **Notas:** un documento que ya existe recibe **la misma respuesta 200** (para no revelar quién está inscrito), pero su permiso no deja subir documentos. El registro no entra a la cuenta pública hasta que el club lo valide.

#### `autorregistro.listarDocumentos` — GET · permiso de autorregistro

- **Respuesta:** los documentos de ese registro y hasta cuándo vale el permiso.

Ejemplo real (llamada como **permiso de autorregistro**): `GET api.php?recurso=autorregistro&accion=listarDocumentos`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documentos": [
            {
                "id": 4,
                "deportista_documento": "1000000160",
                "tipo_documento": "documento_identidad",
                "nombre_original": "cedula_legible.pdf",
                "fecha_carga": "2026-10-28"
            }
        ],
        "vigente_hasta": "2026-10-28T08:59:57-05:00"
    }
}
```

#### `autorregistro.subirDocumento` — POST multipart · permiso de autorregistro

- **Campos:**
  - `tipo_documento`: solo `documento_identidad`, `formulario_inscripcion`, `documento_acudiente` o `fotografia`;
  - `archivo`: formato según el tipo, máximo 10 MB;
  - `documento`: opcional; si viene, debe ser el del registro.
- **Respuesta (201):** el documento creado.

Ejemplo real (llamada como **permiso de autorregistro**): `POST api.php?recurso=autorregistro&accion=subirDocumento`

Cuerpo `multipart/form-data`:

```json
{
    "tipo_documento": "documento_identidad",
    "archivo": "(archivo cedula.pdf, 45 bytes)"
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 4,
        "deportista_documento": "1000000160",
        "tipo_documento": "documento_identidad",
        "nombre_original": "cedula.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

- **Errores:**
  - `409` «Ese documento ya está cargado: usa «reemplazarDocumento» para cambiarlo.»;
  - `403` por otro tipo o por un documento ajeno;
  - `400` por formato o tamaño.

#### `autorregistro.reemplazarDocumento` — POST multipart · permiso de autorregistro

- **URL:** `id` (de un documento propio).
- **Campos:** `archivo`.
- **Respuesta:** el documento con el nombre nuevo.

Ejemplo real (llamada como **permiso de autorregistro**): `POST api.php?recurso=autorregistro&accion=reemplazarDocumento&id=4`

Cuerpo `multipart/form-data`:

```json
{
    "archivo": "(archivo cedula_legible.pdf, 45 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 4,
        "deportista_documento": "1000000160",
        "tipo_documento": "documento_identidad",
        "nombre_original": "cedula_legible.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

- **Errores:** `404` «Documento no encontrado.» si es ajeno o no existe.

#### `autorregistro.eliminarDocumento` — DELETE · permiso de autorregistro

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`.

Ejemplo real (llamada como **permiso de autorregistro**): `DELETE api.php?recurso=autorregistro&accion=eliminarDocumento&id=5`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

#### `autorregistro.confirmar` — POST · permiso de autorregistro

- **Respuesta:** un mensaje. Cierra el permiso; desde ahí los documentos solo los cambia el club.

Ejemplo real (llamada como **permiso de autorregistro**): `POST api.php?recurso=autorregistro&accion=confirmar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "mensaje": "Documentos guardados. Desde ahora cualquier cambio lo hace el club."
    }
}
```

- **Notas:** el permiso también se cierra solo al vencer las 2 horas o cuando el club valida el registro.

---

### B.5 `importacion-deportistas` — carga masiva por CSV (2 acciones)

Solo administrativo. **No guarda el archivo:** las dos acciones reciben el mismo CSV.

- **Formato:** plantilla Excel guardada como CSV, en UTF-8 (con o sin BOM) o Windows-1252, separado por `;` o `,` (se detecta solo).
- **Encabezados:** sin distinguir mayúsculas ni tildes. Las columnas desconocidas o excluidas (género, grupo étnico, víctima del conflicto) se ignoran con un aviso.
- **Plantilla:** columnas, orden y ejemplo en `backend/Instalacion/plantilla_carga_masiva_deportistas.txt` (PROVISIONAL).

#### `importacion-deportistas.previsualizar` — POST multipart · administrativo

- **Campos:** `archivo` (`.csv`, máximo 1 MB y 1.000 filas).
- **Respuesta:** **no escribe nada.** Devuelve:
  - `archivo`: codificación, separador y número de filas;
  - `avisos`;
  - `resumen`: totales por clasificación;
  - `filas`: `linea`, `documento`, `nombre`, `clasificacion` y, según el caso, `cambios` (`[{campo, actual, nuevo}]`), `faltantes` o `motivo`.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=importacion-deportistas&accion=previsualizar`

Cuerpo `multipart/form-data`:

```json
{
    "archivo": "(archivo carga.csv, 323 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "archivo": {
            "codificacion": "UTF-8",
            "separador": ";",
            "filas": 3
        },
        "avisos": [],
        "resumen": {
            "total": 3,
            "nueva": 1,
            "actualizar": 1,
            "sin_cambios": 0,
            "incompleta": 1,
            "invalida": 0,
            "conflicto_correo": 0,
            "duplicada": 0
        },
        "filas": [
            {
                "linea": 2,
                "documento": "1000000170",
                "nombre": "Mateo Importado Ficticio",
                "clasificacion": "nueva"
            },
            {
                "linea": 3,
                "documento": "1000000103",
                "nombre": "Fabián Esteban Rincón Peña",
                "clasificacion": "actualizar",
                "cambios": [
                    {
                        "campo": "peso",
                        "actual": 78.5,
                        "nuevo": 76.5
                    }
                ]
            },
            {
                "linea": 4,
                "documento": "1000000171",
                "nombre": "Sin Peso Ficticio",
                "clasificacion": "incompleta",
                "faltantes": [
                    "peso"
                ],
                "motivo": "Faltan campos obligatorios: peso."
            }
        ]
    }
}
```

- **Errores:**
  - `400` «El encabezado no tiene la columna «documento». Usa la plantilla de carga masiva.»;
  - `400` si el archivo está vacío, no trae encabezados o no es un CSV de texto;
  - `413` si pasa de 1 MB;
  - `422` si pasa de 1.000 filas.
- **Notas:** una celda vacía **no borra** el dato de un deportista existente. Si un documento se repite en el archivo, se rechazan todas sus filas.

#### `importacion-deportistas.aplicar` — POST multipart · administrativo

- **Campos:**
  - `archivo`: el mismo CSV;
  - `confirmar_actualizacion`: documentos cuya actualización aprobó el administrativo, como lista (`confirmar_actualizacion[]=…`) o texto separado por comas.
- **Respuesta:** `resumen` (`creadas`, `actualizadas`, `omitidas`, `rechazadas`, `errores`) y resultado por fila.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=importacion-deportistas&accion=aplicar`

Cuerpo `multipart/form-data`:

```json
{
    "confirmar_actualizacion": "1000000103",
    "archivo": "(archivo carga.csv, 323 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "avisos": [],
        "resumen": {
            "creadas": 1,
            "actualizadas": 1,
            "omitidas": 0,
            "rechazadas": 1,
            "errores": 0
        },
        "filas": [
            {
                "linea": 2,
                "documento": "1000000170",
                "clasificacion": "nueva",
                "resultado": "creada"
            },
            {
                "linea": 3,
                "documento": "1000000103",
                "clasificacion": "actualizar",
                "resultado": "actualizada"
            },
            {
                "linea": 4,
                "documento": "1000000171",
                "clasificacion": "incompleta",
                "resultado": "rechazada",
                "motivo": "Faltan campos obligatorios: peso."
            }
        ]
    }
}
```

- **Notas:**
  - cada fila se guarda en su propia transacción: una que falla no revierte las demás;
  - las altas quedan **validadas**, con `validado_por_correo` = quien carga;
  - nunca cambia el documento ni `estado_registro` de un deportista existente;
  - orden de llamadas: `previsualizar` → el usuario marca las actualizaciones → `aplicar`.

---

### B.6 `documentos` (12 acciones)

Un documento tiene: `id`, `deportista_documento`, `tipo_documento`, `nombre_original` y `fecha_carga`. El nombre del archivo en disco nunca sale.

#### `documentos.listar` — GET · administrativo o público

- **URL:** `documento` (obligatorio para el administrativo). El público puede omitirlo: se usan los suyos.
- **Respuesta:** los documentos del deportista.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=listar&documento=1000000150`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 6,
            "deportista_documento": "1000000150",
            "tipo_documento": "formulario_inscripcion",
            "nombre_original": "formulario_valentina.pdf",
            "fecha_carga": "2026-10-28"
        }
    ]
}
```

Ejemplo real (llamada como **público**): `GET api.php?recurso=documentos&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 1,
            "deportista_documento": "1000000101",
            "tipo_documento": "documento_identidad",
            "nombre_original": "registro_civil_samuel.pdf",
            "fecha_carga": "2025-02-01"
        }
    ]
}
```

- **Errores:** `404` «Deportista no encontrado.» (inexistente, o ajeno para el público).

#### `documentos.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** los metadatos. Si es ajeno para el público o no existe: `404` «No encontrado.».

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=consultar&id=6`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 6,
        "deportista_documento": "1000000150",
        "tipo_documento": "formulario_inscripcion",
        "nombre_original": "formulario_valentina.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

#### `documentos.subir` — POST multipart · administrativo o público

- **Campos:** `documento`, `tipo_documento` y `archivo` (formato según el tipo, máximo 10 MB).
- **Respuesta (201):** el documento creado.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=documentos&accion=subir`

Cuerpo `multipart/form-data`:

```json
{
    "documento": "1000000150",
    "tipo_documento": "formulario_inscripcion",
    "archivo": "(archivo formulario_valentina.pdf, 45 bytes)"
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 6,
        "deportista_documento": "1000000150",
        "tipo_documento": "formulario_inscripcion",
        "nombre_original": "formulario_valentina.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

- **Errores:**
  - `409` «Ya hay un archivo de ese tipo para el deportista: usa «reemplazar».» (tipos de un solo archivo);
  - `400` «El campo "tipo_documento" debe ser uno de: …»;
  - `400` por formato o tamaño;
  - `404` si el deportista no existe.
- **Diferencias por ámbito:** el público solo sube **lo suyo** y solo los cuatro tipos de un solo archivo que aún estén vacíos. Para cambiar uno ya cargado recibe `409` «Ese documento ya está cargado. Para cambiarlo, comunícate con el club.»; para otros tipos, `403` «Este tipo de documento solo lo carga el personal administrativo.».

#### `documentos.reemplazar` — POST multipart · administrativo

- **URL:** `id`.
- **Campos:** `archivo`.
- **Respuesta:** el documento con el nombre nuevo. El archivo anterior se borra del disco.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=documentos&accion=reemplazar&id=6`

Cuerpo `multipart/form-data`:

```json
{
    "archivo": "(archivo formulario_valentina_firmado.pdf, 45 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 6,
        "deportista_documento": "1000000150",
        "tipo_documento": "formulario_inscripcion",
        "nombre_original": "formulario_valentina_firmado.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

#### `documentos.eliminar` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`. Borra la fila y el archivo.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=documentos&accion=eliminar&id=6`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

- **Errores:** `403` «Solo el ámbito administrativo puede eliminar documentos.»; `404`.

#### `documentos.descargar` — GET · administrativo o público (lo suyo)

- **URL:** `id`.
- **Respuesta:** el archivo con su `nombre_original` (A.8).

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=descargar&id=6`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/pdf
Content-Disposition: attachment; filename="formulario_valentina_firmado.pdf"; filename*=UTF-8''formulario_valentina_firmado.pdf
X-Content-Type-Options: nosniff
Content-Length: 45
```

- **Errores:** `404` «El archivo de este documento no está disponible en el servidor. Vuelve a cargarlo o comunícate con el club.» (la fila existe pero el archivo no está en el servidor); `404` «Documento no encontrado.».

#### `documentos.faltantes` — GET · administrativo

- **URL:** `documento` (opcional).
- **Respuesta:**
  - con `documento`: `{criticos, no_criticos}` de ese deportista. Críticos: identidad, formulario y, si es menor, el del acudiente; no crítico: la fotografía.
  - sin `documento`: la lista de **todos los pendientes de validación** con sus faltantes.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=faltantes&documento=1000000150`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "criticos": [
            "documento_identidad",
            "documento_acudiente"
        ],
        "no_criticos": [
            "fotografia"
        ]
    }
}
```

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=faltantes`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000163",
            "nombre": "Registro Con Asistencia",
            "criticos": [
                "documento_identidad",
                "formulario_inscripcion"
            ],
            "no_criticos": [
                "fotografia"
            ]
        }
    ]
}
```

#### `documentos.listarGenerales` — GET · administrativo o público

- **Respuesta:** documentos generales del club (p. ej., teorías de ascenso): `id`, `titulo`, `nombre_original` y `fecha_carga`.

Ejemplo real (llamada como **público**): `GET api.php?recurso=documentos&accion=listarGenerales`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 1,
            "titulo": "Teoría de ascenso — cinturón amarillo",
            "nombre_original": "teoria_ascenso_amarillo.pdf",
            "fecha_carga": "2026-03-01"
        },
        {
            "id": 2,
            "titulo": "Teoría para cinturón amarillo",
            "nombre_original": "teoria_amarillo.pdf",
            "fecha_carga": "2026-10-28"
        }
    ]
}
```

#### `documentos.descargarGeneral` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** el archivo.

Ejemplo real (llamada como **público**): `GET api.php?recurso=documentos&accion=descargarGeneral&id=2`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/pdf
Content-Disposition: attachment; filename="teoria_amarillo.pdf"; filename*=UTF-8''teoria_amarillo.pdf
X-Content-Type-Options: nosniff
Content-Length: 45
```

#### `documentos.subirGeneral` — POST multipart · administrativo

- **Campos:** `titulo` (obligatorio, máximo 150) y `archivo` (pdf, doc, docx, xls, xlsx, jpg, jpeg, png o webp; máximo 10 MB).
- **Respuesta (201):** el documento general.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=documentos&accion=subirGeneral`

Cuerpo `multipart/form-data`:

```json
{
    "titulo": "Teoría para cinturón amarillo",
    "archivo": "(archivo teoria_amarillo.pdf, 45 bytes)"
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "titulo": "Teoría para cinturón amarillo",
        "nombre_original": "teoria_amarillo.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

#### `documentos.reemplazarGeneral` — POST multipart · administrativo

- **URL:** `id`.
- **Campos:** `archivo` y `titulo` (opcional).
- **Respuesta:** el documento general actualizado.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=documentos&accion=reemplazarGeneral&id=2`

Cuerpo `multipart/form-data`:

```json
{
    "titulo": "Teoría para cinturón amarillo (2026)",
    "archivo": "(archivo teoria_amarillo_v2.pdf, 45 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "titulo": "Teoría para cinturón amarillo (2026)",
        "nombre_original": "teoria_amarillo_v2.pdf",
        "fecha_carga": "2026-10-28"
    }
}
```

#### `documentos.eliminarGeneral` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=documentos&accion=eliminarGeneral&id=2`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

---
### B.7 `asistencia` (10 acciones)

La asistencia solo se toma en **sábado o domingo**, de hoy hacia atrás (hora de Bogotá). Una marca es `{deportista_documento, fecha, asistio}`.

#### `asistencia.listarPantalla` — GET · administrativo

- **URL:** `fecha` (sábado o domingo, no futura).
- **Respuesta:** datos para la pantalla de tomar asistencia:
  - `tomada`: si esa fecha ya tiene marcas;
  - `activos` e `inactivos`: deportistas validados, cada uno con `documento`, `nombre`, `discapacidad` (para que quien toma la asistencia la conozca) y `asistio` (`null` si no tiene marca esa fecha).

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=listarPantalla&fecha=2026-10-24`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "fecha": "2026-10-24",
        "tomada": false,
        "activos": [
            {
                "documento": "1000000102",
                "nombre": "Juliana Andrea Castillo Vargas",
                "discapacidad": null,
                "asistio": null
            },
            {
                "documento": "1000000101",
                "nombre": "Samuel Reyes Ortiz",
                "discapacidad": null,
                "asistio": null
            },
            {
                "documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "discapacidad": null,
                "asistio": null
            }
        ],
        "inactivos": [
            {
                "documento": "1000000103",
                "nombre": "Fabián Esteban Rincón Peña",
                "discapacidad": null,
                "asistio": null
            }
        ]
    }
}
```

- **Errores:** `400` «La asistencia solo se registra en sábado o domingo.», «La fecha no puede ser futura.» o fecha inválida.

#### `asistencia.tomar` — POST · administrativo

- **Cuerpo:** `fecha` (sábado o domingo, no futura) y `presentes` (lista de documentos; puede ir vacía).
- **Respuesta (201):** `fecha`, `registrados` (filas creadas), `presentes` y `reactivados`.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=asistencia&accion=tomar`

Cuerpo:

```json
{
    "fecha": "2026-10-24",
    "presentes": [
        "1000000101",
        "1000000103"
    ]
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "fecha": "2026-10-24",
        "registrados": 3,
        "presentes": 2,
        "reactivados": [
            "1000000103"
        ]
    }
}
```

- **Errores:**
  - `409` «La asistencia de esa fecha ya fue tomada: usa «corregir» para cambiar una marca.»;
  - `400` «La asistencia solo se registra en sábado o domingo.»;
  - `400` «Documentos que no se pueden marcar (no existen o están pendientes de validación): …» (no se guarda nada);
  - `400` «El campo "presentes" debe ser una lista de documentos.».
- **Notas:**
  - crea una fila por cada deportista **activo** (presente o ausente) en una transacción;
  - un **inactivo marcado presente se reactiva** solo (aparece en `reactivados`);
  - en una fecha pasada, quien ingresó después de esa fecha no recibe «ausente».

#### `asistencia.listar` — GET · administrativo o público

- **URL:** `fecha`, o `desde` + `hasta`, o `documento`. Se pueden combinar `documento` y un rango. El administrativo debe enviar al menos uno.
- **Respuesta:** las marcas, por fecha.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=listar&fecha=2026-10-24`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "deportista_documento": "1000000101",
            "fecha": "2026-10-24",
            "asistio": true
        },
        {
            "deportista_documento": "1000000102",
            "fecha": "2026-10-24",
            "asistio": false
        },
        {
            "deportista_documento": "1000000103",
            "fecha": "2026-10-24",
            "asistio": true
        }
    ]
}
```

Ejemplo real (llamada como **público**): `GET api.php?recurso=asistencia&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "deportista_documento": "1000000101",
            "fecha": "2026-03-07",
            "asistio": true
        },
        {
            "deportista_documento": "1000000101",
            "fecha": "2026-03-14",
            "asistio": false
        },
        {
            "deportista_documento": "1000000101",
            "fecha": "2026-10-24",
            "asistio": true
        }
    ]
}
```

- **Diferencias por ámbito:** el público solo ve las suyas (sin `documento` usa el propio); un documento ajeno responde `404`.
- **Errores:** `400` «Indica una fecha, un rango (desde y hasta) o un documento.», «Para un rango envía "desde" y "hasta".», «"desde" no puede ser posterior a "hasta".».

#### `asistencia.consultar` — GET · administrativo o público (lo suyo)

- **URL:** `documento` y `fecha`.
- **Respuesta:** la marca. Si no existe o es ajena: `404` «No encontrado.».

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=consultar&documento=1000000101&fecha=2026-10-24`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "deportista_documento": "1000000101",
        "fecha": "2026-10-24",
        "asistio": true
    }
}
```

#### `asistencia.resumenDia` — GET · administrativo

- **Respuesta:** tarjeta del dashboard: `fecha`, `presentes`, `total` y `es_hoy`. Si hoy no hubo sesión, trae la última fecha tomada; si nunca hubo, `fecha: null`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=resumenDia`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "fecha": "2026-10-28",
        "presentes": 1,
        "total": 1,
        "es_hoy": true
    }
}
```

#### `asistencia.corregir` — PUT · administrativo

- **URL:** `documento` y `fecha`.
- **Cuerpo:** `asistio` (booleano).
- **Respuesta:** la marca, con `reactivado` (true si quedó presente y el deportista estaba inactivo).

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=asistencia&accion=corregir&documento=1000000102&fecha=2026-10-24`

Cuerpo:

```json
{
    "asistio": true
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "deportista_documento": "1000000102",
        "fecha": "2026-10-24",
        "asistio": true,
        "reactivado": false
    }
}
```

- **Errores:** `404` «No hay marca de asistencia para ese deportista en esa fecha.»; `400` «El campo "asistio" debe ser verdadero o falso.».

#### `asistencia.eliminarMarca` — DELETE · administrativo

- **URL:** `documento` y `fecha`.
- **Respuesta:** `{"eliminado": true}`. No revierte una reactivación.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=asistencia&accion=eliminarMarca&documento=1000000102&fecha=2026-10-24`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

#### `asistencia.eliminarFecha` — DELETE · administrativo

- **URL:** `fecha`.
- **Respuesta:** `fecha` y `eliminadas` (cuántas filas borró). Borra la fecha completa tomada por error.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=asistencia&accion=eliminarFecha&fecha=2026-10-17`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "fecha": "2026-10-17",
        "eliminadas": 3
    }
}
```

- **Errores:** `404` «Esa fecha no tiene asistencia tomada.».

#### `asistencia.porcentaje` — GET · administrativo o público (lo suyo)

- **URL:** `documento` (obligatorio para el administrativo; el público puede omitirlo) y `ventana` (`30`, `90` o `todo`; por defecto `90`).
- **Respuesta:** `documento`, `ventana`, `desde` (el inicio de la ventana o la fecha de ingreso, la que sea posterior), `sesiones`, `presentes`, `porcentaje` (1 decimal) y `datos_incompletos`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=porcentaje&documento=1000000101&ventana=todo`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000101",
        "ventana": "todo",
        "desde": "2025-02-01",
        "sesiones": 3,
        "presentes": 2,
        "porcentaje": null,
        "datos_incompletos": true
    }
}
```

- **Notas:** con menos de 4 sesiones, `porcentaje` es `null` y `datos_incompletos` es `true`: el front debe mostrar «datos insuficientes», no un 0.
- **Errores:** `400` «El parámetro "documento" es obligatorio.» o «El parámetro "ventana" debe ser 30, 90 o todo.»; `404`.

#### `asistencia.revisarInactivos` — POST · administrativo

- **Respuesta:** `fecha_limite` y `desactivados`. Pasa a **inactivo** a cada deportista activo que lleva 3 meses o más sin asistir (contados desde su última asistencia, o desde el ingreso si es posterior o si nunca asistió). Es idempotente.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=asistencia&accion=revisarInactivos`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "fecha_limite": "2026-07-28",
        "desactivados": [
            "1000000102"
        ]
    }
}
```

- **Notas:** es el botón «Revisar inactivos»; la inactividad no se aplica sola. El front debería pedir confirmación.

---

### B.8 `pagos` (6 acciones)

Solo se guardan los pagos hechos; no hay montos. Un pago es `{id, deportista_documento, tipo, anio, mes, semana}`; la matrícula lleva `mes: 0` y `semana: null`.

#### `pagos.registrar` — POST · administrativo

- **Cuerpo:**
  - mensualidad: `documento`, `tipo: "mensualidad"`, `anio` (2020 hasta el año siguiente) y `pagos` (lista de `{mes, semana}`, con mes 1–12 sin repetir y semana 1–4: la semana del mes en que se pagó);
  - matrícula: `documento`, `tipo: "matricula"` y `anio`, sin `pagos`.
- **Respuesta (201):** los pagos creados.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=pagos&accion=registrar`

Cuerpo:

```json
{
    "documento": "1000000150",
    "tipo": "mensualidad",
    "anio": 2026,
    "pagos": [
        {
            "mes": 10,
            "semana": 2
        },
        {
            "mes": 11,
            "semana": 2
        }
    ]
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 4,
            "deportista_documento": "1000000150",
            "tipo": "mensualidad",
            "anio": 2026,
            "mes": 10,
            "semana": 2
        },
        {
            "id": 5,
            "deportista_documento": "1000000150",
            "tipo": "mensualidad",
            "anio": 2026,
            "mes": 11,
            "semana": 2
        }
    ]
}
```

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=pagos&accion=registrar`

Cuerpo:

```json
{
    "documento": "1000000150",
    "tipo": "matricula",
    "anio": 2026
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 6,
            "deportista_documento": "1000000150",
            "tipo": "matricula",
            "anio": 2026,
            "mes": 0,
            "semana": null
        }
    ]
}
```

- **Errores:**
  - `409` «Ya están pagados los meses 10 de 2026. No se guardó ningún pago.» (todo o nada);
  - `409` «La matrícula de 2026 ya está pagada.»;
  - `400` «El deportista está pendiente de validación: aún no se le registran pagos.»;
  - `400` «Hay meses repetidos en el mismo registro.»;
  - `400` «La matrícula no lleva "pagos" ni semana.»;
  - `404` si el deportista no existe.
- **Notas:** se pueden pagar meses futuros (adelantados).

#### `pagos.anular` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`. El mes vuelve a quedar sin pagar. La doble confirmación la hace el front.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=pagos&accion=anular&id=5`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

- **Errores:** `404` «Pago no encontrado.».

#### `pagos.estado` — GET · administrativo o público (lo suyo)

- **URL:** `documento` (obligatorio para el administrativo) y `anio` (opcional; por defecto el actual).
- **Respuesta:**
  - `semana_habitual`: semana del último pago, o la del ingreso si nunca pagó;
  - `matricula`: `{pagada, pendiente, atrasada}`;
  - `meses`: 12 elementos, cada uno con `mes`, `pagado`, `semana`, `vencimiento` (el primer día de la semana habitual), `vencido` y `en_deuda`;
  - `meses_en_deuda`, `por_vencer` (`{anio, mes, vencimiento}` o null), `al_dia` y `genera_alertas` (solo los activos validados generan alertas).

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=estado&documento=1000000101&anio=2026`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "documento": "1000000101",
        "anio": 2026,
        "semana_habitual": 1,
        "matricula": {
            "pagada": true,
            "pendiente": false,
            "atrasada": false
        },
        "meses": [
            {
                "mes": 1,
                "pagado": false,
                "semana": null,
                "vencimiento": "2026-01-01",
                "vencido": true,
                "en_deuda": false
            },
            {
                "mes": 2,
                "pagado": false,
                "semana": null,
                "vencimiento": "2026-02-01",
                "vencido": true,
                "en_deuda": false
            },
            "… (10 elementos más, omitidos en este ejemplo)"
        ],
        "meses_en_deuda": [],
        "por_vencer": {
            "mes": 11,
            "anio": 2026,
            "vencimiento": "2026-11-01",
            "dias_restantes": 4
        },
        "al_dia": true,
        "genera_alertas": true
    }
}
```

- **Notas:** «deuda» = mes vencido, sin pago y con 2 o más asistencias desde el vencimiento. Un mes vencido sin asistencias no es deuda. La matrícula nunca cuenta como deuda.

#### `pagos.deudores` — GET · administrativo

- **Respuesta:** deudores del **año en curso**: `[{documento, nombre, meses_en_deuda: [mes, …]}]`, por nombre.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=deudores`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": []
}
```

- **Notas:** en la semilla no había deudores ese día, por eso la lista llegó vacía. La forma de cada elemento es la indicada arriba (ver también `reportes.pagos`).

#### `pagos.listar` — GET · administrativo o público (lo suyo)

- **URL (filtros opcionales):** `documento`, `anio` y `tipo`.
- **Respuesta:** los pagos, por año, mes e id.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=listar&documento=1000000150&anio=2026`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 6,
            "deportista_documento": "1000000150",
            "tipo": "matricula",
            "anio": 2026,
            "mes": 0,
            "semana": null
        },
        {
            "id": 4,
            "deportista_documento": "1000000150",
            "tipo": "mensualidad",
            "anio": 2026,
            "mes": 10,
            "semana": 2
        },
        {
            "id": 5,
            "deportista_documento": "1000000150",
            "tipo": "mensualidad",
            "anio": 2026,
            "mes": 11,
            "semana": 2
        }
    ]
}
```

#### `pagos.consultar` — GET · administrativo o público (lo suyo)

- **URL:** `id`.
- **Respuesta:** el pago. Si es ajeno o no existe: `404` «No encontrado.».

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=consultar&id=5`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 5,
        "deportista_documento": "1000000150",
        "tipo": "mensualidad",
        "anio": 2026,
        "mes": 11,
        "semana": 2
    }
}
```

---

### B.9 `calendario` (6 acciones)

Un evento es `{id, nombre, tipo, fecha, lugar, visibilidad, observaciones}`. El público solo ve los de visibilidad `publica`.

#### `calendario.listar` — GET · administrativo o público

- **URL (opcionales):** `desde` + `hasta` (juntos) y `tipo`.
- **Respuesta:** los eventos visibles, por fecha y nombre.

Ejemplo real (llamada como **público**): `GET api.php?recurso=calendario&accion=listar&desde=2026-04-01&hasta=2026-06-30`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 2,
            "nombre": "Exámenes de ascenso abril 2026 (hasta el día del grado)",
            "tipo": "evaluacion",
            "fecha": "2026-04-26",
            "lugar": "Sede Club Hwang Hon",
            "visibilidad": "publica",
            "observaciones": null
        },
        {
            "id": 5,
            "nombre": "Grado abril 2026",
            "tipo": "grado",
            "fecha": "2026-05-03",
            "lugar": "Sede Club Hwang Hon",
            "visibilidad": "publica",
            "observaciones": null
        },
        "… (2 elementos más, omitidos en este ejemplo)"
    ]
}
```

#### `calendario.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** el evento. Uno administrativo pedido por el público responde `404` «No encontrado.».

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=calendario&accion=consultar&id=3`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 3,
        "nombre": "Reunión de staff trimestral",
        "tipo": "reunion_administrativa",
        "fecha": "2026-04-15",
        "lugar": "Sede Club Hwang Hon",
        "visibilidad": "administrativa",
        "observaciones": "Revisión de indicadores del trimestre"
    }
}
```

#### `calendario.crear` — POST · administrativo

- **Cuerpo:**
  - `nombre` y `lugar`: obligatorios, máximo 150;
  - `tipo` y `fecha`: obligatorios;
  - `visibilidad`: obligatoria (salvo en reuniones);
  - `observaciones`: opcional, máximo 65.000 bytes;
  - `confirmar_confidencial: true`: obligatorio para una `reunion_administrativa`, que queda siempre administrativa.
- **Respuesta (201):** el evento.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=calendario&accion=crear`

Cuerpo:

```json
{
    "nombre": "Clausura de fin de año",
    "tipo": "evento_extra",
    "fecha": "2026-12-12",
    "lugar": "Sede principal",
    "visibilidad": "publica",
    "observaciones": "Traer uniforme"
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 6,
        "nombre": "Clausura de fin de año",
        "tipo": "evento_extra",
        "fecha": "2026-12-12",
        "lugar": "Sede principal",
        "visibilidad": "publica",
        "observaciones": "Traer uniforme"
    }
}
```

- **Errores:** `400` «Una reunión administrativa es confidencial (solo visible en el ámbito administrativo): confírmalo enviando "confirmar_confidencial": true.»; `409` «Ya existe un evento con ese nombre en esa fecha.».
- **Notas:** los eventos de exámenes y grados los crea Evaluaciones; los torneos se crean aquí (tipo `competencia`) y luego en Competencias. Los eventos no se eliminan.

#### `calendario.actualizar` — PUT · administrativo

- **URL:** `id`.
- **Cuerpo (parcial):** `nombre`, `fecha`, `lugar`, `visibilidad` y `observaciones`. El `tipo` no se cambia.
- **Respuesta:** el evento actualizado.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=calendario&accion=actualizar&id=6`

Cuerpo:

```json
{
    "fecha": "2026-12-13",
    "observaciones": "Traer uniforme completo"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 6,
        "nombre": "Clausura de fin de año",
        "tipo": "evento_extra",
        "fecha": "2026-12-13",
        "lugar": "Sede principal",
        "visibilidad": "publica",
        "observaciones": "Traer uniforme completo"
    }
}
```

- **Errores:**
  - `409` «La fecha, el lugar y la visibilidad de este evento pertenecen a un torneo: edítalos en Competencias (actualizar el torneo).»: la fecha, el lugar y la visibilidad de un evento de torneo se editan en `competencias.actualizar`, y los de una sesión de examen en `evaluaciones.actualizar`; el mensaje indica cuál. El nombre y las observaciones sí se editan aquí;
  - `400` «El tipo de un evento no se cambia.»;
  - `400` «Una reunión administrativa siempre es de visibilidad administrativa (confidencial).»;
  - `409` por duplicado.

#### `calendario.vista` — GET · administrativo o público

- **URL:** `desde` y `hasta` (obligatorios, máximo 366 días).
- **Respuesta:**
  - `eventos`: los visibles;
  - `cumpleanos` y `vencimientos` (mensualidades sin pagar), calculados y agrupados por día: `{fecha, cantidad, deportistas: [{documento, nombre}]}`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=calendario&accion=vista&desde=2026-03-01&hasta=2026-03-31`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eventos": [],
        "cumpleanos": [
            {
                "fecha": "2026-03-12",
                "cantidad": 1,
                "deportistas": [
                    {
                        "documento": "1000000101",
                        "nombre": "Samuel Reyes Ortiz"
                    }
                ]
            }
        ],
        "vencimientos": [
            {
                "fecha": "2026-03-08",
                "cantidad": 1,
                "deportistas": [
                    {
                        "documento": "1000000103",
                        "nombre": "Fabián Esteban Rincón Peña"
                    }
                ]
            }
        ]
    }
}
```

- **Diferencias por ámbito:** el público solo ve los eventos públicos, **su** cumpleaños y **sus** vencimientos. El administrativo ve todos los de los deportistas activos y validados.

Ejemplo real (llamada como **público**): `GET api.php?recurso=calendario&accion=vista&desde=2026-03-01&hasta=2026-03-31`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eventos": [],
        "cumpleanos": [
            {
                "fecha": "2026-03-12",
                "cantidad": 1,
                "deportistas": [
                    {
                        "documento": "1000000101",
                        "nombre": "Samuel Reyes Ortiz"
                    }
                ]
            }
        ],
        "vencimientos": []
    }
}
```

- **Errores:** `400` «Indica el rango con "desde" y "hasta".» o «El rango no puede superar 366 días.».

#### `calendario.importar` — POST multipart · administrativo

- **Campos:** `archivo` (`.csv` en UTF-8, con o sin BOM; separador `;` o `,`; máximo 500 filas).
- **Columnas exactas:** `nombre;tipo;fecha;lugar;visibilidad;observaciones`. La fecha va como `AAAA-MM-DD` o `DD/MM/AAAA`.
- **Respuesta:**
  - `creados`;
  - `omitidos`: duplicados contra la base o dentro del archivo, con su línea;
  - `errores`: filas inválidas, con su línea y el motivo.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=calendario&accion=importar`

Cuerpo `multipart/form-data`:

```json
{
    "archivo": "(archivo eventos.csv, 249 bytes)"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "creados": 1,
        "omitidos": [
            {
                "linea": 3,
                "nombre": "Clausura de fin de año",
                "fecha": "2026-12-13"
            }
        ],
        "errores": [
            {
                "linea": 4,
                "motivo": "El campo \"fecha\" debe ser una fecha válida con formato AAAA-MM-DD."
            }
        ]
    }
}
```

- **Errores:** `400` «El encabezado debe tener las columnas: …», «El archivo debe estar guardado en UTF-8 (en Excel: «CSV UTF-8»).» o «El archivo supera el máximo de 500 filas.».

---

### B.10 `competencias` — torneos (9 acciones)

- **Torneo:** el evento del calendario de tipo `competencia` más `plataforma`, `tipo_torneo` y `num_participantes`. Su id es el `evento_id`.
- **Participación:** `{deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente}`. `notas_oponente` solo la ve el ámbito administrativo.

#### `competencias.listar` — GET · administrativo o público

- **URL (opcionales):** `deportista`, `desde`, `hasta`, `lugar` (contiene el texto) y `tipo_torneo`.
- **Respuesta:** los torneos, del más reciente al más antiguo.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=competencias&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "evento_id": 1,
            "nombre": "Copa Ficticia Hwang Hon 2026",
            "fecha": "2026-05-10",
            "lugar": "Coliseo Municipal de Soacha",
            "visibilidad": "publica",
            "observaciones": null,
            "plataforma": "Fitofan",
            "tipo_torneo": "departamental",
            "num_participantes": 2
        }
    ]
}
```

- **Diferencias por ámbito:** el público solo ve torneos públicos y cada uno trae `participa` (si él está inscrito). Solo puede filtrar por su propio documento.

Ejemplo real (llamada como **público**): `GET api.php?recurso=competencias&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "evento_id": 1,
            "nombre": "Copa Ficticia Hwang Hon 2026",
            "fecha": "2026-05-10",
            "lugar": "Coliseo Municipal de Soacha",
            "visibilidad": "publica",
            "observaciones": null,
            "plataforma": "Fitofan",
            "tipo_torneo": "departamental",
            "num_participantes": 2,
            "participa": true
        }
    ]
}
```

#### `competencias.consultar` — GET · administrativo o público

- **URL:** `id` (el `evento_id`).
- **Respuesta:** el torneo con `participantes` (cada participación incluye el `nombre`).

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=competencias&accion=consultar&id=1`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 1,
        "nombre": "Copa Ficticia Hwang Hon 2026",
        "fecha": "2026-05-10",
        "lugar": "Coliseo Municipal de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "departamental",
        "num_participantes": 2,
        "participantes": [
            {
                "nombre": "Juliana Andrea Castillo Vargas",
                "deportista_documento": "1000000102",
                "competencia_evento_id": 1,
                "modalidad": "combate_individual",
                "categoria_edad": "mayores",
                "categoria_peso": null,
                "puesto": 3,
                "resultado": null,
                "medalla": "bronce",
                "combates_ganados": 2,
                "combates_perdidos": 1,
                "notas_oponente": null
            },
            {
                "nombre": "Juliana Andrea Castillo Vargas",
                "deportista_documento": "1000000102",
                "competencia_evento_id": 1,
                "modalidad": "defensa_personal",
                "categoria_edad": "mayores",
                "categoria_peso": null,
                "puesto": null,
                "resultado": "Participó sin clasificar",
                "medalla": "ninguna",
                "combates_ganados": null,
                "combates_perdidos": null,
                "notas_oponente": null
            },
            {
                "nombre": "Samuel Reyes Ortiz",
                "deportista_documento": "1000000101",
                "competencia_evento_id": 1,
                "modalidad": "formas",
                "categoria_edad": "infantil",
                "categoria_peso": null,
                "puesto": 1,
                "resultado": "Primer lugar categoría infantil",
                "medalla": "oro",
                "combates_ganados": null,
                "combates_perdidos": null,
                "notas_oponente": null
            }
        ]
    }
}
```

- **Diferencias por ámbito:** el público solo consulta torneos públicos, ve **solo su propia participación** y nunca `notas_oponente`.

Ejemplo real (llamada como **público**): `GET api.php?recurso=competencias&accion=consultar&id=1`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 1,
        "nombre": "Copa Ficticia Hwang Hon 2026",
        "fecha": "2026-05-10",
        "lugar": "Coliseo Municipal de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "departamental",
        "num_participantes": 2,
        "participantes": [
            {
                "nombre": "Samuel Reyes Ortiz",
                "deportista_documento": "1000000101",
                "competencia_evento_id": 1,
                "modalidad": "formas",
                "categoria_edad": "infantil",
                "categoria_peso": null,
                "puesto": 1,
                "resultado": "Primer lugar categoría infantil",
                "medalla": "oro",
                "combates_ganados": null,
                "combates_perdidos": null
            }
        ]
    }
}
```

#### `competencias.proximo` — GET · administrativo

- **Respuesta:** el próximo torneo (fecha ≥ hoy) con `num_participantes`, o `null` si no hay. Es para las tarjetas del dashboard.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=competencias&accion=proximo`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 8,
        "nombre": "Copa Departamental Ficticia",
        "fecha": "2026-12-07",
        "lugar": "Coliseo de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "departamental",
        "num_participantes": 0
    }
}
```

- **Errores:** `403` «Solo el ámbito administrativo puede ver el próximo torneo.».

#### `competencias.crear` — POST · administrativo

- **Cuerpo:** `tipo_torneo` (obligatorio), `plataforma` (opcional, máximo 50) y **uno** de estos dos:
  - `evento_id`: un evento existente de tipo competencia que aún no tiene torneo;
  - `evento`: objeto con `nombre`, `fecha`, `lugar`, `visibilidad` y `observaciones`, para crear el evento y el torneo juntos (modo excepcional).
- **Respuesta (201):** el torneo con `participantes: []`.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=competencias&accion=crear`

Cuerpo:

```json
{
    "tipo_torneo": "departamental",
    "plataforma": "Fitofan",
    "evento": {
        "nombre": "Copa Departamental Ficticia",
        "fecha": "2026-12-07",
        "lugar": "Coliseo de Soacha",
        "visibilidad": "publica",
        "observaciones": null
    }
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 8,
        "nombre": "Copa Departamental Ficticia",
        "fecha": "2026-12-07",
        "lugar": "Coliseo de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "departamental",
        "num_participantes": 0,
        "participantes": []
    }
}
```

- **Errores:**
  - `422` «El evento no es de tipo competencia.»;
  - `422` «Envía "evento_id" (evento existente) o "evento" (creación excepcional), no los dos.»;
  - `400` «Falta "evento_id" o "evento".»;
  - `409` «Ese evento ya tiene su torneo creado.».

#### `competencias.actualizar` — PUT · administrativo

- **URL:** `id`.
- **Cuerpo (parcial):**
  - `plataforma` (máximo 50) y `tipo_torneo`;
  - `fecha`, `lugar` y `visibilidad` del evento, con las mismas validaciones que Calendario: fecha `AAAA-MM-DD` real, lugar obligatorio de máximo 150 y visibilidad `administrativa` o `publica`.
- **Nombre y observaciones:** se cambian en `calendario.actualizar`.
- **Torneo con resultados:** si ya tiene algún puesto, resultado, medalla o combate registrado, no se puede mover a una fecha futura (sí corregirla a otra fecha pasada o de hoy).
- **Respuesta:** el torneo, con los datos del evento ya actualizados.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=competencias&accion=actualizar&id=8`

Cuerpo:

```json
{
    "tipo_torneo": "nacional"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 8,
        "nombre": "Copa Departamental Ficticia",
        "fecha": "2026-12-07",
        "lugar": "Coliseo de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "nacional",
        "num_participantes": 0,
        "participantes": []
    }
}
```

Fecha, lugar y visibilidad:

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=competencias&accion=actualizar&id=8`

Cuerpo:

```json
{
    "fecha": "2026-12-12",
    "lugar": "Coliseo Municipal de Soacha",
    "visibilidad": "administrativa"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 8,
        "nombre": "Copa Departamental Ficticia",
        "fecha": "2026-12-12",
        "lugar": "Coliseo Municipal de Soacha",
        "visibilidad": "administrativa",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "nacional",
        "num_participantes": 0,
        "participantes": []
    }
}
```

- **Errores:**
  - `400` «Aquí se editan plataforma, tipo_torneo, fecha, lugar y visibilidad; el nombre y las observaciones del evento se editan en Calendario. Campos no permitidos (nombre).»;
  - `400` por fecha, lugar o visibilidad inválidos;
  - `422` «El torneo ya tiene resultados registrados: no se puede mover a una fecha futura.»;
  - `409` «Ya existe un evento con ese nombre en esa fecha.»;
  - `404` «Torneo no encontrado.».

#### `competencias.sugerencia` — GET · administrativo

- **URL:** `deportista_documento`, `competencia_evento_id` y `modalidad` (opcional).
- **Respuesta:**
  - `edad_a_la_fecha` (del torneo);
  - `categoria_edad_sugerida` (valor del ENUM);
  - `peso_registrado`;
  - `categoria_peso_sugerida`: solo en modalidades de combate, o si no se envía modalidad;
  - `recomendacion_peso`: texto PROPUESTO; nunca bloquea.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=competencias&accion=sugerencia&deportista_documento=1000000150&competencia_evento_id=8&modalidad=combate_individual`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "edad_a_la_fecha": 13,
        "categoria_edad_sugerida": "junior",
        "peso_registrado": 41.2,
        "categoria_peso_sugerida": "-43",
        "recomendacion_peso": "Supera el corte de -38 kg por 3,2 kg: se recomienda mantener el peso para no subir de categoría."
    }
}
```

#### `competencias.inscribir` — POST · administrativo

- **Cuerpo:** `competencia_evento_id` y `participantes` (máximo 50): `[{deportista_documento, modalidad, categoria_edad?, categoria_peso?}]`. Lo que falte se completa con lo sugerido.
- **Respuesta (201):** las participaciones creadas. Es todo o nada.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=competencias&accion=inscribir`

Cuerpo:

```json
{
    "competencia_evento_id": 8,
    "participantes": [
        {
            "deportista_documento": "1000000150",
            "modalidad": "combate_individual"
        },
        {
            "deportista_documento": "1000000101",
            "modalidad": "formas"
        }
    ]
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": [
        {
            "deportista_documento": "1000000150",
            "competencia_evento_id": 8,
            "modalidad": "combate_individual",
            "categoria_edad": "junior",
            "categoria_peso": "-43",
            "puesto": null,
            "resultado": null,
            "medalla": null,
            "combates_ganados": null,
            "combates_perdidos": null,
            "notas_oponente": null
        },
        {
            "deportista_documento": "1000000101",
            "competencia_evento_id": 8,
            "modalidad": "formas",
            "categoria_edad": "junior",
            "categoria_peso": null,
            "puesto": null,
            "resultado": null,
            "medalla": null,
            "combates_ganados": null,
            "combates_perdidos": null,
            "notas_oponente": null
        }
    ]
}
```

- **Errores:**
  - `409` «El deportista 1000000150 ya está inscrito en combate_individual en este torneo.»;
  - `422` «El deportista X no tiene categoría de edad sugerida (menor de 8 años a la fecha del torneo): envía categoria_edad.»;
  - `404` «Deportista X no encontrado.»;
  - `400` «Máximo 50 participantes por inscripción.».
- **Notas:** el mismo deportista puede estar en varias modalidades del mismo torneo. Se pueden inscribir deportistas pendientes de validación.

#### `competencias.actualizarParticipacion` — PUT · administrativo

- **URL:** `deportista_documento`, `competencia_evento_id` y `modalidad`.
- **Cuerpo (parcial; `null` limpia):**
  - `categoria_edad` y `categoria_peso`;
  - `puesto` (1–99), `resultado` (máximo 255) y `medalla`;
  - `combates_ganados` y `combates_perdidos` (0–127, solo en modalidades de combate);
  - `notas_oponente` (máximo 65.000 bytes).
- **Respuesta:** la participación.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=competencias&accion=actualizarParticipacion&deportista_documento=1000000101&competencia_evento_id=1&modalidad=formas`

Cuerpo:

```json
{
    "resultado": "Primer lugar en formas",
    "notas_oponente": "Sin oponente directo"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "deportista_documento": "1000000101",
        "competencia_evento_id": 1,
        "modalidad": "formas",
        "categoria_edad": "infantil",
        "categoria_peso": null,
        "puesto": 1,
        "resultado": "Primer lugar en formas",
        "medalla": "oro",
        "combates_ganados": null,
        "combates_perdidos": null,
        "notas_oponente": "Sin oponente directo"
    }
}
```

- **Errores:**
  - `422` «El torneo todavía no se ha realizado: los resultados se cargan después de la fecha del torneo.» (puesto, resultado, medalla y combates en un torneo futuro);
  - `422` «"combates_ganados" solo aplica a las modalidades de combate.»;
  - `400` «La modalidad no se cambia: quita al participante y vuelve a inscribirlo.»;
  - `404` «Participación no encontrada.».
- **Notas:** el puesto lo digita el club (puede repetirse) y la medalla es independiente del puesto. Los combates alimentan el récord del deportista.

#### `competencias.quitarParticipante` — DELETE · administrativo

- **URL:** `deportista_documento`, `competencia_evento_id` y `modalidad`.
- **Respuesta:** `{"eliminado": true}`. Los torneos no se eliminan; solo se quitan participantes.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=competencias&accion=quitarParticipante&deportista_documento=1000000101&competencia_evento_id=8&modalidad=formas`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

---

### B.11 `evaluaciones` — exámenes de ascenso (13 acciones)

- **Sesión:** `{id, fecha (inicio), fecha_grado, lugar, estado, evento_examen_id, evento_grado_id}` y sus `participantes`.
- **Participante:** `grado_actual`, `grado_evaluado`, las **seis modalidades** (cada una con `estado`, `fecha_completado`, `nota`, `criterios_evaluados`, `puntajes`, `valor_medible`, `aspectos_fallidos`, `observaciones`, `recomendaciones` y `aprobado`), `resultado_general` (`{completo, promedio, aprobado}`) y `entrega` (de cinturón).
- **Resultado PROVISIONAL** (plantilla de Aldrin): aprueba con promedio de las seis notas ≥ 5.

#### `evaluaciones.candidatos` — GET · administrativo

- **URL:** `sesion_id` **o** `fecha` (la fecha de inicio, antes de crear la sesión); solo uno.
- **Respuesta:** deportistas activos y validados, cada uno con:
  - `grado_actual`, `grado_siguiente`, `edad` y `fecha_ultimo_ascenso`;
  - `ventana_desde`, `sesiones_en_ventana`, `porcentaje_inasistencia` y `umbral` (30 % hasta 16 años, 40 % desde 17);
  - `apto` (null sin datos) y `avisos`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=evaluaciones&accion=candidatos&fecha=2026-10-25`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000103",
            "nombre": "Fabián Esteban Rincón Peña",
            "grado_actual": "amarillo",
            "grado_siguiente": "Naranja",
            "edad": 27,
            "fecha_ultimo_ascenso": null,
            "ventana_desde": "2026-05-03",
            "sesiones_en_ventana": 1,
            "porcentaje_inasistencia": 0,
            "umbral": 40,
            "apto": true,
            "avisos": []
        },
        {
            "documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "grado_actual": null,
            "grado_siguiente": "Blanco",
            "edad": 21,
            "fecha_ultimo_ascenso": null,
            "ventana_desde": "2026-05-03",
            "sesiones_en_ventana": 0,
            "porcentaje_inasistencia": null,
            "umbral": 40,
            "apto": null,
            "avisos": [
                "Sin datos de asistencia en la ventana."
            ]
        },
        "… (2 elementos más, omitidos en este ejemplo)"
    ]
}
```

- **Notas:** solo es un aviso; nunca impide inscribir.

#### `evaluaciones.crear` — POST · administrativo

- **Cuerpo:** `fecha` (inicio), `fecha_grado` (≥ fecha), `lugar` y `participantes` (opcional: `[{deportista_documento, grado_evaluado?}]`, máximo 50; sin grado se usa el siguiente al actual).
- **Respuesta (201):** la sesión completa.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=evaluaciones&accion=crear`

Cuerpo:

```json
{
    "fecha": "2026-10-25",
    "fecha_grado": "2026-10-28",
    "lugar": "Sede principal",
    "participantes": [
        {
            "deportista_documento": "1000000150"
        }
    ]
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Sede principal",
        "estado": "abierta",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Blanco",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": false,
                    "promedio": null,
                    "aprobado": null
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            }
        ]
    }
}
```

- **Errores:**
  - `422` «La fecha de grado no puede ser anterior a la fecha de inicio de los exámenes.»;
  - `409` «Ya hay en el calendario un evento de exámenes o de grado con ese nombre y fecha.»;
  - `400` «No hay grado siguiente sugerido para X: envía grado_evaluado.».
- **Notas:** crea en la misma transacción **dos eventos públicos del calendario** (exámenes y grado) y, por participante, las seis modalidades pendientes y su fila de entrega.

#### `evaluaciones.inscribir` — POST · administrativo

- **URL:** `sesion_id` (sesión abierta).
- **Cuerpo:** `participantes`, como en `crear`.
- **Respuesta (201):** la sesión. Es todo o nada.

Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=evaluaciones&accion=inscribir&sesion_id=2`

Cuerpo:

```json
{
    "participantes": [
        {
            "deportista_documento": "1000000103",
            "grado_evaluado": "Verde"
        }
    ]
}
```

Respuesta `201`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Sede principal",
        "estado": "abierta",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000103",
                "nombre": "Fabián Esteban Rincón Peña",
                "grado_actual": "amarillo",
                "grado_evaluado": "Verde",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": false,
                    "promedio": null,
                    "aprobado": null
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            },
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Blanco",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": false,
                    "promedio": null,
                    "aprobado": null
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            }
        ]
    }
}
```

- **Errores:** `409` «El deportista X ya está inscrito en la sesión.»; `409` «La sesión está cerrada: reábrela para modificarla.».

#### `evaluaciones.listar` — GET · administrativo o público

- **URL (opcionales):** `estado`, `desde`, `hasta` (sobre la fecha de inicio) y `deportista`.
- **Respuesta:**
  - administrativo: las sesiones con `num_participantes`, `num_completados` y `total_modalidades`;
  - público: solo las sesiones donde participa, sin conteos.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=evaluaciones&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 2,
            "fecha": "2026-10-25",
            "fecha_grado": "2026-10-28",
            "lugar": "Coliseo municipal",
            "estado": "abierta",
            "evento_examen_id": 9,
            "evento_grado_id": 10,
            "num_participantes": 1,
            "num_completados": 6,
            "total_modalidades": 6
        },
        {
            "id": 1,
            "fecha": "2026-04-26",
            "fecha_grado": "2026-05-03",
            "lugar": "Sede Club Hwang Hon",
            "estado": "abierta",
            "evento_examen_id": 2,
            "evento_grado_id": 5,
            "num_participantes": 1,
            "num_completados": 2,
            "total_modalidades": 3
        }
    ]
}
```

Ejemplo real (llamada como **público**): `GET api.php?recurso=evaluaciones&accion=listar`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "id": 1,
            "fecha": "2026-04-26",
            "fecha_grado": "2026-05-03",
            "lugar": "Sede Club Hwang Hon",
            "estado": "abierta",
            "evento_examen_id": 2,
            "evento_grado_id": 5
        }
    ]
}
```

#### `evaluaciones.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** la sesión con sus participantes. El público solo ve su propio participante; si no participa, `404`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=evaluaciones&accion=consultar&id=2`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Coliseo municipal",
        "estado": "cerrada",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Blanco",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 8.5,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": true
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 7,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": 42,
                        "aspectos_fallidos": null,
                        "observaciones": "Buena resistencia",
                        "recomendaciones": "Trabajar flexibilidad",
                        "aprobado": true
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": true,
                    "promedio": 7.75,
                    "aprobado": true
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            }
        ]
    }
}
```

#### `evaluaciones.actualizar` — PUT · administrativo

- **URL:** `id` (sesión abierta).
- **Cuerpo (parcial):** `fecha`, `fecha_grado` y `lugar`.
- **Respuesta:** la sesión. También mueve sus dos eventos del calendario.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=actualizar&id=2`

Cuerpo:

```json
{
    "lugar": "Coliseo municipal"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Coliseo municipal",
        "estado": "abierta",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Blanco",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "pendiente",
                        "fecha_completado": null,
                        "nota": null,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": null
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": false,
                    "promedio": null,
                    "aprobado": null
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            }
        ]
    }
}
```

#### `evaluaciones.eliminar` — DELETE · administrativo

- **URL:** `id` (sesión abierta sin ninguna modalidad calificada).
- **Respuesta:** `{"eliminado": true}`. Borra también sus eventos.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=evaluaciones&accion=eliminar&id=3`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

- **Errores:** `409` «La sesión está cerrada: reábrela para modificarla.»; `409` «La sesión tiene modalidades calificadas: no se puede eliminar (solo modificar o reabrir).».

#### `evaluaciones.cerrar` — PUT · administrativo

- **URL:** `id`.
- **Respuesta:** la sesión con `estado: "cerrada"`. Exige al menos un participante y las seis modalidades de todos completadas.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=cerrar&id=2`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Coliseo municipal",
        "estado": "cerrada",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Blanco",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 8.5,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": true
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 7,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": 42,
                        "aspectos_fallidos": null,
                        "observaciones": "Buena resistencia",
                        "recomendaciones": "Trabajar flexibilidad",
                        "aprobado": true
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": true,
                    "promedio": 7.75,
                    "aprobado": true
                },
                "entrega": {
                    "cinturon_entregado": false,
                    "fecha_entrega": null,
                    "confirmado_por": null
                }
            }
        ]
    }
}
```

- **Errores:** `409` «Faltan modalidades por calificar de: 1000000150.»; `409` «La sesión no tiene participantes: no se puede cerrar.».

#### `evaluaciones.reabrir` — PUT · administrativo

- **URL:** `id`.
- **Respuesta:** la sesión abierta, más `advertencias` si alguien ya tiene la entrega de cinturón confirmada. No bloquea: el grado se corrige a mano.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=reabrir&id=2`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "id": 2,
        "fecha": "2026-10-25",
        "fecha_grado": "2026-10-28",
        "lugar": "Coliseo municipal",
        "estado": "abierta",
        "evento_examen_id": 9,
        "evento_grado_id": 10,
        "participantes": [
            {
                "deportista_documento": "1000000150",
                "nombre": "Valentina Gómez Ficticia",
                "grado_actual": "Amarillo",
                "grado_evaluado": "Amarillo",
                "modalidades": [
                    {
                        "modalidad": "teorico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 8.5,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": null,
                        "aspectos_fallidos": null,
                        "observaciones": null,
                        "recomendaciones": null,
                        "aprobado": true
                    },
                    {
                        "modalidad": "fisico",
                        "estado": "completado",
                        "fecha_completado": "2026-10-28",
                        "nota": 7,
                        "criterios_evaluados": null,
                        "puntajes": null,
                        "valor_medible": 42,
                        "aspectos_fallidos": null,
                        "observaciones": "Buena resistencia",
                        "recomendaciones": "Trabajar flexibilidad",
                        "aprobado": true
                    },
                    "… (4 elementos más, omitidos en este ejemplo)"
                ],
                "resultado_general": {
                    "completo": true,
                    "promedio": 7.75,
                    "aprobado": true
                },
                "entrega": {
                    "cinturon_entregado": true,
                    "fecha_entrega": "2026-10-28",
                    "confirmado_por": "administrativo"
                }
            }
        ],
        "advertencias": [
            "El deportista 1000000150 ya tiene la entrega de cinturón confirmada: si cambia su resultado, corrige su grado a mano en Deportistas."
        ]
    }
}
```

- **Errores:** `409` «La sesión ya está abierta.».

#### `evaluaciones.actualizarParticipante` — PUT · administrativo

- **URL:** `sesion_id` y `deportista_documento`.
- **Cuerpo:** `grado_evaluado` (un grado de la lista; sin distinguir mayúsculas ni tildes).
- **Respuesta:** el participante.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=actualizarParticipante&sesion_id=2&deportista_documento=1000000103`

Cuerpo:

```json
{
    "grado_evaluado": "Azul"
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "deportista_documento": "1000000103",
        "nombre": "Fabián Esteban Rincón Peña",
        "grado_actual": "amarillo",
        "grado_evaluado": "Azul",
        "modalidades": [
            {
                "modalidad": "teorico",
                "estado": "pendiente",
                "fecha_completado": null,
                "nota": null,
                "criterios_evaluados": null,
                "puntajes": null,
                "valor_medible": null,
                "aspectos_fallidos": null,
                "observaciones": null,
                "recomendaciones": null,
                "aprobado": null
            },
            {
                "modalidad": "fisico",
                "estado": "pendiente",
                "fecha_completado": null,
                "nota": null,
                "criterios_evaluados": null,
                "puntajes": null,
                "valor_medible": null,
                "aspectos_fallidos": null,
                "observaciones": null,
                "recomendaciones": null,
                "aprobado": null
            },
            "… (4 elementos más, omitidos en este ejemplo)"
        ],
        "resultado_general": {
            "completo": false,
            "promedio": null,
            "aprobado": null
        },
        "entrega": {
            "cinturon_entregado": false,
            "fecha_entrega": null,
            "confirmado_por": null
        }
    }
}
```

#### `evaluaciones.calificar` — PUT · administrativo

- **URL:** `sesion_id`, `deportista_documento` y `modalidad`.
- **Cuerpo (parcial; `null` limpia):**
  - `nota` (1–10, 2 decimales) y `valor_medible` (0–9999.99; para la prueba física);
  - `criterios_evaluados`, `puntajes`, `aspectos_fallidos`, `observaciones` y `recomendaciones` (máximo 65.000 bytes cada uno);
  - `fecha_completado` (por defecto hoy; ni futura ni anterior al inicio).
- **Respuesta:** el participante. El `estado` y el `aprobado` de la modalidad se derivan de la nota (aprueba con ≥ 5).

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=calificar&sesion_id=2&deportista_documento=1000000150&modalidad=llaves`

Cuerpo:

```json
{
    "nota": 7.5
}
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "deportista_documento": "1000000150",
        "nombre": "Valentina Gómez Ficticia",
        "grado_actual": "Blanco",
        "grado_evaluado": "Amarillo",
        "modalidades": [
            {
                "modalidad": "teorico",
                "estado": "completado",
                "fecha_completado": "2026-10-28",
                "nota": 8.5,
                "criterios_evaluados": null,
                "puntajes": null,
                "valor_medible": null,
                "aspectos_fallidos": null,
                "observaciones": null,
                "recomendaciones": null,
                "aprobado": true
            },
            {
                "modalidad": "fisico",
                "estado": "completado",
                "fecha_completado": "2026-10-28",
                "nota": 7,
                "criterios_evaluados": null,
                "puntajes": null,
                "valor_medible": 42,
                "aspectos_fallidos": null,
                "observaciones": "Buena resistencia",
                "recomendaciones": "Trabajar flexibilidad",
                "aprobado": true
            },
            "… (4 elementos más, omitidos en este ejemplo)"
        ],
        "resultado_general": {
            "completo": true,
            "promedio": 7.75,
            "aprobado": true
        },
        "entrega": {
            "cinturon_entregado": false,
            "fecha_entrega": null,
            "confirmado_por": null
        }
    }
}
```

- **Errores:**
  - `400` «El campo "nota" debe estar entre 1 y 10, con máximo 2 decimales.»;
  - `422` «La sesión todavía no ha empezado: no se puede calificar antes de la fecha de inicio.»;
  - `404` «El deportista no tiene esa modalidad en esta sesión.».

#### `evaluaciones.quitarParticipante` — DELETE · administrativo

- **URL:** `sesion_id` y `deportista_documento`.
- **Respuesta:** `{"eliminado": true}`. Solo si no tiene modalidades calificadas.

Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=evaluaciones&accion=quitarParticipante&sesion_id=2&deportista_documento=1000000103`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "eliminado": true
    }
}
```

- **Errores:** `409` «El participante ya tiene modalidades calificadas: no se puede quitar.».

#### `evaluaciones.confirmarEntregaCinturon` — PUT · administrativo o público (el propio deportista)

- **URL:** `sesion_id` y `deportista_documento`.
- **Cuerpo:** `fecha_entrega` (opcional, solo el administrativo; por defecto la fecha de grado; no futura). Se puede enviar `{}`.
- **Respuesta:** la entrega (`cinturon_entregado`, `fecha_entrega`, `confirmado_por`), más `sesion_id`, `deportista_documento`, `grado_actual` y, si aplica, `aviso`.

Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=evaluaciones&accion=confirmarEntregaCinturon&sesion_id=2&deportista_documento=1000000150`

Cuerpo:

```json
[]
```

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "cinturon_entregado": true,
        "fecha_entrega": "2026-10-28",
        "confirmado_por": "administrativo",
        "sesion_id": 2,
        "deportista_documento": "1000000150",
        "grado_actual": "Amarillo"
    }
}
```

- **Errores:**
  - `409` «La entrega de este cinturón ya fue confirmada.»;
  - `409` «La entrega se confirma cuando la sesión está cerrada.»;
  - `409` «El deportista no aprobó el examen: no hay cinturón que entregar.»;
  - `403` «Solo puedes confirmar la entrega de tu propio cinturón.».
- **Notas:** **cambia `grado_actual` del deportista** al grado evaluado. Si ya tenía un cinturón entregado en una sesión posterior, registra la entrega pero no baja el grado, y devuelve `aviso`.

---

### B.12 `historial` — pestaña «Registros» (3 acciones)

Solo lectura. El público siempre ve lo suyo: un documento ajeno en `deportista` responde `404` «Deportista no encontrado.».

#### `historial.torneos` — GET · administrativo o público

- **URL (opcionales):** `deportista` (filtro solo para el administrativo), `desde` y `hasta` (sobre la fecha del torneo).
- **Respuesta:** una fila por participación en torneos **ya realizados**, de la más reciente a la más antigua, con datos del torneo y `participantes_en_modalidad`. Nunca trae `notas_oponente`. El público solo ve torneos públicos.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=historial&accion=torneos`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "evento_id": 1,
            "torneo": "Copa Ficticia Hwang Hon 2026",
            "fecha": "2026-05-10",
            "lugar": "Coliseo Municipal de Soacha",
            "tipo_torneo": "departamental",
            "plataforma": "Fitofan",
            "deportista_documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "modalidad": "combate_individual",
            "categoria_edad": "mayores",
            "categoria_peso": null,
            "puesto": 3,
            "resultado": null,
            "medalla": "bronce",
            "combates_ganados": 2,
            "combates_perdidos": 1,
            "participantes_en_modalidad": 1
        },
        {
            "evento_id": 1,
            "torneo": "Copa Ficticia Hwang Hon 2026",
            "fecha": "2026-05-10",
            "lugar": "Coliseo Municipal de Soacha",
            "tipo_torneo": "departamental",
            "plataforma": "Fitofan",
            "deportista_documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "modalidad": "defensa_personal",
            "categoria_edad": "mayores",
            "categoria_peso": null,
            "puesto": null,
            "resultado": "Participó sin clasificar",
            "medalla": "ninguna",
            "combates_ganados": null,
            "combates_perdidos": null,
            "participantes_en_modalidad": 1
        },
        {
            "evento_id": 1,
            "torneo": "Copa Ficticia Hwang Hon 2026",
            "fecha": "2026-05-10",
            "lugar": "Coliseo Municipal de Soacha",
            "tipo_torneo": "departamental",
            "plataforma": "Fitofan",
            "deportista_documento": "1000000101",
            "nombre": "Samuel Reyes Ortiz",
            "modalidad": "formas",
            "categoria_edad": "infantil",
            "categoria_peso": null,
            "puesto": 1,
            "resultado": "Primer lugar en formas",
            "medalla": "oro",
            "combates_ganados": null,
            "combates_perdidos": null,
            "participantes_en_modalidad": 1
        }
    ]
}
```

- **Errores:** `400` «El parámetro "desde" no puede ser posterior a "hasta".»; fechas mal formadas.

#### `historial.ascensos` — GET · administrativo o público

- **URL (opcionales):** `deportista`, `desde` y `hasta` (sobre `fecha_grado`).
- **Respuesta:** una fila por deportista y **sesión cerrada**, con `resultado_general` (`{promedio, aprobado}`), `entrega` (null si no hay fila) y las seis `modalidades` con nota, aprobado, valor medible, aspectos fallidos, observaciones y recomendaciones.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=historial&accion=ascensos&deportista=1000000150`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "sesion_id": 2,
            "fecha": "2026-10-25",
            "fecha_grado": "2026-10-28",
            "lugar": "Coliseo municipal",
            "deportista_documento": "1000000150",
            "nombre": "Valentina Gómez Ficticia",
            "grado_evaluado": "Amarillo",
            "resultado_general": {
                "promedio": 7.75,
                "aprobado": true
            },
            "entrega": {
                "cinturon_entregado": true,
                "fecha_entrega": "2026-10-28",
                "confirmado_por": "administrativo"
            },
            "modalidades": [
                {
                    "modalidad": "teorico",
                    "nota": 8.5,
                    "aprobado": true,
                    "valor_medible": null,
                    "aspectos_fallidos": null,
                    "observaciones": null,
                    "recomendaciones": null
                },
                {
                    "modalidad": "fisico",
                    "nota": 7,
                    "aprobado": true,
                    "valor_medible": 42,
                    "aspectos_fallidos": null,
                    "observaciones": "Buena resistencia",
                    "recomendaciones": "Trabajar flexibilidad"
                },
                "… (4 elementos más, omitidos en este ejemplo)"
            ]
        }
    ]
}
```

#### `historial.permanencia` — GET · administrativo o público

- **URL (opcionales):** `deportista` y `estado`.
- **Respuesta:** por deportista: `fecha_ingreso`, `meses_pagados` (meses de mensualidad distintos, aunque haya huecos), `primer_mes_pagado` y `ultimo_mes_pagado` (`AAAA-MM` o null). Sin filtro: todos los validados, de más a menos meses pagados.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=historial&accion=permanencia`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": [
        {
            "documento": "1000000102",
            "nombre": "Juliana Andrea Castillo Vargas",
            "estado": "activo",
            "fecha_ingreso": "2026-01-15",
            "meses_pagados": 1,
            "primer_mes_pagado": "2026-03",
            "ultimo_mes_pagado": "2026-03"
        },
        {
            "documento": "1000000101",
            "nombre": "Samuel Reyes Ortiz",
            "estado": "activo",
            "fecha_ingreso": "2025-02-01",
            "meses_pagados": 1,
            "primer_mes_pagado": "2026-03",
            "ultimo_mes_pagado": "2026-03"
        },
        "… (2 elementos más, omitidos en este ejemplo)"
    ]
}
```

---

### B.13 `estadisticas` — pestaña «Gráficas» (2 acciones)

#### `estadisticas.deportista` — GET · administrativo o público

- **URL:** `deportista` (obligatorio para el administrativo; el público puede omitirlo) y `ventana` (opcional, se pasa a la asistencia).
- **Respuesta:**
  - `perfil`;
  - `medallas` (por tipo de torneo y totales; «ninguna» no suma);
  - `record` (`porcentaje_victorias` con 2 decimales, o null sin combates);
  - `posiciones` (serie ascendente de participaciones con puesto);
  - `pruebas_fisicas` (PROVISIONAL);
  - `examenes`;
  - `asistencia` (igual que `asistencia.porcentaje`).
- **Sin datos:** series vacías y ceros.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=estadisticas&accion=deportista&deportista=1000000150`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "perfil": {
            "documento": "1000000150",
            "nombre": "Valentina Gómez Ficticia",
            "grado_actual": "Amarillo",
            "edad": 13,
            "categoria_edad": "Junior",
            "fecha_ingreso": "2026-10-28",
            "estado": "activo"
        },
        "medallas": {
            "por_tipo_torneo": {
                "nacional": {
                    "oro": 0,
                    "plata": 0,
                    "bronce": 0
                },
                "internacional": {
                    "oro": 0,
                    "plata": 0,
                    "bronce": 0
                },
                "departamental": {
                    "oro": 0,
                    "plata": 0,
                    "bronce": 0
                }
            },
            "totales": {
                "oro": 0,
                "plata": 0,
                "bronce": 0,
                "total": 0
            }
        },
        "record": {
            "ganados": 0,
            "perdidos": 0,
            "total": 0,
            "porcentaje_victorias": null
        },
        "posiciones": [],
        "pruebas_fisicas": [
            {
                "sesion_id": 2,
                "fecha_grado": "2026-10-28",
                "grado_evaluado": "Amarillo",
                "valor_medible": 42,
                "nota": 7
            }
        ],
        "examenes": [
            {
                "sesion_id": 2,
                "fecha_grado": "2026-10-28",
                "grado_evaluado": "Amarillo",
                "promedio": 7.75,
                "aprobado": true,
                "notas": {
                    "teorico": 8.5,
                    "fisico": 7,
                    "combate": 6.5,
                    "rompimiento": 9,
                    "formas": 8,
                    "llaves": 7.5
                }
            }
        ],
        "asistencia": {
            "documento": "1000000150",
            "ventana": "90",
            "desde": "2026-10-28",
            "sesiones": 0,
            "presentes": 0,
            "porcentaje": null,
            "datos_incompletos": true
        }
    }
}
```

- **Diferencias por ámbito:** el público solo ve lo suyo y solo los torneos públicos.
- **Notas:** para comparar dos deportistas, el front llama dos veces.

#### `estadisticas.torneo` — GET · administrativo

- **URL:** `id` (de un torneo ya realizado).
- **Respuesta:** datos del torneo, `por_modalidad` (participantes, medallas y combates), `medallas_total` y `participantes` (por modalidad y puesto, con los puestos vacíos al final). Sin `notas_oponente`.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=estadisticas&accion=torneo&id=1`

Respuesta `200`:

```json
{
    "exito": true,
    "datos": {
        "evento_id": 1,
        "nombre": "Copa Ficticia Hwang Hon 2026",
        "fecha": "2026-05-10",
        "lugar": "Coliseo Municipal de Soacha",
        "visibilidad": "publica",
        "observaciones": null,
        "plataforma": "Fitofan",
        "tipo_torneo": "departamental",
        "num_participantes": 2,
        "por_modalidad": [
            {
                "modalidad": "combate_individual",
                "participantes": 1,
                "oro": 0,
                "plata": 0,
                "bronce": 1,
                "combates_ganados": 2,
                "combates_perdidos": 1
            },
            {
                "modalidad": "defensa_personal",
                "participantes": 1,
                "oro": 0,
                "plata": 0,
                "bronce": 0,
                "combates_ganados": 0,
                "combates_perdidos": 0
            },
            {
                "modalidad": "formas",
                "participantes": 1,
                "oro": 1,
                "plata": 0,
                "bronce": 0,
                "combates_ganados": 0,
                "combates_perdidos": 0
            }
        ],
        "medallas_total": {
            "oro": 1,
            "plata": 0,
            "bronce": 1,
            "total": 2
        },
        "participantes": [
            {
                "documento": "1000000102",
                "nombre": "Juliana Andrea Castillo Vargas",
                "modalidad": "combate_individual",
                "categoria_edad": "mayores",
                "categoria_peso": null,
                "puesto": 3,
                "resultado": null,
                "medalla": "bronce",
                "combates_ganados": 2,
                "combates_perdidos": 1
            },
            {
                "documento": "1000000102",
                "nombre": "Juliana Andrea Castillo Vargas",
                "modalidad": "defensa_personal",
                "categoria_edad": "mayores",
                "categoria_peso": null,
                "puesto": null,
                "resultado": "Participó sin clasificar",
                "medalla": "ninguna",
                "combates_ganados": null,
                "combates_perdidos": null
            },
            {
                "documento": "1000000101",
                "nombre": "Samuel Reyes Ortiz",
                "modalidad": "formas",
                "categoria_edad": "infantil",
                "categoria_peso": null,
                "puesto": 1,
                "resultado": "Primer lugar en formas",
                "medalla": "oro",
                "combates_ganados": null,
                "combates_perdidos": null
            }
        ]
    }
}
```

- **Errores:** `403` «Solo el ámbito administrativo puede consultar el resumen de un torneo.»; `404` «Torneo no encontrado.» (inexistente, sin competencia o con fecha futura).

---

### B.14 `reportes` — exportaciones (6 acciones)

Solo administrativo (el público recibe `403` «Acceso restringido al ámbito administrativo.»). Todas son GET y responden un **archivo** (A.8); los errores salen en JSON antes de generar nada. Los filtros de fecha (`desde`/`hasta`) son `AAAA-MM-DD`; si llegan mal formados o con `desde` posterior a `hasta`, responden `400`. Un rango sin datos da un archivo válido con solo encabezados.

#### `reportes.deportistaFicha` — GET · administrativo

- **URL:** `deportista` (obligatorio).
- **Respuesta:** PDF con la ficha consolidada:
  - perfil: edad, categorías, peso, categoría de peso, condición de salud, grado, ingreso y estado;
  - contacto, y el acudiente si es menor;
  - último examen (PROVISIONAL), aspectos por mejorar y recomendaciones;
  - últimos 5 torneos;
  - asistencia (90 días y total) y permanencia.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=deportistaFicha&deportista=1000000150`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/pdf
Content-Disposition: attachment; filename="ficha_1000000150_2026-10-28.pdf"; filename*=UTF-8''ficha_1000000150_2026-10-28.pdf
X-Content-Type-Options: nosniff
Content-Length: 3857
```

- **Errores:** `404` «Deportista no encontrado.»; `400` si falta `deportista`.

#### `reportes.deportistas` — GET · administrativo

- **URL:** `estado` (opcional: `activo` o `inactivo`). Sin filtro: todos los validados.
- **Respuesta:** Excel con documento, nombre, fecha de nacimiento, edad, categoría de edad, grado, estado, ingreso, teléfono y correo.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=deportistas&estado=activo`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="deportistas_activos_2026-10-28.xlsx"; filename*=UTF-8''deportistas_activos_2026-10-28.xlsx
X-Content-Type-Options: nosniff
Content-Length: 12508
```

#### `reportes.asistencia` — GET · administrativo

- **URL:** `desde` y `hasta` (obligatorios, máximo 366 días) y `estado` (opcional).
- **Respuesta:** Excel con la planilla (formato propio estilo IMRDS, PROVISIONAL): deportistas × fechas, condición de salud, total, sesiones, % y una fila final con asistentes por fecha.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=asistencia&desde=2026-01-01&hasta=2026-10-28`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="asistencia_2026-01-01_a_2026-10-28_2026-10-28.xlsx"; filename*=UTF-8''asistencia_2026-01-01_a_2026-10-28_2026-10-28.xlsx
X-Content-Type-Options: nosniff
Content-Length: 11248
```

- **Errores:** `422` «El rango de la planilla de asistencia no puede superar 366 días.»; `400` si falta `desde` o `hasta`.

#### `reportes.pagos` — GET · administrativo

- **URL:** `anio` (opcional; por defecto el actual) y `estado` (opcional).
- **Respuesta:** Excel con dos hojas:
  - «Planilla»: deportista × 12 meses, con la semana de pago y colores para pagado y vencido o deuda, más la matrícula;
  - «Deudores»: los del año en curso.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=pagos&anio=2026`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="pagos_2026_2026-10-28.xlsx"; filename*=UTF-8''pagos_2026_2026-10-28.xlsx
X-Content-Type-Options: nosniff
Content-Length: 14895
```

#### `reportes.torneos` — GET · administrativo

- **URL:** `desde` y `hasta` (opcionales).
- **Respuesta:** Excel con una fila por participación en torneos realizados y la hoja «Medallería». Sin notas de oponente.

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=torneos`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="torneos_2026-10-28.xlsx"; filename*=UTF-8''torneos_2026-10-28.xlsx
X-Content-Type-Options: nosniff
Content-Length: 14816
```

#### `reportes.sesionExamen` — GET · administrativo

- **URL:** `id` (de la sesión).
- **Respuesta:** Excel con las seis notas de cada participante, el promedio, el resultado (PROVISIONAL), el grado evaluado y la entrega. Una sesión abierta sale marcada «EN CURSO».

Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=sesionExamen&id=2`

Respuesta `200` (archivo; cabeceras):

```http
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="sesion_examen_2_2026-10-28.xlsx"; filename*=UTF-8''sesion_examen_2_2026-10-28.xlsx
X-Content-Type-Options: nosniff
Content-Length: 11063
```

- **Errores:** `404` «Sesión de examen no encontrada.».

---
### B.15 Tabla resumen de las 95 acciones

Ámbito: **SS** = sin sesión · **P** = público · **A** = administrativo · **CP** = cuenta principal · **AR** = permiso de autorregistro.

| # | Recurso | Acción | Método | Ámbito |
|---|---|---|---|---|
| 1 | auth | iniciarSesionAdministrativa | POST | SS |
| 2 | auth | iniciarSesionPublica | POST | SS |
| 3 | auth | cerrarSesion | POST | SS / P / A |
| 4 | auth | sesionActual | GET | P / A |
| 5 | auth | cambiarContrasena | POST | A |
| 6 | auth | solicitarRecuperacionContrasena | POST | SS |
| 7 | auth | restablecerContrasena | POST | SS |
| 8 | usuarios-administrativos | listar | GET | CP |
| 9 | usuarios-administrativos | consultar | GET | CP |
| 10 | usuarios-administrativos | crear | POST | CP |
| 11 | usuarios-administrativos | actualizar | PUT | CP |
| 12 | usuarios-administrativos | desactivar | PUT | CP |
| 13 | usuarios-administrativos | reactivar | PUT | CP |
| 14 | usuarios-administrativos | restablecerContrasena | PUT | CP |
| 15 | deportistas | listar | GET | P / A |
| 16 | deportistas | consultar | GET | P / A |
| 17 | deportistas | buscar | GET | A |
| 18 | deportistas | crear | POST | A |
| 19 | deportistas | actualizar | PUT | P / A |
| 20 | deportistas | eliminar | DELETE | A |
| 21 | autorregistro | registrar | POST | SS |
| 22 | autorregistro | listarDocumentos | GET | AR |
| 23 | autorregistro | subirDocumento | POST (multipart) | AR |
| 24 | autorregistro | reemplazarDocumento | POST (multipart) | AR |
| 25 | autorregistro | eliminarDocumento | DELETE | AR |
| 26 | autorregistro | confirmar | POST | AR |
| 27 | importacion-deportistas | previsualizar | POST (multipart) | A |
| 28 | importacion-deportistas | aplicar | POST (multipart) | A |
| 29 | documentos | listar | GET | P / A |
| 30 | documentos | consultar | GET | P / A |
| 31 | documentos | subir | POST (multipart) | P / A |
| 32 | documentos | reemplazar | POST (multipart) | A |
| 33 | documentos | eliminar | DELETE | A |
| 34 | documentos | descargar | GET (archivo) | P / A |
| 35 | documentos | faltantes | GET | A |
| 36 | documentos | listarGenerales | GET | P / A |
| 37 | documentos | descargarGeneral | GET (archivo) | P / A |
| 38 | documentos | subirGeneral | POST (multipart) | A |
| 39 | documentos | reemplazarGeneral | POST (multipart) | A |
| 40 | documentos | eliminarGeneral | DELETE | A |
| 41 | asistencia | tomar | POST | A |
| 42 | asistencia | listarPantalla | GET | A |
| 43 | asistencia | listar | GET | P / A |
| 44 | asistencia | consultar | GET | P / A |
| 45 | asistencia | resumenDia | GET | A |
| 46 | asistencia | corregir | PUT | A |
| 47 | asistencia | eliminarMarca | DELETE | A |
| 48 | asistencia | eliminarFecha | DELETE | A |
| 49 | asistencia | porcentaje | GET | P / A |
| 50 | asistencia | revisarInactivos | POST | A |
| 51 | pagos | registrar | POST | A |
| 52 | pagos | anular | DELETE | A |
| 53 | pagos | estado | GET | P / A |
| 54 | pagos | deudores | GET | A |
| 55 | pagos | listar | GET | P / A |
| 56 | pagos | consultar | GET | P / A |
| 57 | calendario | listar | GET | P / A |
| 58 | calendario | consultar | GET | P / A |
| 59 | calendario | crear | POST | A |
| 60 | calendario | actualizar | PUT | A |
| 61 | calendario | vista | GET | P / A |
| 62 | calendario | importar | POST (multipart) | A |
| 63 | competencias | listar | GET | P / A |
| 64 | competencias | consultar | GET | P / A |
| 65 | competencias | proximo | GET | A |
| 66 | competencias | crear | POST | A |
| 67 | competencias | actualizar | PUT | A |
| 68 | competencias | inscribir | POST | A |
| 69 | competencias | sugerencia | GET | A |
| 70 | competencias | actualizarParticipacion | PUT | A |
| 71 | competencias | quitarParticipante | DELETE | A |
| 72 | evaluaciones | candidatos | GET | A |
| 73 | evaluaciones | listar | GET | P / A |
| 74 | evaluaciones | consultar | GET | P / A |
| 75 | evaluaciones | crear | POST | A |
| 76 | evaluaciones | inscribir | POST | A |
| 77 | evaluaciones | actualizar | PUT | A |
| 78 | evaluaciones | actualizarParticipante | PUT | A |
| 79 | evaluaciones | calificar | PUT | A |
| 80 | evaluaciones | cerrar | PUT | A |
| 81 | evaluaciones | reabrir | PUT | A |
| 82 | evaluaciones | confirmarEntregaCinturon | PUT | P (el propio) / A |
| 83 | evaluaciones | quitarParticipante | DELETE | A |
| 84 | evaluaciones | eliminar | DELETE | A |
| 85 | historial | torneos | GET | P / A |
| 86 | historial | ascensos | GET | P / A |
| 87 | historial | permanencia | GET | P / A |
| 88 | estadisticas | deportista | GET | P / A |
| 89 | estadisticas | torneo | GET | A |
| 90 | reportes | deportistaFicha | GET (archivo) | A |
| 91 | reportes | deportistas | GET (archivo) | A |
| 92 | reportes | asistencia | GET (archivo) | A |
| 93 | reportes | pagos | GET (archivo) | A |
| 94 | reportes | torneos | GET (archivo) | A |
| 95 | reportes | sesionExamen | GET (archivo) | A |

---

## Parte C — Flujos para el MVP del front

Secuencias de llamadas por pantalla, con el ejemplo real de cada paso. Cada flujo empieza **después** de cargar el front.

### C.1 Ingreso

1. **Al abrir el front, ¿hay sesión?** `auth.sesionActual`. Con `401`, mostrar el ingreso (por defecto el administrativo, con un botón para cambiar al público).

   Ejemplo real (llamada como **sin sesión**): `GET api.php?recurso=auth&accion=sesionActual`

   Respuesta `401`:

   ```json
   {
       "exito": false,
       "mensaje": "No autenticado. Inicie sesión para continuar."
   }
   ```

2. **Ingreso administrativo:** `auth.iniciarSesionAdministrativa`. Guardar en memoria `nombre` y `es_administrador_principal`; este último decide si se muestra la gestión de cuentas.

   Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionAdministrativa`

   Cuerpo:

   ```json
   {
       "correo": "maestro@hwanghon.local",
       "contrasena": "12345678"
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "ambito": "administrativa",
           "correo": "maestro@hwanghon.local",
           "nombre": "Maestro (prueba)",
           "es_administrador_principal": true
       }
   }
   ```

   Si falla, mostrar el mensaje tal cual. Con `429`, deshabilitar el botón y mostrar los minutos que dice el mensaje.

   Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionAdministrativa`

   Cuerpo:

   ```json
   {
       "correo": "maestro@hwanghon.local",
       "contrasena": "equivocada"
   }
   ```

   Respuesta `401`:

   ```json
   {
       "exito": false,
       "mensaje": "Correo o contraseña incorrectos."
   }
   ```

3. **Ingreso público** («Mi perfil»): `auth.iniciarSesionPublica`, con el documento y la fecha de nacimiento del deportista.

   Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionPublica`

   Cuerpo:

   ```json
   {
       "documento": "1000000101",
       "fecha_nacimiento": "2014-03-12"
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "ambito": "publica",
           "documento": "1000000101",
           "nombre": "Samuel Reyes Ortiz"
       }
   }
   ```

   Un registro pendiente recibe `403` con «Tu registro está en revisión.».

   Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=iniciarSesionPublica`

   Cuerpo:

   ```json
   {
       "documento": "1000000102",
       "fecha_nacimiento": "2005-07-20"
   }
   ```

   Respuesta `403`:

   ```json
   {
       "exito": false,
       "mensaje": "Tu registro está en revisión."
   }
   ```

4. **Durante el uso:** cualquier `401` («No autenticado…» o «Tu sesión ya no es válida.») lleva de vuelta al ingreso.
5. **Salir:** `auth.cerrarSesion`.

   Ejemplo real (llamada como **público**): `POST api.php?recurso=auth&accion=cerrarSesion`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "sesion_cerrada": true
       }
   }
   ```

6. **Olvidé mi contraseña** (administrativo):
   - `auth.solicitarRecuperacionContrasena`: siempre el mismo mensaje;

     Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=solicitarRecuperacionContrasena`

     Cuerpo:

     ```json
     {
         "correo": "no.registrado@hwanghon.local"
     }
     ```

     Respuesta `200`:

     ```json
     {
         "exito": true,
         "datos": {
             "mensaje": "Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña."
         }
     }
     ```

   - el enlace del correo abre la pantalla de restablecer, que lee `token` de la URL y llama `auth.restablecerContrasena`. Después, volver al ingreso.

     Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=auth&accion=restablecerContrasena`

     Cuerpo:

     ```json
     {
         "token": "7777777777777777777777777777777777777777777777777777777777777777",
         "contrasena_nueva": "12345678"
     }
     ```

     Respuesta `200`:

     ```json
     {
         "exito": true,
         "datos": {
             "contrasena_restablecida": true
         }
     }
     ```

7. **Cambiar mi contraseña** (administrativo con sesión): `auth.cambiarContrasena`.

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=auth&accion=cambiarContrasena`

   Cuerpo:

   ```json
   {
       "contrasena_actual": "12345678",
       "contrasena_nueva": "NuevaClave2026"
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "contrasena_cambiada": true
       }
   }
   ```

### C.2 Deportistas

**Administrativo — lista y ficha**

1. **Lista:** `deportistas.listar`. Para la pestaña «Pendientes de validación», con el filtro `estado_registro=pendiente_validacion`.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=listar&estado_registro=pendiente_validacion`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "documento": "1000000102",
               "nombre": "Juliana Andrea Castillo Vargas",
               "fecha_nacimiento": "2005-07-20",
               "lugar_nacimiento": null,
               "sexo": "femenino",
               "estatura": 1.68,
               "peso": 60,
               "telefono": "3000000002",
               "direccion": "Carrera 4 # 5-06, Soacha",
               "correo": "juliana.castillo@correoficticio.test",
               "discapacidad": null,
               "experiencia_previa": null,
               "sede_entrenamiento": "Escuela IMRDS",
               "autorizacion_datos": true,
               "grado_actual": null,
               "fecha_ingreso": "2026-01-15",
               "estado": "activo",
               "estado_registro": "pendiente_validacion",
               "acudiente_documento": null,
               "validado_por_correo": null,
               "edad": 21,
               "categoria_edad": "Mayores",
               "categoria_peso": "-60"
           }
       ]
   }
   ```

2. **Buscador o autocompletado:** `deportistas.buscar` (desde 2 caracteres).

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=buscar&texto=rey`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "documento": "1000000101",
               "nombre": "Samuel Reyes Ortiz",
               "estado": "activo",
               "estado_registro": "validado",
               "grado_actual": null
           }
       ]
   }
   ```

3. **Abrir una ficha:** `deportistas.consultar` (trae el récord y el acudiente).

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=deportistas&accion=consultar&documento=1000000101`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "nombre": "Samuel Reyes Ortiz",
           "fecha_nacimiento": "2014-03-12",
           "lugar_nacimiento": "Soacha",
           "sexo": "masculino",
           "estatura": 1.55,
           "peso": 45.3,
           "telefono": "3000000001",
           "direccion": "Calle 1 # 2-03, Soacha",
           "correo": "marcela.reyes@correoficticio.test",
           "discapacidad": null,
           "experiencia_previa": null,
           "sede_entrenamiento": "Club Hwang Hon",
           "autorizacion_datos": true,
           "grado_actual": null,
           "fecha_ingreso": "2025-02-01",
           "estado": "activo",
           "estado_registro": "validado",
           "acudiente_documento": "1000000001",
           "validado_por_correo": null,
           "edad": 12,
           "categoria_edad": "Junior",
           "categoria_peso": "-50",
           "combates_ganados": 0,
           "combates_perdidos": 0,
           "acudiente": {
               "documento": "1000000001",
               "nombre": "Marcela Reyes Ortiz",
               "relacion": "madre",
               "telefono": "3000000001",
               "correo": "marcela.reyes@correoficticio.test"
           }
       }
   }
   ```

4. **Documentos de la ficha:** `documentos.listar` y `documentos.faltantes`. Los críticos son los que faltan para considerar completa la inscripción.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=listar&documento=1000000150`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "id": 6,
               "deportista_documento": "1000000150",
               "tipo_documento": "formulario_inscripcion",
               "nombre_original": "formulario_valentina.pdf",
               "fecha_carga": "2026-10-28"
           }
       ]
   }
   ```

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=documentos&accion=faltantes&documento=1000000150`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "criticos": [
               "documento_identidad",
               "documento_acudiente"
           ],
           "no_criticos": [
               "fotografia"
           ]
       }
   }
   ```

5. **Nuevo deportista:** `deportistas.crear`. Si es menor, el formulario exige el bloque del acudiente. Luego, subir sus documentos con `documentos.subir`.

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=deportistas&accion=crear`

   Cuerpo:

   ```json
   {
       "documento": "1000000150",
       "nombre": "Valentina Gómez Ficticia",
       "fecha_nacimiento": "2013-06-15",
       "lugar_nacimiento": "Soacha",
       "sexo": "femenino",
       "estatura": 1.48,
       "peso": 39.5,
       "telefono": "3000000150",
       "direccion": "Calle Ficticia 15",
       "correo": "familia.gomez@correoficticio.test",
       "discapacidad": null,
       "experiencia_previa": null,
       "sede_entrenamiento": "Club Hwang Hon",
       "autorizacion_datos": true,
       "acudiente": {
           "documento": "1000000950",
           "nombre": "Carolina Gómez Ficticia",
           "relacion": "madre",
           "telefono": "3000000950",
           "correo": "familia.gomez@correoficticio.test"
       }
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000150",
           "nombre": "Valentina Gómez Ficticia",
           "fecha_nacimiento": "2013-06-15",
           "lugar_nacimiento": "Soacha",
           "sexo": "femenino",
           "estatura": 1.48,
           "peso": 39.5,
           "telefono": "3000000150",
           "direccion": "Calle Ficticia 15",
           "correo": "familia.gomez@correoficticio.test",
           "discapacidad": null,
           "experiencia_previa": null,
           "sede_entrenamiento": "Club Hwang Hon",
           "autorizacion_datos": true,
           "grado_actual": null,
           "fecha_ingreso": "2026-10-28",
           "estado": "activo",
           "estado_registro": "validado",
           "acudiente_documento": "1000000950",
           "validado_por_correo": null,
           "edad": 13,
           "categoria_edad": "Junior",
           "categoria_peso": "-43",
           "combates_ganados": 0,
           "combates_perdidos": 0,
           "acudiente": {
               "documento": "1000000950",
               "nombre": "Carolina Gómez Ficticia",
               "relacion": "madre",
               "telefono": "3000000950",
               "correo": "familia.gomez@correoficticio.test"
           }
       }
   }
   ```

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=documentos&accion=subir`

   Cuerpo `multipart/form-data`:

   ```json
   {
       "documento": "1000000150",
       "tipo_documento": "formulario_inscripcion",
       "archivo": "(archivo formulario_valentina.pdf, 45 bytes)"
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": {
           "id": 6,
           "deportista_documento": "1000000150",
           "tipo_documento": "formulario_inscripcion",
           "nombre_original": "formulario_valentina.pdf",
           "fecha_carga": "2026-10-28"
       }
   }
   ```

6. **Editar o validar:** `deportistas.actualizar`. Validar un pendiente es enviar `{"estado_registro": "validado"}`; rechazarlo es `deportistas.eliminar`.

   Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=deportistas&accion=actualizar&documento=1000000102`

   Cuerpo:

   ```json
   {
       "estado_registro": "validado"
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000102",
           "nombre": "Juliana Andrea Castillo Vargas",
           "fecha_nacimiento": "2005-07-20",
           "lugar_nacimiento": null,
           "sexo": "femenino",
           "estatura": 1.68,
           "peso": 60,
           "telefono": "3000000002",
           "direccion": "Carrera 4 # 5-06, Soacha",
           "correo": "juliana.castillo@correoficticio.test",
           "discapacidad": null,
           "experiencia_previa": null,
           "sede_entrenamiento": "Escuela IMRDS",
           "autorizacion_datos": true,
           "grado_actual": null,
           "fecha_ingreso": "2026-01-15",
           "estado": "activo",
           "estado_registro": "validado",
           "acudiente_documento": null,
           "validado_por_correo": "maestro@hwanghon.local",
           "edad": 21,
           "categoria_edad": "Mayores",
           "categoria_peso": "-60",
           "combates_ganados": 2,
           "combates_perdidos": 1,
           "acudiente": null
       }
   }
   ```

   Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=deportistas&accion=eliminar&documento=1000000162`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "eliminado": true
       }
   }
   ```

**Público — «Mi perfil»**

1. `deportistas.consultar` con su propio documento (o `deportistas.listar`, que devuelve solo el suyo).

   Ejemplo real (llamada como **público**): `GET api.php?recurso=deportistas&accion=consultar&documento=1000000101`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "nombre": "Samuel Reyes Ortiz",
           "fecha_nacimiento": "2014-03-12",
           "lugar_nacimiento": "Soacha",
           "sexo": "masculino",
           "estatura": 1.55,
           "peso": 45.3,
           "telefono": "3000000001",
           "direccion": "Calle 1 # 2-03, Soacha",
           "correo": "marcela.reyes@correoficticio.test",
           "discapacidad": null,
           "experiencia_previa": null,
           "sede_entrenamiento": "Club Hwang Hon",
           "autorizacion_datos": true,
           "grado_actual": null,
           "fecha_ingreso": "2025-02-01",
           "estado": "activo",
           "estado_registro": "validado",
           "acudiente_documento": "1000000001",
           "edad": 12,
           "categoria_edad": "Junior",
           "categoria_peso": "-50",
           "combates_ganados": 0,
           "combates_perdidos": 0,
           "acudiente": {
               "documento": "1000000001",
               "nombre": "Marcela Reyes Ortiz",
               "relacion": "madre",
               "telefono": "3000000001",
               "correo": "marcela.reyes@correoficticio.test"
           }
       }
   }
   ```

2. **Editar:** solo peso, condición de salud, teléfono, dirección y correo, con `deportistas.actualizar`. Los demás campos se muestran de solo lectura.

   Ejemplo real (llamada como **público**): `PUT api.php?recurso=deportistas&accion=actualizar&documento=1000000101`

   Cuerpo:

   ```json
   {
       "peso": 46,
       "telefono": "3000000002"
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "nombre": "Samuel Reyes Ortiz",
           "fecha_nacimiento": "2014-03-12",
           "lugar_nacimiento": "Soacha",
           "sexo": "masculino",
           "estatura": 1.55,
           "peso": 46,
           "telefono": "3000000002",
           "direccion": "Calle 1 # 2-03, Soacha",
           "correo": "marcela.reyes@correoficticio.test",
           "discapacidad": null,
           "experiencia_previa": null,
           "sede_entrenamiento": "Club Hwang Hon",
           "autorizacion_datos": true,
           "grado_actual": null,
           "fecha_ingreso": "2025-02-01",
           "estado": "activo",
           "estado_registro": "validado",
           "acudiente_documento": "1000000001",
           "edad": 12,
           "categoria_edad": "Junior",
           "categoria_peso": "-50",
           "combates_ganados": 0,
           "combates_perdidos": 0,
           "acudiente": {
               "documento": "1000000001",
               "nombre": "Marcela Reyes Ortiz",
               "relacion": "madre",
               "telefono": "3000000001",
               "correo": "marcela.reyes@correoficticio.test"
           }
       }
   }
   ```

3. **Mis documentos:** `documentos.listar`. Puede subir con `documentos.subir` los cuatro tipos de un solo archivo que aún estén vacíos.

   Ejemplo real (llamada como **público**): `GET api.php?recurso=documentos&accion=listar`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "id": 1,
               "deportista_documento": "1000000101",
               "tipo_documento": "documento_identidad",
               "nombre_original": "registro_civil_samuel.pdf",
               "fecha_carga": "2025-02-01"
           }
       ]
   }
   ```

**Autorregistro** (página pública, sin sesión)

1. `autorregistro.registrar`, con el campo oculto `sitio_web` vacío.

   Ejemplo real (llamada como **sin sesión**): `POST api.php?recurso=autorregistro&accion=registrar`

   Cuerpo:

   ```json
   {
       "documento": "1000000160",
       "nombre": "Andrés Registro Ficticio",
       "fecha_nacimiento": "1999-02-10",
       "sexo": "masculino",
       "estatura": 1.75,
       "peso": 72,
       "telefono": "3000000160",
       "correo": "andres.registro@correoficticio.test",
       "autorizacion_datos": true,
       "sitio_web": ""
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "mensaje": "Registro recibido. Queda en revisión hasta que el club lo valide; mientras tanto puedes cargar tus documentos.",
           "documentos": {
               "carga_habilitada": true,
               "vigente_hasta": "2026-10-28T08:59:57-05:00",
               "tipos_permitidos": [
                   "documento_identidad",
                   "formulario_inscripcion",
                   "… (2 elementos más, omitidos en este ejemplo)"
               ],
               "cargados": []
           }
       }
   }
   ```

2. Dentro de las 2 horas: `autorregistro.subirDocumento`, `autorregistro.listarDocumentos`, `autorregistro.reemplazarDocumento` y `autorregistro.eliminarDocumento`.

   Ejemplo real (llamada como **permiso de autorregistro**): `POST api.php?recurso=autorregistro&accion=subirDocumento`

   Cuerpo `multipart/form-data`:

   ```json
   {
       "tipo_documento": "documento_identidad",
       "archivo": "(archivo cedula.pdf, 45 bytes)"
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": {
           "id": 4,
           "deportista_documento": "1000000160",
           "tipo_documento": "documento_identidad",
           "nombre_original": "cedula.pdf",
           "fecha_carga": "2026-10-28"
       }
   }
   ```

   Ejemplo real (llamada como **permiso de autorregistro**): `GET api.php?recurso=autorregistro&accion=listarDocumentos`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documentos": [
               {
                   "id": 4,
                   "deportista_documento": "1000000160",
                   "tipo_documento": "documento_identidad",
                   "nombre_original": "cedula_legible.pdf",
                   "fecha_carga": "2026-10-28"
               }
           ],
           "vigente_hasta": "2026-10-28T08:59:57-05:00"
       }
   }
   ```

3. «Guardar cambios»: `autorregistro.confirmar`.

   Ejemplo real (llamada como **permiso de autorregistro**): `POST api.php?recurso=autorregistro&accion=confirmar`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "mensaje": "Documentos guardados. Desde ahora cualquier cambio lo hace el club."
       }
   }
   ```

### C.3 Asistencia

1. **Dashboard:** `asistencia.resumenDia`.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=resumenDia`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "fecha": "2026-10-28",
           "presentes": 1,
           "total": 1,
           "es_hoy": true
       }
   }
   ```

2. **Pantalla de tomar asistencia:** elegir un sábado o domingo y llamar `asistencia.listarPantalla`. Mostrar la `discapacidad` junto a cada nombre.
   - con `tomada: false`, el usuario marca y se envía `asistencia.tomar`;
   - con `tomada: true`, cada cambio va por `asistencia.corregir`.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=asistencia&accion=listarPantalla&fecha=2026-10-24`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "fecha": "2026-10-24",
           "tomada": false,
           "activos": [
               {
                   "documento": "1000000102",
                   "nombre": "Juliana Andrea Castillo Vargas",
                   "discapacidad": null,
                   "asistio": null
               },
               {
                   "documento": "1000000101",
                   "nombre": "Samuel Reyes Ortiz",
                   "discapacidad": null,
                   "asistio": null
               },
               {
                   "documento": "1000000150",
                   "nombre": "Valentina Gómez Ficticia",
                   "discapacidad": null,
                   "asistio": null
               }
           ],
           "inactivos": [
               {
                   "documento": "1000000103",
                   "nombre": "Fabián Esteban Rincón Peña",
                   "discapacidad": null,
                   "asistio": null
               }
           ]
       }
   }
   ```

3. **Guardar la toma:** `asistencia.tomar`. Mostrar los `reactivados`: se reactivaron por venir presentes.

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=asistencia&accion=tomar`

   Cuerpo:

   ```json
   {
       "fecha": "2026-10-24",
       "presentes": [
           "1000000101",
           "1000000103"
       ]
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": {
           "fecha": "2026-10-24",
           "registrados": 3,
           "presentes": 2,
           "reactivados": [
               "1000000103"
           ]
       }
   }
   ```

   Si la fecha ya estaba tomada:

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=asistencia&accion=tomar`

   Cuerpo:

   ```json
   {
       "fecha": "2026-10-24",
       "presentes": []
   }
   ```

   Respuesta `409`:

   ```json
   {
       "exito": false,
       "mensaje": "La asistencia de esa fecha ya fue tomada: usa «corregir» para cambiar una marca."
   }
   ```

4. **Corregir una marca:** `asistencia.corregir`.

   Ejemplo real (llamada como **administrativo**): `PUT api.php?recurso=asistencia&accion=corregir&documento=1000000102&fecha=2026-10-24`

   Cuerpo:

   ```json
   {
       "asistio": true
   }
   ```

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "deportista_documento": "1000000102",
           "fecha": "2026-10-24",
           "asistio": true,
           "reactivado": false
       }
   }
   ```

5. **Borrar algo tomado por error:** `asistencia.eliminarMarca` o `asistencia.eliminarFecha` (pedir confirmación).

   Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=asistencia&accion=eliminarFecha&fecha=2026-10-17`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "fecha": "2026-10-17",
           "eliminadas": 3
       }
   }
   ```

6. **Porcentaje de un deportista** (ficha o «Mi perfil»): `asistencia.porcentaje`. Con `datos_incompletos: true`, mostrar «datos insuficientes».

   Ejemplo real (llamada como **público**): `GET api.php?recurso=asistencia&accion=porcentaje`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "ventana": "90",
           "desde": "2026-07-30",
           "sesiones": 1,
           "presentes": 1,
           "porcentaje": null,
           "datos_incompletos": true
       }
   }
   ```

7. **Botón «Revisar inactivos»:** `asistencia.revisarInactivos` (pedir confirmación; muestra a quiénes desactivó).

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=asistencia&accion=revisarInactivos`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "fecha_limite": "2026-07-28",
           "desactivados": [
               "1000000102"
           ]
       }
   }
   ```

### C.4 Pagos

1. **Estado de un deportista** (ficha o «Mi perfil»): `pagos.estado`. Pintar los 12 meses con `pagado`, `vencido` y `en_deuda`, y el aviso de `por_vencer` y de la matrícula `pendiente`/`atrasada`.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=estado&documento=1000000101&anio=2026`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "anio": 2026,
           "semana_habitual": 1,
           "matricula": {
               "pagada": true,
               "pendiente": false,
               "atrasada": false
           },
           "meses": [
               {
                   "mes": 1,
                   "pagado": false,
                   "semana": null,
                   "vencimiento": "2026-01-01",
                   "vencido": true,
                   "en_deuda": false
               },
               {
                   "mes": 2,
                   "pagado": false,
                   "semana": null,
                   "vencimiento": "2026-02-01",
                   "vencido": true,
                   "en_deuda": false
               },
               "… (10 elementos más, omitidos en este ejemplo)"
           ],
           "meses_en_deuda": [],
           "por_vencer": {
               "mes": 11,
               "anio": 2026,
               "vencimiento": "2026-11-01",
               "dias_restantes": 4
           },
           "al_dia": true,
           "genera_alertas": true
       }
   }
   ```

   El público llama sin parámetros y recibe el suyo:

   Ejemplo real (llamada como **público**): `GET api.php?recurso=pagos&accion=estado`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "documento": "1000000101",
           "anio": 2026,
           "semana_habitual": 1,
           "matricula": {
               "pagada": true,
               "pendiente": false,
               "atrasada": false
           },
           "meses": [
               {
                   "mes": 1,
                   "pagado": false,
                   "semana": null,
                   "vencimiento": "2026-01-01",
                   "vencido": true,
                   "en_deuda": false
               },
               {
                   "mes": 2,
                   "pagado": false,
                   "semana": null,
                   "vencimiento": "2026-02-01",
                   "vencido": true,
                   "en_deuda": false
               },
               "… (10 elementos más, omitidos en este ejemplo)"
           ],
           "meses_en_deuda": [],
           "por_vencer": {
               "mes": 11,
               "anio": 2026,
               "vencimiento": "2026-11-01",
               "dias_restantes": 4
           },
           "al_dia": true,
           "genera_alertas": true
       }
   }
   ```

2. **Registrar uno o varios meses:** `pagos.registrar`. La semana es la del mes en que se pagó. Luego, volver a pedir el estado.

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=pagos&accion=registrar`

   Cuerpo:

   ```json
   {
       "documento": "1000000150",
       "tipo": "mensualidad",
       "anio": 2026,
       "pagos": [
           {
               "mes": 10,
               "semana": 2
           },
           {
               "mes": 11,
               "semana": 2
           }
       ]
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "id": 4,
               "deportista_documento": "1000000150",
               "tipo": "mensualidad",
               "anio": 2026,
               "mes": 10,
               "semana": 2
           },
           {
               "id": 5,
               "deportista_documento": "1000000150",
               "tipo": "mensualidad",
               "anio": 2026,
               "mes": 11,
               "semana": 2
           }
       ]
   }
   ```

   Si un mes ya estaba pagado, no se guarda nada del lote:

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=pagos&accion=registrar`

   Cuerpo:

   ```json
   {
       "documento": "1000000150",
       "tipo": "mensualidad",
       "anio": 2026,
       "pagos": [
           {
               "mes": 10,
               "semana": 1
           }
       ]
   }
   ```

   Respuesta `409`:

   ```json
   {
       "exito": false,
       "mensaje": "Ya están pagados los meses 10 de 2026. No se guardó ningún pago."
   }
   ```

3. **Matrícula:** `pagos.registrar` con `tipo: "matricula"`.

   Ejemplo real (llamada como **administrativo**): `POST api.php?recurso=pagos&accion=registrar`

   Cuerpo:

   ```json
   {
       "documento": "1000000150",
       "tipo": "matricula",
       "anio": 2026
   }
   ```

   Respuesta `201`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "id": 6,
               "deportista_documento": "1000000150",
               "tipo": "matricula",
               "anio": 2026,
               "mes": 0,
               "semana": null
           }
       ]
   }
   ```

4. **Historial de pagos:** `pagos.listar`. Para anular uno registrado por error: `pagos.anular` (con doble confirmación en el front).

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=listar&documento=1000000150&anio=2026`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": [
           {
               "id": 6,
               "deportista_documento": "1000000150",
               "tipo": "matricula",
               "anio": 2026,
               "mes": 0,
               "semana": null
           },
           {
               "id": 4,
               "deportista_documento": "1000000150",
               "tipo": "mensualidad",
               "anio": 2026,
               "mes": 10,
               "semana": 2
           },
           {
               "id": 5,
               "deportista_documento": "1000000150",
               "tipo": "mensualidad",
               "anio": 2026,
               "mes": 11,
               "semana": 2
           }
       ]
   }
   ```

   Ejemplo real (llamada como **administrativo**): `DELETE api.php?recurso=pagos&accion=anular&id=5`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": {
           "eliminado": true
       }
   }
   ```

5. **Lista de deudores** (administrativo): `pagos.deudores`. La planilla anual en Excel sale con `reportes.pagos`.

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=pagos&accion=deudores`

   Respuesta `200`:

   ```json
   {
       "exito": true,
       "datos": []
   }
   ```

   Ejemplo real (llamada como **administrativo**): `GET api.php?recurso=reportes&accion=pagos&anio=2026`

   Respuesta `200` (archivo; cabeceras):

   ```http
   Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
   Content-Disposition: attachment; filename="pagos_2026_2026-10-28.xlsx"; filename*=UTF-8''pagos_2026_2026-10-28.xlsx
   X-Content-Type-Options: nosniff
   Content-Length: 14895
   ```

---

*Generado a partir de capturas reales. Para regenerar los ejemplos se usa el procedimiento de `pruebas/backend/README.md`.*
