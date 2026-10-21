<?php

declare(strict_types=1);

/**
 * Dibuja la ficha consolidada del deportista con FPDF (Lib/FPDF, v1.86).
 * Solo presentación: recibe las secciones ya armadas por ReporteService
 * (textos en UTF-8) y devuelve el PDF como cadena, sin escribir en disco.
 *
 * ReporteService la incluye justo después de fpdf.php: no se carga en
 * ninguna otra ruta. Las fuentes estándar de FPDF usan Windows-1252, así
 * que cada texto se convierte antes de dibujarlo (tildes, ñ, «—» y «·»
 * existen en esa tabla; lo que no exista sale como «?»).
 *
 * Cada sección: {titulo, pares?: [[etiqueta, valor]], tabla?: {columnas:
 * [[titulo, ancho mm]], filas: [[...]], resaltar: [índices]}, lista?:
 * [textos]}.
 */
class FichaDeportistaPdf extends FPDF
{
    private const MARGEN = 15;
    private const ANCHO_ETIQUETA = 55;
    private const ALTO_LINEA = 6;

    private string $club;
    private string $titulo;
    private string $generado;

    public function __construct(string $club, string $titulo, string $generado)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->club = $club;
        $this->titulo = $titulo;
        $this->generado = $generado;
        $this->SetMargins(self::MARGEN, self::MARGEN, self::MARGEN);
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
        $this->SetTitle($titulo, true);
        $this->SetAuthor($club, true);
        $this->SetCreator('HwangHon Sports System', true);
    }

    /**
     * @param array<int, array<string, mixed>> $secciones
     */
    public function generar(array $secciones): string
    {
        $this->AddPage();
        foreach ($secciones as $seccion) {
            $this->seccion($seccion);
        }
        return $this->Output('S');
    }

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(40, 90, 40);
        $this->Cell(0, 8, $this->t($this->club), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 11);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 6, $this->t($this->titulo), 0, 1, 'C');
        $this->SetDrawColor(112, 173, 71);
        $this->Line(self::MARGEN, $this->GetY() + 1, 210 - self::MARGEN, $this->GetY() + 1);
        $this->Ln(5);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, $this->t("Generado el {$this->generado} · Documento con datos personales: no compartir · Página {$this->PageNo()}/{nb}"), 0, 0, 'C');
    }

    private function seccion(array $seccion): void
    {
        if ($this->GetY() > 250) {
            $this->AddPage();
        }
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetFillColor(189, 215, 238);
        $this->Cell(0, 7, $this->t($seccion['titulo']), 0, 1, 'L', true);
        $this->Ln(1);
        $this->SetFont('Helvetica', '', 10);

        foreach ($seccion['pares'] ?? [] as [$etiqueta, $valor]) {
            $this->SetFont('Helvetica', 'B', 10);
            $this->Cell(self::ANCHO_ETIQUETA, self::ALTO_LINEA, $this->t($etiqueta . ':'), 0, 0);
            $this->SetFont('Helvetica', '', 10);
            $this->MultiCell(0, self::ALTO_LINEA, $this->t((string) $valor), 0, 'L');
        }
        if (isset($seccion['tabla'])) {
            $this->tabla($seccion['tabla']);
        }
        foreach ($seccion['lista'] ?? [] as $elemento) {
            $this->Cell(5, self::ALTO_LINEA, $this->t('·'), 0, 0);
            $this->MultiCell(0, self::ALTO_LINEA, $this->t($elemento), 0, 'L');
        }
        $this->Ln(4);
    }

    /**
     * Tabla simple; las filas de "resaltar" van en rojo claro (por ejemplo,
     * notas menores de 5). Un texto más ancho que su columna se recorta.
     */
    private function tabla(array $tabla): void
    {
        $this->Ln(1);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetFillColor(217, 217, 217);
        foreach ($tabla['columnas'] as [$titulo, $ancho]) {
            $this->Cell($ancho, 6, $this->t($titulo), 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 9);
        foreach ($tabla['filas'] as $i => $fila) {
            $resaltada = in_array($i, $tabla['resaltar'], true);
            $this->SetFillColor(252, 228, 214);
            foreach ($tabla['columnas'] as $j => [, $ancho]) {
                $this->Cell($ancho, 6, $this->recortar($this->t((string) ($fila[$j] ?? '')), $ancho - 2), 1, 0, 'L', $resaltada);
            }
            $this->Ln();
        }
    }

    /**
     * Recorta un texto (ya en Windows-1252) para que quepa en $ancho mm.
     */
    private function recortar(string $texto, float $ancho): string
    {
        if ($this->GetStringWidth($texto) <= $ancho) {
            return $texto;
        }
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $ancho) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    /**
     * UTF-8 => Windows-1252, la codificación de las fuentes estándar de FPDF.
     */
    private function t(string $texto): string
    {
        return (string) mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    }
}
