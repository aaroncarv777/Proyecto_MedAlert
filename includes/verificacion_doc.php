<?php
/**
 * Código de verificación de documentos clínicos
 *
 * Cada PDF/Excel generado lleva un código único y la fecha de generación,
 * para que el documento pueda autenticarse si después alguien lo cuestiona.
 * El código es determinista respecto al paciente + rango + timestamp, así
 * que dos descargas del mismo reporte en el mismo minuto comparten código.
 */

require_once __DIR__. '/db.php';

class VerificacionDoc
{
 /**
 * Genera el código de verificación de un reporte.
 * Formato legible: MA-XXXX-XXXX-XXXX
 */
 public static function generar(int $idPaciente, string $desde,
 string $hasta, string $tipo): array
 {
 $ts = date('Y-m-d H:i:s');
 $semilla = sprintf('%d|%s|%s|%s|%s|%s',
 $idPaciente, $desde, $hasta, $tipo, date('Y-m-d H:i'),
 JWT_SECRETO);
 $hash = strtoupper(substr(hash('sha256', $semilla), 0, 12));
 $codigo = 'MA-'. substr($hash, 0, 4). '-'. substr($hash, 4, 4). '-'. substr($hash, 8, 4);
 return [
 'codigo' => $codigo,
 'fecha_generacion'=> $ts,
 ];
 }
}
