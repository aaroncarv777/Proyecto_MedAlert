<?php
/**
 * MedAlert · API de prescripciones digitales
 * --------------------------------------------------------------
 * GET ?accion=por_paciente&id_paciente=X — Médico: prescripciones que emitió
 * GET ?accion=mias — Paciente: prescripciones recibidas
 * POST {accion: crear, id_paciente,...campos} — Médico
 * POST {accion: modificar, id_prescripcion,...} — Médico
 * POST {accion: cancelar, id_prescripcion} — Médico
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/prescripciones.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
 $accion = Helpers::get('accion');

 if ($accion === 'por_paciente') {
 if ($tipo !== 'Medico') Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 $idPac = (int)Helpers::get('id_paciente');
 $lista = Prescripciones::listarPorPaciente($idUsuario, $idPac);
 Helpers::jsonRespuesta(['ok' => true, 'prescripciones' => $lista]);
 }

 if ($accion === 'mias') {
 if ($tipo !== 'Paciente') Helpers::jsonRespuesta(['error' => 'Solo pacientes.'], 403);
 $lista = Prescripciones::listarParaPaciente($idUsuario);
 Helpers::jsonRespuesta(['ok' => true, 'prescripciones' => $lista]);
 }

 Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}
if ($tipo !== 'Medico') {
 Helpers::jsonRespuesta(['error' => 'Solo médicos pueden gestionar prescripciones.'], 403);
}
if (empty($usuario['cedula_validada'] ?? null)) {
 // Reforzar: si el JWT no trae el flag, validar contra BD
 $u = DB::uno("SELECT cedula_validada FROM usuarios WHERE id_usuario=:i",
 ['i' => $idUsuario]);
 if (empty($u['cedula_validada'])) {
 Helpers::jsonRespuesta([
 'error' => 'Necesitas tener tu cédula validada para emitir prescripciones.'
 ], 403);
 }
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'crear') {
 $idPaciente = (int)($datos['id_paciente'] ?? 0);
 if ($idPaciente <= 0) Helpers::jsonRespuesta(['error' => 'Falta el paciente.'], 400);
 $r = Prescripciones::crear($idUsuario, $idPaciente, $datos);
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

if ($accion === 'modificar') {
 $idP = (int)($datos['id_prescripcion'] ?? 0);
 $r = Prescripciones::modificar($idUsuario, $idP, $datos);
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

if ($accion === 'cancelar') {
 $idP = (int)($datos['id_prescripcion'] ?? 0);
 $r = Prescripciones::cancelar($idUsuario, $idP);
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
