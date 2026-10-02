<?php
/**
 * MedAlert · API de notificaciones (Sprint 2 — HU-12 + HU-13)
 * --------------------------------------------------------------
 * Endpoints:
 *   GET  ?accion=pendientes       — Lista las últimas 30 notificaciones in-app del usuario
 *   GET  ?accion=contar_no_leidas — Devuelve el conteo para la campanita
 *   POST {accion: marcar_leida, id_notificacion}
 *   POST {accion: marcar_todas_leidas}
 *   POST {accion: ejecutar_pendientes} — Cron disparable desde frontend (admin/cron)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notificaciones.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $accion = Helpers::get('accion');

    if ($accion === 'pendientes') {
        $lista = Notificaciones::pendientesUsuario($idUsuario, 30);
        Helpers::jsonRespuesta([
            'ok' => true,
            'notificaciones' => $lista,
            'no_leidas' => Notificaciones::contarNoLeidas($idUsuario),
        ]);
    }

    if ($accion === 'contar_no_leidas') {
        Helpers::jsonRespuesta([
            'ok' => true,
            'no_leidas' => Notificaciones::contarNoLeidas($idUsuario),
        ]);
    }

    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'marcar_leida') {
    $idNotif = (int)($datos['id_notificacion'] ?? 0);
    if ($idNotif <= 0) Helpers::jsonRespuesta(['error' => 'ID no válido.'], 400);
    Notificaciones::marcarLeida($idNotif, $idUsuario);
    Helpers::jsonRespuesta(['ok' => true]);
}

if ($accion === 'marcar_todas_leidas') {
    $n = Notificaciones::marcarTodasLeidas($idUsuario);
    Helpers::jsonRespuesta(['ok' => true, 'marcadas' => $n]);
}

if ($accion === 'ejecutar_pendientes') {
    // Cualquier usuario autenticado puede dispararlo desde la app (cron-like).
    // En producción debería protegerse adicionalmente, pero aquí sirve como
    // mecanismo de "auto-cron" mientras se hace polling del frontend.
    $resumen = Notificaciones::procesarPendientes();
    Helpers::jsonRespuesta(['ok' => true, 'resumen' => $resumen]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
