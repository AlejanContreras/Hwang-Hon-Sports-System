### B.7 `asistencia` (10 acciones)

La asistencia solo se toma en **sábado o domingo**, de hoy hacia atrás (hora de Bogotá). Una marca es `{deportista_documento, fecha, asistio}`.

#### `asistencia.listarPantalla` — GET · administrativo

- **URL:** `fecha` (sábado o domingo, no futura).
- **Respuesta:** datos para la pantalla de tomar asistencia:
  - `tomada`: si esa fecha ya tiene marcas;
  - `activos` e `inactivos`: deportistas validados, cada uno con `documento`, `nombre`, `discapacidad` (para que quien toma la asistencia la conozca) y `asistio` (`null` si no tiene marca esa fecha).

{{ej:asistencia.listarPantalla}}

- **Errores:** `400` «La asistencia solo se registra en sábado o domingo.», «La fecha no puede ser futura.» o fecha inválida.

#### `asistencia.tomar` — POST · administrativo

- **Cuerpo:** `fecha` (sábado o domingo, no futura) y `presentes` (lista de documentos; puede ir vacía).
- **Respuesta (201):** `fecha`, `registrados` (filas creadas), `presentes` y `reactivados`.

{{ej:asistencia.tomar}}

- **Errores:**
  - {{err:asistencia.tomar.error409}};
  - {{err:asistencia.tomar.error400}};
  - `400` «Documentos que no se pueden marcar (no existen o están pendientes de validación): …» (no se guarda nada);
  - `400` «El campo "presentes" debe ser una lista de documentos.».
- **Notas:**
  - crea una fila por cada deportista **activo** (presente o ausente) en una transacción;
  - un **inactivo marcado presente se reactiva** solo (aparece en `reactivados`);
  - en una fecha pasada, quien ingresó después de esa fecha no recibe «ausente».

#### `asistencia.listar` — GET · administrativo o público

- **URL:** `fecha`, o `desde` + `hasta`, o `documento`. Se pueden combinar `documento` y un rango. El administrativo debe enviar al menos uno.
- **Respuesta:** las marcas, por fecha.

{{ej:asistencia.listar.fecha}}

{{ej:asistencia.listar.publico}}

- **Diferencias por ámbito:** el público solo ve las suyas (sin `documento` usa el propio); un documento ajeno responde `404`.
- **Errores:** `400` «Indica una fecha, un rango (desde y hasta) o un documento.», «Para un rango envía "desde" y "hasta".», «"desde" no puede ser posterior a "hasta".».

#### `asistencia.consultar` — GET · administrativo o público (lo suyo)

- **URL:** `documento` y `fecha`.
- **Respuesta:** la marca. Si no existe o es ajena: `404` «No encontrado.».

{{ej:asistencia.consultar}}

#### `asistencia.resumenDia` — GET · administrativo

- **Respuesta:** tarjeta del dashboard: `fecha`, `presentes`, `total` y `es_hoy`. Si hoy no hubo sesión, trae la última fecha tomada; si nunca hubo, `fecha: null`.

{{ej:asistencia.resumenDia}}

#### `asistencia.corregir` — PUT · administrativo

- **URL:** `documento` y `fecha`.
- **Cuerpo:** `asistio` (booleano).
- **Respuesta:** la marca, con `reactivado` (true si quedó presente y el deportista estaba inactivo).

{{ej:asistencia.corregir}}

- **Errores:** `404` «No hay marca de asistencia para ese deportista en esa fecha.»; `400` «El campo "asistio" debe ser verdadero o falso.».

#### `asistencia.eliminarMarca` — DELETE · administrativo

- **URL:** `documento` y `fecha`.
- **Respuesta:** `{"eliminado": true}`. No revierte una reactivación.

{{ej:asistencia.eliminarMarca}}

#### `asistencia.eliminarFecha` — DELETE · administrativo

