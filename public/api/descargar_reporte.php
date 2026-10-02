<?php
/**
 * MedAlert · API de descarga de reportes
 * --------------------------------------------------------------
 * GET ?formato=pdf|excel&id_paciente=X[&desde=Y&hasta=Z]
 *
 * Genera el reporte de adherencia descargable con código de verificación.
 * · PDF → clase ReportePDF (nativa, sin dependencias)
 * · Excel → SpreadsheetML 2003 (.xls que Excel abre nativo), 3 hojas:
 * Resumen, Detalle diario, Por medicamento.
 * El control de acceso es idéntico al de reportes.php.
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/adherencia.php';
require_once __DIR__. '/../../includes/familiar.php';
require_once __DIR__. '/../../includes/verificacion_doc.php';
require_once __DIR__. '/../../includes/reporte_pdf.php';

$usuario = Auth::usuarioActual();
if (!$usuario) {
 http_response_code(401);
 exit('Sesión no válida.');
}

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];

function puedeVer(int $idUsuario, string $tipo, int $idPaciente): bool
{
 if ($tipo === 'Medico') return Adherencia::medicoPuedeVer($idUsuario, $idPaciente);
 if ($tipo === 'Paciente') return $idUsuario === $idPaciente;
 if ($tipo === 'Cuidador') return Familiar::relacionActiva($idUsuario, $idPaciente) !== null;
 return false;
}

$idPaciente = (int)Helpers::get('id_paciente');
$formato = Helpers::get('formato', 'pdf');
$desde = Helpers::get('desde');
$hasta = Helpers::get('hasta');

if ($idPaciente <= 0 || !puedeVer($idUsuario, $tipo, $idPaciente)) {
 http_response_code(403);
 exit('No tienes acceso a este reporte.');
}
if ($desde === '' || $hasta === '') {
 [$desde, $hasta] = Adherencia::rangoSemana(0);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ||
 !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $desde > $hasta) {
 http_response_code(400);
 exit('Rango de fechas no válido.');
}

$paciente = DB::uno(
 "SELECT id_usuario, nombre_completo, correo FROM usuarios WHERE id_usuario=:p",
 ['p' => $idPaciente]
);
$resumen = Adherencia::resumen($idPaciente, $desde, $hasta);
$tendencia = Adherencia::tendenciaSemanal($idPaciente, 4);
$verif = VerificacionDoc::generar($idPaciente, $desde, $hasta, $formato);

$nombreArchivo = 'MedAlert_Reporte_'. preg_replace('/[^A-Za-z0-9]/', '', $paciente['nombre_completo'] ?? 'paciente'). '_'. $desde. '_'. $hasta;

if ($formato === 'pdf') {
 $pdf = new ReportePDF($verif['codigo'], $verif['fecha_generacion']);
 $pdf->construir($paciente, $resumen, $tendencia,
 Adherencia::UMBRAL_CUMPLIMIENTO);
 $bin = $pdf->salida();
 header('Content-Type: application/pdf');
 header('Content-Disposition: attachment; filename="'. $nombreArchivo. '.pdf"');
 header('Content-Length: '. strlen($bin));
 echo $bin;
 exit;
}

if ($formato === 'excel') {
 $xml = construirExcel($paciente, $resumen, $tendencia, $verif,
 $desde, $hasta);
 header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
 header('Content-Disposition: attachment; filename="'. $nombreArchivo. '.xls"');
 header('Content-Length: '. strlen($xml));
 echo $xml;
 exit;
}

http_response_code(400);
exit('Formato no válido. Usa pdf o excel.');


/* ─── Generación de Excel (SpreadsheetML 2003, sin dependencias) ──────────── */

