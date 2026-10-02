<?php
/**
 * MedAlert · API de efectos adversos
 * --------------------------------------------------------------
 * POST {accion: reportar, id_registro, efecto, descripcion?} — Paciente
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/efectos_adversos.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}
if (($usuario['tipo_nombre'] ?? '') !== 'Paciente') {
 Helpers::jsonRespuesta(['error' => 'Solo el paciente puede reportar efectos adversos.'], 403);
}

$datos = Helpers::leerJson();
if (($datos['accion'] ?? '') !== 'reportar') {
 Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$idRegistro = (int)($datos['id_registro'] ?? 0);
$efecto = trim($datos['efecto'] ?? '');
$descripcion= trim($datos['descripcion'] ?? '');

if ($idRegistro <= 0 || $efecto === '') {
 Helpers::jsonRespuesta(['error' => 'Datos incompletos.'], 400);
}

$r = EfectosAdversos::reportar(
 (int)$usuario['id_usuario'], $idRegistro, $efecto, $descripcion
);

if (isset($r['error'])) {
 Helpers::jsonRespuesta(['error' => $r['error']], 400);
}
Helpers::jsonRespuesta($r);
