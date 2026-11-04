# Informe de pruebas — HwangHon-Sports-System

- **Última ronda:** final corta del 06/11/2026 (corrida el 04/11 por la tarde, hora de Bogotá), sobre el front final con DEF-02 y OBS-04 corregidos. Ver **§10**.
- **Ronda anterior:** 04/11/2026, sobre el front final. Ver **§9**.
- **Corrida anterior:** 31/10/2026 (hora de Bogotá): API, front, baterías, humo y comparación de cuentas en la misma corrida.
- **Código probado:** commit `b33d53f` (front con panel lateral fijo con iconos, sin cabecera; «Cerrar sesión» en Cuentas / Mi perfil). El backend es el mismo de `5fca1f7`.
- **Contrato:** `documentacion/api/catalogo_api.md` (95 acciones, 14 recursos).
- **Entorno:** base aparte `hwanghon_pruebas` (MariaDB 10.11), PHP 8.3 con `php -S` y `router_aislado.php`, Chrome 153 sin interfaz, Newman 6.2, pytest 9 y Selenium 4.52. Todo con datos ficticios. La base `hwanghon` y `backend/Almacenamiento/` del proyecto no se tocaron.
- **Cómo repetirlo:** ver [`README.md`](README.md).

## 1. Resumen

| Parte | Qué se corrió | Resultado |
|---|---|---|
| API — Postman/Newman, forma oficial (`api.php?recurso=…&accion=…`) | 215 peticiones, 95/95 acciones, 1.090 comprobaciones | ✅ **1.090 / 1.090** |
| API — Postman/Newman, forma por ruta (`…/Public/<recurso>/<accion>`) | La misma colección con `forma_url=ruta` | ✅ **1.090 / 1.090** |
| Front — Selenium + pytest | 10 flujos × 2 dispositivos (escritorio 1366×900 y celular 390×844) | ✅ **20 / 20** |
| Backend — 21 baterías PHP, contra la base aparte | `ejecutar_todo.sh` protegido (R-01 mitigado), desde `ejecutar_baterias_aisladas.py` | ✅ **1.808 / 1.808** (21/21 baterías, incluida la sección F de recuperación) |
| Humo del hosting (`humo_hosting.py`) | Contra el servidor aislado + control negativo | ✅ **16 / 16**; el control negativo detecta sus 4 fallos |
| Cuenta de desarrollador frente a `maestro@` (`comparar_cuentas.py`) | 215 peticiones × 2 cuentas + 8 llamadas directas | ✅ **sin diferencias** (§6) |

**Estado al cierre (ronda final, §10):** DEF-01, DEF-02 (en el front) y OBS-04 cerrados; Selenium **65 / 65**.

**Conclusión (31/10):** la API cumple el catálogo en las 95 acciones, en las dos formas de URL: códigos, formato de respuesta, forma de `datos`, mensajes de error, sesiones por ámbito, CSRF y descargas. Los flujos clave del front funcionan de punta a punta en escritorio y en celular, sin errores de JavaScript y sin desborde horizontal en 390 px. Se encontró **1 defecto del front, de severidad baja** (DEF-01). El riesgo de las baterías (R-01) **quedó mitigado**; hay y **tres observaciones de documentación** (OBS-01 a OBS-03). Ninguno bloquea la entrega.

## 2. Detalle por parte

### 2.1 API (Postman/Newman)

- **Cobertura:** las 95 acciones de la tabla B.15. El generador falla si falta una o si el método no coincide con el catálogo.
- **Sesiones por ámbito:**
  - **sin sesión** (sin cookie);
  - **cuenta principal** (`maestro@`);
  - **administrativo no principal** (`administrativo1@`);
  - **público** (1000000101 / 2014-03-12);
  - **permiso de autorregistro**.
- **Casos de error comprobados:**
  - `400`: fecha mal formada, campo desconocido, nota 11, semana 5, JSON mal formado, parámetro en lista, falta la acción, rango invertido, CSV sin columnas;
  - `401`: sin sesión, credenciales erradas, sesión cerrada, sesión invalidada por cambio de contraseña, permiso de autorregistro usado como cuenta;
  - `403`: público en rutas administrativas, cuenta no principal en cuentas, registro pendiente, permiso de autorregistro cerrado, CSRF por `Origin` y por `Sec-Fetch-Site`;
  - `404`: ajeno o inexistente, recurso o acción inexistente, errores de la forma por ruta;
  - `405`;
  - `409`: duplicados, ya hecho, sesión con pendientes, evento de otro módulo;
  - `415`;
  - `422`: torneo futuro, rango de más de 366 días, evento que no es competencia;
  - `429`: bloqueo al cuarto fallo seguido, y sigue bloqueado en el quinto intento.