- **URL:** `fecha`.
- **Respuesta:** `fecha` y `eliminadas` (cuántas filas borró). Borra la fecha completa tomada por error.

{{ej:asistencia.eliminarFecha}}

- **Errores:** {{err:asistencia.eliminarFecha.error404}}.

#### `asistencia.porcentaje` — GET · administrativo o público (lo suyo)

- **URL:** `documento` (obligatorio para el administrativo; el público puede omitirlo) y `ventana` (`30`, `90` o `todo`; por defecto `90`).
- **Respuesta:** `documento`, `ventana`, `desde` (el inicio de la ventana o la fecha de ingreso, la que sea posterior), `sesiones`, `presentes`, `porcentaje` (1 decimal) y `datos_incompletos`.

{{ej:asistencia.porcentaje.admin}}

- **Notas:** con menos de 4 sesiones, `porcentaje` es `null` y `datos_incompletos` es `true`: el front debe mostrar «datos insuficientes», no un 0.
- **Errores:** `400` «El parámetro "documento" es obligatorio.» o «El parámetro "ventana" debe ser 30, 90 o todo.»; `404`.

#### `asistencia.revisarInactivos` — POST · administrativo

- **Respuesta:** `fecha_limite` y `desactivados`. Pasa a **inactivo** a cada deportista activo que lleva 3 meses o más sin asistir (contados desde su última asistencia, o desde el ingreso si es posterior o si nunca asistió). Es idempotente.

{{ej:asistencia.revisarInactivos}}

- **Notas:** es el botón «Revisar inactivos»; la inactividad no se aplica sola. El front debería pedir confirmación.

---

### B.8 `pagos` (6 acciones)

Solo se guardan los pagos hechos; no hay montos. Un pago es `{id, deportista_documento, tipo, anio, mes, semana}`; la matrícula lleva `mes: 0` y `semana: null`.

#### `pagos.registrar` — POST · administrativo

- **Cuerpo:**
  - mensualidad: `documento`, `tipo: "mensualidad"`, `anio` (2020 hasta el año siguiente) y `pagos` (lista de `{mes, semana}`, con mes 1–12 sin repetir y semana 1–4: la semana del mes en que se pagó);
  - matrícula: `documento`, `tipo: "matricula"` y `anio`, sin `pagos`.
- **Respuesta (201):** los pagos creados.

{{ej:pagos.registrar.mensualidad}}

{{ej:pagos.registrar.matricula}}

- **Errores:**
  - {{err:pagos.registrar.error409}} (todo o nada);
  - `409` «La matrícula de 2026 ya está pagada.»;
  - `400` «El deportista está pendiente de validación: aún no se le registran pagos.»;
  - `400` «Hay meses repetidos en el mismo registro.»;
  - `400` «La matrícula no lleva "pagos" ni semana.»;
  - `404` si el deportista no existe.
- **Notas:** se pueden pagar meses futuros (adelantados).

#### `pagos.anular` — DELETE · administrativo

- **URL:** `id`.
- **Respuesta:** `{"eliminado": true}`. El mes vuelve a quedar sin pagar. La doble confirmación la hace el front.

{{ej:pagos.anular}}

- **Errores:** {{err:pagos.anular.error404}}.

#### `pagos.estado` — GET · administrativo o público (lo suyo)

- **URL:** `documento` (obligatorio para el administrativo) y `anio` (opcional; por defecto el actual).
- **Respuesta:**
  - `semana_habitual`: semana del último pago, o la del ingreso si nunca pagó;
  - `matricula`: `{pagada, pendiente, atrasada}`;
  - `meses`: 12 elementos, cada uno con `mes`, `pagado`, `semana`, `vencimiento` (el primer día de la semana habitual), `vencido` y `en_deuda`;
  - `meses_en_deuda`, `por_vencer` (`{anio, mes, vencimiento}` o null), `al_dia` y `genera_alertas` (solo los activos validados generan alertas).

{{ej:pagos.estado.admin}}

