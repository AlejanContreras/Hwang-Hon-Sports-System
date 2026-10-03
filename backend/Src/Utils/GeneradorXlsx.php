<?php

declare(strict_types=1);

/**
 * Generador de archivos .xlsx (Office Open XML) en memoria pura, sin ext-zip.
 * El ZIP se construye con pack() siguiendo la especificacion PKZip 2.0.
 *
 * Compatible con Microsoft Excel, LibreOffice Calc y Google Sheets.
 * Requisitos de PHP: solo extensiones estandar (ninguna adicional).
 *
 * Los 16 estilos (constantes ESTILO_*) tienen nombres neutrales para que
 * sirvan a cualquier reporte de HwangHon (RN-10.x). Que reportes concretos
 * usa cada estilo es decision de la Etapa 5, cuando se implemente el modulo
 * Reporte.
 */
class GeneradorXlsx
{
    // Estilo base, sin color ni borde.
    const ESTILO_DEFECTO = 0;
    // Encabezados de titulo (fondo de color, texto centrado).
    const ESTILO_ENCABEZADO_VERDE = 1;
    const ESTILO_ENCABEZADO_GRIS = 2;
    const ESTILO_ENCABEZADO_AZUL = 3;
    // Subtitulo con sangria (por ejemplo, un dato de contexto bajo el titulo).
    const ESTILO_SUBTITULO = 4;
    // Titulos de columna.
    const ESTILO_COLUMNA_TITULO = 5;
    const ESTILO_COLUMNA_TITULO_AJUSTADO = 6;
    // Celdas de dato con significado (positivo/negativo), con borde.
    const ESTILO_CELDA_POSITIVA = 7;
    const ESTILO_CELDA_NEGATIVA = 8;
    // Celda de dato neutra, con borde.
    const ESTILO_CELDA_NEUTRA = 9;
    // Celdas de una columna de indice/nombre (sin fondo, con borde).
    const ESTILO_CELDA_INDICE = 10;
    const ESTILO_CELDA_TEXTO = 11;
    // Totales, con fondo destacado.
    const ESTILO_TOTAL_DESTACADO = 12;
    const ESTILO_TOTAL_POSITIVO = 13;
    // Celda vacia, sin color ni borde.
    const ESTILO_VACIO = 14;
    // Variante gris del titulo de columna ajustado.
    const ESTILO_COLUMNA_TITULO_ALTERNO = 15;

    private array $hojas = [];
    private array $indiceCadenas = [];
    private array $cadenas = [];

    /**
     * Agrega una hoja nueva al libro y devuelve su indice (0, 1, 2, ...).
     */
    public function agregarHoja(string $nombre): int
    {
        $indice = count($this->hojas);
        $this->hojas[$indice] = [
            'nombre' => $nombre,
            'celdas' => [],
            'combinaciones' => [],
            'anchosColumna' => [],
            'altosFila' => [],
        ];
        return $indice;
    }

    /**
     * Escribe un valor en una celda. $columna es la letra de columna ("A", "B", ...).
     */
    public function celda(int $hoja, string $columna, int $fila, mixed $valor, int $estilo = self::ESTILO_DEFECTO): void
    {
        $indiceColumna = $this->indiceColumna($columna);
        $tipo = '';
        $v = $valor;

        if (is_string($valor) && $valor !== '') {
            $tipo = 's';
            $v = $this->agregarCadenaCompartida($valor);
        } elseif (is_int($valor) || is_float($valor)) {
            $tipo = 'n';
        }

        $this->hojas[$hoja]['celdas'][$fila][$indiceColumna] = ['v' => $v, 's' => $estilo, 't' => $tipo];
    }

    /**
     * Combina un rango de celdas, por ejemplo combinar($hoja, 'A1', 'C1').
     */
    public function combinar(int $hoja, string $desde, string $hasta): void
    {
        $this->hojas[$hoja]['combinaciones'][] = "{$desde}:{$hasta}";
    }

    public function anchoColumna(int $hoja, string $columna, float $ancho): void
    {
        $this->hojas[$hoja]['anchosColumna'][$this->indiceColumna($columna)] = $ancho;
    }

    public function altoFila(int $hoja, int $fila, float $alto): void
    {
        $this->hojas[$hoja]['altosFila'][$fila] = $alto;
    }

    /**
     * Convierte un indice de columna (0, 1, 2, ...) a su letra ("A", "B", ...).
     */
    public function letraColumna(int $indice): string
    {
        $n = $indice + 1;
        $letra = '';
        while ($n > 0) {
            $n--;
            $letra = chr(65 + ($n % 26)) . $letra;
            $n = intdiv($n, 26);
        }
        return $letra;
    }