- **Forma de las respuestas:** en cada éxito se comparan las claves de `datos` con el ejemplo real de la ficha. En `deportistas.consultar` público se comprueba además que **no** llegue `validado_por_correo`, como dice el catálogo.
- **Descargas:** `Content-Type`, `Content-Disposition: attachment; filename=…; filename*=UTF-8''…` y `X-Content-Type-Options: nosniff`.
- **Ningún `500`** en las 430 respuestas (215 en cada forma de URL).
- **Tiempo:** unos 9 s por corrida.

### 2.2 Front (Selenium)

Los selectores son estables: `id`, `name` de los campos, `data-prueba` y `aria-label`; no se usan textos de botones. Cada acción se verifica en la API: lo que quedó guardado, no el mensaje en pantalla.

| # | Flujo | Qué se verifica |
|---|---|---|
| 1 | Ingreso administrativo y público | Clave errada → aviso; ingreso; en celular el menú arranca plegado; pendiente → «Tu registro está en revisión.»; el público no tiene Cuentas, Exportación ni Carga masiva |
| 2 | Crear, validar y rechazar deportista | Menor con acudiente creado; el pendiente de la semilla queda `validado`; un registro nuevo rechazado da 404 |
| 3 | Tomar asistencia | Último sábado; la marca de 1000000101 queda `asistio = true` |
| 4 | Registrar pago | Primer mes pendiente con semana 2, guardado en `pagos.listar` |
| 5 | Subir y descargar documento | Formulario de inscripción guardado; el archivo descargado es idéntico al subido |
| 6 | Crear torneo e inscribir | Torneo a 60 días; inscripción en «formas» con el deportista 1000000101 |
| 7 | Sesión de examen y calificar | Sesión con el candidato; nota 8.5 en teórico guardada |
| 8 | Exportar reporte | Deportistas activos: `.xlsx` válido |
| 9 | Carga masiva CSV | No se aplica sin previsualizar; previsualizar no guarda; aplicar crea los 2, con acudiente |
| 10 | Cuentas y cerrar sesión | La no principal ve «Mi cuenta» y no «Cuentas del club»; la principal crea una cuenta; «Cerrar sesión» desde Cuentas y desde Mi perfil; sin sesión el panel devuelve al ingreso |

En todos los flujos se comprueba además que no haya errores de JavaScript y que la página no se desborde en horizontal. Corrida completa: 105 s.

### 2.3 Baterías del backend

Las 21 baterías pasan contra `hwanghon_pruebas`, con `ejecutar_todo.sh` ya protegido (R-01). Las pruebas de las baterías no cambiaron; solo se agregó la guarda de las secciones «Apache»: **1.808 comprobaciones**, el mismo total que da `pruebas/backend/README.md` con la sección F. La sección F de recuperación de contraseña sí se pudo correr, porque la copia usa el correo en modo `archivo`.

| Batería | OK | Batería | OK | Batería | OK |
|---|---|---|---|---|---|
| repositorios | 145 | calculo_pagos | 55 | evaluaciones | 98 |
| deportistas | 69 | pagos | 57 | estadisticas | 107 |
| deportistas_paso3 | 232 | calendario | 80 | reportes | 110 |
| auth | 68 | competencias | 91 | autorregistro | 142 |
| usuarios | 71 | recomendacion_peso | 9 | revision | 109 |
| documentos | 99 | categorias_peso | 58 | recuperacion_diferida | 7 |
| asistencia | 75 | reglas_evaluacion | 22 | urls_limpias | 104 |

Nota de la corrida: en el primer intento, `documentos` dio 2 fallos («archivo de exactamente 10 MB → 400: supera 8 MB»). La causa estaba en el **entorno de prueba**, no en la aplicación: el servidor 8090 arrancó con el `upload_max_filesize` por defecto de PHP (8 MB), y XAMPP usa 40 MB. Se corrigió el ejecutor para usar los límites de XAMPP y la batería pasó completa (99/99).

Salidas completas: `resultados/baterias/`.

## 3. Defectos

### DEF-01 · Front · Severidad BAJA · ✅ CORREGIDO (verificado el 04/11, 3 de 3) — El ingreso muestra «No autenticado…» en vez de «Tu sesión ya no es válida.»

