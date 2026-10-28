## Parte B — Fichas por acción

Cada ficha indica: método y quién puede llamarla; parámetros de URL; cuerpo; respuesta con un ejemplo real; errores propios (los comunes están en A.5); y notas para el front.

---

### B.1 `auth` — inicio y cierre de sesión (7 acciones)

#### `auth.iniciarSesionAdministrativa` — POST · sin sesión

- **Cuerpo:** `correo` (texto, obligatorio, correo válido de máximo 150; se pasa a minúsculas) y `contrasena` (texto, obligatorio, se compara tal cual).
- **Respuesta:** `ambito`, `correo`, `nombre` y `es_administrador_principal`. Además fija la cookie `HWANGHON_SESION`.

{{ej:auth.iniciarSesionAdministrativa}}

- **Errores:**
  - {{err:auth.iniciarSesionAdministrativa.error401}}: el mismo mensaje si el correo no existe, la clave es errada o la cuenta está desactivada;
  - `429` «Demasiados intentos fallidos. Intenta de nuevo en N minutos.» al cuarto fallo seguido;
  - `400` si falta un campo o el correo no es válido.
- **Notas:** el mensaje nunca revela si el correo existe. Con `es_administrador_principal = true` el front muestra la gestión de cuentas.

#### `auth.iniciarSesionPublica` — POST · sin sesión

- **Cuerpo:** `documento` (texto o número, obligatorio, máximo 20) y `fecha_nacimiento` (`AAAA-MM-DD`, obligatoria: la **del deportista**, también cuando entra el acudiente).
- **Respuesta:** `ambito`, `documento` y `nombre`, más la cookie.

{{ej:auth.iniciarSesionPublica}}

- **Errores:**
  - `401` «Documento o fecha de nacimiento incorrectos.»;
  - {{err:auth.iniciarSesionPublica.error403}}: registro pendiente de validación, sin sesión y sin contar como fallo;
  - `429` por bloqueo;
  - `400` por formato.
- **Notas:** un deportista **inactivo** sí entra.

#### `auth.cerrarSesion` — POST · cualquier sesión (o ninguna)

- **Cuerpo:** ninguno.
- **Respuesta:** `{"sesion_cerrada": true}`. Es idempotente: sin sesión responde lo mismo.

{{ej:auth.cerrarSesion}}

#### `auth.sesionActual` — GET · administrativo o público

- **Respuesta:**
  - administrativo: `ambito`, `identificador` (correo), `nombre` y `es_administrador_principal`;
  - público: `ambito`, `identificador` (documento) y `nombre`.

{{ej:auth.sesionActual.admin}}

{{ej:auth.sesionActual.publico}}

- **Errores:** {{err:auth.sesionActual.error401}}.
- **Notas:** llamarla al cargar el front para saber si ya hay sesión y de qué ámbito.

#### `auth.cambiarContrasena` — POST · administrativo

- **Cuerpo:** `contrasena_actual` (obligatoria) y `contrasena_nueva` (8 caracteres a 72 bytes).
- **Respuesta:** `{"contrasena_cambiada": true}`.

{{ej:auth.cambiarContrasena}}

- **Errores:**
  - {{err:auth.cambiarContrasena.error400}};
  - {{err:auth.cambiarContrasena.error403}};
  - `400` si la nueva no cumple la longitud.
- **Notas:**
  - la sesión actual sigue (con un id de sesión nuevo) y **las demás sesiones de esa cuenta caen** con `401`;
  - invalida cualquier enlace de recuperación pendiente.

#### `auth.solicitarRecuperacionContrasena` — POST · sin sesión

- **Cuerpo:** `correo` (obligatorio).
- **Respuesta:** siempre el mismo mensaje, exista o no la cuenta.

{{ej:auth.solicitarRecuperacionContrasena}}

- **Notas:**
  - si la cuenta existe y está activa, el servidor envía, **después** de responder, un correo con el enlace `url_restablecimiento?token=<64 hex>` (la URL se configura en `Config/correo.php`), válido 1 hora;
  - mientras haya un enlace vigente no se genera otro;
  - el front solo muestra el mensaje.

#### `auth.restablecerContrasena` — POST · sin sesión

- **Cuerpo:** `token` (64 caracteres hexadecimales, el del enlace) y `contrasena_nueva`.
- **Respuesta:** `{"contrasena_restablecida": true}`. **No** inicia sesión.

{{ej:auth.restablecerContrasena}}

- **Errores:** {{err:auth.restablecerContrasena.error400}}: token mal formado, inexistente, vencido o ya usado.
- **Notas:** borra el bloqueo de intentos de ese correo y cierra las sesiones abiertas de la cuenta. La pantalla de restablecer lee `token` de la URL del enlace.

---

### B.2 `usuarios-administrativos` — cuentas del club (7 acciones)

