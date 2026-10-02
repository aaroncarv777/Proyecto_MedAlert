<?php

require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';

$usuarioActual = Auth::requerirSesion();
$tituloPagina = 'Inicio';
$paginaActiva = 'inicio';

$idUsuario = (int)$usuarioActual['id_usuario'];
$tipo = $usuarioActual['tipo_nombre'];

$tomasHoy = [];
$resumenDia = null;
$totalMedicamentos = 0;

if ($tipo === 'Paciente') {
 $tomasHoy = DB::todos(
 "SELECT * FROM v_tomas_dia
 WHERE id_usuario = :u AND fecha_programada = CURDATE()
 ORDER BY hora_programada",
 ['u' => $idUsuario]
 );
 $resumenDia = DB::uno(
 "SELECT * FROM v_resumen_dia
 WHERE id_usuario = :u AND fecha_programada = CURDATE()",
 ['u' => $idUsuario]
 );
 $totalMedicamentos = (int)(DB::uno(
 "SELECT COUNT(*) AS n FROM medicamentos
 WHERE id_usuario_paciente = :u AND activo = TRUE",
 ['u' => $idUsuario]
 )['n'] ?? 0);
}

if ($tipo === 'Administrador') {
 $totalUsuarios = (int)(DB::uno("SELECT COUNT(*) AS n FROM usuarios")['n'] ?? 0);
 $totalPendientes = (int)(DB::uno(
 "SELECT COUNT(*) AS n FROM usuarios WHERE estado_cuenta = 'pendiente_verificacion'"
 )['n'] ?? 0);
 $totalMedicos = (int)(DB::uno(
 "SELECT COUNT(*) AS n FROM usuarios u
 INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE t.nombre = 'Medico'"
 )['n'] ?? 0);
}

if ($tipo === 'Medico') {
 $misPacientes = DB::todos(
 "SELECT DISTINCT u.id_usuario, u.nombre_completo, u.correo
 FROM usuarios u
 INNER JOIN medicamentos m ON m.id_usuario_paciente = u.id_usuario
 WHERE m.id_usuario_creador = :id AND m.activo = TRUE",
 ['id' => $idUsuario]
 );
}


if ($tipo === 'Cuidador') {
    $cuidPacientes = DB::todos(
        "SELECT p.id_usuario, p.nombre_completo, fp.parentesco
           FROM familiar_paciente fp
           JOIN usuarios p ON p.id_usuario = fp.id_paciente
          WHERE fp.id_familiar = :f AND fp.estado = 'activa'
          ORDER BY p.nombre_completo",
        ['f' => $idUsuario]
    );
}

if ($tipo === 'Enfermero') {
    $enfPacientes = (int)(DB::uno(
        "SELECT COUNT(DISTINCT te.id_paciente) AS n
           FROM turno_enfermeria te
          WHERE te.id_enfermero = :e AND te.activo = TRUE",
        ['e' => $idUsuario]
    )['n'] ?? 0);
    $enfPendientes = (int)(DB::uno(
        "SELECT COUNT(*) AS n
           FROM turno_enfermeria te
           JOIN registro_tomas rt ON rt.id_usuario = te.id_paciente
                AND rt.fecha_programada = CURDATE() AND rt.estado = 'pendiente'
          WHERE te.id_enfermero = :e AND te.activo = TRUE",
        ['e' => $idUsuario]
    )['n'] ?? 0);
}

require __DIR__. '/_partials/_layout_inicio.php';

$diasSemana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$hoy = $diasSemana[(int)date('w')]. ' '. (int)date('d'). ' de '. $meses[(int)date('n') - 1];
?>

