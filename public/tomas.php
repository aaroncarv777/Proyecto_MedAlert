<?php

require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Paciente']);
$tituloPagina = 'Tomas del día';
$paginaActiva = 'tomas';

$idUsuario = (int)$usuarioActual['id_usuario'];

$tomasHoy = DB::todos(
 "SELECT * FROM v_tomas_dia
 WHERE id_usuario = :u AND fecha_programada = CURDATE()
 ORDER BY hora_programada",
 ['u' => $idUsuario]
);
$resumen = DB::uno(
 "SELECT * FROM v_resumen_dia
 WHERE id_usuario = :u AND fecha_programada = CURDATE()",
 ['u' => $idUsuario]
);

$diasSemana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$hoy = $diasSemana[(int)date('w')]. ' '. (int)date('d'). ' de '. $meses[(int)date('n') - 1];

$vencidas = [];
$proximas = [];
$cumplidas = [];
foreach ($tomasHoy as $t) {
 if ($t['estado'] === 'cumplida' || $t['estado'] === 'no_cumplida') {
 $cumplidas[] = $t;
 } elseif (Helpers::calcularEstadoToma($t) === 'vencida') {
 $vencidas[] = $t;
 } else {
 $proximas[] = $t;
 }
}

require __DIR__. '/_partials/_layout_inicio.php';
?>

<div class="day-hero">
 <div class="day-counter">
 <div class="num"><?= (int)($resumen['cumplidas'] ?? 0) ?></div>
 <div class="of">de <?= (int)($resumen['total'] ?? 0) ?></div>
 </div>
 <div class="day-info">
 <div class="label">Hoy</div>
 <div class="title"><?= $hoy ?></div>
 <div class="summary">
 <?= (int)($resumen['cumplidas'] ?? 0) ?> tomas cumplidas,
 <?= (int)($resumen['vencidas'] ?? 0) ?> vencidas,
 <?= (int)($resumen['pendientes'] ?? 0) ?> pendientes
 </div>
 </div>
</div>

<?php $msj = Helpers::mensajeMotivacional((int)($resumen['cumplidas'] ?? 0),
 (int)($resumen['total'] ?? 0));
 if ($msj): ?>
<div class="alert alert-success">
 <i class="bi bi-trophy-fill"></i>
 <div><?= Auth::escapar($msj) ?></div>
</div>
<?php endif; ?>

<?php if (empty($tomasHoy)): ?>
<div class="empty-state card">
 <i class="bi bi-calendar-check"></i>
 <h3>No tienes tomas programadas para hoy</h3>
 <p>Agrega un medicamento desde la sección "Medicamentos" para empezar.</p>
 <a href="medicamentos.php" class="btn btn-primary mt-2">
 <i class="bi bi-plus-circle"></i> Ir a medicamentos
 </a>
</div>
<?php endif; ?>

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
 <span class="page-section-meta"><?= count($proximas) ?> programadas</span>
 </div>
 <div class="grid-2">
 <?php foreach ($proximas as $t): ?>
 <?php require __DIR__. '/_partials/_card_toma.php'; ?>
 <?php endforeach; ?>
 </div>
</div>
<?php endif; ?>

<?php if (!empty($cumplidas)): ?>
<div class="page-section">
 <div class="page-section-header">
 <h2 class="page-section-title">
 <i class="bi bi-check-circle-fill" style="color:var(--green-500);"></i>
 Cumplidas hoy
 </h2>
 <span class="page-section-meta"><?= count($cumplidas) ?> registradas</span>
 </div>
 <div class="grid-2">
 <?php foreach ($cumplidas as $t): ?>
 <?php require __DIR__. '/_partials/_card_toma.php'; ?>
 <?php endforeach; ?>
 </div>
</div>
<?php endif; ?>

