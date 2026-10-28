<?php
declare(strict_types=1);
// Genera archivos de prueba reales (sin gd ni zip) en la carpeta indicada. Uso: php archivos_prueba.php <carpeta> <ruta backend/Src>
function zipGuardado(array $entradas): string
{
    $datos = ''; $central = ''; $desplazamiento = 0;
    foreach ($entradas as $nombre => $contenido) {
        $crc = crc32($contenido); $tam = strlen($contenido); $largo = strlen($nombre);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $tam, $tam, $largo, 0) . $nombre . $contenido;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $tam, $tam, $largo, 0, 0, 0, 0, 0, $desplazamiento) . $nombre;
        $datos .= $local; $desplazamiento += strlen($local);
    }
    return $datos . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entradas), count($entradas), strlen($central), $desplazamiento, 0);
}
function archivosDePrueba(string $d, string $src): array
{
    @mkdir($d, 0700, true);
    $a = [];
    $a['pdf'] = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . bin2hex(random_bytes(8));
    $a['jpg'] = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $a['jpeg'] = $a['jpg'];
    $a['png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $a['webp'] = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    $a['doc'] = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
    $a['xls'] = $a['doc'];
    $a['docx'] = zipGuardado([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
    ]);
    require_once $src . '/Utils/GeneradorXlsx.php';
    $x = new GeneradorXlsx();
    
    $hoja = $x->agregarHoja('Hoja1');
    $x->celda($hoja, 'A', 1, 'Prueba');
    $a['xlsx'] = $x->generar();
    $rutas = [];
    foreach ($a as $ext => $contenido) { file_put_contents("$d/prueba.$ext", $contenido); $rutas[$ext] = "$d/prueba.$ext"; }
    return $rutas;
}
if (realpath($argv[0]) === realpath(__FILE__)) {
    $f = new finfo(FILEINFO_MIME_TYPE);
    foreach (archivosDePrueba($argv[1], $argv[2]) as $ext => $r) echo str_pad($ext, 5), ' => ', $f->file($r), PHP_EOL;
}
