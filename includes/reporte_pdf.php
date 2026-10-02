<?php
/**
 * Generador de PDF del reporte de adherencia
 *
 * Implementación mínima de PDF 1.4 (un solo archivo, sin dependencias
 * externas, igual que el resto del proyecto). Soporta: texto, rectángulos
 * de color (gráficas de barras), líneas (tendencia) y el membrete de
 * MedAlert. Incluye código de verificación y fecha de generación al pie.
 */

class ReportePDF
{
 private array $obj = [];
 private string $contenido = '';
 private float $y; // cursor vertical (desde arriba)
 private const W = 595.28; // A4 ancho pt
 private const H = 841.89; // A4 alto pt
 private const MX = 48; // margen lateral

 private string $codigoVerif;
 private string $fechaGen;

 public function __construct(string $codigoVerif, string $fechaGen)
 {
 $this->codigoVerif = $codigoVerif;
 $this->fechaGen = $fechaGen;
 $this->y = self::H - 56;
 }

 // ─── Primitivas de dibujo ────────────────────────────────────────────────

 private function texto(float $x, float $y, string $t, float $size = 10,
 bool $bold = false, array $rgb = [0, 0, 0]): void
 {
 $t = $this->esc($t);
 $f = $bold ? '/F2' : '/F1';
 $this->contenido.= sprintf(
 "q %.3f %.3f %.3f rg BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET Q\n",
 $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $f, $size, $x, $y, $t
 );
 }

 private function rect(float $x, float $y, float $w, float $h, array $rgb): void
 {
 $this->contenido.= sprintf(
 "q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
 $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $x, $y, $w, $h
 );
 }

 private function linea(float $x1, float $y1, float $x2, float $y2,
 array $rgb, float $w = 1): void
 {
 $this->contenido.= sprintf(
 "q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q\n",
 $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $w, $x1, $y1, $x2, $y2
 );
 }

 private function esc(string $s): string
 {
 // PDF WinAnsi: transliterar acentos comunes
 $s = strtr($s, [
 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
 'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
 'ü'=>'u','Ü'=>'U','°'=>' ','–'=>'-','—'=>'-','’'=>"'",
 '“'=>'"','”'=>'"','…'=>'...',
 ]);
 $s = preg_replace('/[^\x20-\x7E]/', '', $s);
 return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
 }

 // ─── Construcción del reporte ────────────────────────────────────────────

 public function construir(array $paciente, array $resumen,
 array $tendencia, int $umbral): void
 {
 $navy = [26, 58, 110];
 $red = [214, 40, 40];
 $amber= [245, 158, 11];
 $green= [22, 163, 74];
 $gray = [100, 116, 139];
 $dark = [30, 41, 59];

 // Membrete
 $this->rect(0, self::H - 96, self::W, 96, $navy);
 $this->texto(self::MX, self::H - 50, 'MedAlert', 24, true, [255,255,255]);
 $this->texto(self::MX, self::H - 70,
 'Reporte de adherencia al tratamiento', 11, false, [219,229,243]);
 $this->texto(self::W - 200, self::H - 50,
 'Documento clinico', 9, false, [219,229,243]);

 $this->y = self::H - 128;

 // Datos del paciente
 $this->texto(self::MX, $this->y, 'Paciente:', 10, true, $dark);
 $this->texto(self::MX + 60, $this->y,
 $paciente['nombre_completo'] ?? '', 10, false, $dark);
 $this->y -= 16;
 $this->texto(self::MX, $this->y, 'Periodo:', 10, true, $dark);
 $this->texto(self::MX + 60, $this->y,
 $this->fmt($resumen['desde']). ' al '. $this->fmt($resumen['hasta']),
 10, false, $dark);
 $this->y -= 28;

 if (!$resumen['hay_datos']) {
 $this->rect(self::MX, $this->y - 30, self::W - 2*self::MX, 44,
 [248,250,252]);
 $this->texto(self::MX + 16, $this->y - 8,
 'Sin datos suficientes para calcular en este periodo.',
 11, true, $gray);
 $this->y -= 60;
 $this->piePagina();
 return;
 }

 // Porcentaje general destacado
 $pct = $resumen['porcentaje'];
 $colorPct = $pct >= 80 ? $green : ($pct >= $umbral ? $amber : $red);
 $this->rect(self::MX, $this->y - 56, self::W - 2*self::MX, 64,
 [248,250,252]);
 $this->texto(self::MX + 16, $this->y - 16,
 'Cumplimiento general de la semana', 10, true, $gray);
 $this->texto(self::MX + 16, $this->y - 48,
 number_format($pct, 1). ' %', 30, true, $colorPct);
 $this->texto(self::W - 240, $this->y - 20,
 'Tomas cumplidas: '. $resumen['cumplidas']. ' / '. $resumen['total_tomas'], 10, false, $dark);
 $this->texto(self::W - 240, $this->y - 36,
 'Omisiones con motivo: '. $resumen['omisiones_justificadas'],
 10, false, $amber);
 $this->texto(self::W - 240, $this->y - 50,
 'Omisiones sin motivo: '. $resumen['omisiones_sin_justificar'],
 10, false, $red);
 $this->y -= 86;

 // Gráfica de barras por medicamento
 $this->texto(self::MX, $this->y, 'Cumplimiento por medicamento',
 12, true, $navy);
 $this->y -= 22;
 $bx = self::MX + 130;
 $bw = self::W - bx_pad() - $bx;
 foreach ($resumen['por_medicamento'] as $m) {
 $p = $m['porcentaje'];
 $col = $p >= 80 ? $green : ($p >= $umbral ? $amber : $red);
 $this->texto(self::MX, $this->y - 9,
 $this->trunc($m['nombre'], 20), 9, false, $dark);
 $this->rect($bx, $this->y - 13, $bw, 12, [226,232,240]);
 $this->rect($bx, $this->y - 13, max(1, $bw * $p / 100), 12, $col);
 $this->texto($bx + $bw + 6, $this->y - 11,
 number_format($p, 0). '%', 9, true, $dark);
 $this->y -= 22;
 }
 $this->y -= 10;

 // Distinción visual de omisiones (leyenda)
 $this->texto(self::MX, $this->y,
 'Omisiones justificadas (con motivo)', 9, false, $amber);
 $this->rect(self::MX - 14, $this->y - 1, 8, 8, $amber);
 $this->texto(self::MX + 230, $this->y,
 'Omisiones sin justificar', 9, false, $red);
 $this->rect(self::MX + 216, $this->y - 1, 8, 8, $red);
 $this->y -= 30;

 // Gráfica de tendencia (línea) de las últimas semanas
 if (count($tendencia) >= 1) {
 $this->texto(self::MX, $this->y,
 'Tendencia de las ultimas semanas', 12, true, $navy);
 $this->y -= 20;
 $gx = self::MX + 10;
 $gw = self::W - 2*self::MX - 20;
 $gh = 90;
 $gyBase = $this->y - $gh;
 // Ejes
 $this->linea($gx, $gyBase, $gx + $gw, $gyBase, [203,213,225], 1);
 $this->linea($gx, $gyBase, $gx, $gyBase + $gh, [203,213,225], 1);
 // Línea de umbral
 $uY = $gyBase + $gh * $umbral / 100;
 $this->linea($gx, $uY, $gx + $gw, $uY, [245,158,11], 0.6);
 $this->texto($gx + $gw - 70, $uY + 3,
 'Umbral '. $umbral. '%', 7, false, $amber);

 $n = count($tendencia);
 $step = $n > 1 ? $gw / ($n - 1) : 0;
 $px = $py = null;
 foreach ($tendencia as $i => $t) {
 $cx = $gx + ($n > 1 ? $i * $step : $gw / 2);
 $cy = $gyBase + $gh * $t['porcentaje'] / 100;
 if ($px !== null) {
 $this->linea($px, $py, $cx, $cy, $navy, 1.4);
 }
 $this->rect($cx - 2.5, $cy - 2.5, 5, 5, $navy);
 $this->texto($cx - 10, $cy + 8,
 number_format($t['porcentaje'], 0). '%', 7, true, $dark);
 $this->texto($cx - 18, $gyBase - 12,
 $this->trunc($t['etiqueta'], 12), 7, false, $gray);
 $px = $cx; $py = $cy;
 }
 $this->y = $gyBase - 28;
 }

 $this->piePagina();
 }

