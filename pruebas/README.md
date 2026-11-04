# Pruebas — HwangHon-Sports-System

> **⚠️ PROTEGIDO (31/10/2026): las baterías BORRAN y RECREAN una base de datos y VACÍAN carpetas de `Almacenamiento`. Córrelas SOLO con `python pruebas/ejecutar_baterias_aisladas.py`, que usa la base aparte `hwanghon_pruebas` y una copia aislada de la app. `ejecutar_todo.sh` se niega a correr si no recibe `PRUEBAS_AISLADAS=1`, `DB_NAME=hwanghon_pruebas` y `BACKEND_PRUEBAS` apuntando a la copia aislada (`pruebas/entorno/app/backend`); las secciones «Apache» se niegan a usar el Apache de XAMPP (puerto 80, base `hwanghon`) salvo con `APACHE_REAL=1`.**

Pruebas automáticas del sistema: la API (Postman/Newman), el front de punta a punta (Selenium + pytest) y las 21 baterías PHP del backend que ya existían. **Todo usa datos ficticios** y una **base de datos aparte**: nada de esto toca la base `hwanghon` que usas a mano ni los documentos de `backend/Almacenamiento/`.

Resultados de la última corrida y defectos encontrados: [`INFORME_PRUEBAS.md`](INFORME_PRUEBAS.md).

## Comandos rápidos (desde la raíz del repositorio, con MySQL de XAMPP encendido)

```bash
# 0) Restablecer la base aparte (hwanghon_pruebas) con la semilla, cuando quieras
python pruebas/herramientas/entorno.py restablecer

# 1) API: prepara entorno + base, corre Newman y deja el reporte en pruebas/resultados/
python pruebas/postman/ejecutar_newman.py

#    …o Newman a mano (en otra terminal antes: python pruebas/postman/ejecutar_newman.py --solo-preparar)
newman run pruebas/postman/HwangHon_API.postman_collection.json -e pruebas/postman/entorno_aislado.postman_environment.json --working-dir pruebas/postman --reporters cli,junit --reporter-junit-export pruebas/resultados/newman.xml

# 2) Front: 10 flujos en escritorio y celular (restablece la semilla antes de cada prueba)
python -m pytest pruebas/selenium --junitxml=pruebas/resultados/selenium.xml

# 3) Baterías PHP existentes, contra la base aparte (necesita Git Bash)
python pruebas/ejecutar_baterias_aisladas.py

# 4) Humo del hosting (solo lectura) y 5) comparar cuentas principales
python pruebas/herramientas/humo_hosting.py https://tu-sitio.infinityfreeapp.com
python pruebas/herramientas/comparar_cuentas.py
```

```text
pruebas/
├── README.md                       este archivo
├── INFORME_PRUEBAS.md              resultados, defectos y suposiciones
├── herramientas/
│   ├── entorno.py                  entorno aislado: copia de la app, base aparte, semilla, servidor
│   ├── router_aislado.php          router de php -S: front y API en el mismo origen (imita a Apache)
│   ├── humo_hosting.py             humo de solo lectura para el hosting (§4)
│   ├── comparar_cuentas.py         maestro@ frente a desarrollador@ en las 95 acciones (§5)
│   └── entorno_limpio.py           sistema «desde cero» para probarlo a mano (§6)
├── postman/
│   ├── HwangHon_API.postman_collection.json     colección v2.1 (215 peticiones, 95/95 acciones)
│   ├── entorno_aislado.postman_environment.json base_url del servidor aislado (recomendado)
│   ├── entorno_xampp.postman_environment.json   base_url de XAMPP (NO correr la colección completa ahí)
│   ├── generar_coleccion.py        genera la colección desde documentacion/api/catalogo_api.md
│   └── ejecutar_newman.py          prepara todo y corre Newman
├── selenium/
│   ├── conftest.py, utilidades.py  navegador, entorno y ayudas
│   ├── test_flujos.py              10 flujos × 2 dispositivos (escritorio y celular 390x844)
│   ├── archivos/                   PDF y CSV ficticios que se suben
│   ├── pytest.ini, requirements.txt
├── ejecutar_baterias_aisladas.py   corre las 21 baterías de backend/ contra la base aparte
├── backend/                        baterías PHP (ejecutar_todo.sh protegido; guardia_apache.php)
├── entorno/      (se genera, Git lo ignora)  copia de la app que usan las pruebas
└── resultados/   (se genera, Git lo ignora)  Newman (xml/json), pytest (xml), capturas, salidas
```

