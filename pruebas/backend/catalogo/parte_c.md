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

   {{ej:auth.sesionActual.error401}}

2. **Ingreso administrativo:** `auth.iniciarSesionAdministrativa`. Guardar en memoria `nombre` y `es_administrador_principal`; este último decide si se muestra la gestión de cuentas.

   {{ej:auth.iniciarSesionAdministrativa}}

   Si falla, mostrar el mensaje tal cual. Con `429`, deshabilitar el botón y mostrar los minutos que dice el mensaje.

   {{ej:auth.iniciarSesionAdministrativa.error401}}

3. **Ingreso público** («Mi perfil»): `auth.iniciarSesionPublica`, con el documento y la fecha de nacimiento del deportista.

   {{ej:auth.iniciarSesionPublica}}

   Un registro pendiente recibe `403` con «Tu registro está en revisión.».

   {{ej:auth.iniciarSesionPublica.error403}}

4. **Durante el uso:** cualquier `401` («No autenticado…» o «Tu sesión ya no es válida.») lleva de vuelta al ingreso.
5. **Salir:** `auth.cerrarSesion`.

   {{ej:auth.cerrarSesion}}

6. **Olvidé mi contraseña** (administrativo):
   - `auth.solicitarRecuperacionContrasena`: siempre el mismo mensaje;

     {{ej:auth.solicitarRecuperacionContrasena}}

   - el enlace del correo abre la pantalla de restablecer, que lee `token` de la URL y llama `auth.restablecerContrasena`. Después, volver al ingreso.

     {{ej:auth.restablecerContrasena}}

7. **Cambiar mi contraseña** (administrativo con sesión): `auth.cambiarContrasena`.

   {{ej:auth.cambiarContrasena}}

### C.2 Deportistas

**Administrativo — lista y ficha**

1. **Lista:** `deportistas.listar`. Para la pestaña «Pendientes de validación», con el filtro `estado_registro=pendiente_validacion`.

   {{ej:deportistas.listar.pendientes}}

2. **Buscador o autocompletado:** `deportistas.buscar` (desde 2 caracteres).

   {{ej:deportistas.buscar}}

3. **Abrir una ficha:** `deportistas.consultar` (trae el récord y el acudiente).

   {{ej:deportistas.consultar.admin}}

4. **Documentos de la ficha:** `documentos.listar` y `documentos.faltantes`. Los críticos son los que faltan para considerar completa la inscripción.

   {{ej:documentos.listar.admin}}

   {{ej:documentos.faltantes.uno}}

5. **Nuevo deportista:** `deportistas.crear`. Si es menor, el formulario exige el bloque del acudiente. Luego, subir sus documentos con `documentos.subir`.

   {{ej:deportistas.crear}}

   {{ej:documentos.subir}}

6. **Editar o validar:** `deportistas.actualizar`. Validar un pendiente es enviar `{"estado_registro": "validado"}`; rechazarlo es `deportistas.eliminar`.

   {{ej:deportistas.actualizar.validar}}

   {{ej:deportistas.eliminar}}

**Público — «Mi perfil»**

1. `deportistas.consultar` con su propio documento (o `deportistas.listar`, que devuelve solo el suyo).

   {{ej:deportistas.consultar.publico}}

2. **Editar:** solo peso, condición de salud, teléfono, dirección y correo, con `deportistas.actualizar`. Los demás campos se muestran de solo lectura.

   {{ej:deportistas.actualizar.publico}}

3. **Mis documentos:** `documentos.listar`. Puede subir con `documentos.subir` los cuatro tipos de un solo archivo que aún estén vacíos.

   {{ej:documentos.listar.publico}}

**Autorregistro** (página pública, sin sesión)

1. `autorregistro.registrar`, con el campo oculto `sitio_web` vacío.

   {{ej:autorregistro.registrar}}

2. Dentro de las 2 horas: `autorregistro.subirDocumento`, `autorregistro.listarDocumentos`, `autorregistro.reemplazarDocumento` y `autorregistro.eliminarDocumento`.

   {{ej:autorregistro.subirDocumento}}

   {{ej:autorregistro.listarDocumentos}}

3. «Guardar cambios»: `autorregistro.confirmar`.

   {{ej:autorregistro.confirmar}}

### C.3 Asistencia

1. **Dashboard:** `asistencia.resumenDia`.

   {{ej:asistencia.resumenDia}}

2. **Pantalla de tomar asistencia:** elegir un sábado o domingo y llamar `asistencia.listarPantalla`. Mostrar la `discapacidad` junto a cada nombre.
   - con `tomada: false`, el usuario marca y se envía `asistencia.tomar`;
   - con `tomada: true`, cada cambio va por `asistencia.corregir`.

   {{ej:asistencia.listarPantalla}}

3. **Guardar la toma:** `asistencia.tomar`. Mostrar los `reactivados`: se reactivaron por venir presentes.

   {{ej:asistencia.tomar}}

   Si la fecha ya estaba tomada:

   {{ej:asistencia.tomar.error409}}

4. **Corregir una marca:** `asistencia.corregir`.

   {{ej:asistencia.corregir}}

5. **Borrar algo tomado por error:** `asistencia.eliminarMarca` o `asistencia.eliminarFecha` (pedir confirmación).

   {{ej:asistencia.eliminarFecha}}

6. **Porcentaje de un deportista** (ficha o «Mi perfil»): `asistencia.porcentaje`. Con `datos_incompletos: true`, mostrar «datos insuficientes».

   {{ej:asistencia.porcentaje.publico}}

7. **Botón «Revisar inactivos»:** `asistencia.revisarInactivos` (pedir confirmación; muestra a quiénes desactivó).

   {{ej:asistencia.revisarInactivos}}

### C.4 Pagos

1. **Estado de un deportista** (ficha o «Mi perfil»): `pagos.estado`. Pintar los 12 meses con `pagado`, `vencido` y `en_deuda`, y el aviso de `por_vencer` y de la matrícula `pendiente`/`atrasada`.

   {{ej:pagos.estado.admin}}

   El público llama sin parámetros y recibe el suyo:

   {{ej:pagos.estado.publico}}

2. **Registrar uno o varios meses:** `pagos.registrar`. La semana es la del mes en que se pagó. Luego, volver a pedir el estado.

   {{ej:pagos.registrar.mensualidad}}

   Si un mes ya estaba pagado, no se guarda nada del lote:

   {{ej:pagos.registrar.error409}}

3. **Matrícula:** `pagos.registrar` con `tipo: "matricula"`.

   {{ej:pagos.registrar.matricula}}

4. **Historial de pagos:** `pagos.listar`. Para anular uno registrado por error: `pagos.anular` (con doble confirmación en el front).

   {{ej:pagos.listar}}

   {{ej:pagos.anular}}

5. **Lista de deudores** (administrativo): `pagos.deudores`. La planilla anual en Excel sale con `reportes.pagos`.

   {{ej:pagos.deudores}}

   {{ej:reportes.pagos}}

---

*Generado a partir de capturas reales. Para regenerar los ejemplos se usa el procedimiento de `pruebas/backend/README.md`.*
