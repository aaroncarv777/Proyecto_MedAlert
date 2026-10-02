<?php
/**
 * MedAlert · API de modo hospitalario
 * --------------------------------------------------------------
 * GET ?accion=turno — Enfermero: tabla del turno
 * POST {accion: registrar_masivo, ids:[...]} — Enfermero: marcado masivo
 *
 * Acceso restringido EXCLUSIVAMENTE a usuarios con rol Enfermero.
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/hospitalario.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

// restricción dura por rol
if (!Hospitalario::esEnfermero($usuario)) {
 Helpers::jsonRespuesta([
 'error' => 'El modo hospitalario solo está disponible para personal de enfermería.'
 ], 403);
}

$idEnfermero = (int)$usuario['id_usuario'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
 if (Helpers::get('accion') === 'turno') {
 Helpers::jsonRespuesta([
 'ok' => true,
 'pacientes' => Hospitalario::tablaTurno($idEnfermero)
 ]);
 }
 Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}

$datos = Helpers::leerJson();
if (($datos['accion'] ?? '') === 'registrar_masivo') {
 $ids = $datos['ids'] ?? [];
 if (!is_array($ids)) {
 Helpers::jsonRespuesta(['error' => 'Lista de tomas no válida.'], 400);
 }
 $r = Hospitalario::registrarMasivo($idEnfermero, $ids);
 Helpers::jsonRespuesta(
 isset($r['error']) ? ['error' => $r['error']] : $r,
 isset($r['error']) ? 400 : 200
 );
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