    /**
     * Genera y devuelve el binario .xlsx completo, listo para enviar al navegador
     * (por ejemplo, con header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')).
     */
    public function generar(): string
    {
        $zip = new ConstructorZipXlsx();
        $zip->agregar('[Content_Types].xml', $this->construirTiposContenido());
        $zip->agregar('_rels/.rels', $this->construirRelaciones());
        $zip->agregar('xl/workbook.xml', $this->construirLibro());
        $zip->agregar('xl/_rels/workbook.xml.rels', $this->construirRelacionesLibro());
        $zip->agregar('xl/styles.xml', $this->construirEstilos());
        $zip->agregar('xl/sharedStrings.xml', $this->construirCadenasCompartidas());

        foreach (array_keys($this->hojas) as $i) {
            $zip->agregar("xl/worksheets/sheet{$i}.xml", $this->construirHoja($i));
        }

        return $zip->construir();
    }

    private function agregarCadenaCompartida(string $texto): int
    {
        if (!isset($this->indiceCadenas[$texto])) {
            $this->indiceCadenas[$texto] = count($this->cadenas);
            $this->cadenas[] = $texto;
        }
        return $this->indiceCadenas[$texto];
    }

    private function indiceColumna(string $columna): int
    {
        $columna = strtoupper(trim($columna));
        $n = 0;
        foreach (str_split($columna) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n - 1;
    }

    private function referenciaCelda(int $indiceColumna, int $fila): string
    {
        return $this->letraColumna($indiceColumna) . $fila;
    }

    private function escaparXml(string $texto): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $texto);
    }

    private function construirTiposContenido(): string
    {
        $hojasXml = '';
        foreach (array_keys($this->hojas) as $i) {
            $hojasXml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml"'
                     . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . $hojasXml
             . '</Types>';
    }

    private function construirRelaciones(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1"'
             . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
             . ' Target="xl/workbook.xml"/>'
             . '</Relationships>';
    }

    private function construirLibro(): string
    {
        $xml = '';
        foreach ($this->hojas as $i => $h) {
            $nombre = $this->escaparXml($h['nombre']);
            $rId = $i + 1;
            $xml .= "<sheet name=\"{$nombre}\" sheetId=\"{$rId}\" r:id=\"rId{$rId}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets>' . $xml . '</sheets>'
             . '</workbook>';
    }

    private function construirRelacionesLibro(): string
    {
        $rels = '';
        foreach (array_keys($this->hojas) as $i) {
            $rId = $i + 1;
            $rels .= '<Relationship Id="rId' . $rId . '"'
                   . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                   . ' Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $n = count($this->hojas) + 1;
        $rels .= '<Relationship Id="rId' . $n . '"'
               . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
               . ' Target="sharedStrings.xml"/>';
        $n++;
        $rels .= '<Relationship Id="rId' . $n . '"'
               . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
               . ' Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . $rels
             . '</Relationships>';
    }

    private function construirEstilos(): string
    {
        $fuentes = '<fonts count="3">'
               . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
               . '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
               . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
               . '</fonts>';

        $rellenos = '<fills count="11">'
               . '<fill><patternFill patternType="none"/></fill>'
               . '<fill><patternFill patternType="gray125"/></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FF70AD47"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFBDD7EE"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FF9DC3E6"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFCE4D6"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/></patternFill></fill>'
               . '</fills>';

        $bordes = '<borders count="2">'
                 . '<border><left/><right/><top/><bottom/></border>'
                 . '<border>'
                 . '<left style="thin"><color rgb="FFB0B0B0"/></left>'
                 . '<right style="thin"><color rgb="FFB0B0B0"/></right>'
                 . '<top style="thin"><color rgb="FFB0B0B0"/></top>'
                 . '<bottom style="thin"><color rgb="FFB0B0B0"/></bottom>'
                 . '</border>'
                 . '</borders>';

        $estilosBase = '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

        // Los indices 0-15 deben coincidir exactamente con las constantes ESTILO_*.
        $alineacion = static fn(string $h = '', string $v = '', bool $ajustar = false, string $sangria = ''): string =>
            '<alignment'
            . ($h ? " horizontal=\"$h\"" : '')
            . ($v ? " vertical=\"$v\""   : '')
            . ($ajustar ? ' wrapText="1"' : '')
            . ($sangria ? " indent=\"$sangria\"" : '')
            . '/>';

        $formato = static fn(int $fuente, int $relleno, int $borde, string $aln = ''): string =>
            '<xf xfId="0" numFmtId="0"'
            . " fontId=\"$fuente\" fillId=\"$relleno\" borderId=\"$borde\""
            . ($fuente   ? ' applyFont="1"'      : '')
            . ($relleno  > 1 ? ' applyFill="1"'  : '')
            . ($borde ? ' applyBorder="1"'    : '')
            . ($aln    ? ' applyAlignment="1"' : '')
            . ($aln    ? '>' . $aln . '</xf>'  : '/>');

        $formatosCelda = '<cellXfs count="16">'
             . $formato(0, 0, 0)                                                                  // 0 ESTILO_DEFECTO
             . $formato(1, 2, 0, $alineacion('center', 'center'))                                 // 1 ESTILO_ENCABEZADO_VERDE
             . $formato(2, 3, 0, $alineacion('center', 'center'))                                 // 2 ESTILO_ENCABEZADO_GRIS
             . $formato(2, 4, 0, $alineacion('center', 'center'))                                 // 3 ESTILO_ENCABEZADO_AZUL
             . $formato(0, 5, 0, $alineacion('', 'center', false, '1'))                           // 4 ESTILO_SUBTITULO
             . $formato(2, 6, 1, $alineacion('center', 'center'))                                 // 5 ESTILO_COLUMNA_TITULO
             . $formato(2, 6, 1, $alineacion('center', 'center', true))                           // 6 ESTILO_COLUMNA_TITULO_AJUSTADO
             . $formato(2, 7, 1, $alineacion('center', 'center'))                                 // 7 ESTILO_CELDA_POSITIVA
             . $formato(2, 8, 1, $alineacion('center', 'center'))                                 // 8 ESTILO_CELDA_NEGATIVA
             . $formato(0, 9, 1, $alineacion('center', 'center'))                                 // 9 ESTILO_CELDA_NEUTRA
             . $formato(0, 0, 1, $alineacion('center', 'center'))                                 // 10 ESTILO_CELDA_INDICE
             . $formato(0, 0, 1, $alineacion('', 'center', false, '1'))                           // 11 ESTILO_CELDA_TEXTO
             . $formato(2, 10, 1, $alineacion('center', 'center'))                                // 12 ESTILO_TOTAL_DESTACADO
             . $formato(2, 7,  1, $alineacion('center', 'center'))                                // 13 ESTILO_TOTAL_POSITIVO
             . $formato(0, 0, 0)                                                                  // 14 ESTILO_VACIO
             . $formato(2, 3, 1, $alineacion('center', 'center', true))                           // 15 ESTILO_COLUMNA_TITULO_ALTERNO
             . '</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . $fuentes . $rellenos . $bordes . $estilosBase . $formatosCelda
             . '</styleSheet>';
    }

    private function construirCadenasCompartidas(): string
    {
        $total = count($this->cadenas);
        $items = '';
        foreach ($this->cadenas as $texto) {
            $escapado = $this->escaparXml($texto);
            $items .= '<si><t xml:space="preserve">' . $escapado . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' count="' . $total . '" uniqueCount="' . $total . '">'
             . $items . '</sst>';
    }

    private function construirHoja(int $indice): string
    {
        $hoja = $this->hojas[$indice];
        $celdas = $hoja['celdas'];
        $combinaciones = $hoja['combinaciones'];
        $anchosColumna = $hoja['anchosColumna'];
        $altosFila = $hoja['altosFila'];

        $columnasXml = '';
        if (!empty($anchosColumna)) {
            ksort($anchosColumna);
            $columnasXml = '<cols>';
            foreach ($anchosColumna as $indiceColumna => $ancho) {
                $n = $indiceColumna + 1;
                $columnasXml .= '<col min="' . $n . '" max="' . $n . '" width="' . $ancho . '" customWidth="1"/>';
            }
            $columnasXml .= '</cols>';
        }

        $datosXml = '<sheetData>';
        ksort($celdas);
        foreach ($celdas as $numeroFila => $celdasFila) {
            $alto = isset($altosFila[$numeroFila])
                ? ' ht="' . $altosFila[$numeroFila] . '" customHeight="1"' : '';
            $datosXml .= '<row r="' . $numeroFila . '"' . $alto . '>';
            ksort($celdasFila);
            foreach ($celdasFila as $indiceColumna => $celda) {
                $ref = $this->referenciaCelda($indiceColumna, $numeroFila);
                $s = $celda['s'];
                $t = $celda['t'];
                $v = $celda['v'];

                if ($v === null || $v === '') {
                    $datosXml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif ($t === 's') {
                    $datosXml .= '<c r="' . $ref . '" s="' . $s . '" t="s"><v>' . $v . '</v></c>';
                } else {
                    $datosXml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                }
            }
            $datosXml .= '</row>';
        }
        $datosXml .= '</sheetData>';

        $combinacionesXml = '';
        if (!empty($combinaciones)) {
            $combinacionesXml = '<mergeCells count="' . count($combinaciones) . '">';
            foreach ($combinaciones as $m) {
                $combinacionesXml .= '<mergeCell ref="' . $m . '"/>';
            }
            $combinacionesXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
             . '<sheetFormatPr defaultRowHeight="15" customHeight="0"/>'
             . $columnasXml . $datosXml . $combinacionesXml
             . '</worksheet>';
    }
}

/**
 * Construccion de un ZIP en PHP puro (PKZip 2.0, entradas almacenadas sin
 * compresion). No requiere la extension ext-zip. Suficiente para un .xlsx,
 * cuyo contenido ya es XML (texto).
 */
class ConstructorZipXlsx
{
    /** @var array<int, array{nombre: string, datos: string, crc: int, tamano: int, posicion: int}> */
    private array $entradas = [];
    private int $posicion = 0;

    public function agregar(string $nombre, string $datos): void
    {
        $crc = crc32($datos);
        $tamano = strlen($datos);
        $this->entradas[] = [
            'nombre' => $nombre,
            'datos' => $datos,
            'crc' => $crc,
            'tamano' => $tamano,
            'posicion' => $this->posicion,
        ];
        // Encabezado local = 30 bytes + strlen(nombre) + 0 (sin campo extra) + datos.
        $this->posicion += 30 + strlen($nombre) + $tamano;
    }

    public function construir(): string
    {
        $partesLocales = '';
        $directorioCentral = '';

        foreach ($this->entradas as $e) {
            $largoNombre = strlen($e['nombre']);

            // Encabezado de archivo local (firma 0x04034b50).
            $local = "\x50\x4b\x03\x04";           // firma
            $local .= "\x14\x00";                   // version requerida: 2.0
            $local .= "\x00\x00";                   // indicadores generales
            $local .= "\x00\x00";                   // compresion: almacenado
            $local .= "\x00\x00\x00\x00";           // hora + fecha de modificacion
            $local .= pack('V', $e['crc']);          // CRC-32
            $local .= pack('V', $e['tamano']);       // tamano comprimido
            $local .= pack('V', $e['tamano']);       // tamano sin comprimir
            $local .= pack('v', $largoNombre);       // largo del nombre
            $local .= "\x00\x00";                   // largo de campo extra
            $local .= $e['nombre'];
            $local .= $e['datos'];
            $partesLocales .= $local;

            // Entrada del directorio central (firma 0x02014b50).
            $central = "\x50\x4b\x01\x02";          // firma
            $central .= "\x14\x00";                 // version que crea el archivo
            $central .= "\x14\x00";                 // version requerida
            $central .= "\x00\x00";                 // indicadores
            $central .= "\x00\x00";                 // compresion: almacenado
            $central .= "\x00\x00\x00\x00";         // hora + fecha de modificacion
            $central .= pack('V', $e['crc']);        // CRC-32
            $central .= pack('V', $e['tamano']);     // tamano comprimido
            $central .= pack('V', $e['tamano']);     // tamano sin comprimir
            $central .= pack('v', $largoNombre);     // largo del nombre
            $central .= "\x00\x00";                 // largo de campo extra
            $central .= "\x00\x00";                 // largo de comentario
            $central .= "\x00\x00";                 // numero de disco
            $central .= "\x00\x00";                 // atributos internos
            $central .= "\x00\x00\x00\x00";         // atributos externos
            $central .= pack('V', $e['posicion']);   // posicion del encabezado local
            $central .= $e['nombre'];
            $directorioCentral .= $central;
        }

        $cantidad = count($this->entradas);
        $tamanoDirectorio = strlen($directorioCentral);
        $posicionDirectorio = strlen($partesLocales);

        // Registro de fin de directorio central (firma 0x06054b50).
        $fin = "\x50\x4b\x05\x06";                  // firma
        $fin .= "\x00\x00";                         // numero de disco
        $fin .= "\x00\x00";                         // disco con el directorio central
        $fin .= pack('v', $cantidad);                // entradas en este disco
        $fin .= pack('v', $cantidad);                // total de entradas
        $fin .= pack('V', $tamanoDirectorio);        // tamano del directorio central
        $fin .= pack('V', $posicionDirectorio);      // posicion del directorio central
        $fin .= "\x00\x00";                         // largo de comentario

        return $partesLocales . $directorioCentral . $fin;
    }
}