<!-- Modal: confirmación opcional al marcar (Sprint Review) -->
<div class="modal-backdrop" id="modal-confirmar">
 <div class="modal">
 <div class="modal-header">
 <h3><i class="bi bi-question-circle"></i> Confirmar registro</h3>
 <button class="modal-close" data-modal-close><i class="bi bi-x-lg"></i></button>
 </div>
 <div class="modal-body">
 <p style="font-size:15px; color:var(--gray-800);" id="modal-confirmar-texto">
 ¿Confirmas que tomaste <strong id="modal-confirmar-medicamento">este medicamento</strong>?
 </p>
 <div class="alert alert-info mt-2">
 <i class="bi bi-info-circle-fill"></i>
 <div style="font-size:12.5px;">Tendrás 30 minutos para deshacer este registro si te equivocaste.</div>
 </div>
 </div>
 <div class="modal-footer">
 <button class="btn btn-outline" data-modal-close>Cancelar</button>
 <button class="btn btn-success" id="btn-confirmar-marcar">
 <i class="bi bi-check-lg"></i> Sí, confirmar
 </button>
 </div>
 </div>
</div>

<!-- Modal: motivo de omisión (obligatorio, motivos del catálogo) -->
<div class="modal-backdrop" id="modal-motivo">
 <div class="modal">
 <div class="modal-header">
 <h3><i class="bi bi-x-circle"></i> ¿Por qué no tomaste el medicamento?</h3>
 </div>
 <div class="modal-body">
 <div class="alert alert-info" style="font-size:12.5px;margin-bottom:14px;">
 <i class="bi bi-info-circle-fill"></i>
 <div>Indicar el motivo es obligatorio. Esta información ayuda a tu médico a entender tu tratamiento.</div>
 </div>
 <div class="form-group">
 <label>Motivo <span style="color:var(--red-500);">*</span></label>
 <select id="motivo-codigo" class="form-select">
 <option value="">— Selecciona un motivo —</option>
 <option value="olvido">Olvido</option>
 <option value="efecto_adverso">Efecto adverso</option>
 <option value="medicamento_no_disponible">Medicamento no disponible</option>
 <option value="indicacion_medica">Indicación médica</option>
 <option value="otro">Otro motivo (especificar)</option>
 </select>
 </div>
 <div class="form-group" id="motivo-texto-wrapper" hidden>
 <label>Describe el motivo (mínimo 5 caracteres)</label>
 <textarea class="form-textarea" id="motivo-texto" rows="3"
 placeholder="Ej.: viaje, sin agua disponible, cita médica..."></textarea>
 </div>
 </div>
 <div class="modal-footer">
 <button class="btn btn-danger" id="btn-confirmar-omision">
 <i class="bi bi-check-lg"></i> Registrar omisión
 </button>
 </div>
 </div>
</div>

<!-- Modal: reportar efecto adverso post-toma (, opcional) -->
<div class="modal-backdrop" id="modal-efecto">
 <div class="modal">
 <div class="modal-header">
 <h3><i class="bi bi-heart-pulse"></i> ¿Presentaste algún efecto?</h3>
 </div>
 <div class="modal-body">
 <div id="efecto-paso-1">
 <p style="font-size:14.5px;color:var(--gray-800);">
 Registraste tu toma de <strong id="efecto-medicamento">tu medicamento</strong>.
 ¿Presentaste algún efecto adverso después de tomarlo?
 </p>
 <div style="display:flex;gap:10px;margin-top:16px;">
 <button class="btn btn-outline" id="btn-efecto-no" style="flex:1;">
 No, todo bien
 </button>
 <button class="btn btn-primary" id="btn-efecto-si" style="flex:1;">
 Sí, reportar
 </button>
 </div>
 </div>
 <div id="efecto-paso-2" hidden>
 <div class="form-group">
 <label>¿Qué efecto presentaste? <span style="color:var(--red-500);">*</span></label>
 <select id="efecto-tipo" class="form-select">
 <option value="">— Selecciona —</option>
 <option value="nausea">Náusea</option>
 <option value="mareo">Mareo</option>
 <option value="dolor_cabeza">Dolor de cabeza</option>
 <option value="somnolencia">Somnolencia</option>
 <option value="malestar_estomacal">Malestar estomacal</option>
 <option value="reaccion_alergica">Reacción alérgica</option>
 <option value="otro">Otro (especificar)</option>
 </select>
 </div>
 <div class="form-group" id="efecto-desc-wrapper" hidden>
 <label>Describe el efecto (mínimo 5 caracteres)</label>
 <textarea class="form-textarea" id="efecto-desc" rows="3"
 placeholder="Describe lo que sentiste..."></textarea>
 </div>
 <div class="alert alert-warning" id="efecto-aviso-alergia" hidden
 style="font-size:12.5px;">
 <i class="bi bi-exclamation-triangle-fill"></i>
 <div>Al reportar una reacción alérgica, tu médico será notificado de inmediato por todos los canales.</div>
 </div>
 <div style="display:flex;gap:10px;margin-top:16px;">
 <button class="btn btn-outline" id="btn-efecto-cancelar" style="flex:1;">
 Cancelar
 </button>
 <button class="btn btn-danger" id="btn-efecto-enviar" style="flex:1;">
 Enviar reporte
 </button>
 </div>
 </div>
 </div>
 </div>
