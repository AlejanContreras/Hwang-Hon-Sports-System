<?php

declare(strict_types=1);

/**
 * Lectura y validacion de un archivo subido por formulario (multipart,
 * arreglo $_FILES). Se usa en Documentos y en las cargas masivas por CSV
 * (deportistas y calendario).
 *
 * Solo valida y entrega los datos del archivo: que hacer con el (guardarlo,
 * leerlo como CSV) y que extensiones admite cada caso lo decide el Service
 * que lo recibe.
 *
 * Requiere la extension fileinfo de PHP (activa por defecto en XAMPP) para
 * comprobar el tipo real del archivo, no solo su extension. No se aceptan
 * HEIC, SVG ni HTML: ninguna extension de la lista lleva a esos tipos.
 */
class ArchivoSubido
{
    /**
     * Tipos reales (MIME) aceptados para cada extension. El primero de cada
     * lista es el que se envia al descargar. Los Office modernos (docx, xlsx)
     * son ZIP por dentro y fileinfo a veces los informa como application/zip;
     * los antiguos (doc, xls) son contenedores OLE (application/CDFV2).
     */
    private const TIPOS_POR_EXTENSION = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ];

    /**
     * Valida el archivo del campo $campo y devuelve sus datos.
     * Lanza ExcepcionNegocio (400) si no llego, si hubo error de subida, si
     * la extension o el tipo real no estan permitidos, o si supera el tamano.
     *
     * @param array<int, string> $extensionesPermitidas ej. ['pdf', 'docx']
     * @return array{nombreOriginal: string, rutaTemporal: string, extension: string, tamanoBytes: int}
     */
    public static function leer(string $campo, array $extensionesPermitidas, int $tamanoMaximoBytes): array
    {
        $archivo = $_FILES[$campo] ?? null;
        $mensajeTamano = 'El archivo supera el tamaño máximo permitido (' . round($tamanoMaximoBytes / 1048576, 1) . ' MB).';

        if ($archivo === null || !is_string($archivo['name'] ?? null)) {
            throw new ExcepcionNegocio("No se recibió el archivo \"{$campo}\".", 400);
        }

        $codigoError = (int) $archivo['error'];
        if ($codigoError === UPLOAD_ERR_NO_FILE) {
            throw new ExcepcionNegocio("No se recibió el archivo \"{$campo}\".", 400);
        }
        if ($codigoError === UPLOAD_ERR_INI_SIZE || $codigoError === UPLOAD_ERR_FORM_SIZE) {
            throw new ExcepcionNegocio($mensajeTamano, 400);
        }
        if ($codigoError !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new ExcepcionNegocio('No se pudo subir el archivo. Intente de nuevo.', 400);
        }

        $tamanoBytes = (int) $archivo['size'];
        if ($tamanoBytes <= 0) {
            throw new ExcepcionNegocio('El archivo está vacío.', 400);
        }
        if ($tamanoBytes > $tamanoMaximoBytes) {
            throw new ExcepcionNegocio($mensajeTamano, 400);
        }

        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $extensionesPermitidas, true)) {
            $lista = implode(', ', $extensionesPermitidas);
            throw new ExcepcionNegocio("Tipo de archivo no permitido. Se aceptan: {$lista}.", 400);
        }

        $tiposAceptados = self::TIPOS_POR_EXTENSION[$extension] ?? null;
        if ($tiposAceptados === null) {
            // Error de programacion: se pidio una extension que esta clase no conoce.
            throw new LogicException("ArchivoSubido no conoce la extension: {$extension}");
        }

        $tipoReal = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (!in_array($tipoReal, $tiposAceptados, true)) {
            throw new ExcepcionNegocio('El contenido del archivo no corresponde a su extensión.', 400);
        }

        return [
            'nombreOriginal' => basename($archivo['name']),
            'rutaTemporal' => $archivo['tmp_name'],
            'extension' => $extension,
            'tamanoBytes' => $tamanoBytes,
        ];
    }

    /**
     * Si el cuerpo de la peticion supera post_max_size, PHP descarta TODO el
     * formulario ($_POST y $_FILES llegan vacios) y el error que veria el
     * usuario seria "falta un campo". Este chequeo va primero y responde 400
     * con el mensaje real de tamano.
     */
    public static function exigirCuerpoDentroDelLimite(): void
    {
        $tamanoCuerpo = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limite = self::bytesDeIni((string) ini_get('post_max_size'));
        if ($limite > 0 && $tamanoCuerpo > $limite) {
            throw new ExcepcionNegocio(
                'El archivo supera el tamaño máximo que acepta el servidor (' . round($limite / 1048576, 1) . ' MB).',
                400
            );
        }
    }

    /**
     * Convierte un valor de php.ini como "40M" o "512K" a bytes.
     */
    private static function bytesDeIni(string $valor): int
    {
        $valor = trim($valor);
        $numero = (int) $valor;
        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1073741824,
            'M' => $numero * 1048576,
            'K' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * Tipo MIME con el que se entrega un archivo de esa extension al
     * descargarlo (o application/octet-stream si no es conocida).
     */
    public static function tipoMime(string $extension): string
    {
        return self::TIPOS_POR_EXTENSION[strtolower($extension)][0] ?? 'application/octet-stream';
    }
}