- **Notas:** «deuda» = mes vencido, sin pago y con 2 o más asistencias desde el vencimiento. Un mes vencido sin asistencias no es deuda. La matrícula nunca cuenta como deuda.

#### `pagos.deudores` — GET · administrativo

- **Respuesta:** deudores del **año en curso**: `[{documento, nombre, meses_en_deuda: [mes, …]}]`, por nombre.

{{ej:pagos.deudores}}

- **Notas:** en la semilla no había deudores ese día, por eso la lista llegó vacía. La forma de cada elemento es la indicada arriba (ver también `reportes.pagos`).

#### `pagos.listar` — GET · administrativo o público (lo suyo)

- **URL (filtros opcionales):** `documento`, `anio` y `tipo`.
- **Respuesta:** los pagos, por año, mes e id.

{{ej:pagos.listar}}

#### `pagos.consultar` — GET · administrativo o público (lo suyo)

- **URL:** `id`.
- **Respuesta:** el pago. Si es ajeno o no existe: `404` «No encontrado.».

{{ej:pagos.consultar}}

---

### B.9 `calendario` (6 acciones)

Un evento es `{id, nombre, tipo, fecha, lugar, visibilidad, observaciones}`. El público solo ve los de visibilidad `publica`.

#### `calendario.listar` — GET · administrativo o público

- **URL (opcionales):** `desde` + `hasta` (juntos) y `tipo`.
- **Respuesta:** los eventos visibles, por fecha y nombre.

{{ej:calendario.listar}}

#### `calendario.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** el evento. Uno administrativo pedido por el público responde {{err:calendario.consultar.error404}}.

{{ej:calendario.consultar}}

#### `calendario.crear` — POST · administrativo

- **Cuerpo:**
  - `nombre` y `lugar`: obligatorios, máximo 150;
  - `tipo` y `fecha`: obligatorios;
  - `visibilidad`: obligatoria (salvo en reuniones);
  - `observaciones`: opcional, máximo 65.000 bytes;
  - `confirmar_confidencial: true`: obligatorio para una `reunion_administrativa`, que queda siempre administrativa.
- **Respuesta (201):** el evento.

{{ej:calendario.crear}}

- **Errores:** {{err:calendario.crear.error400}}; `409` «Ya existe un evento con ese nombre en esa fecha.».
- **Notas:** los eventos de exámenes y grados los crea Evaluaciones; los torneos se crean aquí (tipo `competencia`) y luego en Competencias. Los eventos no se eliminan.

#### `calendario.actualizar` — PUT · administrativo

- **URL:** `id`.
- **Cuerpo (parcial):** `nombre`, `fecha`, `lugar`, `visibilidad` y `observaciones`. El `tipo` no se cambia.
- **Respuesta:** el evento actualizado.

{{ej:calendario.actualizar}}

- **Errores:**
  - {{err:calendario.actualizar.error409}}: la fecha, el lugar y la visibilidad de un evento de torneo se editan en `competencias.actualizar`, y los de una sesión de examen en `evaluaciones.actualizar`; el mensaje indica cuál. El nombre y las observaciones sí se editan aquí;
  - `400` «El tipo de un evento no se cambia.»;
  - `400` «Una reunión administrativa siempre es de visibilidad administrativa (confidencial).»;
  - `409` por duplicado.

#### `calendario.vista` — GET · administrativo o público

- **URL:** `desde` y `hasta` (obligatorios, máximo 366 días).
- **Respuesta:**
  - `eventos`: los visibles;
  - `cumpleanos` y `vencimientos` (mensualidades sin pagar), calculados y agrupados por día: `{fecha, cantidad, deportistas: [{documento, nombre}]}`.

{{ej:calendario.vista.admin}}

- **Diferencias por ámbito:** el público solo ve los eventos públicos, **su** cumpleaños y **sus** vencimientos. El administrativo ve todos los de los deportistas activos y validados.

{{ej:calendario.vista.publico}}