- **Dónde:** `frontend/js/sesion.js` (`exigirSesion` → `alVencerSesion` → `volverAlIngreso`) y `frontend/js/api.js` (`solicitar`, rama 401).
- **Pasos para reproducirlo:**
  1. Entrar como `administrativo1@hwanghon.local` (clave `12345678`) e ir a «Deportistas».
  2. Desde otra sesión, `maestro@hwanghon.local` restablece la contraseña de `administrativo1@` (Cuentas → Restablecer contraseña, o `PUT usuarios-administrativos.restablecerContrasena`).
  3. En la primera sesión, abrir «Asistencia». Esta vista hace varias peticiones a la vez.
- **Resultado esperado (catálogo A.3):** la sesión cae con `401` «Tu sesión ya no es válida.» y el front vuelve al ingreso mostrando ese mensaje.
- **Resultado obtenido:** vuelve al ingreso, pero muchas veces muestra «No autenticado. Inicie sesión para continuar.».
- **Frecuencia:** 3 de 5 intentos con «Asistencia». Con una vista de una sola petición («Torneos»), 2 de 2 veces sale el mensaje correcto.
- **Causa:** la API responde «Tu sesión ya no es válida.» solo a la **primera** petición, y en esa misma petición destruye la sesión; las peticiones paralelas reciben «No autenticado…». Cada 401 llama a `volverAlIngreso(error.message)`, que **sobrescribe** el motivo guardado. Gana el último 401 en llegar.
- **Impacto:** solo el texto. La persona vuelve al ingreso de todos modos, pero no entiende que la causa fue un cambio de contraseña.
- **Sugerencia (para el chat de front):** en `sesion.js`, guardar solo el **primer** motivo, por ejemplo con una bandera `saliendo` que ignore los 401 siguientes.
- **Estado:** se reprodujo el 30/10 con el front anterior. **Corregido en el árbol de trabajo del 04/11:** `volverAlIngreso` ahora tiene la bandera `saliendo`. La prueba `test_2m_def01_tres_de_tres` dio «Tu sesión ya no es válida.» en 3 de 3 intentos con Asistencia.

### DEF-02 · Backend (se ve en el front) · Severidad BAJA · ✅ CORREGIDO EN EL FRONT (verificado en la ronda final, §10) — Fechas ISO en los nombres de los eventos que crea una sesión de examen

- **Dónde:** `backend/Src/Services/EvaluacionService.php:160-161`: `$nombreExamen = "Exámenes de ascenso {$fecha}"` y `$nombreGrado = "Grado {$fechaGrado}"`.
- **Pasos para reproducirlo:**
  1. En Ascensos, crea una sesión de examen (inicio y grado con cualquier fecha).
  2. Abre el Inicio (administrativo o deportista) o el Calendario.
- **Resultado esperado:** ninguna fecha visible en formato AAAA-MM-DD (criterio de la ronda; el front muestra DD/MM/AAAA).
- **Resultado obtenido:**
  - Inicio, «Próximas fechas»: «Grados: Grado 2026-11-04».
  - Inicio del deportista, tarjeta «Próximo torneo o ascenso»: «Grado 2026-11-04», que a 390 px además se parte en «2026-11-» / «04».
  - Calendario: «Exámenes de ascenso 2026-11-01» y «Grado 2026-11-04».
- **Causa:** el nombre del evento lo arma el backend con la fecha ISO y el front lo muestra tal cual, porque es el nombre guardado.
- **Sugerencia:** que el backend no ponga la fecha en el nombre (ya va en el campo `fecha`), por ejemplo «Exámenes de ascenso» / «Ceremonia de grados». Otra opción es formatearla DD/MM/AAAA. Los eventos ya creados conservan su nombre, salvo que se editen en Calendario.
- **Prueba que lo cubre:** `test_vistas.py` marca `xfail` con «DEF-02» mientras aparezca. Cuando se corrija, pasa sola.
- **Estado (ronda final):** corregido **al mostrar**. `ui.js` agrega `textoConFechas()` / `formatearTextoConFechas()`, que pasan las fechas AAAA-MM-DD de los nombres a DD/MM/AAAA, cada una en un `<span class="fecha-en-texto">` que no se parte. Inicio y Calendario, en los dos ámbitos y los dos dispositivos, ya no muestran fechas ISO, y se ve «Exámenes de ascenso DD/MM/AAAA» (`test_def02_…`, 2/2). `test_vistas.py` ya no da `xfail`. **Queda en el backend:** `EvaluacionService.php:160-161` sigue guardando el nombre con la fecha ISO. No se ve en el front, pero sí sale en la API y en lo que la lea sin pasar por el front (por ejemplo, exportaciones). Se deja anotado; no bloquea.

