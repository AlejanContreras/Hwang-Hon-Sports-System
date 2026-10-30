# Catálogo de la API — HwangHon Sports System

Guía para escribir el front **sin leer el backend**. Documenta lo que existe hoy (95 acciones en 14 recursos); no propone cambios.

- **Ejemplos reales.** Cada ejemplo de petición y respuesta se copió de una llamada real hecha el 28/10/2026 contra la base cargada con `base_datos/esquema_hwanghon_v4.sql`, `insercion_datos_prueba.sql` y `cuentas_prueba_locales.sql` (datos ficticios). Las fechas de los ejemplos dependen del día en que se capturaron: «hoy» era 2026-10-28.
- **Listas recortadas.** Si una lista tiene más de 3 elementos, el ejemplo muestra 2 y una línea `"… (N elementos más, omitidos en este ejemplo)"`. Esa línea no la envía la API.
- **Quién llama.** Cada ficha dice qué sesión hace falta: **sin sesión**, **público** (cuenta de un deportista), **administrativo** (cualquier cuenta del club), **cuenta principal** (administrativo con `es_administrador_principal = true`) o **permiso de autorregistro** (el permiso temporal que abre `autorregistro.registrar`).

---

## Parte A — Generalidades

### A.1 URL y forma de la ruta

- **Punto de entrada único:** `…/backend/Public/api.php`. En XAMPP local: `http://localhost/HwangHon-Sports-System/backend/Public/api.php`.
- **Forma oficial (consulta):** `api.php?recurso=<recurso>&accion=<accion>[&otros parámetros]`. Ejemplo: `api.php?recurso=deportistas&accion=consultar&documento=1000000101`. Es la que usan todas las fichas y funciona en cualquier servidor.
- **Forma alternativa (ruta), equivalente:** `…/backend/Public/<recurso>/<accion>[?otros parámetros]`. Ejemplo: `…/backend/Public/deportistas/consultar?documento=1000000101`.
  - Da exactamente la misma respuesta que la forma oficial: mismas acciones, sesiones, permisos, protección CSRF, `415` y errores.
  - Solo el recurso y la acción van en la ruta. Los demás parámetros (`documento`, `id`, `fecha`…) siguen en la consulta, y el cuerpo no cambia.
  - Una barra final se ignora (`deportistas/consultar/`). También vale con `api.php` en la ruta: `…/api.php/deportistas/consultar`.
  - Si la consulta trae `recurso` o `accion`, manda la consulta.
  - Requiere `mod_rewrite` en Apache (ver `backend/Public/.htaccess`). Si el hosting no lo tiene, solo funciona la forma oficial. Por eso el front debe poder volver a ella con un cambio de configuración.
- **Método HTTP:** cada acción exige el suyo (GET para leer, POST para crear o enviar, PUT para cambiar, DELETE para borrar). Con otro método responde `405` «Método no permitido. Se esperaba GET.».
- **Comprobación de salud:** `GET api.php` sin parámetros responde `{"exito":true,"datos":{"mensaje":"HwangHon-Sports-System API activa."}}`.
- **Errores de ruta:**
  - sin `accion`: `400` «Falta el parámetro "accion".»;
  - recurso que no existe: `404` «Recurso no reconocido: x»;
  - acción que no existe: `404` «Acción no reconocida: x».
- **Errores propios de la forma por ruta** (siempre `404`, nunca `500`):
  - sin acción (`…/Public/deportistas`): «Acción no reconocida: falta la acción en la ruta (recurso/accion).»;
  - segmentos de más (`…/deportistas/consultar/extra`): «Acción no reconocida: consultar/extra»;
  - segmentos con caracteres fuera de letras, números y guion (o una barra doble): «Recurso no reconocido.» o «Acción no reconocida.».
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
