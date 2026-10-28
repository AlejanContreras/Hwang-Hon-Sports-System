# INFORME F1 — Estructura, ingreso y deportistas

Fecha: 2026-10-28 · Contrato: `documentacion/api/catalogo_api.md` · Backend sin cambios.

## 1. Archivos creados

| Archivo | Qué hace |
|---|---|
| `frontend/index.html` | Ingreso: pestañas «Personal del club» (correo + contraseña) y «Deportista o familia» (documento + fecha de nacimiento del deportista). |
| `frontend/administrativo.html` | Panel del ámbito administrativo con menú lateral simple. |
| `frontend/publico.html` | «Mi perfil». En F1 solo muestra la sesión y permite salir; el contenido es F4. |
| `frontend/css/estilos.css` | Estilos sobrios, variables CSS, foco visible y diseño responsive (menú plegable a menos de 800 px). |
| `frontend/js/api.js` | **Único punto de contacto con la API.** `solicitar()` (JSON, `credentials: same-origin`, rutas relativas `../backend/Public/api.php`), `ErrorApi` con `estado` y el `mensaje` de la API, aviso global de 401 y `descargar()` para archivos (A.8). Expone `auth`, `deportistas` y `reportes`. |
| `frontend/js/ui.js` | Utilidades de pantalla: `crear()` (sin innerHTML), avisos, notificaciones, diálogo de confirmación (`<dialog>`), campos con `<label>` asociada y formateo (fechas, números, grado, estados). |
| `frontend/js/sesion.js` | `exigirSesion(ambito)`, `cerrarSesion()` y el mensaje que se muestra al volver al ingreso. |
| `frontend/js/ingreso.js` | Lógica del ingreso. |
| `frontend/js/administrativo.js` | Menú y enrutado por hash: `#deportistas`, `#pendientes`, `#deportista/<doc>`, `#deportista-nuevo`, `#deportista-editar/<doc>`. |
| `frontend/js/publico.js` | Control de la sesión pública (placeholder F4). |
| `frontend/js/vistas/deportistas.js` | Lista y filtros, pendientes (Validar / Rechazar), ficha, crear y editar, cambio de estado y ficha PDF. |

## 2. Decisiones

- **Multipágina por ámbito y vistas por hash dentro del panel.** Cada página llama `auth.sesionActual` al cargar: sin sesión vuelve al ingreso; con el otro ámbito redirige a su página. El menú lateral es una lista simple en `administrativo.html` y se cambia sin tocar las vistas.
- **ES modules nativos** (`<script type="module">`), sin framework ni librerías.
- **401 en cualquier ruta autenticada** → `api.js` avisa a `sesion.js`, que guarda el mensaje de la API en `sessionStorage` y vuelve al ingreso, donde se muestra. En las rutas de ingreso el 401 lo maneja la pantalla («Correo o contraseña incorrectos.»).
- **429**: se muestra el mensaje tal cual y el botón queda deshabilitado los N minutos del mensaje, o hasta que se cambie el correo o documento (el bloqueo es por identificador).
- **403 «Tu registro está en revisión.»** se muestra como advertencia, no como error.
- **Filtros de la lista:** `estado`, `estado_registro` y `sede_entrenamiento` van a la API (`deportistas.listar`). El texto de búsqueda y el grado se filtran en el navegador sobre la lista completa (ver §4). La búsqueda imita `deportistas.buscar`: documento que empieza por el texto o nombre que lo contiene, sin tildes ni mayúsculas. Los filtros se conservan al ir a una ficha y volver.
- **Edición parcial:** solo se envían los campos que cambiaron; un campo opcional vaciado se envía como `null` (lo borra, A.2). Grado guardado con otra escritura («amarillo») se muestra como «Amarillo» y no cuenta como cambio.
- **Acudiente al editar:** mismo documento → se envían solo los datos cambiados (edita el actual); otro documento → se envía el objeto con documento (reasigna o crea); todos los campos vacíos → `acudiente: null` (la API lo rechaza si es menor).
- **Validaciones:** el formulario usa `novalidate` y deja las reglas a la API, cuyo mensaje se muestra tal cual. No se calcula la edad en el navegador para exigir acudiente (la regla depende del «hoy» del servidor).
- **Seguridad de datos:** ningún dato de la API se pinta con innerHTML (verificado con búsqueda en `frontend/`). No hay credenciales en el código.
- **«Pasar a inactivo»** también ofrece el inverso («Pasar a activo») en la ficha de un validado. Validar y Rechazar aparecen en la pestaña de pendientes y en la ficha de un pendiente; todas las acciones piden confirmación.
- **Ficha PDF:** `fetch` + `blob`, revisando código y `Content-Type` antes de guardar; el nombre sale de `Content-Disposition` (`filename*`).