## 4. Riesgo encontrado en las baterías existentes

### R-01 · `pruebas/backend/` · ALTA para los datos de uso manual (no es un defecto de la aplicación) · ✅ MITIGADO el 31/10/2026

**Qué cambió (solo dentro de `pruebas/`):**

- `pruebas/backend/ejecutar_todo.sh`:
  - el nombre de la base sale de `DB_NAME` y los SQL se redirigen al vuelo a esa base;
  - **se niega a correr** (mensaje y `exit 1`) si falta `PRUEBAS_AISLADAS=1` (solo lo pone `ejecutar_baterias_aisladas.py`), si `DB_NAME` no es exactamente `hwanghon_pruebas`, o si `BACKEND_PRUEBAS` no es la copia aislada `pruebas/entorno/app/backend`;
  - solo vacía `Almacenamiento/documentos`, `generales` y `sesiones` de `BACKEND_PRUEBAS`, sin valor por defecto. El `Almacenamiento` del proyecto real ya no se toca nunca;
  - `URL_APACHE` apunta por defecto al servidor aislado (`http://127.0.0.1:8095/backend/Public`).
- `pruebas/backend/guardia_apache.php` (nuevo), usado por `prueba_http_revision.php` y `prueba_http_urls_limpias.php`: se niegan a usar una URL sin puerto explícito (el Apache de XAMPP en el 80) salvo con `APACHE_REAL=1`.
- Aviso en negrita al principio de `pruebas/README.md` y `pruebas/backend/README.md`.
- **Comprobado:** lanzado a mano sin variables, con `DB_NAME=hwanghon` o con `BACKEND_PRUEBAS` = el backend real, el script sale con código 1 sin tocar nada. Las baterías por URL de XAMPP también se niegan. Desde el ejecutor aislado: 21/21, **1.808 / 1.808**.

**Lo que se encontró (30/10):**

- `ejecutar_todo.sh` hace `DROP DATABASE IF EXISTS hwanghon` y vacía `backend/Almacenamiento/documentos` y `generales` **del proyecto real**. En tu computador hay un PDF en `Almacenamiento/documentos/` que se borraría.
- El nombre de la base está fijo en el script y en los SQL (`CREATE DATABASE … hwanghon` / `USE hwanghon`).
- Las secciones «Apache» de `prueba_http_revision.php` (30 logins en paralelo con clave errada para 1000000101 y para un correo inventado) y de `prueba_http_urls_limpias.php` van contra el Apache de XAMPP, que usa la base **`hwanghon`**. Eso deja intentos fallidos en tu base y puede **bloquear 15 minutos** al deportista 1000000101 si lo estás usando a mano.
- **Primera mitigación (30/10, sin modificar las baterías):** `ejecutar_baterias_aisladas.py` corre el `ejecutar_todo.sh` de una copia, con un cliente `mysql` envoltorio que redirige a `hwanghon_pruebas`. Las secciones «Apache» se apuntan al servidor aislado, que imita las reglas de Apache (403 en carpetas internas, `DirectoryIndex`, forma por ruta).
- **Límite:** con esto **no** se verifica el `mod_rewrite` ni los `.htaccess` reales. Para verificarlos hay que correr esas dos baterías contra XAMPP, sabiendo que escriben en `hwanghon`.
- **Pendiente opcional:** para verificar el Apache real sin tocar `hwanghon`, un VirtualHost de pruebas con `SetEnv DB_NAME hwanghon_pruebas`. Una batería lanzada sola con `php …` sigue usando `hwanghon` por defecto si no se define `DB_NAME`: el README indica usar siempre el ejecutor aislado.

## 5. Humo del hosting (nuevo, para el ensayo en InfinityFree del 03–04/11)

`pruebas/herramientas/humo_hosting.py <URL_BASE>`: solo GET, sin credenciales ni sesión. Tabla OK/FALLA con la causa probable de cada fallo (uso en el README, §4). Comprueba:

- carga del front (`index.html` y `api.js`);
- JSON de `api.php` (200 salud, 401 sin sesión, 400 sin acción; nunca HTML ni 500);
- ruta limpia igual a la forma oficial (si no, sugiere `USAR_RUTA_LIMPIA = false`);
- 403/404 en `backend/Src`, `backend/Src/Config/database.php`, `backend/Config`, `backend/Almacenamiento`, `…/sesiones` y los `.sql` de `base_datos/`;
- atributos de la cookie: `HttpOnly` y `SameSite`; con https, también `Secure`.

