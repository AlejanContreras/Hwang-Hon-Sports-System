<?php

declare(strict_types=1);

/**
 * Carga masiva de deportistas por CSV (RN-1.7, RN-1.9, RN-1.10). Solo
 * administrativo. Sin estado en el servidor: el archivo no se guarda (datos
 * de menores); previsualizar y aplicar reciben el mismo CSV y lo analizan
 * igual.
 *
 *   - previsualizar: NO escribe. Clasifica cada fila: nueva, actualizar (con
 *     los campos que cambiarían, valor actual → nuevo, RN-1.9), sin_cambios,
 *     incompleta (obligatorios faltantes, nombrados), invalida (un valor no
 *     pasa las reglas de crear/actualizar), conflicto_correo (RN-1.10) o
 *     duplicada (el documento se repite en el archivo: se rechazan todas sus
 *     filas, porque no se sabe cuál vale). Una fila mala no bloquea las demás.
 *   - aplicar: da de alta las nuevas (validadas, validado_por_correo = quien
 *     carga), actualiza SOLO las que el administrativo confirmó en
 *     confirmar_actualizacion, omite las demás coincidencias y rechaza el
 *     resto. Cada fila en su propia transacción. Nunca cambia el documento
 *     ni estado_registro de un deportista existente.
 *   - Celda vacía = sin dato: en un alta, el campo queda vacío; en una
 *     actualización, el valor actual se conserva (el CSV no borra datos).
 *   - Conflicto de correo (RN-1.10): el mismo correo en dos deportistas
 *     ADULTOS distintos (en la base o en el archivo). Un menor que comparte
 *     el correo de su acudiente, o hermanos que lo comparten, no es
 *     conflicto; tampoco un adulto cuyo correo es el de acudiente de un menor
 *     (la misma persona en dos papeles).
 *
 * Validación: la de DeportistaService (modeloAlta, acudienteAlta,
 * prepararCambios), con los existentes precargados en bloque: el número de
 * consultas no depende del número de filas.
 *
 * Plantilla: backend/Instalacion/plantilla_carga_masiva_deportistas.txt
 * (PROVISIONAL hasta que Aldrin valide la plantilla final).
 */
class ImportacionDeportistaService
{
    /** PROPUESTA: tamaño máximo del CSV (413 al superarlo). */
    private const TAMANO_MAXIMO_BYTES = 1024 * 1024;
    /** PROPUESTA: filas de datos por archivo (422 al superarlo). */
    private const MAXIMO_FILAS = 1000;

    /** Columnas de la plantilla, en su orden. */
    private const COLUMNAS = [
        'documento', 'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'estatura', 'peso',
        'telefono', 'direccion', 'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento',
        'autorizacion_datos', 'grado_actual', 'fecha_ingreso', 'estado',
        'acudiente_documento', 'acudiente_nombre', 'acudiente_relacion', 'acudiente_telefono', 'acudiente_correo',
    ];
    /** Otros encabezados aceptados (ya normalizados) => columna de la plantilla. */
    private const ALIAS = [
        'fecha_de_nacimiento' => 'fecha_nacimiento', 'lugar_de_nacimiento' => 'lugar_nacimiento',
        'autorizacion_de_datos' => 'autorizacion_datos', 'fecha_de_ingreso' => 'fecha_ingreso',
        'sede_de_entrenamiento' => 'sede_entrenamiento', 'nombres' => 'nombre', 'nombre_completo' => 'nombre',
        'celular' => 'telefono', 'grado' => 'grado_actual', 'cinturon' => 'grado_actual',
        'condicion_de_salud' => 'discapacidad', 'documento_acudiente' => 'acudiente_documento',
        'nombre_acudiente' => 'acudiente_nombre', 'relacion_acudiente' => 'acudiente_relacion',
        'telefono_acudiente' => 'acudiente_telefono', 'correo_acudiente' => 'acudiente_correo',
    ];
    /** Campos excluidos del sistema (RN-1.6): si vienen, se ignoran con aviso. */
    private const EXCLUIDAS = [
        'genero', 'grupo_etnico', 'etnia', 'victima', 'victima_conflicto', 'victima_del_conflicto',
        'victima_conflicto_armado', 'victima_del_conflicto_armado',
    ];
    /** Los fija el sistema, nunca el CSV. */
    private const RESERVADAS = ['estado_registro', 'validado_por_correo', 'validado_por'];
    private const OBLIGATORIOS_ALTA = ['documento', 'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso'];
    private const CAMPOS_ACUDIENTE = ['acudiente_nombre' => 'nombre', 'acudiente_relacion' => 'relacion', 'acudiente_telefono' => 'telefono', 'acudiente_correo' => 'correo'];
    /** Campos del deportista que se comparan para "actualizar" (el documento nunca cambia). */
    private const CAMPOS_COMPARABLES = [
        'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'estatura', 'peso', 'telefono', 'direccion',
        'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento', 'autorizacion_datos',
        'grado_actual', 'fecha_ingreso', 'estado', 'acudiente_documento',
    ];
    private const CLASIFICACIONES = ['nueva', 'actualizar', 'sin_cambios', 'incompleta', 'invalida', 'conflicto_correo', 'duplicada'];