<?php if ($tipo === 'Paciente'): ?>
 <div class="day-hero">
 <div class="day-counter">
 <div class="num"><?= (int)($resumenDia['cumplidas'] ?? 0) ?></div>
 <div class="of">de <?= (int)($resumenDia['total'] ?? 0) ?></div>
 </div>
 <div class="day-info">
 <div class="label">Hoy</div>
 <div class="title"><?= $hoy ?></div>
 <div class="summary">
 <?php if ($resumenDia && $resumenDia['total'] > 0): ?>
 <?= (int)$resumenDia['cumplidas'] ?> tomas cumplidas,
 <?= (int)$resumenDia['vencidas'] ?> vencidas,
 <?= (int)$resumenDia['pendientes'] ?> pendientes
 <?php else: ?>
 No tienes tomas programadas para hoy.
 <?php endif; ?>
 </div>
 </div>
 </div>

 <?php $msj = Helpers::mensajeMotivacional((int)($resumenDia['cumplidas'] ?? 0),
 (int)($resumenDia['total'] ?? 0));
 if ($msj): ?>
 <div class="alert alert-success">
 <i class="bi bi-trophy-fill"></i><div><?= Auth::escapar($msj) ?></div>
 </div>
 <?php endif; ?>

 <div class="stat-row">
 <div class="stat-card is-success">
 <div class="stat-card-label">Cumplimiento</div>
 <div class="stat-card-value"><?= (int)($resumenDia['porcentaje_cumplimiento'] ?? 0) ?>%</div>
 <div class="stat-card-meta">del día de hoy</div>
 </div>
 <div class="stat-card">
 <div class="stat-card-label">Medicamentos activos</div>
 <div class="stat-card-value"><?= $totalMedicamentos ?></div>
 <div class="stat-card-meta">en tu tratamiento</div>
 </div>
 <div class="stat-card is-danger">
 <div class="stat-card-label">Vencidas</div>
 <div class="stat-card-value"><?= (int)($resumenDia['vencidas'] ?? 0) ?></div>
 <div class="stat-card-meta">aún puedes registrarlas</div>
 </div>
 <div class="stat-card is-amber">
 <div class="stat-card-label">Pendientes</div>
 <div class="stat-card-value"><?= (int)($resumenDia['pendientes'] ?? 0) ?></div>
 <div class="stat-card-meta">por tomar</div>
 </div>
 </div>

 <?php
 $vencidas = array_filter($tomasHoy, fn($t) =>
 Helpers::calcularEstadoToma($t) === 'vencida' && $t['estado'] !== 'cumplida');
 $proximas = array_filter($tomasHoy, fn($t) =>
 $t['estado'] === 'pendiente' && Helpers::calcularEstadoToma($t) !== 'vencida');
 ?>

 <?php if (!empty($vencidas)): ?>
 <div class="page-section">
 <div class="page-section-header">
 <h2 class="page-section-title">
 <i class="bi bi-exclamation-triangle-fill" style="color:var(--red-500);"></i>
 Pendientes urgentes
 </h2>
 <span class="badge badge-red"><?= count($vencidas) ?> vencida(s)</span>
 </div>
 <div class="grid-2">
 <?php foreach ($vencidas as $t): ?>
 <?php require __DIR__. '/_partials/_card_toma.php'; ?>
 <?php endforeach; ?>
 </div>
 </div>
 <?php endif; ?>

 <?php if (!empty($proximas)): ?>
 <div class="page-section">
 <div class="page-section-header">
 <h2 class="page-section-title">
 <i class="bi bi-clock-history" style="color:var(--navy-700);"></i>
 Próximas tomas
 </h2>
 <a href="tomas.php" class="text-muted" style="font-size:13px;">Ver todas →</a>
 </div>
 <div class="grid-2">
 <?php foreach (array_slice($proximas, 0, 4) as $t): ?>
 <?php require __DIR__. '/_partials/_card_toma.php'; ?>
 <?php endforeach; ?>
 </div>
 </div>
 <?php endif; ?>

 <?php if (empty($tomasHoy)): ?>
 <div class="empty-state card">
 <i class="bi bi-calendar-check"></i>
 <h3>No tienes tomas programadas para hoy</h3>
 <p>Agrega un medicamento para empezar a recibir recordatorios.</p>
 <a href="medicamentos.php" class="btn btn-primary mt-2">
 <i class="bi bi-plus-circle"></i> Agregar medicamento
 </a>
 </div>
 <?php endif; ?>