</div>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>

<script>

const CONFIRMAR_AL_MARCAR = <?= !empty($usuarioActual['confirmar_marcar_toma']) ? 'true' : 'false' ?>;

let estadoMarcado = { idRegistro: null, accion: null };

document.querySelectorAll('.js-marcar-toma').forEach(btn => {
 btn.addEventListener('click', () => {
 const idReg = parseInt(btn.dataset.id, 10);
 const accion = btn.dataset.accion;
 const med = btn.dataset.medicamento;
 estadoMarcado = { idRegistro: idReg, accion, medicamento: med };

 if (accion === 'no_cumplida') {
 document.getElementById('motivo-texto').value = '';
 MedAlert.abrirModal('modal-motivo');
 return;
 }
 if (CONFIRMAR_AL_MARCAR) {
 document.getElementById('modal-confirmar-medicamento').textContent = med;
 MedAlert.abrirModal('modal-confirmar');
 } else {
 enviarMarcado(idReg, accion);
 }
 });
});

document.getElementById('btn-confirmar-marcar').addEventListener('click', () => {
 MedAlert.cerrarModal('modal-confirmar');
 enviarMarcado(estadoMarcado.idRegistro, 'cumplida');
});

// mostrar/ocultar campo libre cuando se elige "otro"
document.getElementById('motivo-codigo').addEventListener('change', (e) => {
 const wrap = document.getElementById('motivo-texto-wrapper');
 wrap.hidden = (e.target.value !== 'otro');
});

document.getElementById('btn-confirmar-omision').addEventListener('click', () => {
 const codigo = document.getElementById('motivo-codigo').value;
 const texto = document.getElementById('motivo-texto').value.trim;
 if (!codigo) {
 MedAlert.toast('Selecciona el motivo de la omisión. Es obligatorio.', 'error');
 return;
 }
 if (codigo === 'otro' && texto.length < 5) {
 MedAlert.toast('Si eliges "Otro" describe el motivo (mínimo 5 caracteres).', 'error');
 return;
 }
 MedAlert.cerrarModal('modal-motivo');
 enviarMarcado(estadoMarcado.idRegistro, 'no_cumplida', texto, codigo);
});

// flujo de reporte de efecto adverso post-toma
let efectoIdRegistro = null;

function mostrarEfectoAdverso(idRegistro, medicamento) {
 efectoIdRegistro = idRegistro;
 document.getElementById('efecto-medicamento').textContent = medicamento || 'tu medicamento';
 document.getElementById('efecto-paso-1').hidden = false;
 document.getElementById('efecto-paso-2').hidden = true;
 document.getElementById('efecto-tipo').value = '';
 document.getElementById('efecto-desc').value = '';
 document.getElementById('efecto-desc-wrapper').hidden = true;
 document.getElementById('efecto-aviso-alergia').hidden = true;
 MedAlert.abrirModal('modal-efecto');
}

