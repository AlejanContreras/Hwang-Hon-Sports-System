<?php

declare(strict_types=1);

/**
 * Lectura y validacion de un archivo subido por formulario (multipart,
 * arreglo $_FILES). Se usa en Documentos (PDF/Word) y en las cargas
 * masivas por CSV (deportistas y calendario).
 *
 * Solo valida y entrega los datos del archivo: que hacer con el (guardarlo,
 * leerlo como CSV) lo decide el Service que lo recibe.
 *
 * Requiere la extension fileinfo de PHP (activa por defecto en XAMPP) para
 * comprobar el tipo real del archivo, no solo su extension.
 */
class ArchivoSubido
{
    /**
     * Tipos reales (MIME) aceptados para cada extension.
     */
    private const TIPOS_POR_EXTENSION = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
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

        if ($archivo === null || !is_string($archivo['name'] ?? null)) {
            throw new ExcepcionNegocio("No se recibio el archivo \"{$campo}\".", 400);
        }

        $codigoError = (int) $archivo['error'];
        if ($codigoError === UPLOAD_ERR_NO_FILE) {
            throw new ExcepcionNegocio("No se recibio el archivo \"{$campo}\".", 400);
        }
        if ($codigoError === UPLOAD_ERR_INI_SIZE || $codigoError === UPLOAD_ERR_FORM_SIZE) {
            throw new ExcepcionNegocio('El archivo supera el tamano maximo permitido.', 400);
        }
        if ($codigoError !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new ExcepcionNegocio('No se pudo subir el archivo. Intente de nuevo.', 400);
        }

        $tamanoBytes = (int) $archivo['size'];
        if ($tamanoBytes <= 0) {
            throw new ExcepcionNegocio('El archivo esta vacio.', 400);
        }
        if ($tamanoBytes > $tamanoMaximoBytes) {
            throw new ExcepcionNegocio('El archivo supera el tamano maximo permitido.', 400);
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
            throw new ExcepcionNegocio('El contenido del archivo no corresponde a su extension.', 400);
        }

        return [
            'nombreOriginal' => basename($archivo['name']),
            'rutaTemporal' => $archivo['tmp_name'],
            'extension' => $extension,
            'tamanoBytes' => $tamanoBytes,
        ];
    }
}