## 3. Probado a mano (navegador integrado, http://localhost/HwangHon-Sports-System/frontend/)

| # | Caso | Resultado |
|---|---|---|
| 1 | Abrir el front sin sesión | Muestra el ingreso administrativo ✔ |
| 2 | Clave errada (`administrativo1@…`) | «Correo o contraseña incorrectos.» ✔ |
| 3 | 4.º fallo seguido | «Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.» y botón deshabilitado ✔ |
| 4 | Cambiar el correo tras el 429 | El botón se rehabilita ✔ |
| 5 | Cuenta desactivada (`inactivo@…`) | «Correo o contraseña incorrectos.» ✔ |
| 6 | Público pendiente (1000000180 / 1995-04-04) | 403 «Tu registro está en revisión.» como advertencia, sin entrar ✔ |
| 7 | Público con fecha errada (1000000101 / 2014-03-13) | «Documento o fecha de nacimiento incorrectos.» ✔ |
| 8 | Público válido (1000000101 / 2014-03-12) | Entra a `publico.html` con el nombre ✔ |
| 9 | Con sesión pública abrir `administrativo.html` o `index.html` | Redirige a `publico.html` ✔ |
| 10 | Cerrar sesión (público y administrativo) | Vuelve al ingreso con «Sesión cerrada.» ✔ |
| 11 | Ingreso `maestro@hwanghon.local` | Panel con la lista (8 deportistas) y «Pendientes de validación (3)» ✔ |
| 12 | Búsqueda «FABIAN» (sin tilde) / «100000018» | Fabián Esteban Rincón Peña / los dos 100000018x ✔ |
| 13 | Filtros estado inactivo, registro pendiente, grado Amarillo, grado Ingresado, sede «Escuela IMRDS» (y en minúsculas), Limpiar | Resultados correctos en todos ✔ |
| 14 | Rechazar 1000000163 (pendiente con asistencia) | 409 «El registro ya tiene datos en otros módulos (pagos, asistencia, torneos o exámenes): no se puede eliminar.» mostrado tal cual; la fila sigue ✔ |
| 15 | Validar 1000000180 | Notificación, sale de pendientes, contador baja ✔ |
| 16 | Rechazar 1000000181 | Se elimina, contador «(1)» ✔ |
| 17 | Crear menor sin acudiente | 400 «El deportista es menor de edad: se requiere un acudiente.» ✔ |
| 18 | Crear menor con acudiente nuevo (1000000182 / acudiente 1000000982) | 201, abre la ficha con acudiente, categorías y salud ✔ |
| 19 | Guardar edición sin cambios | «No hay cambios para guardar.» sin llamar a la API ✔ |
| 20 | Editar peso, grado y teléfono del acudiente | Cuerpo enviado: `{"peso":36,"grado_actual":"Blanco","acudiente":{"telefono":"3000000983"}}` ✔ |
| 21 | Vaciar el teléfono | Cuerpo `{"telefono":null}`; la ficha muestra «—» ✔ |
| 22 | Quitar el acudiente a un menor | 400 «El deportista es menor de edad y no tiene acudiente asignado.» ✔ |
| 23 | Editar 1000000103 (grado guardado «amarillo») sin cambios | Muestra «Amarillo» y no envía nada ✔ |
| 24 | Crear con documento repetido / teléfono «abc» | 409 «Ya existe un deportista con el documento 1000000182.» / 400 del campo `telefono` ✔ |
| 25 | Pasar a inactivo (cancelar y luego aceptar) y volver a activo | Cancelar no cambia nada; aceptar cambia la insignia y el botón ✔ |
| 26 | Descargar ficha PDF | Descarga `ficha_1000000182_2026-10-28.pdf` ✔ (no abrí el contenido) |
| 27 | Ficha de un documento inexistente | «No encontrado.» ✔ |
| 28 | Sesión cerrada en el servidor y luego navegar | Vuelve al ingreso con «No autenticado. Inicie sesión para continuar.» ✔ |
| 29 | Vista móvil 375 px | Menú plegable «Menú», filtros en una columna, ficha legible ✔ (corregí el ancho del botón «Menú») |
| 30 | Consola | Sin errores de JavaScript; solo los códigos HTTP esperados de los casos de error ✔ |

