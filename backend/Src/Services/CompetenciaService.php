<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Competencias (RN-3.x; Contexto Base
 * §35.7).
 *
 *   - Un torneo = un evento de calendario de tipo competencia + su fila en
 *     `competencia`. No se eliminan torneos: "eliminar" aquí es quitar a un
 *     participante (RN-3.9).
 *   - Los participantes se inscriben ANTES; resultado, puesto, medalla y
 *     combates se llenan DESPUÉS (nulos mientras tanto) y no se pueden cargar
 *     en un torneo con fecha futura (hoy de Bogotá).
 *   - Categoría de edad sugerida por la edad a la FECHA DEL TORNEO y
 *     categoría de peso sugerida por el peso registrado (tabla PROPUESTA),
 *     ambas siempre editables (RN-3.8); la de peso solo en modalidades de
 *     combate. El cálculo es el de DeportistaService, no se duplica.
 *   - Puesto: lo digita el staff, 1 a 99, puede repetirse; la medalla es
 *     independiente del puesto.
 *   - Ámbito público: torneos públicos y, dentro de uno, solo su propia
 *     participación, nunca notas_oponente.
 *   - El récord acumulado de combates lo expone Deportistas (consultar).
 */
class CompetenciaService
{
    private const ZONA_HORARIA = 'America/Bogota';
    /** Orden del ENUM; público para Estadística (resumen de un torneo). */
    public const MODALIDADES = ['combate_individual', 'combate_equipos', 'defensa_personal', 'salto_alto', 'salto_largo', 'formas'];
    private const MODALIDADES_COMBATE = ['combate_individual', 'combate_equipos'];
    public const TIPOS_TORNEO = ['nacional', 'internacional', 'departamental'];
    private const MEDALLAS = ['oro', 'plata', 'bronce', 'ninguna'];
    /**
     * Nombre de la categoría por edad de DeportistaService (para mostrar) =>
     * valor del ENUM participacion_competencia.categoria_edad (sin tildes).
     */
    private const CATEGORIA_EDAD_ENUM = [
        'Infantil' => 'infantil', 'Junior' => 'junior', 'Juvenil' => 'juvenil',
        'Mayores' => 'mayores', 'Sénior' => 'senior', 'Máster' => 'master',
    ];
    private const MAXIMO_PARTICIPANTES_LOTE = 50;
    private const MAXIMO_PLATAFORMA = 50;
    private const MAXIMO_CATEGORIA_PESO = 30;
    private const MAXIMO_RESULTADO = 255;
    private const PUESTO_MINIMO = 1;
    private const PUESTO_MAXIMO = 99;
    private const COMBATES_MAXIMO = 127;
    /** PROPUESTA (RN-3.8, extra): exceso sobre el corte inferior que se sugiere recortar. */
    private const MARGEN_RECOMENDACION_PESO_KG = 2.5;
    /** Campos de resultado: no se cargan en un torneo con fecha futura. */
    private const CAMPOS_RESULTADO = ['puesto', 'resultado', 'medalla', 'combates_ganados', 'combates_perdidos'];
    private const CAMPOS_PARTICIPACION = ['categoria_edad', 'categoria_peso', 'puesto', 'resultado', 'medalla', 'combates_ganados', 'combates_perdidos', 'notas_oponente'];
    private const CAMPOS_EVENTO = ['nombre', 'fecha', 'lugar', 'visibilidad', 'observaciones'];

    private CompetenciaRepository $competencia;
    private ParticipacionCompetenciaRepository $participacion;
    private EventoCalendarioRepository $evento;
    private DeportistaRepository $deportista;

    public function __construct()
    {
        $this->competencia = new CompetenciaRepository();
        $this->participacion = new ParticipacionCompetenciaRepository();
        $this->evento = new EventoCalendarioRepository();
        $this->deportista = new DeportistaRepository();
    }

    // ------------------------------------------------------------------
    // Torneos
    // ------------------------------------------------------------------