- **Errores:** `400` «Indica el rango con "desde" y "hasta".» o «El rango no puede superar 366 días.».

#### `calendario.importar` — POST multipart · administrativo

- **Campos:** `archivo` (`.csv` en UTF-8, con o sin BOM; separador `;` o `,`; máximo 500 filas).
- **Columnas exactas:** `nombre;tipo;fecha;lugar;visibilidad;observaciones`. La fecha va como `AAAA-MM-DD` o `DD/MM/AAAA`.
- **Respuesta:**
  - `creados`;
  - `omitidos`: duplicados contra la base o dentro del archivo, con su línea;
  - `errores`: filas inválidas, con su línea y el motivo.

{{ej:calendario.importar}}

- **Errores:** `400` «El encabezado debe tener las columnas: …», «El archivo debe estar guardado en UTF-8 (en Excel: «CSV UTF-8»).» o «El archivo supera el máximo de 500 filas.».

---

### B.10 `competencias` — torneos (9 acciones)

- **Torneo:** el evento del calendario de tipo `competencia` más `plataforma`, `tipo_torneo` y `num_participantes`. Su id es el `evento_id`.
- **Participación:** `{deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente}`. `notas_oponente` solo la ve el ámbito administrativo.

#### `competencias.listar` — GET · administrativo o público

- **URL (opcionales):** `deportista`, `desde`, `hasta`, `lugar` (contiene el texto) y `tipo_torneo`.
- **Respuesta:** los torneos, del más reciente al más antiguo.

{{ej:competencias.listar.admin}}

- **Diferencias por ámbito:** el público solo ve torneos públicos y cada uno trae `participa` (si él está inscrito). Solo puede filtrar por su propio documento.

{{ej:competencias.listar.publico}}

#### `competencias.consultar` — GET · administrativo o público

- **URL:** `id` (el `evento_id`).
- **Respuesta:** el torneo con `participantes` (cada participación incluye el `nombre`).

{{ej:competencias.consultar.admin}}

- **Diferencias por ámbito:** el público solo consulta torneos públicos, ve **solo su propia participación** y nunca `notas_oponente`.

{{ej:competencias.consultar.publico}}

#### `competencias.proximo` — GET · administrativo

- **Respuesta:** el próximo torneo (fecha ≥ hoy) con `num_participantes`, o `null` si no hay. Es para las tarjetas del dashboard.

{{ej:competencias.proximo}}

- **Errores:** {{err:competencias.proximo.error403}}.

#### `competencias.crear` — POST · administrativo

- **Cuerpo:** `tipo_torneo` (obligatorio), `plataforma` (opcional, máximo 50) y **uno** de estos dos:
  - `evento_id`: un evento existente de tipo competencia que aún no tiene torneo;
  - `evento`: objeto con `nombre`, `fecha`, `lugar`, `visibilidad` y `observaciones`, para crear el evento y el torneo juntos (modo excepcional).
- **Respuesta (201):** el torneo con `participantes: []`.

{{ej:competencias.crear}}

- **Errores:**
  - {{err:competencias.crear.error422}};
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

{{ej:competencias.actualizar}}

Fecha, lugar y visibilidad:

{{ej:competencias.actualizar.evento}}

- **Errores:**
  - {{err:competencias.actualizar.error400}};
  - `400` por fecha, lugar o visibilidad inválidos;
  - {{err:competencias.actualizar.error422}};
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

{{ej:competencias.sugerencia}}

#### `competencias.inscribir` — POST · administrativo

- **Cuerpo:** `competencia_evento_id` y `participantes` (máximo 50): `[{deportista_documento, modalidad, categoria_edad?, categoria_peso?}]`. Lo que falte se completa con lo sugerido.
- **Respuesta (201):** las participaciones creadas. Es todo o nada.

{{ej:competencias.inscribir}}

- **Errores:**
  - {{err:competencias.inscribir.error409}};
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

