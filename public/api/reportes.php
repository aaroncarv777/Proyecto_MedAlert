<?php
/**
 * MedAlert · API de reportes 
 * --------------------------------------------------------------
 * GET ?accion=semanal&id_paciente=X[&desde=Y&hasta=Z]
 * GET ?accion=panel_medico
 * GET ?accion=comparativo&id_paciente=X[&desp=N]
 * GET ?accion=efectos&id_paciente=X (vista médico)
 * GET ?accion=detalle_omisiones&id_paciente=X — desglose motivos
 *
 * Solo el médico con el paciente asignado (o el propio paciente / su
 * familiar activo) puede consultar el reporte.
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/adherencia.php';
require_once __DIR__. '/../../includes/efectos_adversos.php';
require_once __DIR__. '/../../includes/familiar.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];
$accion = Helpers::get('accion');

/** ¿Este usuario puede ver el reporte del paciente indicado? */
function puedeVerPaciente(int $idUsuario, string $tipo, int $idPaciente): bool
{
 if ($tipo === 'Medico') {
 return Adherencia::medicoPuedeVer($idUsuario, $idPaciente);
 }
 if ($tipo === 'Paciente') {
 return $idUsuario === $idPaciente;
 }
 if ($tipo === 'Cuidador') {
 return Familiar::relacionActiva($idUsuario, $idPaciente) !== null;
 }
 return false;
}

if ($accion === 'panel_medico') {
 if ($tipo !== 'Medico') {
 Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 }
 Helpers::jsonRespuesta(['ok' => true, 'panel' => Adherencia::panelMedico($idUsuario)]);
}

$idPaciente = (int)Helpers::get('id_paciente');
if ($idPaciente <= 0) {
 Helpers::jsonRespuesta(['error' => 'Falta el paciente.'], 400);
}
if (!puedeVerPaciente($idUsuario, $tipo, $idPaciente)) {
 Helpers::jsonRespuesta(['error' => 'No tienes acceso a este paciente.'], 403);
}

$paciente = DB::uno(
 "SELECT id_usuario, nombre_completo, correo FROM usuarios WHERE id_usuario=:p",
 ['p' => $idPaciente]
);

if ($accion === 'semanal') {
 $desde = Helpers::get('desde');
 $hasta = Helpers::get('hasta');
 if ($desde === '' || $hasta === '') {
 [$desde, $hasta] = Adherencia::rangoSemana(0);
 }
 if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ||
 !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $desde > $hasta) {
 Helpers::jsonRespuesta(['error' => 'Rango de fechas no válido.'], 400);
 }
 $resumen = Adherencia::resumen($idPaciente, $desde, $hasta);
 $tendencia = Adherencia::tendenciaSemanal($idPaciente, 4);

 Helpers::jsonRespuesta([
 'ok' => true,
 'paciente' => $paciente,
 'resumen' => $resumen,
 'tendencia' => $tendencia,
 'umbral' => Adherencia::UMBRAL_CUMPLIMIENTO,
 ]);
}

if ($accion === 'comparativo') {
 $desp = max(0, (int)Helpers::get('desp', '0'));
 Helpers::jsonRespuesta([
 'ok' => true,
 'paciente' => $paciente,
 'comparativo' => Adherencia::comparativo($idPaciente, $desp),
 ]);
}

if ($accion === 'efectos') {
 $efectos = EfectosAdversos::historialPaciente($idPaciente);
 foreach ($efectos as &$e) {
 $e['efecto_etiqueta'] = Adherencia::etiquetaEfecto($e['efecto']);
 }
 Helpers::jsonRespuesta(['ok' => true, 'efectos' => $efectos]);
}

if ($accion === 'detalle_omisiones') {
 [$d, $h] = [Helpers::get('desde'), Helpers::get('hasta')];
 if ($d === '' || $h === '') [$d, $h] = Adherencia::rangoSemana(0);
 $filtro = Helpers::get('motivo'); // opcional

 $sql = "SELECT vc.fecha_programada, vc.hora_programada, vc.medicamento_nombre,
 vc.motivo_omision_codigo, vc.motivo_omision_descripcion,
 vc.clasificacion
 FROM v_tomas_clasificadas vc
 WHERE vc.id_paciente = :p
 AND vc.fecha_programada BETWEEN :d AND :h
 AND vc.clasificacion IN ('omitida_justificada','omitida_sin_justificar')";
 $params = ['p' => $idPaciente, 'd' => $d, 'h' => $h];
 if ($filtro !== '' && in_array($filtro,
 ['olvido','efecto_adverso','medicamento_no_disponible','indicacion_medica','otro'], true)) {
 $sql.= " AND vc.motivo_omision_codigo = :f";
 $params['f'] = $filtro;
 }
 $sql.= " ORDER BY vc.fecha_programada DESC, vc.hora_programada";
 $filas = DB::todos($sql, $params);
 foreach ($filas as &$f) {
 $f['motivo_etiqueta'] = Adherencia::etiquetaMotivo($f['motivo_omision_codigo']);
 }
 Helpers::jsonRespuesta(['ok' => true, 'omisiones' => $filas]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