    private DeportistaRepository $deportista;
    private AcudienteTutorRepository $acudiente;
    private DeportistaService $servicio;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->acudiente = new AcudienteTutorRepository();
        $this->servicio = new DeportistaService();
    }

    /**
     * Clasificación por fila y resumen, sin escribir nada.
     */
    public function previsualizar(): array
    {
        $analisis = $this->analizar($this->correoAdministrativo());
        $resumen = array_fill_keys(self::CLASIFICACIONES, 0);
        foreach ($analisis['filas'] as $fila) {
            $resumen[$fila['clasificacion']]++;
        }
        return [
            'archivo' => $analisis['archivo'],
            'avisos' => $analisis['avisos'],
            'resumen' => ['total' => count($analisis['filas'])] + $resumen,
            'filas' => array_map(fn (array $fila): array => $this->formatearFila($fila), $analisis['filas']),
        ];
    }

    /**
     * Aplica el CSV: altas, actualizaciones confirmadas (documentos en
     * confirmar_actualizacion, como lista o separados por coma) y resultado
     * por fila. Cada fila se guarda en su propia transacción.
     */
    public function aplicar(array $datos): array
    {
        $correo = $this->correoAdministrativo();
        $confirmados = $this->confirmados($datos['confirmar_actualizacion'] ?? []);
        $analisis = $this->analizar($correo);

        $resumen = ['creadas' => 0, 'actualizadas' => 0, 'omitidas' => 0, 'rechazadas' => 0, 'errores' => 0];
        $filas = [];
        foreach ($analisis['filas'] as $fila) {
            $resultado = ['linea' => $fila['linea'], 'documento' => $fila['documento'], 'clasificacion' => $fila['clasificacion']];
            try {
                switch ($fila['clasificacion']) {
                    case 'nueva':
                        $this->servicio->guardarAlta($fila['modelo'], $fila['acudiente_nuevo']);
                        $resultado['resultado'] = 'creada';
                        $resumen['creadas']++;
                        break;
                    case 'actualizar':
                        if (!isset($confirmados[$fila['documento']])) {
                            $resultado += ['resultado' => 'omitida', 'motivo' => 'Actualización no confirmada: no se tocó.'];
                            $resumen['omitidas']++;
                            break;
                        }
                        $this->servicio->guardarCambios($fila['cambios_preparados']);
                        $resultado['resultado'] = 'actualizada';
                        $resumen['actualizadas']++;
                        break;
                    case 'sin_cambios':
                        $resultado += ['resultado' => 'omitida', 'motivo' => 'Sin cambios.'];
                        $resumen['omitidas']++;
                        break;
                    default:
                        $resultado += ['resultado' => 'rechazada', 'motivo' => $fila['motivo']];
                        $resumen['rechazadas']++;
                }
            } catch (ExcepcionNegocio $error) {
                $resultado += ['resultado' => 'error', 'motivo' => $error->getMessage()];
                $resumen['errores']++;
            } catch (Throwable $error) {
                error_log((string) $error);
                $resultado += ['resultado' => 'error', 'motivo' => 'Error interno al guardar esta fila; las demás siguen.'];
                $resumen['errores']++;
            }
            $filas[] = $resultado;
        }
        return ['avisos' => $analisis['avisos'], 'resumen' => $resumen, 'filas' => $filas];
    }

    // ------------------------------------------------------------------
    // Análisis (común a previsualizar y aplicar)
    // ------------------------------------------------------------------

    /**
     * Lee el CSV, precarga en bloque lo existente y clasifica cada fila en
     * orden. Los acudientes nuevos de filas anteriores cuentan como
     * existentes para las siguientes (hermanos con el mismo acudiente nuevo).
     *
     * @return array{archivo: array<string, mixed>, avisos: array<int, string>, filas: array<int, array<string, mixed>>}
     */
    private function analizar(string $correoAdministrativo): array
    {
        $csv = $this->leerCsv();
        [$mapa, $avisos] = $this->mapearEncabezado($csv['encabezado']);

        $filas = [];
        foreach ($csv['filas'] as $linea => $valores) {
            $filas[] = $this->valoresDeFila((int) $linea, $valores, $mapa, count($csv['encabezado']));
        }

        $documentos = [];
        $correos = [];
        $acudientesArchivo = [];
        foreach ($filas as $fila) {
            if (is_string($fila['valores']['documento'] ?? null)) {
                $documentos[] = $fila['valores']['documento'];
            }
            if (is_string($fila['valores']['correo'] ?? null)) {
                $correos[] = $fila['valores']['correo'];
            }
            if (is_string($fila['valores']['acudiente_documento'] ?? null)) {
                $acudientesArchivo[] = $fila['valores']['acudiente_documento'];
            }
        }
        $existentes = $this->deportista->listarPorDocumentos($documentos);
        $acudientes = $this->acudiente->listarPorDocumentos(array_merge(
            $acudientesArchivo,
            array_values(array_filter(array_column($existentes, 'acudiente_documento')))
        ));

        $repetidos = array_filter(array_count_values($documentos), fn (int $n): bool => $n > 1);
        foreach ($filas as $i => $fila) {
            if (isset($fila['clasificacion'])) {
                continue;
            }
            $documento = $fila['valores']['documento'];
            if (isset($repetidos[$documento])) {
                $lineas = array_column(array_filter($filas, fn (array $f): bool => ($f['valores']['documento'] ?? null) === $documento), 'linea');
                $filas[$i] = $fila + ['clasificacion' => 'duplicada', 'motivo' => "El documento {$documento} se repite en el archivo (líneas " . implode(', ', $lineas) . '): corrige el archivo y vuelve a cargarlo.'];
                continue;
            }
            $filas[$i] = isset($existentes[$documento])
                ? $this->clasificarExistente($fila, $existentes[$documento], $acudientes, $correoAdministrativo)
                : $this->clasificarNueva($fila, $acudientes, $correoAdministrativo);
            if (($filas[$i]['acudiente_nuevo'] ?? null) instanceof AcudienteTutorModel) {
                $acudientes[$filas[$i]['acudiente_nuevo']->obtenerDocumento()] = $filas[$i]['acudiente_nuevo']->toArray();
            }
        }

        $filas = $this->marcarConflictosDeCorreo($filas, $correos);

        return [
            'archivo' => ['codificacion' => $csv['codificacion'], 'separador' => $csv['separador'], 'filas' => count($filas)],
            'avisos' => $avisos,
            'filas' => $filas,
        ];
    }

    /**
     * El archivo subido: tamaño (413), tipo CSV de texto (400), vacío o sin
     * encabezados (400) y máximo de filas (422). Se lee desde el temporal de
     * PHP, que PHP borra al terminar la petición: nada queda en disco.
     *
     * @return array{encabezado: array<int, ?string>, filas: array<int, array<int, string>>, separador: string, codificacion: string}
     */
    private function leerCsv(): array
    {
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        if (isset($_FILES['archivo']['size']) && is_int($_FILES['archivo']['size']) && $_FILES['archivo']['size'] > self::TAMANO_MAXIMO_BYTES) {
            throw new ExcepcionNegocio('El archivo supera el tamaño máximo de ' . (self::TAMANO_MAXIMO_BYTES / 1048576) . ' MB.', 413);
        }
        $archivo = ArchivoSubido::leer('archivo', ['csv'], self::TAMANO_MAXIMO_BYTES);
        $contenido = (string) file_get_contents($archivo['rutaTemporal']);
        if (str_contains($contenido, "\0")) {
            throw new ExcepcionNegocio('El archivo no es un CSV de texto: en Excel usa «Guardar como» > «CSV».', 400);
        }

        $csv = LectorCsv::leer($contenido, true, true);
        if (implode('', array_map(fn (?string $c): string => trim((string) $c), $csv['encabezado'])) === '') {
            throw new ExcepcionNegocio('El archivo está vacío o no tiene encabezados.', 400);
        }
        if ($csv['filas'] === []) {
            throw new ExcepcionNegocio('El archivo no tiene filas de datos.', 400);
        }
        if (count($csv['filas']) > self::MAXIMO_FILAS) {
            throw new ExcepcionNegocio('El archivo supera el máximo de ' . self::MAXIMO_FILAS . ' filas.', 422);
        }
        return $csv;
    }

    /**
     * Posición => columna de la plantilla. Encabezados sin distinguir
     * mayúsculas, tildes ni espacios; desconocidos, excluidos (RN-1.6) y
     * reservados se ignoran con aviso. Sin columna documento: 400.
     *
     * @param array<int, ?string> $encabezado
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function mapearEncabezado(array $encabezado): array
    {
        $mapa = [];
        $avisos = [];
        foreach ($encabezado as $posicion => $titulo) {
            $original = trim((string) $titulo);
            if ($original === '') {
                continue;
            }
            $clave = self::normalizar($original);
            $clave = self::ALIAS[$clave] ?? $clave;
            if (in_array($clave, self::EXCLUIDAS, true)) {
                $avisos[] = "Columna «{$original}» ignorada: es un campo excluido del sistema (RN-1.6).";
            } elseif (in_array($clave, self::RESERVADAS, true)) {
                $avisos[] = "Columna «{$original}» ignorada: ese dato lo fija el sistema.";
            } elseif (!in_array($clave, self::COLUMNAS, true)) {
                $avisos[] = "Columna «{$original}» ignorada: no es una columna de la plantilla.";
            } elseif (in_array($clave, $mapa, true)) {
                $avisos[] = "Columna «{$original}» ignorada: «{$clave}» ya venía en otra columna.";
            } else {
                $mapa[$posicion] = $clave;
            }
        }
        if (!in_array('documento', $mapa, true)) {
            throw new ExcepcionNegocio('El encabezado no tiene la columna «documento». Usa la plantilla de carga masiva.', 400);
        }
        return [$mapa, $avisos];
    }

    /**
     * Valores de una fila por columna (recortados; vacío = null). Una fila
     * con más celdas que el encabezado es inválida; con menos, las que faltan
     * quedan vacías (Excel recorta las celdas vacías del final).
     *
     * @param array<int, string> $valores
     * @param array<int, string> $mapa
     */
    private function valoresDeFila(int $linea, array $valores, array $mapa, int $columnas): array
    {
        $datos = [];
        foreach ($mapa as $posicion => $clave) {
            $valor = isset($valores[$posicion]) ? (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $valores[$posicion]) : '';
            $datos[$clave] = $valor === '' ? null : $valor;
        }
        $fila = ['linea' => $linea, 'valores' => $datos, 'documento' => $datos['documento'] ?? null, 'nombre' => $datos['nombre'] ?? null];
        if (count($valores) > $columnas) {
            return $fila + ['clasificacion' => 'invalida', 'motivo' => 'La fila tiene ' . count($valores) . " celdas y el encabezado {$columnas}: revisa separadores o comillas."];
        }
        if ($fila['documento'] === null) {
            return $fila + ['clasificacion' => 'incompleta', 'faltantes' => ['documento'], 'motivo' => 'Falta el documento.'];
        }
        return $fila;
    }

    /**
     * Fila con documento nuevo: obligatorios (incluido el acudiente de un
     * menor y nombre/relación de un acudiente nuevo) y luego las reglas de
     * crear (modeloAlta, acudienteAlta).
     *
     * @param array<string, array<string, mixed>> $acudientes
     */
    private function clasificarNueva(array $fila, array $acudientes, string $correoAdministrativo): array
    {
        $v = $fila['valores'];
        $faltantes = array_values(array_filter(self::OBLIGATORIOS_ALTA, fn (string $campo): bool => ($v[$campo] ?? null) === null));
        $datos = $this->datosDeFila($v);

        $fecha = $datos['fecha_nacimiento'] ?? null;
        $esMenor = is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))
            && DeportistaService::edadEnFecha($fecha, self::hoy()) < DeportistaService::MAYORIA_EDAD;
        $acudiente = $v['acudiente_documento'] ?? null;
        if ($acudiente === null && $esMenor) {
            $faltantes[] = 'acudiente_documento';
        }
        if ($acudiente === null && array_filter(array_intersect_key($v, self::CAMPOS_ACUDIENTE)) !== []) {
            $faltantes[] = 'acudiente_documento';
        }
        if ($acudiente !== null && !isset($acudientes[$acudiente])) {
            foreach (['acudiente_nombre', 'acudiente_relacion'] as $campo) {
                if (($v[$campo] ?? null) === null) {
                    $faltantes[] = $campo;
                }
            }
        }
        $faltantes = array_values(array_unique($faltantes));
        if ($faltantes !== []) {
            return $fila + ['clasificacion' => 'incompleta', 'faltantes' => $faltantes, 'motivo' => 'Faltan campos obligatorios: ' . implode(', ', $faltantes) . '.'];
        }

        try {
            $modelo = $this->servicio->modeloAlta($datos, 'validado', $correoAdministrativo);
            $acudienteNuevo = $this->servicio->acudienteAlta($modelo, $datos['acudiente'] ?? null, $acudientes);
        } catch (ExcepcionNegocio $error) {
            return $fila + ['clasificacion' => 'invalida', 'motivo' => $error->getMessage()];
        }
        return $fila + ['clasificacion' => 'nueva', 'modelo' => $modelo, 'acudiente_nuevo' => $acudienteNuevo];
    }

    /**
     * Fila de un deportista existente: solo las celdas con valor, con las
     * reglas de actualizar (prepararCambios), y la lista de cambios campo por
     * campo (RN-1.9). Sin cambios: sin_cambios.
     *
     * @param array<string, array<string, mixed>> $acudientes
     */
    private function clasificarExistente(array $fila, array $actual, array $acudientes, string $correoAdministrativo): array
    {
        $datos = $this->datosDeFila($fila['valores']);
        unset($datos['documento']);
        if (isset($datos['acudiente']) && ($datos['acudiente']['documento'] ?? null) === $actual['acudiente_documento']) {
            // Mismo acudiente: solo se editan sus datos si la fila trae alguno.
            unset($datos['acudiente']['documento']);
            if ($datos['acudiente'] === []) {
                unset($datos['acudiente']);
            }
        }
        if (isset($datos['acudiente']['documento']) && !isset($acudientes[$datos['acudiente']['documento']])) {
            $faltantes = array_values(array_filter(['acudiente_nombre', 'acudiente_relacion'], fn (string $c): bool => ($fila['valores'][$c] ?? null) === null));
            if ($faltantes !== []) {
                return $fila + ['clasificacion' => 'incompleta', 'faltantes' => $faltantes, 'motivo' => 'Acudiente nuevo: faltan ' . implode(', ', $faltantes) . '.'];
            }
        }

        try {
            $preparado = $this->servicio->prepararCambios($actual, $datos, $correoAdministrativo, $acudientes);
        } catch (ExcepcionNegocio $error) {
            return $fila + ['clasificacion' => 'invalida', 'motivo' => $error->getMessage()];
        }

        $antes = DeportistaModel::fromArray($actual)->toArray();
        $despues = $preparado['modelo']->toArray();
        $cambios = [];
        foreach (self::CAMPOS_COMPARABLES as $campo) {
            if ($antes[$campo] != $despues[$campo] || ($antes[$campo] === null) !== ($despues[$campo] === null)) {
                $cambios[] = ['campo' => $campo, 'actual' => $antes[$campo], 'nuevo' => $despues[$campo]];
            }
        }
        if ($preparado['acudiente_editado'] !== null) {
            $acudienteAntes = $acudientes[$preparado['acudiente_editado']->obtenerDocumento()] ?? [];
            foreach ($preparado['acudiente_editado']->toArray() as $campo => $valor) {
                if ($campo !== 'documento' && ($acudienteAntes[$campo] ?? null) !== $valor) {
                    $cambios[] = ['campo' => "acudiente_{$campo}", 'actual' => $acudienteAntes[$campo] ?? null, 'nuevo' => $valor];
                }
            }
        }
        if ($cambios === []) {
            return $fila + ['clasificacion' => 'sin_cambios', 'modelo' => $preparado['modelo']];
        }
        return $fila + [
            'clasificacion' => 'actualizar',
            'cambios' => $cambios,
            'modelo' => $preparado['modelo'],
            'acudiente_nuevo' => $preparado['acudiente_nuevo'],
            'cambios_preparados' => $preparado,
        ];
    }

    /**
     * RN-1.10: un correo compartido por dos o más deportistas ADULTOS
     * distintos (filas aplicables del archivo y deportistas de la base que el
     * archivo no reemplaza) es conflicto: se rechazan las filas del archivo
     * con ese correo. Los menores no cuentan.
     *
     * @param array<int, array<string, mixed>> $filas
     * @param array<int, string> $correos
     * @return array<int, array<string, mixed>>
     */
    private function marcarConflictosDeCorreo(array $filas, array $correos): array
    {
        $hoy = self::hoy();
        $aplicables = [];
        $duenos = [];
        foreach ($filas as $i => $fila) {
            if (!in_array($fila['clasificacion'], ['nueva', 'actualizar', 'sin_cambios'], true)) {
                continue;
            }
            $modelo = $fila['modelo'];
            $aplicables[$modelo->obtenerDocumento()] = true;
            $correo = $modelo->obtenerCorreo();
            if ($correo !== null && DeportistaService::edadEnFecha($modelo->obtenerFechaNacimiento(), $hoy) >= DeportistaService::MAYORIA_EDAD) {
                $duenos[mb_strtolower($correo)][$modelo->obtenerDocumento()] = $i;
            }
        }
        foreach ($this->deportista->listarPorCorreos($correos) as $otro) {
            $documento = (string) $otro['documento'];
            if (!isset($aplicables[$documento]) && DeportistaService::edadEnFecha($otro['fecha_nacimiento'], $hoy) >= DeportistaService::MAYORIA_EDAD) {
                $duenos[mb_strtolower((string) $otro['correo'])][$documento] = null;
            }
        }
        foreach ($duenos as $correo => $documentos) {
            if (count($documentos) < 2) {
                continue;
            }
            foreach ($documentos as $documento => $indice) {
                if ($indice === null) {
                    continue;
                }
                $otros = implode(', ', array_map('strval', array_diff(array_keys($documentos), [$documento])));
                $filas[$indice]['clasificacion'] = 'conflicto_correo';
                $filas[$indice]['motivo'] = "El correo {$correo} también es de otro deportista adulto sin relación de tutoría (documento {$otros}). Corrige uno de los dos.";
            }
        }
        return $filas;
    }

    /**
     * Valores del CSV => datos en el formato de crear/actualizar: fechas
     * DD/MM/AAAA a AAAA-MM-DD, decimales con coma, sexo M/F, autorización
     * sí/no, estado en minúsculas y el objeto acudiente.
     *
     * @param array<string, ?string> $v
     * @return array<string, mixed>
     */
    private function datosDeFila(array $v): array
    {
        $datos = [];
        foreach (['documento', 'nombre', 'lugar_nacimiento', 'telefono', 'direccion', 'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento', 'grado_actual'] as $campo) {
            if (($v[$campo] ?? null) !== null) {
                $datos[$campo] = $v[$campo];
            }
        }
        foreach (['fecha_nacimiento', 'fecha_ingreso'] as $campo) {
            if (($v[$campo] ?? null) !== null) {
                $datos[$campo] = preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v[$campo], $p) === 1 ? sprintf('%04d-%02d-%02d', $p[3], $p[2], $p[1]) : $v[$campo];
            }
        }
        foreach (['estatura', 'peso'] as $campo) {
            if (($v[$campo] ?? null) !== null) {
                $datos[$campo] = preg_match('/^\d+,\d+$/', $v[$campo]) === 1 ? str_replace(',', '.', $v[$campo]) : $v[$campo];
            }
        }
        if (($v['sexo'] ?? null) !== null) {
            $sexo = mb_strtolower($v['sexo']);
            $datos['sexo'] = ['m' => 'masculino', 'f' => 'femenino'][$sexo] ?? $sexo;
        }
        if (($v['estado'] ?? null) !== null) {
            $datos['estado'] = mb_strtolower($v['estado']);
        }
        if (($v['autorizacion_datos'] ?? null) !== null) {
            $valor = self::normalizar($v['autorizacion_datos']);
            $datos['autorizacion_datos'] = match (true) {
                in_array($valor, ['si', 's', '1', 'x', 'true', 'verdadero'], true) => true,
                in_array($valor, ['no', 'n', '0', 'false', 'falso'], true) => false,
                default => $v['autorizacion_datos'],
            };
        }
        if (($v['acudiente_documento'] ?? null) !== null) {
            $datos['acudiente'] = ['documento' => $v['acudiente_documento']];
            foreach (self::CAMPOS_ACUDIENTE as $columna => $campo) {
                if (($v[$columna] ?? null) !== null) {
                    $datos['acudiente'][$campo] = $v[$columna];
                }
            }
        }
        return $datos;
    }

    /**
     * Lo que se devuelve de cada fila (sin los modelos internos).
     */
    private function formatearFila(array $fila): array
    {
        $salida = ['linea' => $fila['linea'], 'documento' => $fila['documento'], 'nombre' => $fila['nombre'], 'clasificacion' => $fila['clasificacion']];
        foreach (['cambios', 'faltantes', 'motivo'] as $clave) {
            if (isset($fila[$clave])) {
                $salida[$clave] = $fila[$clave];
            }
        }
        return $salida;
    }

    /**
     * @return array<string, true>
     */
    private function confirmados(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = preg_split('/[,;\s]+/', $valor, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($valor)) {
            throw new ExcepcionNegocio('"confirmar_actualizacion" debe ser una lista de documentos.', 400);
        }
        $resultado = [];
        foreach ($valor as $documento) {
            if (!is_string($documento) || trim($documento) === '') {
                throw new ExcepcionNegocio('"confirmar_actualizacion" debe ser una lista de documentos.', 400);
            }
            $resultado[trim($documento)] = true;
        }
        return $resultado;
    }

    private function correoAdministrativo(): string
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesión para continuar.', 401);
        }
        if ($usuario['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio('Solo el ámbito administrativo puede cargar deportistas por CSV.', 403);
        }
        return $usuario['identificador'];
    }

    /** Fecha de hoy en Bogotá (AAAA-MM-DD), sin depender de la zona por defecto. */
    private static function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    }

    /** "Fecha de Nacimiento" => "fecha_de_nacimiento". */
    private static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $texto), '_');
    }
}