    /**
     * Torneos con filtros opcionales deportista, desde, hasta, lugar y
     * tipo_torneo, por fecha descendente, con num_participantes. El público
     * solo ve torneos públicos y un indicador "participa"; solo puede filtrar
     * por su propio documento. Una sola consulta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $usuario = $this->usuario();
        $esPublico = $usuario['ambito'] !== 'administrativa';
        $deportista = array_key_exists('deportista', $parametros) ? $this->documento($parametros['deportista'], 'deportista') : null;
        if ($esPublico && $deportista !== null && $deportista !== $usuario['identificador']) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        $desde = array_key_exists('desde', $parametros) ? $this->fecha($parametros['desde'], 'desde') : null;
        $hasta = array_key_exists('hasta', $parametros) ? $this->fecha($parametros['hasta'], 'hasta') : null;
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            throw new ExcepcionNegocio('"desde" no puede ser posterior a "hasta".', 400);
        }
        $lugar = array_key_exists('lugar', $parametros) ? Validador::texto($parametros['lugar'], 'lugar', 150, true) : null;
        $tipoTorneo = array_key_exists('tipo_torneo', $parametros) ? $this->opcion($parametros['tipo_torneo'], 'tipo_torneo', self::TIPOS_TORNEO) : null;

        $filas = $this->competencia->listarTorneos(
            $deportista, $desde, $hasta, $lugar, $tipoTorneo,
            $esPublico ? 'publica' : null,
            $esPublico ? $usuario['identificador'] : null
        );
        return array_map(function (array $fila) use ($esPublico): array {
            $torneo = $this->formatearTorneo($fila);
            if ($esPublico) {
                $torneo['participa'] = (bool) $fila['participa'];
            }
            return $torneo;
        }, $filas);
    }

    /**
     * Un torneo (?id= del evento) con sus participantes. Administrativo:
     * todos, con notas_oponente. Público: solo torneos públicos y solo su
     * propia participación, sin notas_oponente. null si no existe o no es
     * visible (el Controller responde 404).
     */
    public function consultar(array $parametros): ?array
    {
        $usuario = $this->usuario();
        $fila = $this->competencia->obtenerTorneo($this->idDe($parametros, 'id'));
        $esPublico = $usuario['ambito'] !== 'administrativa';
        if ($fila === null || ($esPublico && $fila['visibilidad'] !== 'publica')) {
            return null;
        }
        $participantes = $this->participacion->listarConDeportista((int) $fila['evento_id'], $esPublico ? $usuario['identificador'] : null);
        return $this->formatearTorneo($fila) + [
            'participantes' => array_map(fn (array $p): array => $this->formatearParticipacion($p, !$esPublico), $participantes),
        ];
    }

    /**
     * Próximo torneo (fecha >= hoy de Bogotá) con nombre, fecha y
     * num_participantes, o null (tarjetas 1 y 2 del dashboard).
     */
    public function proximo(): ?array
    {
        $this->exigirAdministrativo('ver el próximo torneo');
        $fila = $this->competencia->proximoTorneo($this->hoy());
        return $fila === null ? null : $this->formatearTorneo($fila);
    }