| Contra qué | Resultado |
|---|---|
| Servidor aislado (`http://127.0.0.1:8096`) | ✅ 16 / 16 OK (`Secure` no aplica en http) |
| Control negativo: `php -S` sin reglas (como un hosting sin `.htaccess`) | 4 FALLA detectadas, con su causa: ruta limpia (404 HTML → sugiere `USAR_RUTA_LIMPIA=false`), `database.php` responde 200, y los dos `.sql` quedan expuestos |

**A tener en cuenta en InfinityFree:**

- Si el hosting responde a los scripts con su página anti-bots (exige JavaScript), el humo lo dice explícitamente; el front se prueba entonces desde el navegador.
- Si `Secure` falla con https, PHP no está viendo la conexión como HTTPS (el hosting termina el TLS en un proxy). Ver `TRUST_PROXY` en `Config/seguridad.php`.

## 6. Cuenta de desarrollador frente a la cuenta principal (nuevo)

**Cuenta revisada:** `cuentas_prueba_locales.sql` define `desarrollador@hwanghon.local` (rol `desarrollador`, `es_administrador_principal = 1`, activa).

**Cómo se probó:** `pruebas/herramientas/comparar_cuentas.py`.

- Corre la colección completa (215 peticiones) dos veces sobre la base recién restablecida: la sesión administrativa entra primero con `maestro@` y luego con `desarrollador@` (variable `correo_admin`). Después compara código HTTP y comprobaciones petición por petición.
- Las acciones administrativas que la colección hace con otra sesión se llaman directo con cada cuenta: `cambiarContrasena`, `listarGenerales`, `descargarGeneral`, `calendario.listar`, y además `subirGeneral` y la recuperación con token propio.

**Resultado: sin diferencias. La cuenta de desarrollador puede hacer TODO lo que hace `maestro@`.**

- Las 84 acciones ejecutadas con la sesión administrativa dan el mismo código y las mismas comprobaciones (0 fallos en las dos corridas). Entre ellas están las 7 de «Cuentas del club» (`usuarios-administrativos.*`: listar, consultar, crear, actualizar, desactivar, reactivar, restablecer contraseña).
- Las 4 acciones administrativas restantes, más la recuperación por token, también coinciden: 200/201 en las dos cuentas.
- Las otras 7 de las 95 no dependen de la cuenta administrativa: `iniciarSesionPublica`, `solicitarRecuperacionContrasena` y las 6 de autorregistro, que son sin sesión o con permiso de autorregistro.
- **Entre cuentas principales**, comprobado a mano: `desarrollador@` puede editar a `maestro@` y restablecer su contraseña (200), pero no desactivarlo (409 «Una cuenta principal no se puede desactivar desde la aplicación.»). Es **por diseño**: catálogo B.2 y RN-T.11/RN-T.12, que dicen que principales son Aldrin y la cuenta Desarrollador, y la decisión M4 del 23/10. Es simétrico entre las dos cuentas.
- **No es un fallo:** no hay nada que reportar como defecto.

## 7. Observaciones de documentación (catálogo)

| # | Observación | Sugerencia |
|---|---|---|
| OBS-01 | A.2 dice que un campo desconocido responde «Campos no reconocidos: x.», pero `usuarios-administrativos.crear` responde «Campos no permitidos: x.». La ficha B.2 lo documenta así, pero queda inconsistente con A.2 y con `deportistas`. | Unificar el texto (en el código o en A.2). La colección acepta ambos. |
| OBS-02 | A.5 dice que «registrar» responde `201`, pero `autorregistro.registrar` responde `200` a propósito (para no revelar quién está inscrito; está en su ficha). | Agregar la excepción en A.5. |
| OBS-03 | Los `403` del público en `deportistas.buscar`, `asistencia.tomar` y `pagos.deudores` traen mensajes propios («Solo el ámbito administrativo puede …») que no están en esas fichas. A.5 da otro ejemplo («Acceso restringido al ámbito administrativo.»). | Mencionar en A.5 la familia «Solo el ámbito administrativo puede …». La colección la acepta como patrón. |

## 8. Suposiciones (decididas sin preguntar, porque no bloqueaban)

