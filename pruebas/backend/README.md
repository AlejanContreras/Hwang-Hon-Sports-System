# Pruebas automáticas del backend

Baterías de pruebas del backend de HwangHon Sports System: 21 baterías y **1.790 comprobaciones con `SALTAR_RECUPERACION=1`**, todas en verde al 30/10/2026. La sección F de Auth suma 18 más (**1.808** en total); la última corrida completa con ella fue el 28/10/2026. Usan solo **datos ficticios** y las cuentas de prueba locales de `base_datos/cuentas_prueba_locales.sql` (contraseña de prueba `12345678`, dominio `@hwanghon.local`). No contienen credenciales reales.

Cada batería deja la base modificada: antes de cada una hay que **recargar la semilla**. `ejecutar_todo.sh` lo hace solo.

## Requisitos

- **PHP 8.2** (probado con el 8.2.12 de XAMPP), con `pdo_mysql`, `mbstring`, `fileinfo`, `curl` (para la batería de revisión) y `zlib`.
- **MariaDB 10.4 o superior** (probado con 10.4.32 de XAMPP; el esquema también se verificó en 10.11). Usuario `root` sin contraseña en local, o el que se indique en `MYSQL`.
- **Python 3** (solo la biblioteca estándar): lo usan `lectores/lector_xlsx.py` y `lectores/lector_pdf.py` para abrir los Excel y PDF de los reportes.
- **Bash** (en Windows, Git Bash) para `ejecutar_todo.sh`.
- **Apache de XAMPP encendido**, con el proyecto en `htdocs/HwangHon-Sports-System`: solo para la prueba de 30 logins en paralelo de `prueba_http_revision.php` (el servidor de PHP atiende de a una petición).

## 1. Cargar la base

Desde la raíz del repositorio:

```bash
mysql -uroot -e "DROP DATABASE IF EXISTS hwanghon"
mysql -uroot --default-character-set=utf8mb4 < base_datos/esquema_hwanghon_v4.sql
mysql -uroot --default-character-set=utf8mb4 hwanghon < base_datos/insercion_datos_prueba.sql
mysql -uroot --default-character-set=utf8mb4 hwanghon < base_datos/cuentas_prueba_locales.sql
```

El tercer archivo (las cuentas de prueba) lo piden casi todas las baterías HTTP; la tabla de abajo dice cuáles.

## 2. Levantar los servidores de prueba

Las baterías HTTP hablan con el servidor de PHP a través de `router_pruebas.php`. Este router despacha a `backend/Public/api.php` y añade dos atajos de prueba (`/__sesion` y `/__ini`). En Windows, `pwd -W` da la ruta `C:/…` que necesita PHP; en Linux, usar `pwd`.

Servidor principal (puerto 8090), en una terminal aparte:

```bash
MEMORIA_LOG="$(pwd -W)/pruebas/backend/memoria.log" RUTA_PUBLIC="$(pwd -W)/backend/Public" php -d display_errors=0 -d log_errors=1 -d error_reporting=-1 -d memory_limit=128M -S 127.0.0.1:8090 pruebas/backend/router_pruebas.php
```

Servidor con `post_max_size=1M` (puerto 8091), solo para la batería de calendario:

```bash
RUTA_PUBLIC="$(pwd -W)/backend/Public" php -d display_errors=0 -d post_max_size=1M -S 127.0.0.1:8091 pruebas/backend/router_pruebas.php
```

Servidor con lista blanca de orígenes (puerto 8092), solo para la batería de revisión:

```bash
ORIGENES_PERMITIDOS="http://front.example:5173" RUTA_PUBLIC="$(pwd -W)/backend/Public" php -d display_errors=0 -S 127.0.0.1:8092 pruebas/backend/router_pruebas.php
```

## 3. Ejecutar

Todas a la vez, recargando la base antes de cada una. Imprime el resultado por batería y el total:

```bash
bash pruebas/backend/ejecutar_todo.sh
```

La sección F de Auth (recuperación de contraseña) necesita `backend/Src/Config/correo.php` en modo `archivo` (como `correo.example.php`): lee los correos que quedan en `backend/Almacenamiento/correos/` (Git los ignora; se pueden borrar al terminar). Si `correo.php` está en modo `smtp`, hay que saltar esa sección, para que no se envíen correos reales:

```bash
SALTAR_RECUPERACION=1 bash pruebas/backend/ejecutar_todo.sh
```

Para ejecutar una sola batería: recargar la base (paso 1) y lanzarla con sus argumentos, por ejemplo:

```bash
php pruebas/backend/prueba_http_asistencia.php http://127.0.0.1:8090
```

Las salidas completas de `ejecutar_todo.sh` quedan en `pruebas/backend/salidas/`, que Git ignora.

## Baterías

`URL` = `http://127.0.0.1:8090`; `backend` = ruta Windows/Linux de la carpeta `backend`; `Src` = `backend/Src`. «Semilla» = esquema + datos de prueba; «+ cuentas» = además `cuentas_prueba_locales.sql`.