{{ej:competencias.actualizarParticipacion}}

- **Errores:**
  - {{err:competencias.actualizarParticipacion.error422}} (puesto, resultado, medalla y combates en un torneo futuro);
  - `422` «"combates_ganados" solo aplica a las modalidades de combate.»;
  - `400` «La modalidad no se cambia: quita al participante y vuelve a inscribirlo.»;
  - `404` «Participación no encontrada.».
- **Notas:** el puesto lo digita el club (puede repetirse) y la medalla es independiente del puesto. Los combates alimentan el récord del deportista.

#### `competencias.quitarParticipante` — DELETE · administrativo

- **URL:** `deportista_documento`, `competencia_evento_id` y `modalidad`.
- **Respuesta:** `{"eliminado": true}`. Los torneos no se eliminan; solo se quitan participantes.

{{ej:competencias.quitarParticipante}}

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

{{ej:evaluaciones.candidatos}}

- **Notas:** solo es un aviso; nunca impide inscribir.

#### `evaluaciones.crear` — POST · administrativo

- **Cuerpo:** `fecha` (inicio), `fecha_grado` (≥ fecha), `lugar` y `participantes` (opcional: `[{deportista_documento, grado_evaluado?}]`, máximo 50; sin grado se usa el siguiente al actual).
- **Respuesta (201):** la sesión completa.

{{ej:evaluaciones.crear}}

- **Errores:**
  - `422` «La fecha de grado no puede ser anterior a la fecha de inicio de los exámenes.»;
  - `409` «Ya hay en el calendario un evento de exámenes o de grado con ese nombre y fecha.»;
  - `400` «No hay grado siguiente sugerido para X: envía grado_evaluado.».
- **Notas:** crea en la misma transacción **dos eventos públicos del calendario** (exámenes y grado) y, por participante, las seis modalidades pendientes y su fila de entrega.

#### `evaluaciones.inscribir` — POST · administrativo

- **URL:** `sesion_id` (sesión abierta).
- **Cuerpo:** `participantes`, como en `crear`.
- **Respuesta (201):** la sesión. Es todo o nada.

{{ej:evaluaciones.inscribir}}

- **Errores:** `409` «El deportista X ya está inscrito en la sesión.»; `409` «La sesión está cerrada: reábrela para modificarla.».

#### `evaluaciones.listar` — GET · administrativo o público

- **URL (opcionales):** `estado`, `desde`, `hasta` (sobre la fecha de inicio) y `deportista`.
- **Respuesta:**
  - administrativo: las sesiones con `num_participantes`, `num_completados` y `total_modalidades`;
  - público: solo las sesiones donde participa, sin conteos.

{{ej:evaluaciones.listar.admin}}

{{ej:evaluaciones.listar.publico}}

#### `evaluaciones.consultar` — GET · administrativo o público

- **URL:** `id`.
- **Respuesta:** la sesión con sus participantes. El público solo ve su propio participante; si no participa, `404`.

{{ej:evaluaciones.consultar}}

#### `evaluaciones.actualizar` — PUT · administrativo

- **URL:** `id` (sesión abierta).
- **Cuerpo (parcial):** `fecha`, `fecha_grado` y `lugar`.
- **Respuesta:** la sesión. También mueve sus dos eventos del calendario.

{{ej:evaluaciones.actualizar}}

#### `evaluaciones.eliminar` — DELETE · administrativo

- **URL:** `id` (sesión abierta sin ninguna modalidad calificada).
- **Respuesta:** `{"eliminado": true}`. Borra también sus eventos.

{{ej:evaluaciones.eliminar}}

- **Errores:** {{err:evaluaciones.eliminar.error409}}; `409` «La sesión tiene modalidades calificadas: no se puede eliminar (solo modificar o reabrir).».

#### `evaluaciones.cerrar` — PUT · administrativo

- **URL:** `id`.
- **Respuesta:** la sesión con `estado: "cerrada"`. Exige al menos un participante y las seis modalidades de todos completadas.