1. **Base aparte:** `hwanghon_pruebas`, recreada antes de cada corrida (Newman y baterías) y antes de **cada** prueba de Selenium, con esquema v4 + datos de prueba + cuentas locales. Los SQL se redirigen al vuelo; los archivos no se tocan.
2. **Servidor de pruebas propio** (`php -S` en el 8095 sobre una copia de la app) en vez del Apache de XAMPP. Es la única forma de usar otra base sin tocar `backend/Src/Config/` (Apache no recibe `DB_NAME`) y de no mezclar archivos subidos con los de uso manual. La forma por ruta se emula en `router_aislado.php`.
3. **Correo:** en la copia, `correo.php` siempre en modo `archivo`. La colección solo pide recuperación con un correo inexistente.
4. **Token de recuperación:** `restablecerContrasena` necesita un token. Como Postman no puede leer el correo, `ejecutar_newman.py` deja en la base aparte el mismo token ficticio que usa `capturar.php`. Desde la app de Postman hay que preparar con `--solo-preparar`.
5. **Orden de la colección:** sigue a `capturar.php` (con esas mismas llamadas se escribió el catálogo), agrupado por recurso. `asistencia.revisarInactivos` va en una carpeta al final porque cambia estados, igual que en `capturar.php`. La colección es una **secuencia**: se corre completa y en orden.
6. **Mensajes de error:** se comparan con el catálogo, salvo los `400` de validación genérica, que el catálogo no enumera uno por uno.
7. **Alcance del front (31/10):** la suite se acotó a los 10 flujos pedidos. Salieron autorregistro, sesión vencida y sesión invalidada.
8. **Celular:** viewport 390×844 con la emulación móvil de Chrome (táctil y agente de celular), no solo una ventana más pequeña.
9. **Sesión vencida:** las 2 h de inactividad no se esperan. Se simulan borrando los archivos de sesión del servidor de la copia, que es lo que pasa cuando vence o cuando PHP limpia sesiones. El 401 resultante es el mismo.
10. **Bloqueo por intentos:** se probó 4, como dicen el catálogo y la decisión del 14/10 (antes eran 5 para el público).
11. **R-01 (31/10):** «que no venga de `ejecutar_baterias_aisladas.py`» se implementa con `PRUEBAS_AISLADAS=1` + `DB_NAME=hwanghon_pruebas` + `BACKEND_PRUEBAS` dentro de `pruebas/entorno/app/backend`. La guarda «Apache» trata como XAMPP cualquier URL sin puerto explícito.
12. **Commits:** no se hizo ninguno; los archivos quedan en `pruebas/` para que los revises y los subas tú.

## 9. Ronda del 04/11/2026 — front final (bloques A, B y C + buscador de documentos generales)

- **Código probado:** commit `47f772a` más el **árbol de trabajo sin commit** de tu computador al 04/11 (bloques A, B y C del front, `formulario-deportista.js`, `recuperar.html`, `plantilla_importar_eventos.csv`). El backend no cambió.
- **Entorno:** el de siempre (base aparte `hwanghon_pruebas`, copia aislada, puerto 8095). La copia ahora pone `url_restablecimiento` en `frontend/recuperar.html` (`entorno.py` y `entorno_limpio.py`).

| Parte | Resultado |
|---|---|
| Selenium, 10 flujos × escritorio y 390 px | ✅ **20 / 20**. Un solo selector cambió: «Lugar» de la sesión de examen ahora es `<select>` (el flujo 7 elige «Otro lugar…» y escribe en `lugar_otro`). Sede, tarjetas de documentos, formulario compartido y panel lateral no rompieron selectores. |
| Flujos nuevos 2a–2m (`test_ronda_front_final.py`) | ✅ **14 / 14** (2e corre en escritorio y en 390 px) |
| Cinturón (marrón franja negra y negro, en inicio y perfil) | ✅ cuerpo `#7a4a24` y nudo/colas `#1a1a1a`; negro puro, todo `#1a1a1a` |
| Todas las vistas, 2 ámbitos × 2 dispositivos × claro/oscuro (`test_vistas.py`) | ✅ sin errores de JS y sin desborde de página a 390 px · ❌ **DEF-02**, fechas ISO visibles (4 xfail) |
| Revisión estática (`revision_estatica.py`) | ✅ 0 `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write`, 0 «Juangón». Una sola fecha ISO literal: el ejemplo del formato CSV en Calendario («ej. 2027-03-20 o 20/03/2027»), justificado. |
| Newman, forma por ruta | ✅ **1.090 / 1.090** (215 peticiones) |
| Baterías PHP | ✅ **21 / 21, 1.808 / 1.808** |

