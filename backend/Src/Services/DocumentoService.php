<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Documentos (RN-6.1, RN-6.3, RN-6.8;
 * Contexto Base §35.9).
 *
 * Documentos del deportista (tabla `documento`), en tipos fijos (RN-6.1):
 *   - Un solo archivo por tipo: documento_identidad, formulario_inscripcion,
 *     documento_acudiente y fotografia. Uno nuevo del mismo tipo se carga
 *     con "reemplazar" (solo administrativo), que borra el anterior del disco.
 *   - Varios archivos por tipo: consentimiento, certificado_ascenso,
 *     competencia, preparacion_fisica y otro (carga solo administrativa).
 *   - Ámbito público: ve y descarga solo lo suyo; sube solo los tipos de
 *     archivo único que aún están vacíos; no reemplaza ni elimina. Un
 *     documento ajeno responde 404 (no revela si existe).
 *   - Faltantes: se calculan, no se guardan. Críticos: identidad, formulario
 *     y, en menores, el del acudiente; la fotografía es no crítica.
 *
 * Documentos generales (tabla `documento_general`, RN-6.8): lectura para los
 * dos ámbitos; subir, reemplazar y eliminar solo administrativo.
 *
 * Los archivos se guardan en backend/Almacenamiento/ (fuera de la carpeta
 * pública, con .htaccess que niega el acceso) con un nombre aleatorio; ese
 * nombre nunca sale en las respuestas. La descarga entrega nombre_original.
 */
class DocumentoService
{
    /** Tamaño máximo por archivo (RN-6.3): valor inicial, modificable aquí. */
    private const TAMANO_MAXIMO_BYTES = 10 * 1024 * 1024;

    /**
     * Extensiones permitidas por tipo de documento del deportista (RN-6.3).
     * Las claves son los nueve tipos fijos de RN-6.1.
     */
    private const EXTENSIONES_POR_TIPO = [
        'documento_identidad' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'formulario_inscripcion' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'documento_acudiente' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'fotografia' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'consentimiento' => ['pdf', 'doc', 'docx'],
        'certificado_ascenso' => ['pdf', 'doc', 'docx'],
        'competencia' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'preparacion_fisica' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'otro' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
    ];

