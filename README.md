# Hwang Hon Sports System

Sistema web de gestión deportiva y administrativa para el **Club Deportivo de Hapkido Hwang Hon**. Reemplaza las planillas y documentos sueltos del club por un solo sistema: deportistas, asistencia, pagos, documentos, torneos, ascensos de cinturón, estadísticas y reportes.

Proyecto de práctica profesional de la Fundación Universitaria Compensar, construido de punta a punta: análisis, base de datos, backend, frontend, pruebas, despliegue y documentación.

**Estado: terminado y desplegado en hosting.** Backend con una API de 95 acciones, frontend completo (escritorio y celular) y manual de usuario.

## Qué hace

El sistema tiene dos ámbitos de acceso:

- **Personal del club (administrativo):** entra con correo y contraseña. Gestiona todo el club.
- **Deportista o familia (público):** entra con el documento y la fecha de nacimiento del deportista. Solo consulta su propia información y corrige unos pocos datos propios.

Módulos principales:

| Módulo | Qué cubre |
|---|---|
| Deportistas | Alta, edición, autorregistro con validación del club, carga masiva por CSV, ficha en PDF, récord de combates |
| Asistencia | Toma de asistencia de fin de semana, correcciones, revisión de inactivos y reactivación al volver a clase |
| Pagos y matrícula | Mensualidades, matrícula anual, deudores, anulaciones con doble confirmación |
| Documentos | Documentos personales y generales del club, con validación de tipo y tamaño |
| Torneos | Inscripción por modalidad, resultados, medallería |
| Ascensos | Sesiones de examen, seis modalidades de nota, entrega de cinturón confirmada por una persona |
| Estadísticas e historial | Gráficas por deportista, comparación entre dos deportistas |
| Calendario | Torneos, evaluaciones, grados, reuniones y eventos; importación por CSV |
| Exportación | Reportes en PDF y Excel generados al momento, sin guardarlos en el servidor |
| Cuentas | Cuentas administrativas con recuperación de contraseña por correo |

## Tecnologías

- **Backend:** PHP 8 sin framework, arquitectura por capas (Middleware → Controllers → Services → Repositories → Models), acceso a datos con PDO.
- **Base de datos:** MySQL / MariaDB (esquema v4, modo SQL estricto).
- **Frontend:** JavaScript con módulos ES, HTML5 y CSS3, sin librerías ni paso de compilación. Tema claro y oscuro, adaptado a celular.
- **Correo:** PHPMailer (recuperación de contraseña). **PDF:** FPDF.
- **Pruebas:** Postman/Newman, Selenium con pytest y baterías PHP propias.
- **Hosting:** InfinityFree.

## Seguridad

- Contraseñas guardadas con hash; la recuperación usa un enlace de un solo uso que vence en 1 hora.
- Bloqueo del ingreso tras 4 intentos fallidos durante 15 minutos.
- Sesiones separadas por ámbito, protección CSRF y expiración por inactividad.
- Respuestas de error genéricas en el ingreso y la recuperación, para no revelar qué cuentas existen.
- Las cuentas administrativas no se borran: se desactivan. Solo la cuenta principal las gestiona.
- Frontend sin `innerHTML`: se verifica con una revisión estática automática.
- Los archivos con datos de menores y de salud no se guardan al exportar.

## Pruebas

Todas las pruebas usan datos ficticios y una base de datos aparte: no tocan los datos reales.

| Parte | Qué se prueba | Resultado |
|---|---|---|
| API con Postman/Newman | 95 de 95 acciones, en las dos formas de URL | **1.090 de 1.090** comprobaciones |
| Backend, baterías PHP | 21 baterías, incluida la recuperación de contraseña | **1.808 de 1.808** comprobaciones |
| Frontend con Selenium y pytest | Flujos de punta a punta y todas las vistas, en escritorio y celular (390 px), con tema claro y oscuro | **65 de 65** pruebas |
| Prueba de humo del despliegue | Solo lectura: carga del front, respuestas JSON de la API, carpetas sensibles y archivos `.sql` no expuestos, cookie de sesión `HttpOnly` y `SameSite` | **16 de 16** (corrida contra servidor aislado) |

Además hay una revisión estática del frontend y una comparación automática de las respuestas de la API entre la cuenta del maestro y la de desarrollador (sin diferencias).

El informe completo, con los defectos que se encontraron y cómo se cerraron, está en [`pruebas/INFORME_PRUEBAS.md`](pruebas/INFORME_PRUEBAS.md). Cómo repetir cada prueba: [`pruebas/README.md`](pruebas/README.md).

## Documentación

| Documento | Ubicación |
|---|---|
| Manual de usuario v1.0 (Word y PDF) | [`documentacion/manual_usuario/`](documentacion/manual_usuario/) |
| Catálogo de la API (14 recursos, 95 acciones) | [`documentacion/api/catalogo_api.md`](documentacion/api/catalogo_api.md) |
| Documento Maestro, Documentación Técnica, Reglas de Negocio y Arquitectura del Backend | [`documentacion/`](documentacion/) |
| Diagramas de flujo y modelo entidad-relación | [`diagramas/`](diagramas/) |
| Esquema SQL de la base de datos | [`base_datos/`](base_datos/) |

## Estructura del proyecto

```text
HwangHon-Sports-System/
├── backend/        API en PHP (Public/api.php, Src/ por capas, Instalacion/)
├── frontend/       Aplicación web (HTML, CSS y módulos JS)
├── base_datos/     Esquema v4 y datos de prueba
├── pruebas/        Newman, Selenium, baterías PHP y herramientas
├── documentacion/  Manual, catálogo de la API y documentos del proyecto
├── diagramas/      Diagramas de flujo y modelo de datos
└── recursos/       Material de apoyo
```

## Cómo ejecutarlo en local

Requisitos: XAMPP (Apache, PHP 8 y MariaDB/MySQL).

1. Copia la carpeta del proyecto en `htdocs` de XAMPP y enciende Apache y MySQL.
2. Crea una base de datos llamada `hwanghon` e importa, en este orden, `base_datos/esquema_hwanghon_v4.sql`, `base_datos/insercion_datos_prueba.sql` y `base_datos/cuentas_prueba_locales.sql` (solo para desarrollo local; nunca en producción).
3. La conexión se configura con las variables de entorno `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`; los valores por defecto sirven para XAMPP.
4. Para probar la recuperación de contraseña, copia `backend/Src/Config/correo.example.php` como `correo.php` y ajústalo (el modo `archivo` guarda los correos en una carpeta en vez de enviarlos).
5. Abre `http://localhost/HwangHon-Sports-System/frontend/`. Las cuentas de prueba están descritas en el encabezado de `cuentas_prueba_locales.sql`.

En producción las cuentas iniciales se crean con `backend/Instalacion/crear_cuentas_iniciales.php`, nunca con el archivo de cuentas de prueba.

## Desarrollo con apoyo de IA

Este proyecto se desarrolló con la colaboración de **Claude** (Anthropic), un asistente de inteligencia artificial que apoyó la escritura de código, de pruebas y de documentación. El autor, John Alejandro Contreras Valencia, definió los requisitos junto con el club, tomó las decisiones de diseño, revisó el resultado y lo validó con las pruebas descritas arriba.

## Alcance

El sistema está hecho para el Club Hwang Hon Hapkido. Una sola sede, sin pagos en línea y sin empates en el récord de combates: está explicado en la sección 5 del manual de usuario. Quedan como mejoras futuras el histórico de resultados por CSV y un récord previo editable.