**Qué verifica cada flujo nuevo (resultado en la API):**

- **2a, Calendario:** crea con los 5 tipos. Al elegir reunión, la visibilidad pasa sola a «solo administrativa» y se bloquea. Al editar se ve el tipo y no hay control para cambiarlo.
- **2b:** importar `plantilla_importar_eventos.csv` deja los 5 eventos en la API, con sus 5 tipos.
- **2c, Ascensos:**
  - «Lugar» es un `<select>` con la sede principal y «Otro lugar…»;
  - se ve el rótulo «Grado al que asciende:»;
  - con 2 candidatos, «Guardar todas las notas» guarda las dos notas.
- **2d, carga masiva con la plantilla PROVISIONAL:** previsualizar no guarda; aplicar crea los 2.
- **2e, Comparar:** dos selectores con buscador, en Estadísticas y en Historial. En escritorio las columnas van lado a lado y a 390 px apiladas. El público no ve «Comparar».
- **2f, Estadísticas / Historial:**
  - el selector muestra el nombre del deportista;
  - «← Volver a la ficha» aparece solo si se llegó desde la ficha;
  - el panel resalta Estadísticas o Historial según la pestaña, en los dos ámbitos.
- **2g, menor sin acudiente:** no se envía y el bloque se marca obligatorio; con mayor de edad vuelve a opcional. Probado en crear, editar y registro público.
- **2h, sede como `<select>`:** muestra «Club Hwang Hon — sede principal (Ciudad Verde)» y guarda «Club Hwang Hon». Al editar se conserva «Escuela IMRDS». Probado en crear, editar y registro público.
- **2i, recuperación de contraseña de punta a punta:**
  - el enlace «¿Olvidaste tu contraseña?» se ve también después del bloqueo (4 fallos → 429);
  - el `.eml` queda en `Almacenamiento/correos` de la copia, con enlace a `frontend/recuperar.html`;
  - después de restablecer, la clave vieja da 401 y la nueva entra. No se envía correo real.
- **2j:** la miga «← Deportistas» aparece solo al llegar desde Deportistas.
- **2k, documentos generales:**
  - el buscador filtra desde 2 letras, sin distinguir tildes ni mayúsculas, en los dos ámbitos;
  - se ve la recomendación de nombre;
  - el título se propone desde el nombre del archivo.
- **2l, Torneos:**
  - la etiqueta dice «Plataforma de inscripción»;
  - el buscador muestra completo «10 dígitos + ~15 caracteres»;
  - en la categoría de peso, `45kg` muestra el aviso y `-45` no, y se guarda;
  - Puesto, Medalla y Resultado tienen ayuda.
- **2m, DEF-01:** «Tu sesión ya no es válida.» en **3 de 3** intentos → **DEF-01 corregido**.

**Visual (capturas en `resultados/visual/`, fuera de Git): solo lo que se ve mal**