{{ej:evaluaciones.cerrar}}

- **Errores:** {{err:evaluaciones.cerrar.error409}}; `409` «La sesión no tiene participantes: no se puede cerrar.».

#### `evaluaciones.reabrir` — PUT · administrativo

- **URL:** `id`.
- **Respuesta:** la sesión abierta, más `advertencias` si alguien ya tiene la entrega de cinturón confirmada. No bloquea: el grado se corrige a mano.

{{ej:evaluaciones.reabrir}}

- **Errores:** `409` «La sesión ya está abierta.».

#### `evaluaciones.actualizarParticipante` — PUT · administrativo

- **URL:** `sesion_id` y `deportista_documento`.
- **Cuerpo:** `grado_evaluado` (un grado de la lista; sin distinguir mayúsculas ni tildes).
- **Respuesta:** el participante.

{{ej:evaluaciones.actualizarParticipante}}

#### `evaluaciones.calificar` — PUT · administrativo

- **URL:** `sesion_id`, `deportista_documento` y `modalidad`.
- **Cuerpo (parcial; `null` limpia):**
  - `nota` (1–10, 2 decimales) y `valor_medible` (0–9999.99; para la prueba física);
  - `criterios_evaluados`, `puntajes`, `aspectos_fallidos`, `observaciones` y `recomendaciones` (máximo 65.000 bytes cada uno);
  - `fecha_completado` (por defecto hoy; ni futura ni anterior al inicio).
- **Respuesta:** el participante. El `estado` y el `aprobado` de la modalidad se derivan de la nota (aprueba con ≥ 5).

{{ej:evaluaciones.calificar.llaves}}

- **Errores:**
  - {{err:evaluaciones.calificar.error400}};
  - `422` «La sesión todavía no ha empezado: no se puede calificar antes de la fecha de inicio.»;
  - `404` «El deportista no tiene esa modalidad en esta sesión.».

#### `evaluaciones.quitarParticipante` — DELETE · administrativo

- **URL:** `sesion_id` y `deportista_documento`.
- **Respuesta:** `{"eliminado": true}`. Solo si no tiene modalidades calificadas.

{{ej:evaluaciones.quitarParticipante}}

- **Errores:** `409` «El participante ya tiene modalidades calificadas: no se puede quitar.».

#### `evaluaciones.confirmarEntregaCinturon` — PUT · administrativo o público (el propio deportista)

- **URL:** `sesion_id` y `deportista_documento`.
- **Cuerpo:** `fecha_entrega` (opcional, solo el administrativo; por defecto la fecha de grado; no futura). Se puede enviar `{}`.
- **Respuesta:** la entrega (`cinturon_entregado`, `fecha_entrega`, `confirmado_por`), más `sesion_id`, `deportista_documento`, `grado_actual` y, si aplica, `aviso`.

{{ej:evaluaciones.confirmarEntregaCinturon}}

- **Errores:**
  - {{err:evaluaciones.confirmarEntregaCinturon.error409}};
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

{{ej:historial.torneos.admin}}

- **Errores:** {{err:historial.torneos.error400}}; fechas mal formadas.

#### `historial.ascensos` — GET · administrativo o público

- **URL (opcionales):** `deportista`, `desde` y `hasta` (sobre `fecha_grado`).
- **Respuesta:** una fila por deportista y **sesión cerrada**, con `resultado_general` (`{promedio, aprobado}`), `entrega` (null si no hay fila) y las seis `modalidades` con nota, aprobado, valor medible, aspectos fallidos, observaciones y recomendaciones.

{{ej:historial.ascensos}}

#### `historial.permanencia` — GET · administrativo o público

- **URL (opcionales):** `deportista` y `estado`.
- **Respuesta:** por deportista: `fecha_ingreso`, `meses_pagados` (meses de mensualidad distintos, aunque haya huecos), `primer_mes_pagado` y `ultimo_mes_pagado` (`AAAA-MM` o null). Sin filtro: todos los validados, de más a menos meses pagados.

