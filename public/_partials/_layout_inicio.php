<?php
/**
 * Layout de cabecera
 * Sidebar con menús por rol
 * Campanita conectada al API de notificaciones
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';

$usuarioActual = $usuarioActual ?? Auth::requerirSesion();
$tituloPagina = $tituloPagina ?? 'MedAlert';
$paginaActiva = $paginaActiva ?? '';

$partes = preg_split('/\s+/', $usuarioActual['nombre_completo']);
$iniciales = mb_strtoupper(mb_substr($partes[0], 0, 1). (count($partes) > 1 ? mb_substr($partes[1], 0, 1) : ''));

$resumen = DB::uno(
 "SELECT total, cumplidas, pendientes, vencidas
 FROM v_resumen_dia
 WHERE id_usuario = :u AND fecha_programada = CURDATE()",
 ['u' => $usuarioActual['id_usuario']]
);
$pendientesTomas = (int)($resumen['pendientes'] ?? 0) + (int)($resumen['vencidas'] ?? 0);

$tipo = $usuarioActual['tipo_nombre'];

// Conteo de notificaciones no leídas (campanita)
$noLeidas = (int)(DB::uno(
 "SELECT COUNT(*) AS n FROM notificaciones
 WHERE id_usuario = :u AND leida = FALSE AND canal = 'app'
 AND estado_envio IN ('pendiente','enviada')",
 ['u' => $usuarioActual['id_usuario']]
)['n'] ?? 0);

$menus = [
 ['id' => 'inicio', 'icono' => 'bi-house-door-fill', 'texto' => 'Inicio',
 'url' => 'dashboard.php', 'tipos' => ['Paciente','Medico','Administrador','Cuidador','Enfermero']],

 ['id' => 'medicamentos', 'icono' => 'bi-capsule-pill', 'texto' => 'Medicamentos',
 'url' => 'medicamentos.php', 'tipos' => ['Paciente','Medico']],

 ['id' => 'tomas', 'icono' => 'bi-check2-square', 'texto' => 'Tomas del día',
 'url' => 'tomas.php', 'tipos' => ['Paciente'],
 'badge' => $pendientesTomas],

 // /— Panel multi-paciente del médico
 ['id' => 'mis_pacientes', 'icono' => 'bi-clipboard2-pulse', 'texto' => 'Mis pacientes',
 'url' => 'panel_medico.php', 'tipos' => ['Medico']],

 // /— Reportes de adherencia con gráficas y comparativo
 ['id' => 'reportes', 'icono' => 'bi-graph-up-arrow', 'texto' => 'Reportes',
 'url' => 'reportes.php', 'tipos' => ['Medico','Paciente','Cuidador']],

 // — Vista familiar simplificada
 ['id' => 'vista_familiar', 'icono' => 'bi-people', 'texto' => 'Vista familiar',
 'url' => 'vista_familiar.php', 'tipos' => ['Cuidador']],

 // — Modo hospitalario (solo enfermería)
 ['id' => 'modo_hospitalario', 'icono' => 'bi-hospital', 'texto' => 'Modo hospitalario',
 'url' => 'modo_hospitalario.php', 'tipos' => ['Enfermero']],

 // — Alertas de interacciones
 ['id' => 'interacciones', 'icono' => 'bi-exclamation-triangle', 'texto' => 'Interacciones',
 'url' => 'interacciones.php', 'tipos' => ['Paciente','Medico']],

 // — Validación de cédulas (admin)
 ['id' => 'cedulas', 'icono' => 'bi-patch-check', 'texto' => 'Validar cédulas',
 'url' => 'cedulas.php', 'tipos' => ['Administrador']],

 // — Asignación médico-paciente (admin)
 ['id' => 'asignaciones', 'icono' => 'bi-diagram-3', 'texto' => 'Médicos y pacientes',
 'url' => 'asignaciones.php', 'tipos' => ['Administrador']],

 ['id' => 'usuarios', 'icono' => 'bi-people-fill', 'texto' => 'Gestión de usuarios',
 'url' => 'usuarios.php', 'tipos' => ['Administrador']],

 ['id' => 'perfil', 'icono' => 'bi-person-circle', 'texto' => 'Mi perfil',
 'url' => 'perfil.php', 'tipos' => ['Paciente','Medico','Administrador','Cuidador','Enfermero']],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= Auth::escapar($tituloPagina) ?> · MedAlert</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="app-layout">
 <!-- ════════ SIDEBAR ════════ -->
 <aside class="sidebar">
 <div class="sidebar-brand">
 <div class="sidebar-logo"><img src="assets/img/logo-sm.png" alt="MedAlert"></div>
 <span>MedAlert</span>
 </div>

 <div class="sidebar-section">Menú principal</div>
 <nav class="sidebar-nav">
 <?php foreach ($menus as $m): ?>
 <?php if (in_array($tipo, $m['tipos'], true)): ?>
 <a href="<?= $m['url'] ?>"
 class="sidebar-link <?= $paginaActiva === $m['id'] ? 'is-active' : '' ?>">
 <i class="bi <?= $m['icono'] ?>"></i>
 <span><?= $m['texto'] ?></span>
 <?php if (!empty($m['badge']) && $m['badge'] > 0): ?>
 <span class="sidebar-badge"><?= $m['badge'] ?></span>
 <?php endif; ?>
 </a>
 <?php endif; ?>
 <?php endforeach; ?>
 </nav>

 <div class="sidebar-user">
 <div class="avatar"><?= Auth::escapar($iniciales) ?></div>
 <div class="sidebar-user-info">
 <div class="sidebar-user-name"><?= Auth::escapar($usuarioActual['nombre_completo']) ?></div>
 <div class="sidebar-user-role"><?= Auth::escapar($tipo) ?></div>
 </div>
 <a href="logout.php" class="btn-logout" title="Cerrar sesión">
 <i class="bi bi-box-arrow-right"></i>
 </a>
 </div>
 </aside>

 <!-- ════════ MAIN ════════ -->
 <main class="app-main">
 <header class="app-header">
 <button class="menu-toggle" aria-label="Menú">
 <i class="bi bi-list"></i>
 </button>
 <h1 class="page-title"><?= Auth::escapar($tituloPagina) ?></h1>
 <div class="header-actions">
 <div class="notif-dropdown">
 <button id="btn-campanita" class="notification-btn" title="Notificaciones" type="button">
 <i class="bi bi-bell"></i>
 <span id="badge-no-leidas" class="notif-badge"
 <?= $noLeidas === 0 ? 'hidden' : '' ?>><?= $noLeidas ?></span>
 </button>
 <div id="panel-notificaciones" class="notif-panel" hidden>
 <div class="notif-header">
 <strong>Notificaciones</strong>
 <button id="btn-marcar-todas" class="notif-link" type="button">
 Marcar todas como leídas
 </button>
 </div>
 <div id="notif-lista" class="notif-lista">
 <div class="notif-vacio">Cargando…</div>
 </div>
 </div>
 </div>
 </div>
 </header>

 <div class="app-content">