function xmlEsc(string $s): string
{
 return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function celda($valor, string $tipo = 'String', string $estilo = ''): string
{
 $st = $estilo !== '' ? ' ss:StyleID="'. $estilo. '"' : '';
 if ($valor === null || $valor === '') {
 return "<Cell$st><Data ss:Type=\"String\"></Data></Cell>";
 }
 $v = $tipo === 'Number' ? $valor : xmlEsc((string)$valor);
 return "<Cell$st><Data ss:Type=\"$tipo\">$v</Data></Cell>";
}

function fila(array $celdas): string
{
 return '<Row>'. implode('', $celdas). '</Row>';
}

function construirExcel(array $pac, array $res, array $tend, array $verif,
 string $desde, string $hasta): string
{
 $nom = $pac['nombre_completo'] ?? '';

 // Hoja 1 · Resumen
 $h1 = fila([celda('REPORTE DE ADHERENCIA — MedAlert', 'String', 'titulo')]);
 $h1.= fila([celda('Paciente', 'String', 'eti'), celda($nom)]);
 $h1.= fila([celda('Periodo', 'String', 'eti'),
 celda($desde. ' al '. $hasta)]);
 $h1.= fila([celda('Código de verificación', 'String', 'eti'),
 celda($verif['codigo'], 'String', 'codigo')]);
 $h1.= fila([celda('Generado', 'String', 'eti'),
 celda($verif['fecha_generacion'])]);
 $h1.= fila([celda('')]);
 if ($res['hay_datos']) {
 $h1.= fila([celda('Cumplimiento general (%)', 'String', 'eti'),
 celda($res['porcentaje'], 'Number', 'dato')]);
 $h1.= fila([celda('Tomas cumplidas', 'String', 'eti'),
 celda($res['cumplidas'], 'Number')]);
 $h1.= fila([celda('Total de tomas', 'String', 'eti'),
 celda($res['total_tomas'], 'Number')]);
 $h1.= fila([celda('Omisiones con motivo', 'String', 'eti'),
 celda($res['omisiones_justificadas'], 'Number')]);
 $h1.= fila([celda('Omisiones sin motivo', 'String', 'eti'),
 celda($res['omisiones_sin_justificar'], 'Number')]);
 } else {
 $h1.= fila([celda('Sin datos suficientes para calcular', 'String', 'eti')]);
 }

 // Hoja 2 · Detalle diario
 $h2 = fila([celda('Fecha', 'String', 'cab'),
 celda('Tomas', 'String', 'cab'),
 celda('Cumplidas', 'String', 'cab'),
 celda('Cumplimiento (%)', 'String', 'cab')]);
 foreach ($res['por_dia'] as $d) {
 $h2.= fila([celda($d['fecha']),
 celda($d['total'], 'Number'),
 celda($d['cumplidas'], 'Number'),
 celda($d['porcentaje'], 'Number')]);
 }
 if (empty($res['por_dia'])) {
 $h2.= fila([celda('Sin registros en el periodo')]);
 }

 // Hoja 3 · Por medicamento
 $h3 = fila([celda('Medicamento', 'String', 'cab'),
 celda('Tomas', 'String', 'cab'),
 celda('Cumplidas', 'String', 'cab'),
 celda('Omisiones c/motivo', 'String', 'cab'),
 celda('Omisiones s/motivo', 'String', 'cab'),
 celda('Cumplimiento (%)', 'String', 'cab')]);
 foreach ($res['por_medicamento'] as $m) {
 $h3.= fila([celda($m['nombre']),
 celda($m['total'], 'Number'),
 celda($m['cumplidas'], 'Number'),
 celda($m['omit_just'], 'Number'),
 celda($m['omit_sin'], 'Number'),
 celda($m['porcentaje'], 'Number')]);
 }
 if (empty($res['por_medicamento'])) {
 $h3.= fila([celda('Sin registros en el periodo')]);
 }

 return '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
 <Style ss:ID="titulo"><Font ss:Bold="1" ss:Size="14"
 ss:Color="#1A3A6E"/></Style>
 <Style ss:ID="eti"><Font ss:Bold="1" ss:Color="#334155"/></Style>
 <Style ss:ID="cab"><Font ss:Bold="1" ss:Color="#FFFFFF"/>
 <Interior ss:Color="#1A3A6E" ss:Pattern="Solid"/></Style>
 <Style ss:ID="dato"><Font ss:Bold="1" ss:Size="12"/></Style>
 <Style ss:ID="codigo"><Font ss:Bold="1" ss:Color="#1A3A6E"/></Style>
 </Styles>
 <Worksheet ss:Name="Resumen"><Table>'. $h1. '</Table></Worksheet>
 <Worksheet ss:Name="Detalle diario"><Table>'. $h2. '</Table></Worksheet>
 <Worksheet ss:Name="Por medicamento"><Table>'. $h3. '</Table></Worksheet>
</Workbook>';
}