    /**
     * Crea un torneo (solo administrativo), en uno de dos modos:
     *   a) evento_id de un evento existente de tipo competencia sin competencia;
     *   b) EXCEPCIONAL: objeto "evento" nuevo (tipo competencia forzado), en
     *      una transacción con la fila de competencia: nada queda a medias.
     * Enviar los dos modos a la vez: 422.
     */
    public function crear(array $datos): array
    {
        $this->exigirAdministrativo('crear torneos');
        $this->rechazarCamposNoPermitidos($datos, ['evento_id', 'evento', 'plataforma', 'tipo_torneo']);
        $tieneEventoId = array_key_exists('evento_id', $datos);
        $tieneEvento = array_key_exists('evento', $datos);
        if ($tieneEventoId && $tieneEvento) {
            throw new ExcepcionNegocio('Envía "evento_id" (evento existente) o "evento" (creación excepcional), no los dos.', 422);
        }
        if (!$tieneEventoId && !$tieneEvento) {
            throw new ExcepcionNegocio('Falta "evento_id" o "evento".', 400);
        }
        $plataforma = Validador::texto($datos['plataforma'] ?? null, 'plataforma', self::MAXIMO_PLATAFORMA, false);
        $tipoTorneo = $this->opcion($datos['tipo_torneo'] ?? null, 'tipo_torneo', self::TIPOS_TORNEO);

        if ($tieneEventoId) {
            $eventoId = $this->entero($datos['evento_id'], 'evento_id', 1, PHP_INT_MAX);
            $evento = $this->evento->obtenerPorId($eventoId);
            if ($evento === null) {
                throw new ExcepcionNegocio('Evento no encontrado.', 404);
            }
            if ($evento['tipo'] !== 'competencia') {
                throw new ExcepcionNegocio('El evento no es de tipo competencia.', 422);
            }
            if ($this->competencia->obtenerPorId($eventoId) !== null) {
                throw new ExcepcionNegocio('Ese evento ya tiene su torneo creado.', 409);
            }
            $this->guardarSinDuplicados(fn () => $this->competencia->crear(new CompetenciaModel($eventoId, $plataforma, $tipoTorneo)), 'Ese evento ya tiene su torneo creado.');
            return $this->consultar(['id' => (string) $eventoId]);
        }

        $modelo = $this->eventoNuevo($datos['evento']);
        if ($this->evento->existeNombreFecha($modelo->obtenerNombre(), $modelo->obtenerFecha())) {
            throw new ExcepcionNegocio('Ya existe un evento con ese nombre en esa fecha.', 409);
        }
        $eventoId = $this->competencia->enTransaccion(function () use ($modelo, $plataforma, $tipoTorneo): int {
            $id = $this->evento->crear($modelo);
            $this->competencia->crear(new CompetenciaModel($id, $plataforma, $tipoTorneo));
            return $id;
        });
        return $this->consultar(['id' => (string) $eventoId]);
    }

    /**
     * Actualiza solo plataforma y tipo_torneo (los datos del evento se editan
     * en Calendario).
     */
    public function actualizar(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('actualizar torneos');
        $eventoId = $this->idDe($parametros, 'id');
        $fila = $this->competencia->obtenerPorId($eventoId);
        if ($fila === null) {
            throw new ExcepcionNegocio('Torneo no encontrado.', 404);
        }
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, ['plataforma', 'tipo_torneo'], 'Solo se editan plataforma y tipo_torneo; los datos del evento se editan en Calendario.');