<?php elseif ($tipo === 'Administrador'): ?>
 <div class="day-hero">
 <div class="day-counter">
 <div class="num"><i class="bi bi-shield-fill-check" style="font-size:30px;"></i></div>
 </div>
 <div class="day-info">
 <div class="label">Panel administrativo</div>
 <div class="title">Bienvenido, <?= Auth::escapar(explode(' ', $usuarioActual['nombre_completo'])[0]) ?></div>
 <div class="summary">Gestiona los usuarios y monitorea el estado del sistema.</div>
 </div>
 </div>

 <div class="stat-row">
 <div class="stat-card">
 <div class="stat-card-label">Usuarios totales</div>
 <div class="stat-card-value"><?= $totalUsuarios ?></div>
 <div class="stat-card-meta">activos en el sistema</div>
 </div>
 <div class="stat-card is-amber">
 <div class="stat-card-label">Pendientes verificación</div>
 <div class="stat-card-value"><?= $totalPendientes ?></div>
 <div class="stat-card-meta">aún no confirmaron correo</div>
 </div>
 <div class="stat-card is-success">
 <div class="stat-card-label">Médicos registrados</div>
 <div class="stat-card-value"><?= $totalMedicos ?></div>
 <div class="stat-card-meta">con credenciales activas</div>
 </div>
 </div>

 <div class="page-section">
 <div class="page-section-header">
 <h2 class="page-section-title">
 <i class="bi bi-lightning-charge"></i> Acciones rápidas
 </h2>
 </div>
 <div class="grid-3">
 <a href="usuarios.php" class="card" style="cursor:pointer; text-decoration:none; color:inherit;">
 <div style="display:flex; gap:14px; align-items:center;">
 <div style="width:48px; height:48px; border-radius:12px; background:var(--navy-50);
 color:var(--navy-700); display:flex; align-items:center; justify-content:center;
 font-size:22px;">
 <i class="bi bi-people-fill"></i>
 </div>
 <div>
 <div style="font-weight:700; color:var(--navy-900);">Gestionar usuarios</div>
 <div style="font-size:13px; color:var(--gray-500);">Asignar tipos y roles</div>
 </div>
 </div>
 </a>
 <a href="usuarios.php?tipo=Medico" class="card" style="cursor:pointer; text-decoration:none; color:inherit;">
 <div style="display:flex; gap:14px; align-items:center;">
 <div style="width:48px; height:48px; border-radius:12px; background:var(--green-50);
 color:var(--green-500); display:flex; align-items:center; justify-content:center;
 font-size:22px;">
 <i class="bi bi-clipboard-check-fill"></i>
 </div>
 <div>
 <div style="font-weight:700; color:var(--navy-900);">Validar médicos</div>
 <div style="font-size:13px; color:var(--gray-500);">Cédula profesional</div>
 </div>
 </div>
 </a>
 </div>
 </div>

<?php elseif ($tipo === 'Medico'): ?>
 <div class="day-hero">
 <div class="day-counter">
 <div class="num"><?= count($misPacientes) ?></div>
 <div class="of">pacientes</div>
 </div>
 <div class="day-info">
 <div class="label">Panel médico</div>
 <div class="title">Bienvenido, Dr(a). <?= Auth::escapar(explode(' ', $usuarioActual['nombre_completo'])[0]) ?></div>
 <div class="summary">Tienes <?= count($misPacientes) ?> paciente(s) bajo tu seguimiento.</div>
 </div>
 </div>

 <div class="page-section">
 <div class="page-section-header">
 <h2 class="page-section-title"><i class="bi bi-people-fill"></i> Mis pacientes</h2>
 <a href="medicamentos.php" class="text-muted" style="font-size:13px;">Ver todos los medicamentos →</a>
 </div>
 <?php if (empty($misPacientes)): ?>
 <div class="empty-state card">
 <i class="bi bi-people"></i>
 <h3>Aún no tienes pacientes asignados</h3>
 <p>Cuando prescribas un medicamento a un paciente, aparecerá aquí.</p>
 </div>
 <?php else: ?>
 <div class="grid-3">
 <?php foreach ($misPacientes as $p): ?>
 <div class="card">
 <div style="display:flex; gap:12px; align-items:center;">
 <div class="user-avatar"><?= mb_strtoupper(mb_substr($p['nombre_completo'], 0, 1)) ?></div>
 <div style="flex:1; min-width:0;">
 <div class="user-name"><?= Auth::escapar($p['nombre_completo']) ?></div>
 <div class="user-mail"><?= Auth::escapar($p['correo']) ?></div>
 </div>
 </div>
 </div>
 <?php endforeach; ?>
 </div>
 <?php endif; ?>
 </div>