## Cómo se aísla (la regla de la base aparte)

1. `herramientas/entorno.py` copia `backend/`, `frontend/`, `base_datos/` y `pruebas/backend/` a `pruebas/entorno/app/`. Así los archivos subidos, las sesiones y los correos de prueba quedan en el `Almacenamiento` **de la copia**. En la copia, `Config/correo.php` va siempre en modo `archivo`: ninguna prueba envía un correo real.
2. La base de pruebas es **`hwanghon_pruebas`** (variable `HWANGHON_BD_PRUEBAS`). Antes de cada corrida (y en Selenium, antes de **cada prueba**) se borra y se recrea con `esquema_hwanghon_v4.sql` + `insercion_datos_prueba.sql` + `cuentas_prueba_locales.sql`. Los scripts SQL traen `USE hwanghon`: se redirigen al vuelo, **sin modificar los archivos**. Si alguien configura `hwanghon` como base de pruebas, el script se niega.
3. Un servidor `php -S` en **http://127.0.0.1:8095** sirve la copia con `router_aislado.php`: el front en `/frontend/` y la API en `/backend/Public/` (forma oficial y forma por ruta), en el mismo origen, como en XAMPP. No hace falta el Apache de XAMPP; solo **MySQL encendido**.

## Requisitos (Windows con XAMPP)

| Qué | Para qué | Cómo |
|---|---|---|
| MySQL/MariaDB de XAMPP **encendido** | todo | Panel de XAMPP → MySQL → Start. Usuario `root` sin clave (o `DB_USER`/`DB_PASS`). |
| PHP de XAMPP | servidor aislado | Se detecta solo en `C:\Program Files\Xampp\php\php.exe` o `C:\xampp\php\php.exe` (o `PHP_BIN`). |
| Python 3.10+ | Selenium y los ejecutores | `pip install -r pruebas/selenium/requirements.txt` |
| Google Chrome | Selenium | Selenium Manager descarga el chromedriver correcto (requiere internet la primera vez). |
| Node.js + Newman | colección Postman | `npm install -g newman` |
| Git Bash | solo las baterías PHP | `ejecutar_todo.sh` es bash. Se busca en `C:\Program Files\Git\bin\bash.exe` (o `BASH_BIN`). |

Todos los comandos se ejecutan **desde la raíz del repositorio** (`C:\Program Files\Xampp\htdocs\HwangHon-Sports-System`).

## 1. API con Postman / Newman

```bash
python pruebas/postman/ejecutar_newman.py               # forma oficial: api.php?recurso=…&accion=…
python pruebas/postman/ejecutar_newman.py --forma ruta  # la MISMA colección con …/Public/<recurso>/<accion>
```

El ejecutor rehace el entorno aislado, deja los archivos que se suben (`postman/archivos/`, con fechas del año en curso) y un token de recuperación ficticio, arranca el servidor en el 8095, corre Newman y lo apaga. Resultados en `resultados/newman_consulta.{xml,json}` y `resultados/newman_ruta.{xml,json}`.

**Desde la app de Postman:**