        $modelo = CompetenciaModel::fromArray($fila);
        if (array_key_exists('plataforma', $datos)) {
            $modelo->establecerPlataforma(Validador::texto($datos['plataforma'], 'plataforma', self::MAXIMO_PLATAFORMA, false));
        }
        if (array_key_exists('tipo_torneo', $datos)) {
            $modelo->establecerTipoTorneo($this->opcion($datos['tipo_torneo'], 'tipo_torneo', self::TIPOS_TORNEO));
        }
        $this->competencia->actualizar($modelo);
        return $this->consultar(['id' => (string) $eventoId]);
    }

    // ------------------------------------------------------------------
    // Participantes
    // ------------------------------------------------------------------

    /**
     * Inscribe participantes (solo administrativo): competencia_evento_id +
     * participantes[] (máximo 50) con deportista_documento, modalidad y,
     * opcionalmente, categoria_edad y categoria_peso (si faltan, las
     * sugeridas). Todo o nada; un duplicado (deportista + modalidad ya
     * inscrito, o repetido en el lote) da 409 indicando cuál.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inscribir(array $datos): array
    {
        $this->exigirAdministrativo('inscribir participantes');
        $this->rechazarCamposNoPermitidos($datos, ['competencia_evento_id', 'participantes']);
        $eventoId = $this->entero($datos['competencia_evento_id'] ?? null, 'competencia_evento_id', 1, PHP_INT_MAX);
        $torneo = $this->torneoExistente($eventoId);

        $lista = $datos['participantes'] ?? null;
        if (!is_array($lista) || $lista === [] || !array_is_list($lista)) {
            throw new ExcepcionNegocio('El campo "participantes" debe ser una lista con al menos un participante.', 400);
        }
        if (count($lista) > self::MAXIMO_PARTICIPANTES_LOTE) {
            throw new ExcepcionNegocio('Máximo 50 participantes por inscripción.', 400);
        }

        $modelos = [];
        $vistos = [];
        foreach ($lista as $indice => $participante) {
            if (!is_array($participante)) {
                throw new ExcepcionNegocio('Cada participante debe ser un objeto.', 400);
            }
            $this->rechazarCamposNoPermitidos($participante, ['deportista_documento', 'modalidad', 'categoria_edad', 'categoria_peso']);
            $documento = $this->documento($participante['deportista_documento'] ?? null, 'deportista_documento');
            $modalidad = $this->opcion($participante['modalidad'] ?? null, 'modalidad', self::MODALIDADES);
            $deportista = $this->deportista->obtenerPorId($documento);
            if ($deportista === null) {
                throw new ExcepcionNegocio("Deportista {$documento} no encontrado.", 404);
            }

            $clave = "{$documento}|{$modalidad}";
            if (isset($vistos[$clave]) || $this->participacion->obtenerPorId($documento, $eventoId, $modalidad) !== null) {
                throw new ExcepcionNegocio("El deportista {$documento} ya está inscrito en {$modalidad} en este torneo.", 409);
            }
            $vistos[$clave] = true;

            $sugerida = self::sugerirCategorias($deportista, $torneo['fecha'], $modalidad);
            $categoriaEdad = array_key_exists('categoria_edad', $participante)
                ? $this->opcion($participante['categoria_edad'], 'categoria_edad', array_values(self::CATEGORIA_EDAD_ENUM))
                : $sugerida['categoria_edad_sugerida'];
            if ($categoriaEdad === null) {
                throw new ExcepcionNegocio("El deportista {$documento} no tiene categoría de edad sugerida (menor de 8 años a la fecha del torneo): envía categoria_edad.", 422);
            }
            $categoriaPeso = array_key_exists('categoria_peso', $participante)
                ? Validador::texto($participante['categoria_peso'], 'categoria_peso', self::MAXIMO_CATEGORIA_PESO, false)
                : $sugerida['categoria_peso_sugerida'];

            $modelos[$indice] = new ParticipacionCompetenciaModel(
                deportistaDocumento: $documento,
                competenciaEventoId: $eventoId,
                modalidad: $modalidad,
                categoriaEdad: $categoriaEdad,
                categoriaPeso: $categoriaPeso,
            );
        }

        $this->guardarSinDuplicados(function () use ($modelos): void {
            $this->participacion->enTransaccion(function () use ($modelos): void {
                foreach ($modelos as $modelo) {
                    $this->participacion->crear($modelo);
                }
            });
        }, 'Uno de los participantes ya estaba inscrito en esa modalidad. No se inscribió a nadie.');

        return array_values(array_map(
            fn (ParticipacionCompetenciaModel $m): array => $this->formatearParticipacion(
                $this->participacion->obtenerPorId($m->obtenerDeportistaDocumento(), $eventoId, $m->obtenerModalidad()) + ['nombre' => null],
                true
            ),
            $modelos
        ));
    }

    /**
     * Sugerencia para el formulario de inscripción (solo lectura):
     * ?deportista_documento=&competencia_evento_id=[&modalidad=].
     */
    public function sugerencia(array $parametros): array
    {
        $this->exigirAdministrativo('consultar sugerencias de categoría');
        $documento = $this->documento($parametros['deportista_documento'] ?? null, 'deportista_documento');
        $torneo = $this->torneoExistente($this->entero($parametros['competencia_evento_id'] ?? null, 'competencia_evento_id', 1, PHP_INT_MAX));
        $modalidad = array_key_exists('modalidad', $parametros) ? $this->opcion($parametros['modalidad'], 'modalidad', self::MODALIDADES) : null;
        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        return self::sugerirCategorias($deportista, $torneo['fecha'], $modalidad);
    }

    /**
     * Actualiza una participación por su llave (?deportista_documento=
     * &competencia_evento_id=&modalidad=). Parcial: lo no enviado no se toca;
     * null explícito limpia el campo. La modalidad no se cambia (se quita y
     * se vuelve a inscribir).
     */
    public function actualizarParticipacion(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('actualizar participaciones');
        [$documento, $eventoId, $modalidad] = $this->llaveParticipacion($parametros);
        $fila = $this->participacion->obtenerPorId($documento, $eventoId, $modalidad);
        if ($fila === null) {
            throw new ExcepcionNegocio('Participación no encontrada.', 404);
        }
        if (array_key_exists('modalidad', $datos)) {
            throw new ExcepcionNegocio('La modalidad no se cambia: quita al participante y vuelve a inscribirlo.', 400);
        }
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_PARTICIPACION);

        $esCombate = in_array($modalidad, self::MODALIDADES_COMBATE, true);
        $torneo = $this->torneoExistente($eventoId);
        $cargaResultados = array_filter(self::CAMPOS_RESULTADO, fn (string $campo): bool => array_key_exists($campo, $datos) && $datos[$campo] !== null) !== [];
        if ($cargaResultados && $torneo['fecha'] > $this->hoy()) {
            throw new ExcepcionNegocio('El torneo todavía no se ha realizado: los resultados se cargan después de la fecha del torneo.', 422);
        }

        $modelo = ParticipacionCompetenciaModel::fromArray($fila);
        if (array_key_exists('categoria_edad', $datos)) {
            $modelo->establecerCategoriaEdad($this->opcion($datos['categoria_edad'], 'categoria_edad', array_values(self::CATEGORIA_EDAD_ENUM)));
        }
        if (array_key_exists('categoria_peso', $datos)) {
            $modelo->establecerCategoriaPeso(Validador::texto($datos['categoria_peso'], 'categoria_peso', self::MAXIMO_CATEGORIA_PESO, false));
        }
        if (array_key_exists('puesto', $datos)) {
            $modelo->establecerPuesto($this->enteroONulo($datos['puesto'], 'puesto', self::PUESTO_MINIMO, self::PUESTO_MAXIMO));
        }
        if (array_key_exists('resultado', $datos)) {
            $modelo->establecerResultado(Validador::texto($datos['resultado'], 'resultado', self::MAXIMO_RESULTADO, false));
        }
        if (array_key_exists('medalla', $datos)) {
            $modelo->establecerMedalla($datos['medalla'] === null ? null : $this->opcion($datos['medalla'], 'medalla', self::MEDALLAS));
        }
        foreach (['combates_ganados' => 'establecerCombatesGanados', 'combates_perdidos' => 'establecerCombatesPerdidos'] as $campo => $metodo) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }
            if ($datos[$campo] !== null && !$esCombate) {
                throw new ExcepcionNegocio("\"{$campo}\" solo aplica a las modalidades de combate.", 422);
            }
            $modelo->{$metodo}($this->enteroONulo($datos[$campo], $campo, 0, self::COMBATES_MAXIMO));
        }
        if (array_key_exists('notas_oponente', $datos)) {
            $modelo->establecerNotasOponente($this->textoLibre($datos['notas_oponente'], 'notas_oponente'));
        }

        $this->participacion->actualizar($modelo);
        return $this->formatearParticipacion($this->participacion->obtenerPorId($documento, $eventoId, $modalidad) + ['nombre' => null], true);
    }

    /**
     * Quita a un participante (retirado o no asistió) por su llave.
     */
    public function quitarParticipante(array $parametros): void
    {
        $this->exigirAdministrativo('quitar participantes');
        [$documento, $eventoId, $modalidad] = $this->llaveParticipacion($parametros);
        if ($this->participacion->obtenerPorId($documento, $eventoId, $modalidad) === null) {
            throw new ExcepcionNegocio('Participación no encontrada.', 404);
        }
        $this->participacion->eliminar($documento, $eventoId, $modalidad);
    }

    // ------------------------------------------------------------------
    // Cálculo puro (reutiliza DeportistaService)
    // ------------------------------------------------------------------

    /**
     * Sugerencias de categoría para un deportista en un torneo: edad a la
     * fecha del torneo, categoría de edad (valor del ENUM), peso registrado y
     * categoría de peso (solo combate; sin modalidad también se sugiere).
     * Incluye recomendacion_peso (PROPUESTA).
     *
     * @param array<string, mixed> $deportista fila con fecha_nacimiento, sexo y peso
     */
    public static function sugerirCategorias(array $deportista, string $fechaTorneo, ?string $modalidad): array
    {
        $edad = DeportistaService::edadEnFecha($deportista['fecha_nacimiento'], $fechaTorneo);
        $categoriaNombre = DeportistaService::categoriaPorEdad($edad);
        $peso = (float) ($deportista['peso'] ?? 0);
        $aplicaPeso = $modalidad === null || in_array($modalidad, self::MODALIDADES_COMBATE, true);

        return [
            'edad_a_la_fecha' => $edad,
            'categoria_edad_sugerida' => $categoriaNombre === null ? null : self::CATEGORIA_EDAD_ENUM[$categoriaNombre],
            'peso_registrado' => $peso > 0 ? $peso : null,
            'categoria_peso_sugerida' => $aplicaPeso ? DeportistaService::categoriaPorPeso($categoriaNombre, (string) $deportista['sexo'], $peso) : null,
            'recomendacion_peso' => $aplicaPeso ? self::recomendacionPeso($categoriaNombre, (string) $deportista['sexo'], $peso) : null,
        ];
    }

    /**
     * PROPUESTA (RN-3.8, extra; no bloquea nada): mensaje según cuánto supera
     * el peso al corte de la categoría inmediatamente inferior. Si lo supera
     * por poco (0 < exceso <= 2,5 kg), sugiere recortar para bajar a esa
     * categoría; si lo supera por más, sugiere mantener el peso para no subir
     * de categoría. null si no hay categoría inferior o no hay datos.
     */
    public static function recomendacionPeso(?string $categoriaEdad, string $sexo, float $peso): ?string
    {
        $cortes = DeportistaService::cortesDePeso($categoriaEdad, $sexo);
        if ($cortes === null || $peso <= 0) {
            return null;
        }
        $inferior = null;
        foreach ($cortes as $corte) {
            if ($corte < $peso) {
                $inferior = $corte;
            }
        }
        if ($inferior === null) {
            return null;
        }
        $exceso = round($peso - $inferior, 2);
        $texto = number_format($exceso, 1, ',', '');
        if ($exceso <= self::MARGEN_RECOMENDACION_PESO_KG) {
            return "Supera el corte de -{$inferior} kg por {$texto} kg: puede recortar para competir en -{$inferior}.";
        }
        return "Supera el corte de -{$inferior} kg por {$texto} kg: se recomienda mantener el peso para no subir de categoría.";
    }

    // ------------------------------------------------------------------
    // Apoyo y validación
    // ------------------------------------------------------------------

    /**
     * @return array{ambito: string, identificador: string}
     */
    private function usuario(): array
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesión para continuar.', 401);
        }
        return $usuario;
    }

    private function exigirAdministrativo(string $accion): void
    {
        if ($this->usuario()['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio("Solo el ámbito administrativo puede {$accion}.", 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function torneoExistente(int $eventoId): array
    {
        $torneo = $this->competencia->obtenerTorneo($eventoId);
        if ($torneo === null) {
            throw new ExcepcionNegocio('Torneo no encontrado.', 404);
        }
        return $torneo;
    }

    /**
     * Valida el objeto "evento" de la creación excepcional con las reglas de
     * CalendarioService (tipo competencia forzado).
     */
    private function eventoNuevo(mixed $evento): EventoCalendarioModel
    {
        if (!is_array($evento) || $evento === [] || array_is_list($evento)) {
            throw new ExcepcionNegocio('El campo "evento" debe ser un objeto con nombre, fecha, lugar, visibilidad y observaciones.', 400);
        }
        if (array_key_exists('tipo', $evento) && $evento['tipo'] !== 'competencia') {
            throw new ExcepcionNegocio('El evento de un torneo siempre es de tipo competencia.', 422);
        }
        unset($evento['tipo']);
        $this->rechazarCamposNoPermitidos($evento, self::CAMPOS_EVENTO);
        return (new CalendarioService())->validarEventoNuevo(['tipo' => 'competencia'] + $evento);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function llaveParticipacion(array $parametros): array
    {
        return [
            $this->documento($parametros['deportista_documento'] ?? null, 'deportista_documento'),
            $this->entero($parametros['competencia_evento_id'] ?? null, 'competencia_evento_id', 1, PHP_INT_MAX),
            $this->opcion($parametros['modalidad'] ?? null, 'modalidad', self::MODALIDADES),
        ];
    }

    private function formatearTorneo(array $fila): array
    {
        return [
            'evento_id' => (int) $fila['evento_id'],
            'nombre' => $fila['nombre'],
            'fecha' => $fila['fecha'],
            'lugar' => $fila['lugar'],
            'visibilidad' => $fila['visibilidad'],
            'observaciones' => $fila['observaciones'],
            'plataforma' => $fila['plataforma'],
            'tipo_torneo' => $fila['tipo_torneo'],
            'num_participantes' => (int) $fila['num_participantes'],
        ];
    }

    /**
     * Forma de una participación; notas_oponente solo para el administrativo.
     */
    private function formatearParticipacion(array $fila, bool $conNotas): array
    {
        $datos = ParticipacionCompetenciaModel::fromArray($fila)->toArray();
        $resultado = ['nombre' => $fila['nombre'] ?? null] + $datos;
        if ($resultado['nombre'] === null) {
            unset($resultado['nombre']);
        }
        if (!$conNotas) {
            unset($resultado['notas_oponente']);
        }
        return $resultado;
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }

    private function documento(mixed $valor, string $campo): string
    {
        return Validador::texto($valor, $campo, 20, true);
    }

    private function fecha(mixed $valor, string $campo): string
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        return $valor;
    }

    /**
     * @param array<int, string> $opciones
     */
    private function opcion(mixed $valor, string $campo, array $opciones): string
    {
        if (!is_string($valor) || !in_array($valor, $opciones, true)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser uno de: " . implode(', ', $opciones) . '.', 400);
        }
        return $valor;
    }

    /**
     * Entero (o texto de dígitos, como en la URL) dentro de [min, max].
     */
    private function entero(mixed $valor, string $campo, int $minimo, int $maximo): int
    {
        if (is_string($valor) && ctype_digit($valor)) {
            $valor = (int) $valor;
        }
        if (!is_int($valor) || $valor < $minimo || $valor > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un número entero entre {$minimo} y {$maximo}.", 400);
        }
        return $valor;
    }

    private function enteroONulo(mixed $valor, string $campo, int $minimo, int $maximo): ?int
    {
        return $valor === null ? null : $this->entero($valor, $campo, $minimo, $maximo);
    }

    private function idDe(array $parametros, string $campo): int
    {
        return $this->entero($parametros[$campo] ?? null, $campo, 1, PHP_INT_MAX);
    }

    private function textoLibre(mixed $valor, string $campo): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (!is_string($valor)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser texto.", 400);
        }
        return trim($valor);
    }

    /**
     * @param array<int, string> $permitidos
     */
    private function rechazarCamposNoPermitidos(array $datos, array $permitidos, ?string $mensaje = null): void
    {
        $sobrantes = array_diff(array_keys($datos), $permitidos);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio(($mensaje ?? 'Campos no permitidos') . ' (' . implode(', ', $sobrantes) . ').', 400);
        }
    }

    /**
     * Convierte una llave duplicada creada al mismo tiempo (SQLSTATE 23000)
     * en un 409.
     */
    private function guardarSinDuplicados(callable $escritura, string $mensaje): void
    {
        try {
            $escritura();
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new ExcepcionNegocio($mensaje, 409);
            }
            throw $error;
        }
    }
}
