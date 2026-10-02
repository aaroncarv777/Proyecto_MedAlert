<?php
/**
 * MedAlert · API de vista familiar
 * --------------------------------------------------------------
 * GET ?accion=mis_pacientes — Familiar: pacientes con acceso
 * GET ?accion=resumen&id_paciente=X — Familiar: resumen del paciente
 * GET ?accion=mis_familiares — Paciente: quién tiene acceso
 * POST {accion: invitar, correo, parentesco?} — Paciente
 * POST {accion: revocar, id_relacion} — Paciente
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/familiar.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
 $accion = Helpers::get('accion');

 if ($accion === 'mis_pacientes') {
 if ($tipo !== 'Cuidador') Helpers::jsonRespuesta(['error' => 'Solo familiares.'], 403);
 Helpers::jsonRespuesta([
 'ok' => true,
 'pacientes' => Familiar::pacientesDe($idUsuario)
 ]);
 }

 if ($accion === 'resumen') {
 if ($tipo !== 'Cuidador') Helpers::jsonRespuesta(['error' => 'Solo familiares.'], 403);
 $idPac = (int)Helpers::get('id_paciente');
 $rel = Familiar::relacionActiva($idUsuario, $idPac);
 if (!$rel) {
 Helpers::jsonRespuesta([
 'error' => 'No tienes acceso a este paciente o fue revocado.'
 ], 403);
 }
 Helpers::jsonRespuesta([
 'ok' => true,
 'paciente_nombre' => $rel['paciente_nombre'],
 'resumen' => Familiar::resumenPaciente($idPac),
 ]);
 }

 if ($accion === 'mis_familiares') {
 if ($tipo !== 'Paciente') Helpers::jsonRespuesta(['error' => 'Solo pacientes.'], 403);
 Helpers::jsonRespuesta([
 'ok' => true,
 'familiares' => Familiar::familiaresDe($idUsuario)
 ]);
 }

 Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}
if ($tipo !== 'Paciente') {
 Helpers::jsonRespuesta(['error' => 'Solo el paciente gestiona sus familiares.'], 403);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'invitar') {
 $r = Familiar::invitar(
 $idUsuario,
 $datos['correo'] ?? '',
 trim($datos['parentesco'] ?? '')
 );
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

if ($accion === 'revocar') {
 $idRel = (int)($datos['id_relacion'] ?? 0);
 $r = Familiar::revocar($idUsuario, $idRel);
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
