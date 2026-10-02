<?php
/**
 * MedAlert · API de interacciones medicamentosas (Sprint 2 — HU-08)
 * --------------------------------------------------------------
 * GET  ?accion=listar&id_paciente=X&estado=activa|aceptada|obsoleta
 * POST {accion: aceptar, id_alerta, justificacion}   ← solo Medico
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/interacciones.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $accion = Helpers::get('accion');

    if ($accion === 'listar') {
        $idPaciente = (int)Helpers::get('id_paciente');
        $estado = Helpers::get('estado') ?: null;

        if ($idPaciente <= 0) {
            // Si no especifica, el paciente lo asume sobre sí mismo
            if ($tipo === 'Paciente') $idPaciente = $idUsuario;
            else Helpers::jsonRespuesta(['error' => 'Debes indicar el paciente.'], 400);
        }

        // Validación de permisos
        if ($tipo === 'Paciente' && $idPaciente !== $idUsuario) {
            Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
        }
        if ($tipo === 'Medico') {
            $rel = DB::uno(
                "SELECT 1 FROM medico_paciente
                  WHERE id_medico = :m AND id_paciente = :p AND activo = TRUE",
                ['m' => $idUsuario, 'p' => $idPaciente]
            );
            if (!$rel) Helpers::jsonRespuesta(['error' => 'No tienes a este paciente asignado.'], 403);
        }

        $alertas = Interacciones::alertasPaciente($idPaciente, $estado);
        Helpers::jsonRespuesta(['ok' => true, 'alertas' => $alertas]);
    }

    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'aceptar') {
    if ($tipo !== 'Medico') {
        Helpers::jsonRespuesta([
            'error' => 'Sólo el médico puede aceptar una alerta de interacción.'
        ], 403);
    }
    if (empty($usuario['cedula_validada'])) {
        Helpers::jsonRespuesta([
            'error' => 'Tus credenciales aún no han sido validadas. No puedes aceptar alertas.'
        ], 403);
    }

    $idAlerta = (int)($datos['id_alerta'] ?? 0);
    $justificacion = trim($datos['justificacion'] ?? '');

    if ($idAlerta <= 0) Helpers::jsonRespuesta(['error' => 'ID de alerta no válido.'], 400);

    $len = mb_strlen($justificacion);
    if ($len < JUSTIFICACION_INTERACCION_MIN) {
        Helpers::jsonRespuesta([
            'error' => 'La justificación clínica debe tener al menos '
                . JUSTIFICACION_INTERACCION_MIN . ' caracteres.'
        ], 400);
    }
    if ($len > JUSTIFICACION_INTERACCION_MAX) {
        Helpers::jsonRespuesta([
            'error' => 'La justificación no puede superar '
                . JUSTIFICACION_INTERACCION_MAX . ' caracteres.'
        ], 400);
    }

    $r = Interacciones::aceptarAlerta($idAlerta, $idUsuario, $justificacion);
    if (!$r['ok']) Helpers::jsonRespuesta(['error' => $r['error']], 400);
    Helpers::jsonRespuesta(['ok' => true]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