Todas exigen sesión administrativa de una **cuenta principal** (`es_administrador_principal = true`). Cualquier otra cuenta administrativa recibe {{err:usuarios-administrativos.listar.error403}}.

Una cuenta tiene: `correo`, `nombre`, `rol` (texto libre o null), `es_administrador_principal`, `activa` y `creado_por_correo`. **Nunca** se devuelven la contraseña ni el token de recuperación.

#### `usuarios-administrativos.listar` — GET · cuenta principal

- **Respuesta:** todas las cuentas (activas y desactivadas), por nombre y correo.

{{ej:usuarios-administrativos.listar}}

#### `usuarios-administrativos.consultar` — GET · cuenta principal

- **URL:** `correo` (obligatorio).
- **Respuesta:** la cuenta. Si no existe: `404` «No encontrado.».

{{ej:usuarios-administrativos.consultar}}

#### `usuarios-administrativos.crear` — POST · cuenta principal

- **Cuerpo:**
  - `correo`: obligatorio, único;
  - `nombre`: obligatorio, máximo 150;
  - `rol`: opcional, máximo 30;
  - `contrasena`: obligatoria, la contraseña inicial, de 8 caracteres a 72 bytes.
- **Respuesta (201):** la cuenta creada. Nace activa, sin rol principal y con `creado_por_correo` = quien la crea.

{{ej:usuarios-administrativos.crear}}

- **Errores:** {{err:usuarios-administrativos.crear.error409}}; `400` «Campos no permitidos: x.».

#### `usuarios-administrativos.actualizar` — PUT · cuenta principal

- **URL:** `correo`.
- **Cuerpo (parcial):** `nombre`, `rol` y `correo` (el correo nuevo).
- **Respuesta:** la cuenta actualizada.

{{ej:usuarios-administrativos.actualizar}}

- **Errores:**
  - `400` «No se recibió ningún campo para actualizar.»;
  - `409` «No puedes cambiar tu propio correo: la sesión lo usa para identificarte. Pide a la otra cuenta principal que lo cambie.»;
  - `409` por un correo ya usado;
  - `404` «Cuenta no encontrada.».
- **Notas:** no cambia la contraseña, `activa` ni `es_administrador_principal`.

#### `usuarios-administrativos.desactivar` — PUT · cuenta principal

- **URL:** `correo`.
- **Respuesta:** la cuenta con `activa: false`. Es idempotente.

{{ej:usuarios-administrativos.desactivar}}

- **Errores:** {{err:usuarios-administrativos.desactivar.error409}}; `409` «Una cuenta principal no se puede desactivar desde la aplicación.».
- **Notas:** la sesión abierta de esa cuenta cae en su siguiente petición. Las cuentas nunca se borran.

#### `usuarios-administrativos.reactivar` — PUT · cuenta principal

- **URL:** `correo`.
- **Respuesta:** la cuenta con `activa: true`. Es idempotente.

{{ej:usuarios-administrativos.reactivar}}

#### `usuarios-administrativos.restablecerContrasena` — PUT · cuenta principal

- **URL:** `correo` (de OTRA cuenta).
- **Cuerpo:** `contrasena_nueva`.
- **Respuesta:** `{"contrasena_restablecida": true}`.

{{ej:usuarios-administrativos.restablecerContrasena}}

- **Errores:** {{err:usuarios-administrativos.restablecerContrasena.error409}}.
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

{{ej:deportistas.listar.admin}}

Lista de pendientes de validación:

{{ej:deportistas.listar.pendientes}}

Público (sin `validado_por_correo`):

{{ej:deportistas.listar.publico}}

- **Notas:** `listar` no trae el récord ni el acudiente (para eso, `consultar`).

#### `deportistas.consultar` — GET · administrativo o público

- **URL:** `documento` (obligatorio).
- **Respuesta:** la ficha más `combates_ganados` y `combates_perdidos` (récord acumulado de torneos) y `acudiente` (`{documento, nombre, relacion, telefono, correo}` o null).

{{ej:deportistas.consultar.admin}}

- **Diferencias por ámbito:** el público solo consulta su propio documento y no recibe `validado_por_correo`. Con un documento ajeno responde {{err:deportistas.consultar.error404}}, igual que si no existiera.

#### `deportistas.buscar` — GET · administrativo

- **URL:** `texto` (mínimo 2 caracteres).
- **Respuesta:** hasta 20 deportistas cuyo documento **empieza** por el texto o cuyo nombre lo **contiene** (sin distinguir mayúsculas ni tildes), con `documento`, `nombre`, `estado`, `estado_registro` y `grado_actual`. Incluye inactivos y pendientes.

{{ej:deportistas.buscar}}

- **Errores:** {{err:deportistas.buscar.error400}}.
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

{{ej:deportistas.crear}}