**Datos que quedaron en la base local por las pruebas (todos ficticios):** 1000000180 validado; 1000000181 eliminado; 1000000182 «Tomás Prueba Front Ficticio» (activo) con el acudiente 1000000982. Un validado no se puede borrar desde la API.

## 4. No probado

- Navegación completa solo con teclado (Tab/Enter/Escape real) y lector de pantalla: las pestañas tienen flechas, los diálogos son `<dialog>` modales y todo control tiene etiqueta, pero lo probé con clics y scripts, no tecleando.
- Fin del bloqueo de 15 minutos y vencimiento real de la sesión por inactividad (simulé el 401 cerrando la sesión en el servidor). El camino de «Tu sesión ya no es válida.» usa el mismo manejador, pero no lo provoqué.
- Ingreso con una cuenta administrativa **no principal**: `administrativo1@hwanghon.local` quedó bloqueada 15 min por la prueba del 429 y no pude limpiar `intento_acceso` (el permiso para borrar en la base fue denegado en esta sesión). En F1 no hay nada distinto para ella.
- Contenido del PDF; otros navegadores (solo el navegador integrado, basado en Chromium).
- Errores 500 y caída de red (el mensaje existe en `api.js`, no lo provoqué).

## 5. Lo que la API no cubre o estorba (sin rodearlo en el backend)

1. **`deportistas.listar` no filtra por grado «Ingresado»** (`grado_actual` null) y el grado se guarda con escrituras distintas (la semilla tiene «amarillo»; el catálogo A.7 dice «Amarillo»). Por eso el grado se filtra en el navegador comparando sin mayúsculas ni tildes. Sugerencia: normalizar el grado al guardar.
2. **No hay búsqueda de texto en `listar`**, y `deportistas.buscar` devuelve solo 5 campos y hasta 20 resultados: no sirve para la tabla con filtros. La búsqueda se hace sobre la lista completa en el navegador (sirve mientras no haya paginación, A.9).
3. **No hay lista de sedes**: el filtro es texto exacto con sugerencias de las sedes ya vistas.
4. **La API no expone la fecha de hoy de Bogotá.** En F1 no hizo falta, pero en F2 (fecha por defecto de la toma) y F3 (año por defecto) el front tendría que adivinarla con el reloj del navegador o usar `asistencia.resumenDia` / `pagos.estado` como referencia. Un `GET` que devuelva la fecha del servidor evitaría depender del navegador.
5. **Entorno, no API:** la base local ya no coincide con lo que dice `cuentas_prueba_locales.sql`: 1000000102 está **validado e inactivo** (entra al ámbito público en lugar de dar 403), quedaron registros de las capturas del catálogo (1000000163, 1000000170…). Para repetir las pruebas conviene recargar la base o actualizar ese comentario.

## 6. Capturas

Tomadas en el navegador integrado con datos ficticios (ingreso con 429, lista con filtros, pendientes con el 409, ficha en móvil). No las guardé como archivos; si las quieres en el repositorio, dime dónde.