 private function piePagina(): void
    {
 $gray = [100, 116, 139];
 $this->linea(self::MX, 64, self::W - self::MX, 64, [203,213,225], 0.6);
 $this->texto(self::MX, 50,
 'Codigo de verificacion: '. $this->codigoVerif, 9, true, [26,58,110]);
 $this->texto(self::MX, 36,
 'Generado el '. $this->fmtHora($this->fechaGen). ' - MedAlert (c) 2026', 8, false, $gray);
 $this->texto(self::W - 210, 36,
 'Documento valido si el codigo coincide', 8, false, $gray);
 }

 private function fmt(string $f): string
 {
 $d = DateTime::createFromFormat('Y-m-d', $f);
 return $d ? $d->format('d/m/Y') : $f;
 }
 private function fmtHora(string $f): string
 {
 $d = DateTime::createFromFormat('Y-m-d H:i:s', $f);
 return $d ? $d->format('d/m/Y H:i') : $f;
 }
 private function trunc(string $s, int $n): string
 {
 $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
 if ($len <= $n) return $s;
 return (function_exists('mb_substr')
 ? mb_substr($s, 0, $n - 1) : substr($s, 0, $n - 1)). '.';
 }

 // ─── Ensamblado del archivo PDF ──────────────────────────────────────────

 public function salida(): string
    {
 $objs = [];
 $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
 $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
 $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ". self::W. " ". self::H. "] ". "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> ". "/Contents 4 0 R >>";
 $stream = $this->contenido;
 $objs[4] = "<< /Length ". strlen($stream). " >>\nstream\n". $stream. "\nendstream";
 $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica ". "/Encoding /WinAnsiEncoding >>";
 $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold ". "/Encoding /WinAnsiEncoding >>";

 $pdf = "%PDF-1.4\n";
 $offsets = [];
 foreach ($objs as $i => $o) {
 $offsets[$i] = strlen($pdf);
 $pdf.= "$i 0 obj\n$o\nendobj\n";
 }
 $xrefPos = strlen($pdf);
 $pdf.= "xref\n0 ". (count($objs) + 1). "\n";
 $pdf.= "0000000000 65535 f \n";
 for ($i = 1; $i <= count($objs); $i++) {
 $pdf.= sprintf("%010d 00000 n \n", $offsets[$i]);
 }
 $pdf.= "trailer\n<< /Size ". (count($objs) + 1). " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
 return $pdf;
 }
}

/** Ancho de relleno derecho para las barras (deja espacio al % a la derecha). */
function bx_pad(): float { return 48 + 44; }