document.getElementById('btn-efecto-no').addEventListener('click', () => {
 MedAlert.cerrarModal('modal-efecto');
 setTimeout( () => location.reload, 300);
});
document.getElementById('btn-efecto-si').addEventListener('click', () => {
 document.getElementById('efecto-paso-1').hidden = true;
 document.getElementById('efecto-paso-2').hidden = false;
});
document.getElementById('btn-efecto-cancelar').addEventListener('click', () => {
 MedAlert.cerrarModal('modal-efecto');
 setTimeout( () => location.reload, 300);
});
document.getElementById('efecto-tipo').addEventListener('change', (e) => {
 document.getElementById('efecto-desc-wrapper').hidden = (e.target.value !== 'otro');
 document.getElementById('efecto-aviso-alergia').hidden =
 (e.target.value !== 'reaccion_alergica');
});
document.getElementById('btn-efecto-enviar').addEventListener('click', async () => {
 const tipo = document.getElementById('efecto-tipo').value;
 const desc = document.getElementById('efecto-desc').value.trim;
 if (!tipo) {
 MedAlert.toast('Selecciona el efecto que presentaste.', 'error');
 return;
 }
 if (tipo === 'otro' && desc.length < 5) {
 MedAlert.toast('Si eliges "Otro" describe el efecto (mínimo 5 caracteres).', 'error');
 return;
 }
 const r = await MedAlert.api('api/efectos_adversos.php', {
 method: 'POST',
 body: { accion: 'reportar', id_registro: efectoIdRegistro,
 efecto: tipo, descripcion: desc }
 });
 if (r.ok) {
 MedAlert.cerrarModal('modal-efecto');
 if (r.datos.urgente) {
 MedAlert.toast('Reporte enviado. Tu médico fue notificado de inmediato.', 'success', 5000);
 } else {
 MedAlert.toast('Efecto adverso reportado. Gracias por avisar.', 'success');
 }
 setTimeout( () => location.reload, 1500);
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo enviar el reporte.', 'error');
 }
});

async function enviarMarcado(idRegistro, accion, motivo = '', motivoCodigo = '') {
 const r = await MedAlert.api('api/tomas.php', {
 method: 'POST',
 body: { accion: 'marcar', id_registro: idRegistro, estado: accion,
 motivo: motivo, motivo_codigo: motivoCodigo },
 });
 if (r.ok) {
 let msg = 'Toma registrada correctamente. Puedes deshacer durante 30 minutos.';
 if (r.datos && r.datos.stock && r.datos.stock.estado_stock === 'bajo') {
 msg += ' Tu stock está bajo.';
 } else if (r.datos && r.datos.stock && r.datos.stock.estado_stock === 'agotado') {
 msg += ' Te quedaste sin stock.';
 }
 MedAlert.toast(msg, 'success');
 // si fue cumplida, ofrecer reportar efecto adverso (opcional)
 if (r.datos && r.datos.ofrecer_efecto_adverso) {
 setTimeout( () => mostrarEfectoAdverso(idRegistro, estadoMarcado.medicamento), 900);
 } else {
 setTimeout( () => location.reload, 1000);
 }
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo registrar la toma.', 'error');
 }
}

document.querySelectorAll('.js-deshacer-toma').forEach(btn => {
 btn.addEventListener('click', async () => {
 const ok = await MedAlert.confirmar('¿Quieres deshacer este registro? La toma volverá a estado pendiente.');
 if (!ok) return;
 const idReg = parseInt(btn.dataset.id, 10);
 const r = await MedAlert.api('api/tomas.php', {
 method: 'POST',
 body: { accion: 'deshacer', id_registro: idReg }
 });
 if (r.ok) {
 MedAlert.toast('Registro deshecho.', 'success');
 setTimeout( () => location.reload, 600);
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo deshacer.', 'error');
 }
 });
});
</script>