- **Errores:**
  - {{err:deportistas.crear.error409}};
  - {{err:deportistas.crear.error400}};
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

{{ej:deportistas.actualizar.admin}}

Validar un registro pendiente:

{{ej:deportistas.actualizar.validar}}

Público:

{{ej:deportistas.actualizar.publico}}

- **Errores:**
  - {{err:deportistas.actualizar.error403}} (público con otro campo);
  - `400` «No se recibió ningún campo para actualizar.»;
  - `409` «Un registro validado no puede volver a pendiente de validación.»;
  - `400` «El deportista es menor de edad y no tiene acudiente asignado.»;
  - `404` si no existe o es ajeno.
- **Notas:** el estado `inactivo` lo pone el botón «Revisar inactivos» o el administrativo. Marcar al deportista presente lo reactiva solo.

#### `deportistas.eliminar` — DELETE · administrativo

- **URL:** `documento`.
- **Respuesta:** `{"eliminado": true}`. Solo para registros **pendientes de validación** (así se «rechaza» un autorregistro).
- **Qué borra:** en una sola transacción, sus documentos (p. ej. los subidos en el autorregistro), el deportista y su acudiente si queda sin deportistas. Los archivos de esos documentos se borran del disco **después** de confirmar la transacción; si algo falla antes, no se borra nada (ni filas ni archivos).

{{ej:deportistas.eliminar}}

Con documentos cargados:

{{ej:deportistas.eliminar.conDocumentos}}

- **Errores:**
  - {{err:deportistas.eliminar.error409}};
  - {{err:deportistas.eliminar.error409modulos}}: el pendiente ya tiene asistencia, pagos, torneos o exámenes; esos datos no se borran en silencio;
  - `404`.

---

### B.4 `autorregistro` — inscripción pública (6 acciones)

Ruta **sin sesión**. Las cinco acciones de documentos necesitan el **permiso de autorregistro** que abre `registrar` en la cookie (2 horas, solo para ese documento). Sin él, o tras `confirmar`, responden {{err:autorregistro.subirDocumento.error403}}.

#### `autorregistro.registrar` — POST · sin sesión

- **Cuerpo:** los campos de `deportistas.crear` (incluido `acudiente` si es menor), con dos diferencias:
  - `autorizacion_datos` **debe ser `true`**;
  - si el acudiente se envía, `nombre` y `relacion` son obligatorios aunque ya exista.
- **Campos ignorados:** si llegan, el servidor ignora `estado`, `estado_registro`, `validado_por_correo`, `validado_por`, `grado_actual` y `fecha_ingreso`. El registro queda pendiente, activo, sin grado y con ingreso hoy.
- **Campo trampa:** `sitio_web`. El formulario lo lleva **oculto y vacío**; si llega con valor, la respuesta es la misma pero no se crea nada.
- **Respuesta (200):** solo un mensaje y el estado de la carga de documentos, sin datos ni ids.

{{ej:autorregistro.registrar}}

- **Errores:**
  - {{err:autorregistro.registrar.error400}};
  - `400` por campos inválidos o vacíos, o JSON mal formado;
  - `413` si el cuerpo supera 128 KB;
  - `429` «Se recibieron demasiados registros desde esta conexión. Intenta de nuevo más tarde.» (10 por hora por IP).
- **Notas:** un documento que ya existe recibe **la misma respuesta 200** (para no revelar quién está inscrito), pero su permiso no deja subir documentos. El registro no entra a la cuenta pública hasta que el club lo valide.

#### `autorregistro.listarDocumentos` — GET · permiso de autorregistro

- **Respuesta:** los documentos de ese registro y hasta cuándo vale el permiso.

{{ej:autorregistro.listarDocumentos}}

#### `autorregistro.subirDocumento` — POST multipart · permiso de autorregistro

- **Campos:**
  - `tipo_documento`: solo `documento_identidad`, `formulario_inscripcion`, `documento_acudiente` o `fotografia`;
  - `archivo`: formato según el tipo, máximo 10 MB;
  - `documento`: opcional; si viene, debe ser el del registro.
- **Respuesta (201):** el documento creado.

{{ej:autorregistro.subirDocumento}}

- **Errores:**
  - `409` «Ese documento ya está cargado: usa «reemplazarDocumento» para cambiarlo.»;
  - `403` por otro tipo o por un documento ajeno;
  - `400` por formato o tamaño.

#### `autorregistro.reemplazarDocumento` — POST multipart · permiso de autorregistro

- **URL:** `id` (de un documento propio).
- **Campos:** `archivo`.
- **Respuesta:** el documento con el nombre nuevo.

{{ej:autorregistro.reemplazarDocumento}}

- **Errores:** `404` «Documento no encontrado.» si es ajeno o no existe.