    /** Extensiones permitidas para los documentos generales del club. */
    private const EXTENSIONES_GENERALES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];

    /** Tipos con un solo archivo por deportista (los demás acumulan). */
    private const TIPOS_UNICOS = ['documento_identidad', 'formulario_inscripcion', 'documento_acudiente', 'fotografia'];
    /** Tipos que el registrante carga durante el autorregistro (los de archivo único). */
    public const TIPOS_AUTORREGISTRO = self::TIPOS_UNICOS;

    private const MAYORIA_EDAD = 18;
    private const MAXIMO_NOMBRE_ORIGINAL = 150;
    private const MAXIMO_TITULO = 150;
    private const ZONA_HORARIA = 'America/Bogota';

    private const CARPETA_DOCUMENTOS = __DIR__ . '/../../Almacenamiento/documentos';
    private const CARPETA_GENERALES = __DIR__ . '/../../Almacenamiento/generales';

    private DocumentoRepository $documento;
    private DocumentoGeneralRepository $documentoGeneral;
    private DeportistaRepository $deportista;

    public function __construct()
    {
        $this->documento = new DocumentoRepository();
        $this->documentoGeneral = new DocumentoGeneralRepository();
        $this->deportista = new DeportistaRepository();
    }

    // ------------------------------------------------------------------
    // Documentos del deportista
    // ------------------------------------------------------------------

    /**
     * Documentos de un deportista (?documento=). El público solo los suyos:
     * sin ?documento= usa el de su sesión; con uno ajeno responde 404.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $usuario = $this->usuario();
        if ($usuario['ambito'] === 'publica') {
            $documento = array_key_exists('documento', $parametros)
                ? $this->documentoDeportista($parametros['documento'])
                : $usuario['identificador'];
            if ($documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
        } else {
            $documento = $this->documentoDeportista($parametros['documento'] ?? null);
            $this->exigirDeportista($documento);
        }

        return array_map(
            fn (array $fila): array => $this->formatear($fila),
            $this->documento->listar(['deportista_documento' => $documento])
        );
    }

    /**
     * Metadatos de un documento (?id=), o null si no existe o no es visible
     * para la sesión (el Controller responde 404).
     */
    public function consultar(array $parametros): ?array
    {
        $fila = $this->documentoVisible($this->idDe($parametros));
        return $fila === null ? null : $this->formatear($fila);
    }

    /**
     * Sube un documento (multipart: documento, tipo_documento, archivo).
     * Guarda el archivo y después inserta la fila; si el INSERT falla, borra
     * el archivo para no dejar huérfanos.
     */
    public function subir(array $datos): array
    {
        $usuario = $this->usuario();
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        $documento = $this->documentoDeportista($datos['documento'] ?? null);
        $tipo = $this->tipoDocumento($datos['tipo_documento'] ?? null);
        $esUnico = in_array($tipo, self::TIPOS_UNICOS, true);

        if ($usuario['ambito'] === 'publica') {
            if ($documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
            if (!$esUnico) {
                throw new ExcepcionNegocio('Este tipo de documento solo lo carga el personal administrativo.', 403);
            }
        }
        $this->exigirDeportista($documento);

        return $this->crearDocumento($documento, $tipo, $usuario['ambito'] === 'publica'
            ? 'Ese documento ya está cargado. Para cambiarlo, comunícate con el club.'
            : 'Ya hay un archivo de ese tipo para el deportista: usa «reemplazar».');
    }

    /**
     * Núcleo de subir (sin reglas de ámbito): un tipo de archivo único que ya
     * existe responde 409 con $mensajeRepetido; valida el archivo, lo guarda
     * y luego inserta la fila (si el INSERT falla, borra el archivo).
     */
    private function crearDocumento(string $documento, string $tipo, string $mensajeRepetido): array
    {
        if (in_array($tipo, self::TIPOS_UNICOS, true)
            && $this->documento->contar(['deportista_documento' => $documento, 'tipo_documento' => $tipo]) > 0) {
            throw new ExcepcionNegocio($mensajeRepetido, 409);
        }

        $archivo = ArchivoSubido::leer('archivo', self::EXTENSIONES_POR_TIPO[$tipo], self::TAMANO_MAXIMO_BYTES);
        $nombreDisco = $this->guardarArchivo($archivo, self::CARPETA_DOCUMENTOS);
        try {
            $id = $this->documento->crear(new DocumentoModel(
                deportistaDocumento: $documento,
                tipoDocumento: $tipo,
                archivo: $nombreDisco,
                nombreOriginal: $this->nombreOriginal($archivo['nombreOriginal']),
                fechaCarga: $this->hoy(),
            ));
        } catch (Throwable $error) {
            $this->borrarArchivo(self::CARPETA_DOCUMENTOS, $nombreDisco);
            throw $error;
        }

        return $this->formatear($this->documento->obtenerPorId($id));
    }

    /**
     * Reemplaza el archivo de un documento (?id=, multipart: archivo). Solo
     * administrativo. Guarda el nuevo, actualiza la fila y solo entonces borra
     * el anterior del disco; si la actualización falla, borra el nuevo.
     */
    public function reemplazar(array $parametros): array
    {
        $this->exigirAdministrativo('reemplazar documentos');
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        return $this->reemplazarArchivo($this->documentoExistente($this->idDe($parametros)));
    }

    /**
     * Núcleo de reemplazar (sin reglas de ámbito).
     */
    private function reemplazarArchivo(array $fila): array
    {
        $archivo = ArchivoSubido::leer('archivo', self::EXTENSIONES_POR_TIPO[$fila['tipo_documento']], self::TAMANO_MAXIMO_BYTES);
        $nombreDisco = $this->guardarArchivo($archivo, self::CARPETA_DOCUMENTOS);

        $modelo = DocumentoModel::fromArray($fila);
        $modelo->establecerArchivo($nombreDisco);
        $modelo->establecerNombreOriginal($this->nombreOriginal($archivo['nombreOriginal']));
        $modelo->establecerFechaCarga($this->hoy());
        try {
            $this->documento->actualizar($modelo);
        } catch (Throwable $error) {
            $this->borrarArchivo(self::CARPETA_DOCUMENTOS, $nombreDisco);
            throw $error;
        }
        $this->borrarArchivo(self::CARPETA_DOCUMENTOS, $fila['archivo']);

        return $this->formatear($this->documento->obtenerPorId($modelo->obtenerId()));
    }

    /**
     * Elimina de verdad un documento (fila y archivo). Solo administrativo.
     * Primero la fila: si borrar el archivo falla, queda un archivo sin uso
     * (se registra), nunca una fila que apunte a un archivo inexistente.
     */
    public function eliminar(array $parametros): void
    {
        $this->exigirAdministrativo('eliminar documentos');
        $this->eliminarFila($this->documentoExistente($this->idDe($parametros)));
    }

    /**
     * Núcleo de eliminar (sin reglas de ámbito): primero la fila, luego el
     * archivo.
     */
    private function eliminarFila(array $fila): void
    {
        $this->documento->eliminar($fila['id']);
        $this->borrarArchivo(self::CARPETA_DOCUMENTOS, $fila['archivo']);
    }

    // ------------------------------------------------------------------
    // Documentos del autorregistro (RN-T.14, RN-6.3)
    // ------------------------------------------------------------------
    //
    // Los llama SOLO AutorregistroService, que ya verificó el permiso
    // temporal de la sesión y que el registro sigue pendiente: $documento es
    // el del permiso, nunca uno que mande el cliente. Mismos tipos únicos que
    // el ámbito público, mismos formatos y tamaño (RN-6.3) y mismo campo de
    // la base de datos (RN-6.7); a diferencia del público, durante la
    // ventana el registrante también reemplaza y elimina los suyos.

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarDeRegistro(string $documento): array
    {
        return array_map(fn (array $fila): array => $this->formatear($fila), $this->documento->listar(['deportista_documento' => $documento]));
    }

    public function subirDeRegistro(string $documento, array $datos): array
    {
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        if (array_key_exists('documento', $datos) && $this->documentoDeportista($datos['documento']) !== $documento) {
            throw new ExcepcionNegocio('Solo puedes cargar documentos de tu propio registro.', 403);
        }
        $tipo = $this->tipoDocumento($datos['tipo_documento'] ?? null);
        if (!in_array($tipo, self::TIPOS_AUTORREGISTRO, true)) {
            throw new ExcepcionNegocio('Durante el registro solo se cargan: ' . implode(', ', self::TIPOS_AUTORREGISTRO) . '. Los demás los carga el club.', 403);
        }
        return $this->crearDocumento($documento, $tipo, 'Ese documento ya está cargado: usa «reemplazarDocumento» para cambiarlo.');
    }

    public function reemplazarDeRegistro(string $documento, array $parametros): array
    {
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        return $this->reemplazarArchivo($this->documentoDeRegistro($documento, $parametros));
    }

    public function eliminarDeRegistro(string $documento, array $parametros): void
    {
        $this->eliminarFila($this->documentoDeRegistro($documento, $parametros));
    }

    /**
     * Fila de un documento del propio registro; uno ajeno o inexistente
     * responde 404 (no revela si existe).
     */
    private function documentoDeRegistro(string $documento, array $parametros): array
    {
        $fila = $this->documento->obtenerPorId($this->idDe($parametros));
        if ($fila === null || $fila['deportista_documento'] !== $documento) {
            throw new ExcepcionNegocio('Documento no encontrado.', 404);
        }
        return $fila;
    }

    /**
     * Contenido y nombre original de un documento visible para la sesión
     * (el Controller lo entrega con Respuesta::archivo).
     *
     * @return array{contenido: string, nombre: string, tipo_mime: string}
     */
    public function descargar(array $parametros): array
    {
        $fila = $this->documentoVisible($this->idDe($parametros));
        if ($fila === null) {
            throw new ExcepcionNegocio('Documento no encontrado.', 404);
        }
        return $this->paraDescarga(self::CARPETA_DOCUMENTOS, $fila['archivo'], $fila['nombre_original']);
    }

    /**
     * Indicador de documentos faltantes (solo administrativo), calculado.
     * Con ?documento=: {criticos, no_criticos} de ese deportista. Sin él: la
     * lista de todos los pendientes de validación, con dos consultas en total.
     *
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function faltantes(array $parametros): array
    {
        $this->exigirAdministrativo('consultar documentos faltantes');

        if (array_key_exists('documento', $parametros)) {
            $documento = $this->documentoDeportista($parametros['documento']);
            $deportista = $this->exigirDeportista($documento);
            $tipos = array_column($this->documento->listar(['deportista_documento' => $documento]), 'tipo_documento');
            return $this->calcularFaltantes($deportista['fecha_nacimiento'], $tipos);
        }

        $tiposPorDeportista = [];
        foreach ($this->documento->tiposPorEstadoRegistro('pendiente_validacion') as $fila) {
            $tiposPorDeportista[$fila['deportista_documento']][] = $fila['tipo_documento'];
        }

        $resultado = [];
        foreach ($this->deportista->listar(['estado_registro' => 'pendiente_validacion']) as $deportista) {
            $resultado[] = ['documento' => $deportista['documento'], 'nombre' => $deportista['nombre']]
                + $this->calcularFaltantes($deportista['fecha_nacimiento'], $tiposPorDeportista[$deportista['documento']] ?? []);
        }
        return $resultado;
    }

    // ------------------------------------------------------------------
    // Documentos generales (RN-6.8)
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarGenerales(): array
    {
        $this->usuario();
        return array_map(
            fn (array $fila): array => $this->formatearGeneral($fila),
            $this->documentoGeneral->listar()
        );
    }

    /**
     * @return array{contenido: string, nombre: string, tipo_mime: string}
     */
    public function descargarGeneral(array $parametros): array
    {
        $this->usuario();
        $fila = $this->generalExistente($this->idDe($parametros));
        return $this->paraDescarga(self::CARPETA_GENERALES, $fila['archivo'], $fila['nombre_original']);
    }

    /**
     * Sube un documento general (multipart: titulo, archivo). Solo administrativo.
     */
    public function subirGeneral(array $datos): array
    {
        $this->exigirAdministrativo('subir documentos generales');
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        $titulo = Validador::texto($datos['titulo'] ?? null, 'titulo', self::MAXIMO_TITULO, true);

        $archivo = ArchivoSubido::leer('archivo', self::EXTENSIONES_GENERALES, self::TAMANO_MAXIMO_BYTES);
        $nombreDisco = $this->guardarArchivo($archivo, self::CARPETA_GENERALES);
        try {
            $id = $this->documentoGeneral->crear(new DocumentoGeneralModel(
                titulo: $titulo,
                archivo: $nombreDisco,
                nombreOriginal: $this->nombreOriginal($archivo['nombreOriginal']),
                fechaCarga: $this->hoy(),
            ));
        } catch (Throwable $error) {
            $this->borrarArchivo(self::CARPETA_GENERALES, $nombreDisco);
            throw $error;
        }

        return $this->formatearGeneral($this->documentoGeneral->obtenerPorId($id));
    }

    /**
     * Reemplaza el archivo de un documento general (?id=, multipart: archivo
     * y, si viene, titulo). Solo administrativo.
     */
    public function reemplazarGeneral(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('reemplazar documentos generales');
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        $fila = $this->generalExistente($this->idDe($parametros));
        $modelo = DocumentoGeneralModel::fromArray($fila);
        if (array_key_exists('titulo', $datos)) {
            $modelo->establecerTitulo(Validador::texto($datos['titulo'], 'titulo', self::MAXIMO_TITULO, true));
        }

        $archivo = ArchivoSubido::leer('archivo', self::EXTENSIONES_GENERALES, self::TAMANO_MAXIMO_BYTES);
        $nombreDisco = $this->guardarArchivo($archivo, self::CARPETA_GENERALES);
        $modelo->establecerArchivo($nombreDisco);
        $modelo->establecerNombreOriginal($this->nombreOriginal($archivo['nombreOriginal']));
        $modelo->establecerFechaCarga($this->hoy());
        try {
            $this->documentoGeneral->actualizar($modelo);
        } catch (Throwable $error) {
            $this->borrarArchivo(self::CARPETA_GENERALES, $nombreDisco);
            throw $error;
        }
        $this->borrarArchivo(self::CARPETA_GENERALES, $fila['archivo']);

        return $this->formatearGeneral($this->documentoGeneral->obtenerPorId($modelo->obtenerId()));
    }

    public function eliminarGeneral(array $parametros): void
    {
        $this->exigirAdministrativo('eliminar documentos generales');
        $fila = $this->generalExistente($this->idDe($parametros));

        $this->documentoGeneral->eliminar($fila['id']);
        $this->borrarArchivo(self::CARPETA_GENERALES, $fila['archivo']);
    }

    // ------------------------------------------------------------------
    // Permisos y búsqueda de filas
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
    private function exigirDeportista(string $documento): array
    {
        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        return $deportista;
    }

    /**
     * Fila del documento si la sesión puede verla: el administrativo ve
     * todo; el público solo los de su propio documento.
     */
    private function documentoVisible(int $id): ?array
    {
        $usuario = $this->usuario();
        $fila = $this->documento->obtenerPorId($id);
        if ($fila === null) {
            return null;
        }
        if ($usuario['ambito'] !== 'administrativa' && $fila['deportista_documento'] !== $usuario['identificador']) {
            return null;
        }
        return $fila;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoExistente(int $id): array
    {
        $fila = $this->documento->obtenerPorId($id);
        if ($fila === null) {
            throw new ExcepcionNegocio('Documento no encontrado.', 404);
        }
        return $fila;
    }

    /**
     * @return array<string, mixed>
     */
    private function generalExistente(int $id): array
    {
        $fila = $this->documentoGeneral->obtenerPorId($id);
        if ($fila === null) {
            throw new ExcepcionNegocio('Documento no encontrado.', 404);
        }
        return $fila;
    }

    // ------------------------------------------------------------------
    // Cálculos y formato
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $tiposCargados
     * @return array{criticos: array<int, string>, no_criticos: array<int, string>}
     */
    private function calcularFaltantes(string $fechaNacimiento, array $tiposCargados): array
    {
        $edad = (new DateTimeImmutable($fechaNacimiento))->diff(new DateTimeImmutable('today'))->y;
        $criticos = ['documento_identidad', 'formulario_inscripcion'];
        if ($edad < self::MAYORIA_EDAD) {
            $criticos[] = 'documento_acudiente';
        }

        return [
            'criticos' => array_values(array_diff($criticos, $tiposCargados)),
            'no_criticos' => array_values(array_diff(['fotografia'], $tiposCargados)),
        ];
    }

    /**
     * Forma de un documento en las respuestas: nunca el nombre en disco.
     */
    private function formatear(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'deportista_documento' => $fila['deportista_documento'],
            'tipo_documento' => $fila['tipo_documento'],
            'nombre_original' => $fila['nombre_original'],
            'fecha_carga' => $fila['fecha_carga'],
        ];
    }

    private function formatearGeneral(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'titulo' => $fila['titulo'],
            'nombre_original' => $fila['nombre_original'],
            'fecha_carga' => $fila['fecha_carga'],
        ];
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Validación de datos de entrada
    // ------------------------------------------------------------------

    private function idDe(array $parametros): int
    {
        $id = $parametros['id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
            throw new ExcepcionNegocio('El parámetro "id" debe ser un número entero positivo.', 400);
        }
        return (int) $id;
    }

    private function documentoDeportista(mixed $valor): string
    {
        return Validador::texto($valor, 'documento', 20, true);
    }

    private function tipoDocumento(mixed $valor): string
    {
        if (!is_string($valor) || !array_key_exists($valor, self::EXTENSIONES_POR_TIPO)) {
            throw new ExcepcionNegocio(
                'El campo "tipo_documento" debe ser uno de: ' . implode(', ', array_keys(self::EXTENSIONES_POR_TIPO)) . '.',
                400
            );
        }
        return $valor;
    }

    /**
     * Nombre con el que se subió, sin caracteres de control y recortado a
     * 150 caracteres (columna nombre_original). Si hay que recortar, se
     * conserva la extensión para que la descarga abra con el programa correcto.
     */
    private function nombreOriginal(string $nombre): string
    {
        $limpio = preg_replace('/[\x00-\x1F\x7F]/u', '', $nombre);
        $limpio = trim($limpio ?? '');
        if ($limpio === '') {
            $limpio = 'archivo';
        }
        if (mb_strlen($limpio) <= self::MAXIMO_NOMBRE_ORIGINAL) {
            return $limpio;
        }

        $punto = mb_strrpos($limpio, '.');
        $extension = $punto === false ? '' : mb_substr($limpio, $punto);
        if (mb_strlen($extension) > 10) {
            $extension = '';
        }
        return mb_substr($limpio, 0, self::MAXIMO_NOMBRE_ORIGINAL - mb_strlen($extension)) . $extension;
    }

    // ------------------------------------------------------------------
    // Archivos en disco
    // ------------------------------------------------------------------

    /**
     * Mueve el archivo subido a la carpeta con un nombre aleatorio y devuelve
     * ese nombre (el que se guarda en la columna archivo).
     *
     * @param array{rutaTemporal: string, extension: string} $archivo
     */
    private function guardarArchivo(array $archivo, string $carpeta): string
    {
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0700, true) && !is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear la carpeta de almacenamiento {$carpeta}.");
        }
        $nombre = bin2hex(random_bytes(16)) . '.' . $archivo['extension'];
        if (!move_uploaded_file($archivo['rutaTemporal'], $carpeta . '/' . $nombre)) {
            throw new RuntimeException('No se pudo guardar el archivo subido.');
        }
        return $nombre;
    }

    /**
     * Borra del disco los archivos de documentos cuyas filas ya se borraron
     * (rechazo de un registro pendiente, después del commit). Un fallo solo
     * queda en el log: las filas ya no existen y no se revierte nada.
     *
     * @param array<int, string> $nombresEnDisco valores de la columna archivo
     */
    public function borrarArchivosDeDocumentos(array $nombresEnDisco): void
    {
        foreach ($nombresEnDisco as $nombre) {
            $this->borrarArchivo(self::CARPETA_DOCUMENTOS, (string) $nombre);
        }
    }

    /**
     * Borra el archivo del disco. Si el nombre guardado en la base no tiene
     * la forma esperada (valor alterado), no toca el disco: lo registra en el
     * log y sigue, porque la fila ya se borró o se actualizó y responder 500
     * a esa altura no ayuda a nadie.
     */
    private function borrarArchivo(string $carpeta, string $nombre): void
    {
        try {
            $ruta = $this->rutaSegura($carpeta, $nombre);
        } catch (RuntimeException $error) {
            error_log('DocumentoService: no se borró del disco un archivo con nombre no válido en la base de datos: ' . $error->getMessage());
            return;
        }
        if (is_file($ruta) && !@unlink($ruta)) {
            error_log("DocumentoService: no se pudo borrar el archivo {$ruta}.");
        }
    }

    /**
     * @return array{contenido: string, nombre: string, tipo_mime: string}
     */
    private function paraDescarga(string $carpeta, string $nombreDisco, string $nombreOriginal): array
    {
        $ruta = $this->rutaSegura($carpeta, $nombreDisco);
        $contenido = is_file($ruta) ? @file_get_contents($ruta) : false;
        if ($contenido === false) {
            // La fila existe pero el archivo no (borrado a mano, copia de
            // seguridad incompleta): 404 claro para el usuario y el detalle al log.
            error_log("DocumentoService: falta en disco el archivo {$nombreDisco} registrado en la base de datos.");
            throw new ExcepcionNegocio('El archivo de este documento no está disponible en el servidor. Vuelve a cargarlo o comunícate con el club.', 404);
        }
        return [
            'contenido' => $contenido,
            'nombre' => $nombreOriginal,
            'tipo_mime' => ArchivoSubido::tipoMime(pathinfo($nombreDisco, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * Ruta del archivo en disco. El nombre lo generó guardarArchivo (32
     * hexadecimales y extensión); si no tiene esa forma, no se usa: así un
     * valor alterado en la base nunca apunta fuera de la carpeta.
     */
    private function rutaSegura(string $carpeta, string $nombre): string
    {
        if (preg_match('/^[0-9a-f]{32}\.[a-z0-9]{2,5}$/', $nombre) !== 1) {
            throw new RuntimeException("Nombre de archivo en disco no válido: {$nombre}");
        }
        return $carpeta . '/' . $nombre;
    }
}