1. `python pruebas/postman/ejecutar_newman.py --solo-preparar` (deja base, archivos y servidor listos; Ctrl+C para parar).
2. Importa la colección y `entorno_aislado.postman_environment.json`; elige ese entorno.
3. En *Settings → General → Working directory* pon la carpeta `pruebas/postman` (para que encuentre `archivos/`).
4. Córrela **completa y en orden** con el *Runner*: es una secuencia (crea, consulta, anula…). Para repetirla, vuelve al paso 1.

**Cómo está armada:**

- Una carpeta por recurso (`01 auth` … `14 importacion-deportistas`), más `00 Salud`, `15 errores comunes, forma por ruta y CSRF`, `16 al final` y `17 cierre de sesión y sesión vencida (401)`.
- **Sesiones por ámbito:** cada petición lleva la cabecera `X-Sesion-Prueba` (`ses_admin` = cuenta principal, `ses_staff` = administrativo no principal, `ses_pub` = público, `ses_reg` = permiso de autorregistro). El script de la colección la cambia por la cookie `HWANGHON_SESION` de esa sesión; las peticiones sin esa cabecera van **sin sesión**. Así conviven varias sesiones en la misma corrida.
- **Variables:** `base_url` (…/backend/Public), `forma_url` (`consulta` u `ruta`: el script reescribe la URL), `clave_prueba` (la de las cuentas ficticias). Las fechas (`sab1`, `anio`, `mas40`…) se calculan en cada corrida con la hora de Bogotá.
- **Pruebas en cada petición:** código HTTP; formato `{exito, datos|mensaje}`; forma de `datos` comparada con el ejemplo real del catálogo; en los errores (menos los 400 de validación genérica), que el mensaje esté documentado en el catálogo; en las descargas, `Content-Type`, `Content-Disposition` y `nosniff`; y nunca un 500.
- Casos de error: 400, 401, 403, 404, 405, 409, 415, 422, 429 (bloqueo por intentos) y CSRF (`Origin` ajeno y `Sec-Fetch-Site: cross-site`).

Si cambia el catálogo: `python pruebas/postman/generar_coleccion.py` (falla si alguna de las 95 acciones queda sin petición o si un método no coincide).

## 2. Front con Selenium (pytest)

```bash
python -m pytest pruebas/selenium                          # todo: 10 flujos ×2, ronda del front final y recorrido de vistas
python -m pytest pruebas/selenium/test_flujos.py           # solo los 10 flujos (escritorio y celular)
python -m pytest pruebas/selenium --dispositivo celular    # solo celular (viewport 390x844, emulación móvil)
python -m pytest pruebas/selenium -k pago                  # un flujo
set SELENIUM_VISIBLE=1  (PowerShell: $env:SELENIUM_VISIBLE=1)   # ver el navegador en vez de headless
```

Flujos (`test_flujos.py`, uno por función `test_01` … `test_10`):

1. ingreso administrativo y público;
2. crear, validar y rechazar deportista;
3. tomar asistencia;
4. registrar pago;
5. subir y descargar un documento;
6. crear torneo e inscribir;
7. crear sesión de examen y calificar;
8. exportar un reporte;
9. carga masiva CSV (previsualizar y aplicar);
10. Cuentas («Mi cuenta» y «Cuentas del club») y cerrar sesión.

Además:

- **`test_ronda_front_final.py`** (ronda del 04/11, flujos 2a–2m): calendario con los 5 tipos e importación CSV, «Guardar todas las notas», plantilla de carga masiva, Comparar (también a 390 px), Estadísticas/Historial, acudiente obligatorio, sede como `<select>`, recuperación de contraseña de punta a punta (lee el `.eml` de `entorno/app/backend/Almacenamiento/correos`), miga de carga masiva, buscador de documentos generales, torneos y DEF-01. La mayoría corre solo en escritorio (marcador `solo_escritorio`).
- **`test_ronda_final_0611.py`** (ronda final del 06/11): sin fechas AAAA-MM-DD visibles en Inicio y Calendario (DEF-02), «Datos insuficientes» sin desbordar a 390 px (OBS-04), pestañas de Estadísticas en una fila, select de «Categoría de peso» por edad y sexo (Infantil, Juvenil y Mayores; conserva un valor guardado fuera de la lista), logo y favicon, y fondo de la tarjeta sin scroll horizontal a 390 px.
- **`test_vistas.py`** recorre todas las vistas de los dos ámbitos, en claro y oscuro:
  - sin errores de JavaScript;
  - sin desborde horizontal a 390 px;
  - sin fechas ISO visibles.

  Deja también, en `resultados/visual/`:
  - capturas del inicio;
  - el cinturón marrón franja negra;
  - el contraste con axe-core (`contraste.json`);
  - los textos que se salen de su caja (`texto_desbordado_*.txt`).

  Para el contraste, una vez: `cd pruebas/selenium && npm install axe-core@4.10.2`. Sin axe-core, ese paso se salta.
