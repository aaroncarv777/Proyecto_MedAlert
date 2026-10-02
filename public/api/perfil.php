<?php
/**
 * MedAlert · API de perfil (Sprint 2)
 * --------------------------------------------------------------
 * POST {accion: guardar_datos_clinicos, peso_kg, fecha_nacimiento, hora_desayuno, hora_comida, hora_cena}
 * POST {accion: guardar_preferencias_notificacion, canal_app, canal_correo, canal_sms, aviso_temprano, aviso_recordatorio, aviso_tardio}
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'guardar_datos_clinicos') {
    $peso = $datos['peso_kg'] !== null && $datos['peso_kg'] !== ''
        ? (float)$datos['peso_kg'] : null;
    if ($peso !== null && ($peso < DOSIS_PESO_MIN_KG || $peso > DOSIS_PESO_MAX_KG)) {
        Helpers::jsonRespuesta([
            'error' => 'El peso debe estar entre ' . DOSIS_PESO_MIN_KG
                . ' y ' . DOSIS_PESO_MAX_KG . ' kg.'
        ], 400);
    }
    $nacimiento = $datos['fecha_nacimiento'] ?? null;
    if ($nacimiento && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nacimiento)) {
        Helpers::jsonRespuesta(['error' => 'Fecha de nacimiento inválida.'], 400);
    }
    foreach (['hora_desayuno','hora_comida','hora_cena'] as $h) {
        if (!empty($datos[$h]) && !preg_match('/^\d{2}:\d{2}/', $datos[$h])) {
            Helpers::jsonRespuesta(['error' => 'Hora de comida inválida.'], 400);
        }
    }

    DB::consultar(
        "UPDATE usuarios
            SET peso_kg = :p, fecha_nacimiento = :n,
                hora_desayuno = :hd, hora_comida = :hc, hora_cena = :hn
          WHERE id_usuario = :id",
        [
            'p' => $peso, 'n' => $nacimiento ?: null,
            'hd' => $datos['hora_desayuno'] ?: '08:00:00',
            'hc' => $datos['hora_comida']   ?: '14:00:00',
            'hn' => $datos['hora_cena']     ?: '20:00:00',
            'id' => $idUsuario,
        ]
    );
    Helpers::jsonRespuesta(['ok' => true]);
}

if ($accion === 'guardar_preferencias_notificacion') {
    DB::consultar(
        "UPDATE usuarios
            SET canal_app_activo    = :ca,
                canal_correo_activo = :cc,
                canal_sms_activo    = :cs,
                aviso_temprano_activo    = :at,
                aviso_recordatorio_activo = :ar,
                aviso_tardio_activo      = :ad
          WHERE id_usuario = :id",
        [
            'ca' => !empty($datos['canal_app']) ? 1 : 0,
            'cc' => !empty($datos['canal_correo']) ? 1 : 0,
            'cs' => !empty($datos['canal_sms']) ? 1 : 0,
            'at' => !empty($datos['aviso_temprano']) ? 1 : 0,
            'ar' => !empty($datos['aviso_recordatorio']) ? 1 : 0,
            'ad' => !empty($datos['aviso_tardio']) ? 1 : 0,
            'id' => $idUsuario,
        ]
    );
    Helpers::jsonRespuesta(['ok' => true]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