<?php elseif ($tipo === 'Cuidador'): ?>
  <div class="day-hero">
    <div class="day-counter">
      <div class="num"><i class="bi bi-people" style="font-size:30px;"></i></div>
    </div>
    <div class="day-info">
      <div class="label">Bienvenido a MedAlert</div>
      <div class="title">Hola, <?= Auth::escapar(explode(' ', $usuarioActual['nombre_completo'])[0]) ?></div>
      <div class="summary">
        <?php if (empty($cuidPacientes)): ?>
          Aún no tienes acceso al seguimiento de ningún paciente.
        <?php else: ?>
          Tienes acceso al seguimiento de <?= count($cuidPacientes) ?> paciente(s).
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (empty($cuidPacientes)): ?>
    <div class="empty-state card">
      <i class="bi bi-person-heart"></i>
      <h3>Todavía no sigues a ningún paciente</h3>
      <p>Cuando un paciente te invite desde su perfil, aquí podrás ver
         un resumen de su tratamiento. Si ya te invitaron, revisa
         "Vista familiar" en el menú.</p>
    </div>
  <?php else: ?>
    <h2 style="margin:18px 0 12px;">Pacientes que sigues</h2>
    <div class="med-cards">
      <?php foreach ($cuidPacientes as $cp): ?>
        <div class="card" style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <strong><?= Auth::escapar($cp['nombre_completo']) ?></strong>
              <?php if ($cp['parentesco']): ?>
                <div style="font-size:12px;color:var(--gray-500);">
                  <?= Auth::escapar($cp['parentesco']) ?></div>
              <?php endif; ?>
            </div>
            <a class="btn btn-outline btn-sm" href="vista_familiar.php">
              Ver resumen</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:13px;color:var(--gray-500);margin-top:8px;">
      Entra a "Vista familiar" o "Reportes" en el menú para ver el detalle
      del cumplimiento de cada paciente.</p>
  <?php endif; ?>

<?php elseif ($tipo === 'Enfermero'): ?>
  <div class="day-hero">
    <div class="day-counter">
      <div class="num"><?= $enfPendientes ?></div>
    </div>
    <div class="day-info">
      <div class="label">Tomas pendientes hoy en tu turno</div>
      <div class="title">Hola, <?= Auth::escapar(explode(' ', $usuarioActual['nombre_completo'])[0]) ?></div>
      <div class="summary">
        <?php if ($enfPacientes === 0): ?>
          No tienes pacientes asignados a tu turno por el momento.
        <?php else: ?>
          Tienes <?= $enfPacientes ?> paciente(s) en tu turno y
          <?= $enfPendientes ?> toma(s) pendiente(s) de administrar hoy.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($enfPendientes > 0): ?>
    <div class="card" style="margin-top:16px;text-align:center;">
      <p style="margin:0 0 12px;">Tienes tomas por registrar en tu turno.</p>
      <a class="btn btn-primary" href="modo_hospitalario.php">
        <i class="bi bi-hospital"></i> Ir al modo hospitalario</a>
    </div>
  <?php else: ?>
    <div class="empty-state card">
      <i class="bi bi-clipboard-check"></i>
      <h3>No hay tomas pendientes en tu turno</h3>
      <p>Cuando haya pacientes con medicamentos por administrar
         aparecerán en "Modo hospitalario".</p>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="empty-state card">
    <i class="bi bi-house"></i>
    <h3>Bienvenido a MedAlert</h3>
    <p>Usa el menú lateral para acceder a las secciones disponibles
       para tu cuenta.</p>
  </div>
<?php endif; ?>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>