- **`python pruebas/herramientas/revision_estatica.py`**: busca en `frontend/` `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, «Juangón» y fechas ISO literales.

**Selectores estables:** `id`, el atributo `name` de los campos (es el nombre del dato de la API), `data-prueba` (menú `menu-<clave>`, `cerrar-sesion`, `cambiar-contrasena`) y `aria-label`. No se usan los textos de los botones. El resultado de cada acción se comprueba **contra la API** (lo que quedó guardado), no contra mensajes de pantalla. En cada flujo se revisa además que no haya errores de JavaScript ni desborde horizontal. Si una prueba falla, deja una captura en `resultados/capturas/`.

## 3. Baterías PHP del backend contra la base aparte

```bash
python pruebas/ejecutar_baterias_aisladas.py
```

Corre el `ejecutar_todo.sh` **de la copia**, sin cambiar ninguna batería: levanta los servidores 8090/8091/8092 desde la copia con `DB_NAME=hwanghon_pruebas` y le pasa un cliente `mysql` envoltorio que redirige `hwanghon` a la base aparte. Las secciones que piden «Apache» se apuntan al servidor aislado (ver la nota en el informe). Salidas en `resultados/baterias/`.

`ejecutar_todo.sh` está protegido (ver el aviso de arriba): lanzado a mano, sale con un mensaje y código 1 sin tocar nada.

## 4. Humo del hosting (antes de publicar)

```bash
python pruebas/herramientas/humo_hosting.py https://tu-sitio.infinityfreeapp.com      # carpeta que contiene frontend/ y backend/
python pruebas/herramientas/humo_hosting.py http://localhost/HwangHon-Sports-System   # XAMPP (solo lectura: no escribe en la base)
```

Solo hace peticiones **GET**, sin credenciales ni sesión. Imprime una tabla OK/FALLA con la causa probable de cada fallo. Comprueba:

- que el front carga (`index.html` y `api.js`);
- que `api.php` responde JSON (200 / 401 / 400; nunca HTML ni 500);
- que la ruta limpia `backend/Public/deportistas/listar` responde igual que la forma oficial (si da 404 HTML, mod_rewrite no funciona: poner `USAR_RUTA_LIMPIA = false` en `frontend/js/api.js`);
- que `backend/Src`, `backend/Config`, `backend/Almacenamiento` y los `.sql` de `base_datos/` dan 403/404;
- que la cookie de sesión lleva `HttpOnly`, `SameSite` y, con https, `Secure`. Para verlo envía una cookie inventada, que la API descarta reenviándola con sus atributos.

**Ojo con InfinityFree:** su protección anti-bots puede responder a los scripts con una página que exige JavaScript. El humo lo detecta y lo dice; en ese caso el front se prueba a mano desde el navegador.

## 5. Comparar cuentas principales (maestro@ y desarrollador@)

```bash
python pruebas/herramientas/comparar_cuentas.py                       # desarrollador@hwanghon.local por defecto
python pruebas/postman/ejecutar_newman.py --correo-admin desarrollador@hwanghon.local   # solo la colección con esa cuenta
```

Corre la colección completa con cada cuenta en la sesión administrativa (`correo_admin`) y compara petición por petición. También llama directo a las acciones administrativas que la colección hace con otra sesión. Resultado en `resultados/comparacion_cuentas.txt`.

## 6. Entorno limpio «desde cero» (para probar a mano)

```bash
python pruebas/herramientas/entorno_limpio.py crear     # base vacía + cuentas iniciales + servidor
python pruebas/herramientas/entorno_limpio.py detener      # apaga el servidor, SIN borrar datos
python pruebas/herramientas/entorno_limpio.py iniciar      # lo vuelve a arrancar (p. ej. tras reiniciar el PC), con el mismo código
python pruebas/herramientas/entorno_limpio.py actualizar   # tras corregir código: trae backend/frontend nuevos y conserva base y archivos
python pruebas/herramientas/entorno_limpio.py borrar       # apaga el servidor y elimina la base y la copia
```

Sirve para usar el sistema como la primera vez real: sin semilla ni datos. **Ojo:** el entorno usa una COPIA del código hecha al crearlo. Después de corregir código, usa `actualizar`; con `iniciar` seguirías probando el código viejo.

- **Base:** `hwanghon_limpia`, creada solo con `esquema_hwanghon_v4.sql`. No toca `hwanghon` ni `hwanghon_pruebas`.
- **Cuentas iniciales:** las crea `backend/Instalacion/crear_cuentas_iniciales.php` (de la copia):
  - `aldrin@hwanghon.local`, la fundadora;
  - `desarrollador@hwanghon.local`.
- **Contraseña local de prueba:** `Limpia2026Local`, para las dos cuentas. `crear` la imprime al terminar, junto con la URL.
- **URL:** `http://127.0.0.1:8096/frontend/`.