{{ej:historial.permanencia}}

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

{{ej:estadisticas.deportista}}

- **Diferencias por ámbito:** el público solo ve lo suyo y solo los torneos públicos.
- **Notas:** para comparar dos deportistas, el front llama dos veces.

#### `estadisticas.torneo` — GET · administrativo

- **URL:** `id` (de un torneo ya realizado).
- **Respuesta:** datos del torneo, `por_modalidad` (participantes, medallas y combates), `medallas_total` y `participantes` (por modalidad y puesto, con los puestos vacíos al final). Sin `notas_oponente`.

{{ej:estadisticas.torneo}}

- **Errores:** {{err:estadisticas.torneo.error403}}; `404` «Torneo no encontrado.» (inexistente, sin competencia o con fecha futura).

---

### B.14 `reportes` — exportaciones (6 acciones)

Solo administrativo (el público recibe {{err:reportes.deportistas.error403}}). Todas son GET y responden un **archivo** (A.8); los errores salen en JSON antes de generar nada. Los filtros de fecha (`desde`/`hasta`) son `AAAA-MM-DD`; si llegan mal formados o con `desde` posterior a `hasta`, responden `400`. Un rango sin datos da un archivo válido con solo encabezados.

#### `reportes.deportistaFicha` — GET · administrativo

- **URL:** `deportista` (obligatorio).
- **Respuesta:** PDF con la ficha consolidada:
  - perfil: edad, categorías, peso, categoría de peso, condición de salud, grado, ingreso y estado;
  - contacto, y el acudiente si es menor;
  - último examen (PROVISIONAL), aspectos por mejorar y recomendaciones;
  - últimos 5 torneos;
  - asistencia (90 días y total) y permanencia.

{{ej:reportes.deportistaFicha}}

- **Errores:** `404` «Deportista no encontrado.»; `400` si falta `deportista`.

#### `reportes.deportistas` — GET · administrativo

- **URL:** `estado` (opcional: `activo` o `inactivo`). Sin filtro: todos los validados.
- **Respuesta:** Excel con documento, nombre, fecha de nacimiento, edad, categoría de edad, grado, estado, ingreso, teléfono y correo.

{{ej:reportes.deportistas}}

#### `reportes.asistencia` — GET · administrativo

- **URL:** `desde` y `hasta` (obligatorios, máximo 366 días) y `estado` (opcional).
- **Respuesta:** Excel con la planilla (formato propio estilo IMRDS, PROVISIONAL): deportistas × fechas, condición de salud, total, sesiones, % y una fila final con asistentes por fecha.

{{ej:reportes.asistencia}}

- **Errores:** {{err:reportes.asistencia.error422}}; `400` si falta `desde` o `hasta`.

#### `reportes.pagos` — GET · administrativo

- **URL:** `anio` (opcional; por defecto el actual) y `estado` (opcional).
- **Respuesta:** Excel con dos hojas:
  - «Planilla»: deportista × 12 meses, con la semana de pago y colores para pagado y vencido o deuda, más la matrícula;
  - «Deudores»: los del año en curso.

{{ej:reportes.pagos}}

#### `reportes.torneos` — GET · administrativo

- **URL:** `desde` y `hasta` (opcionales).
- **Respuesta:** Excel con una fila por participación en torneos realizados y la hoja «Medallería». Sin notas de oponente.

{{ej:reportes.torneos}}

#### `reportes.sesionExamen` — GET · administrativo

- **URL:** `id` (de la sesión).
- **Respuesta:** Excel con las seis notas de cada participante, el promedio, el resultado (PROVISIONAL), el grado evaluado y la entrega. Una sesión abierta sale marcada «EN CURSO».

{{ej:reportes.sesionExamen}}

- **Errores:** `404` «Sesión de examen no encontrada.».

---