#### `autorregistro.eliminarDocumento` — DELETE · permiso de autorregistro

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`.

{{ej:autorregistro.eliminarDocumento}}

#### `autorregistro.confirmar` — POST · permiso de autorregistro

- **Respuesta:** un mensaje. Cierra el permiso; desde ahí los documentos solo los cambia el club.

{{ej:autorregistro.confirmar}}

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

{{ej:importacion-deportistas.previsualizar}}

- **Errores:**
  - {{err:importacion-deportistas.previsualizar.error400}};
  - `400` si el archivo está vacío, no trae encabezados o no es un CSV de texto;
  - `413` si pasa de 1 MB;
  - `422` si pasa de 1.000 filas.
- **Notas:** una celda vacía **no borra** el dato de un deportista existente. Si un documento se repite en el archivo, se rechazan todas sus filas.

#### `importacion-deportistas.aplicar` — POST multipart · administrativo

- **Campos:**
  - `archivo`: el mismo CSV;
  - `confirmar_actualizacion`: documentos cuya actualización aprobó el administrativo, como lista (`confirmar_actualizacion[]=…`) o texto separado por comas.
- **Respuesta:** `resumen` (`creadas`, `actualizadas`, `omitidas`, `rechazadas`, `errores`) y resultado por fila.

{{ej:importacion-deportistas.aplicar}}

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

{{ej:documentos.listar.admin}}

{{ej:documentos.listar.publico}}

- **Errores:** `404` «Deportista no encontrado.» (inexistente, o ajeno para el público).

#### `documentos.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** los metadatos. Si es ajeno para el público o no existe: `404` «No encontrado.».

{{ej:documentos.consultar}}

#### `documentos.subir` — POST multipart · administrativo o público

- **Campos:** `documento`, `tipo_documento` y `archivo` (formato según el tipo, máximo 10 MB).
- **Respuesta (201):** el documento creado.

{{ej:documentos.subir}}

- **Errores:**
  - {{err:documentos.subir.error409}} (tipos de un solo archivo);
  - `400` «El campo "tipo_documento" debe ser uno de: …»;
  - `400` por formato o tamaño;
  - `404` si el deportista no existe.
- **Diferencias por ámbito:** el público solo sube **lo suyo** y solo los cuatro tipos de un solo archivo que aún estén vacíos. Para cambiar uno ya cargado recibe `409` «Ese documento ya está cargado. Para cambiarlo, comunícate con el club.»; para otros tipos, `403` «Este tipo de documento solo lo carga el personal administrativo.».

#### `documentos.reemplazar` — POST multipart · administrativo

- **URL:** `id`.
- **Campos:** `archivo`.
- **Respuesta:** el documento con el nombre nuevo. El archivo anterior se borra del disco.

{{ej:documentos.reemplazar}}

#### `documentos.eliminar` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`. Borra la fila y el archivo.

{{ej:documentos.eliminar}}

- **Errores:** {{err:documentos.eliminar.error403}}; `404`.

#### `documentos.descargar` — GET · administrativo o público (lo suyo)

- **URL:** `id`.
- **Respuesta:** el archivo con su `nombre_original` (A.8).

{{ej:documentos.descargar}}

- **Errores:** {{err:documentos.descargar.error404}} (la fila existe pero el archivo no está en el servidor); `404` «Documento no encontrado.».

#### `documentos.faltantes` — GET · administrativo

- **URL:** `documento` (opcional).
- **Respuesta:**
  - con `documento`: `{criticos, no_criticos}` de ese deportista. Críticos: identidad, formulario y, si es menor, el del acudiente; no crítico: la fotografía.
  - sin `documento`: la lista de **todos los pendientes de validación** con sus faltantes.

{{ej:documentos.faltantes.uno}}

{{ej:documentos.faltantes.pendientes}}

#### `documentos.listarGenerales` — GET · administrativo o público

- **Respuesta:** documentos generales del club (p. ej., teorías de ascenso): `id`, `titulo`, `nombre_original` y `fecha_carga`.

{{ej:documentos.listarGenerales}}

#### `documentos.descargarGeneral` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** el archivo.

{{ej:documentos.descargarGeneral}}

#### `documentos.subirGeneral` — POST multipart · administrativo

- **Campos:** `titulo` (obligatorio, máximo 150) y `archivo` (pdf, doc, docx, xls, xlsx, jpg, jpeg, png o webp; máximo 10 MB).
- **Respuesta (201):** el documento general.

{{ej:documentos.subirGeneral}}

#### `documentos.reemplazarGeneral` — POST multipart · administrativo

- **URL:** `id`.
- **Campos:** `archivo` y `titulo` (opcional).
- **Respuesta:** el documento general actualizado.

{{ej:documentos.reemplazarGeneral}}

#### `documentos.eliminarGeneral` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`.

{{ej:documentos.eliminarGeneral}}

---