> **Solo local.** Esos correos y esa contraseña son de prueba y **nunca** van a producción. En el hosting, las cuentas se crean con el instalador, que pide correo y clave reales por consola (o `--sql` para phpMyAdmin).

**Tus archivos quedan separados.** Lo que subas en este entorno (cédula, foto, documentos) queda **solo** en `pruebas/entorno/limpio/app/backend/Almacenamiento/`, una copia propia que Git ignora y que `borrar` elimina. Esa carpeta nunca se mezcla con la base ni con el `Almacenamiento` real del proyecto, ni con los del entorno de pruebas automáticas. El correo de esa copia va en modo `archivo`: nunca envía nada.

## Variables de entorno (opcionales)

| Variable | Por defecto | Para qué |
|---|---|---|
| `HWANGHON_BD_PRUEBAS` | `hwanghon_pruebas` | Nombre de la base aparte (nunca `hwanghon`). |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` | `localhost`, `3306`, `root`, vacío | Conexión a MariaDB. |
| `MYSQL_BIN`, `PHP_BIN`, `BASH_BIN` | los de XAMPP / Git Bash si existen | Ejecutables. |
| `PUERTO_PRUEBAS` | `8095` | Puerto del servidor aislado. |
| `SELENIUM_VISIBLE` | — | `1` para ver Chrome. |
| `CHROMEDRIVER`, `CHROME_BINARY` | Selenium Manager | Rutas propias si hiciera falta. |

## Problemas comunes

- **«El puerto 8095 ya está en uso»**: quedó abierto otro servidor de pruebas (p. ej. `--solo-preparar`). Ciérralo o usa `PUERTO_PRUEBAS`.
- **`mysql falló … Can't connect`**: MySQL de XAMPP apagado.
- **Newman no encontrado**: `npm install -g newman` y abre una terminal nueva.
- **Selenium no abre Chrome**: actualiza Chrome; sin internet, descarga el chromedriver de la misma versión y usa `CHROMEDRIVER`.
- **En Windows el servidor aislado es más lento**: `php -S` atiende de a una petición; es normal que la suite de Selenium tarde unos minutos.