| Batería | Qué cubre | Argumentos | Base | Comprobaciones |
|---|---|---|---|---|
| `prueba_repositorios.php` | Repositories y Models: listar/contar, CRUD genérico, modo estricto, credenciales nunca expuestas | `Src` | semilla | 145 |
| `prueba_http_deportistas.php` | Deportistas: permisos por ámbito y edición pública | `URL` | semilla | 69 |
| `prueba_http_deportistas_paso3.php` | Deportistas a fondo: alta, acudientes, validación, búsqueda, categorías de edad y peso | `URL` | + cuentas | 232 |
| `prueba_http_auth.php` | Login de los dos ámbitos, bloqueo, sesiones, cambio y recuperación de contraseña | `URL backend` | semilla | 68 (50 con `SALTAR_RECUPERACION=1`) |
| `prueba_http_usuarios.php` | Cuentas administrativas: gestión por cuentas principales, desactivar, restablecer | `URL` | + cuentas | 71 |
| `prueba_http_documentos.php` | Documentos del deportista y generales: subida, formatos, permisos, descargas, faltantes | `URL backend` | + cuentas | 99 |
| `prueba_http_asistencia.php` | Tomar, corregir, listar, porcentaje, revisar inactivos | `URL` | + cuentas | 75 |
| `prueba_calculo_pagos.php` | Reglas puras de pagos (semanas, vencimientos, deuda, matrícula) | `Src` | semilla | 55 |
| `prueba_http_pagos.php` | Registrar, estado, deudores, anular | `URL` | + cuentas | 57 |
| `prueba_http_calendario.php` | Eventos, vista con cumpleaños y vencimientos, importación CSV | `URL http://127.0.0.1:8091 backend` | + cuentas | 80 |
| `prueba_http_competencias.php` | Torneos, inscripción, resultados, sugerencias | `URL` | + cuentas | 91 |
| `prueba_recomendacion_peso.php` | Recomendación de peso (margen de 2,5 kg) | `Src` | semilla | 9 |
| `prueba_categorias_peso.php` | Tablas de peso oficiales (bordes por grupo y fronteras de edad) | `Src` | semilla | 58 |
| `prueba_reglas_evaluacion.php` | Reglas puras de examen (grado siguiente, resultado, elegibilidad) | `Src` | semilla | 22 |
| `prueba_http_evaluaciones.php` | Sesiones de examen completas: crear, calificar, cerrar, entrega de cinturón | `URL` | + cuentas | 98 |
| `prueba_http_estadisticas.php` | Historial y estadísticas, con consultas constantes | `URL Src` | + cuentas | 107 |
| `prueba_http_reportes.php` | Los 6 reportes (PDF y Excel), inyección de fórmulas, memoria y disco | `URL backend memoria.log` | + cuentas | 110 |
| `prueba_http_autorregistro.php` | Autorregistro con documentos y carga masiva por CSV | `URL backend` | + cuentas | 142 |
| `prueba_http_revision.php` | Correcciones de la revisión del 23/10: CSRF, bloqueo paralelo (Apache), huella de sesión, robustez, integridad; eliminar un pendiente con documentos; fecha, lugar y visibilidad de un torneo | `URL http://127.0.0.1:8092 URL_APACHE backend` | + cuentas | 109 |
| `prueba_recuperacion_diferida.php` | Recuperación de contraseña diferida, con un correo **falso** (nunca envía nada) | `Src` | + cuentas | 7 |
| `prueba_http_urls_limpias.php` | URLs limpias: cada acción con la forma de consulta y la de ruta (`/<recurso>/<accion>`) da la misma respuesta (200, 401, 403, 404, 405, 415, CSRF, multipart y descargas); bordes de la ruta; carpetas internas sin acceso. En php -S **y** en Apache (mod_rewrite real) | `URL URL_APACHE` | + cuentas | 104 |
| **Total** | | | | **1.790** con `SALTAR_RECUPERACION=1` (1.808 con la sección F) |

`URL_APACHE` = `http://localhost/HwangHon-Sports-System/backend/Public`.

## Catálogo de la API

`catalogo/` contiene lo que genera `documentacion/api/catalogo_api.md`:

- `capturar.php` hace llamadas reales contra la semilla y las guarda en `capturas.json`. Nunca dispara un correo real.
- Las partes `parte_*.md` son la plantilla, con marcadores `{{ej:clave}}` y `{{err:clave}}`.
- `generar.php` reemplaza esos marcadores por las capturas.

Para regenerar (con la base recién cargada y el servidor principal arriba):

```bash
php pruebas/backend/catalogo/capturar.php http://127.0.0.1:8090 pruebas/backend/catalogo/capturas.json
cat pruebas/backend/catalogo/parte_a.md pruebas/backend/catalogo/parte_b1.md pruebas/backend/catalogo/parte_b2.md pruebas/backend/catalogo/parte_c.md > pruebas/backend/catalogo/plantilla.md
php pruebas/backend/catalogo/generar.php pruebas/backend/catalogo/plantilla.md pruebas/backend/catalogo/capturas.json documentacion/api/catalogo_api.md
```

## Notas

- **Archivos temporales:** las baterías de reportes y autorregistro crean y borran temporales en esta carpeta (`descargas_p11/`, `tmp_*.xlsx`), ignorados por Git.
- **Almacenamiento:** la batería de documentos exige `backend/Almacenamiento/documentos` y `generales` vacíos; `ejecutar_todo.sh` los limpia antes de cada batería. **No ejecutar en una instalación con documentos reales.**
- **Conexión a la base:** las baterías, el router y `capturar.php` leen `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` (las mismas variables que el backend); por defecto `localhost:3306`, base `hwanghon`, usuario `root` sin clave.
- **Zona horaria:** todas las baterías fijan `America/Bogota`, como el backend.
- **Word y Excel antiguos:** según la versión de `libmagic`, un `.doc` o `.xls` se detecta como `application/x-ole-storage` o como `CDFV2`; `ArchivoSubido` acepta ambos y la batería de documentos lo comprueba.
- **URLs limpias:** `router_pruebas.php` fija `SCRIPT_NAME=/api.php`, así la forma por ruta se resuelve igual que en Apache con mod_rewrite. La batería 21 y la de revisión necesitan el Apache de XAMPP encendido (`URL_APACHE`).
- **Triggers de prueba:** algunas baterías crean triggers temporales que fuerzan fallos (para probar las transacciones) y los borran al terminar. En el log del servidor quedan como «falla forzada»: es lo esperado.