- **DEF-02** (ver §3): «Grado 2026-11-04» y «Exámenes de ascenso 2026-11-01» en el Inicio y el Calendario. A 390 px, en la tarjeta «Próximo torneo o ascenso» del deportista, la fecha se parte en «2026-11-» / «04».
- **OBS-04 (visual, baja) · ✅ CERRADA en la ronda final (§10):** a 390 px, en el inicio del deportista, la cifra «Datos insuficientes» de la tarjeta Asistencia se sale de su caja (162 px de texto en 137 px). La página no se desborda. Fue el único texto desbordado en todas las vistas. Sugerencia: `overflow-wrap: anywhere` o una fuente más pequeña en `.cifra` cuando el valor es texto.
- **Contraste (axe-core 4.10, regla color-contrast):** solo marca los títulos de grupo del menú lateral en tema claro y escritorio («GESTIÓN DEPORTIVA»…). Es un **falso positivo**: axe toma el fondo de la página (#f4f5f7) en vez del panel (#153a66). Con el fondo real (blanco al 75 % sobre #153a66), el contraste es de unos **7:1** (AA exige 4,5:1). No hay más problemas de contraste en ninguna vista, ni en claro ni en oscuro.

**Entorno limpio (`entorno_limpio.py`):**

- **URL:** `http://127.0.0.1:8096/frontend/`.
- **Cuentas:** `aldrin@hwanghon.local` y `desarrollador@hwanghon.local`, con la clave local `Limpia2026Local`.
- **Correos:** `pruebas/entorno/limpio/app/backend/Almacenamiento/correos/` (modo archivo; el enlace apunta a `frontend/recuperar.html`).
- No se usó en esta ronda.

## 10. Ronda final corta (06/11/2026) — DEF-02, OBS-04 y últimos cambios del front

- **Código probado:** commit `47f772a` más el **árbol de trabajo sin commit** de tu computador, copiado el 04/11 a las 13:00. Incluye `ui.js`, `inicio.js`, `inicio-deportista.js`, `calendario.js`, `estadisticas.js`, `torneos.js` y `estilos.css` con las correcciones, y el logo y el favicon. Se comparó la huella de los 46 archivos de `frontend/`: la copia probada es idéntica a la de tu computador. El backend no cambió.
- **Entorno:** el de siempre (base aparte `hwanghon_pruebas`, semilla restablecida antes de cada prueba, puerto 8095, datos ficticios).

| Parte | Resultado |
|---|---|
| Selenium, 10 flujos × escritorio y 390 px (`test_flujos.py`) | ✅ **20 / 20**, sin cambios de selectores |
| Flujos 2a–2m (`test_ronda_front_final.py`) | ✅ **14 / 14**. Un solo ajuste: en 2l, «Categoría de peso» ya no es texto con aviso de formato sino un `<select>`; ahora elige «juvenil» y «-45» y verifica en la API que se guardó |
| Todas las vistas (`test_vistas.py`) y cinturón | ✅ **5 / 5**, **sin `xfail`**: ya no hay fechas ISO visibles en ninguna vista, ni texto desbordado a 390 px (los 4 `texto_desbordado_*.txt` quedan vacíos) |
| Comprobaciones de la ronda final (`test_ronda_final_0611.py`, nuevo) | ✅ **26 / 26** (detalle abajo) |
| **Total Selenium** | ✅ **65 / 65** en 3 min 38 s |
| Revisión estática | ✅ 0 hallazgos, 0 fechas ISO literales |

**Detalle de `test_ronda_final_0611.py`:**

- **DEF-02 → ✅ cerrado** (escritorio y 390 px): Inicio y Calendario, administrativo y deportista, sin fechas AAAA-MM-DD visibles. El examen se ve como «Exámenes de ascenso DD/MM/AAAA». La única fecha ISO visible es el ejemplo de formato en la ayuda de importar CSV, que está justificado.
- **OBS-04 → ✅ cerrada** (escritorio y 390 px): «Datos insuficientes» no se sale de su caja en el Inicio y las Estadísticas del deportista, en las Estadísticas de 1000000101 (administrativo) ni en Asistencia. La página no se desborda.
- **Pestañas de Estadísticas:** caben en una sola fila, a la misma altura y sin scroll de la tira, en los dos ámbitos y los dos dispositivos.
- **Select de «Categoría de peso»:** las opciones salen de la tabla del catálogo (§A, `categoria_peso`): «-X» cada 5 kg y un «+X» final.

  | Categoría | Masculino | Femenino |
  |---|---|---|
  | Infantil | -20 a -35, +35 | -20 a -35, +35 |
  | Juvenil | -45 a -75, +75 | -43 a -73, +73 |
  | Mayores | -55 a -85, +85 | -50 a -80, +80 |

  Se probó con 1000000101 (masculino) y 1000000102 (femenino): las tres categorías coinciden en los dos sexos. Un valor guardado que no está en la lista (`-7`) se conserva como opción extra y queda elegido al cambiar de categoría. Guardar otro campo no lo borra (verificado en la API).

  > Nota: al inscribir por la API, la participación guarda la categoría calculada del deportista (1000000101 → `-50`, de Junior). Por eso, en Infantil y Mayores aparece `-50` como opción extra. Es lo esperado, porque es un valor guardado.
- **Logo y favicon:** `favicon.ico`, `favicon.png`, `logo_hwanghon.png` y `logo_hwanghon_difuminado.png` responden `200` con tipo imagen. Las 5 páginas (`index`, `registro`, `recuperar`, `administrativo`, `publico`) enlazan los dos favicon y los dos cargan. El logo del panel (`data-prueba="logo-club"`) carga (`naturalWidth > 0`).
- **Fondo de la tarjeta:** en `index`, `registro` y `recuperar`, el `::before` de `main` usa `logo_hwanghon.png`, que carga. A 390 px no hay scroll horizontal en ninguna de las 5 páginas.

**Abierto:** nada del front. Del backend queda solo la nota de DEF-02: el nombre guardado lleva la fecha ISO.
