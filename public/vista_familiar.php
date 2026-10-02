<?php
/**
 * Vista familiar simplificada
 * Solo rol Cuidador. Muestra 4 elementos por paciente: tomas del día,
 * % de cumplimiento de la semana, próximas 3 tomas. Sin gráficas.
 */
require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';
require_once __DIR__. '/../includes/familiar.php';

$usuarioActual = Auth::requerirTipo(['Cuidador']);
$tituloPagina = 'Vista familiar';
$paginaActiva = 'vista_familiar';
$idFamiliar = (int)$usuarioActual['id_usuario'];

$pacientes = Familiar::pacientesDe($idFamiliar);

require __DIR__. '/_partials/_layout_inicio.php';
?>

<div class="page-header">
 <h1><i class="bi bi-people"></i> Vista familiar</h1>
 <p>Seguimiento sencillo del tratamiento de tus seres queridos.</p>
</div>

<?php if (empty($pacientes)): ?>
 <div class="empty-state">
 <i class="bi bi-person-heart"></i>
 <h3>Todavía no tienes acceso a ningún paciente</h3>
 <p>Cuando un paciente te invite desde su perfil, podrás ver aquí
 el resumen de su medicación.</p>
 </div>
<?php else: ?>

<?php if (count($pacientes) > 1): ?>
<div class="form-group" style="max-width:320px;">
 <label>Paciente</label>
 <select id="sel-paciente" class="form-select">
 <?php foreach ($pacientes as $p): ?>
 <option value="<?= (int)$p['id_usuario'] ?>">
 <?= htmlspecialchars($p['nombre_completo']) ?>
 <?= $p['parentesco'] ? '('. htmlspecialchars($p['parentesco']). ')' : '' ?>
 </option>
 <?php endforeach; ?>
 </select>
</div>
<?php else: ?>
 <input type="hidden" id="sel-paciente" value="<?= (int)$pacientes[0]['id_usuario'] ?>">
<?php endif; ?>

<div id="vf-cargando" class="empty-state">
 <i class="bi bi-hourglass-split"></i><h3>Cargando…</h3>
</div>

<div id="vf-contenido" hidden>
 <h2 id="vf-nombre" style="margin:6px 0 16px;"></h2>

 <!-- 1 · Cumplimiento de la semana -->
 <div class="card" style="margin-bottom:16px;">
 <div class="card-body" style="text-align:center;">
 <div class="stat-card-label">Cumplimiento de esta semana</div>
 <div id="vf-pct" style="font-size:52px;font-weight:800;line-height:1.1;">—</div>
 <div id="vf-pct-msg" style="font-size:13px;color:var(--gray-500);"></div>
 </div>
 </div>

 <!-- 2 · Tomas de hoy -->
 <div class="card" style="margin-bottom:16px;">
 <div class="card-header"><h3><i class="bi bi-calendar-day"></i>
 Tomas de hoy</h3></div>
 <div class="card-body"><div id="vf-tomas-hoy"></div></div>
 </div>

 <!-- 3 · Próximas tomas -->
 <div class="card">
 <div class="card-header"><h3><i class="bi bi-clock-history"></i>
 Próximas 3 tomas</h3></div>
 <div class="card-body"><div id="vf-proximas"></div></div>
 </div>
</div>
<?php endif; ?>

<script>
function idPaciente() {
 return document.getElementById('sel-paciente').value;
}
function fmtFecha(f) {
 const d = new Date(f + 'T00:00:00');
 const hoy = new Date; hoy.setHours(0,0,0,0);
 const man = new Date(hoy); man.setDate(man.getDate+1);
 if (d.getTime === hoy.getTime) return 'Hoy';
 if (d.getTime === man.getTime) return 'Mañana';
 return d.toLocaleDateString('es-MX',{weekday:'long',day:'numeric',month:'short'});
}
function fmtHora(h) {
 const [H,M] = h.split(':'); let n=+H;
 const am = n<12?'a.m.':'p.m.'; n=n%12||12;
 return `${n}:${M} ${am}`;
}
const ESTADOS = {
 cumplida: ['Tomada','var(--green-500)','bi-check-circle-fill'],
 no_cumplida:['No tomada','var(--red-500)','bi-x-circle-fill'],
 pendiente: ['Pendiente','var(--gray-500)','bi-clock'],
 vencida: ['Atrasada','var(--amber-500)','bi-exclamation-circle-fill'],
};

async function cargar() {
 const id = idPaciente();
 document.getElementById('vf-cargando').hidden = false;
 document.getElementById('vf-contenido').hidden = true;
 const r = await MedAlert.api(
 `api/familiar.php?accion=resumen&id_paciente=${id}`);
 document.getElementById('vf-cargando').hidden = true;
 if (!r.ok) {
 MedAlert.toast(r.datos.error || 'No se pudo cargar.', 'error');
 return;
 }
 const d = r.datos.resumen;
 document.getElementById('vf-contenido').hidden = false;
 document.getElementById('vf-nombre').textContent = r.datos.paciente_nombre;

 // Cumplimiento
 const pctEl = document.getElementById('vf-pct');
 const msgEl = document.getElementById('vf-pct-msg');
 if (!d.hay_datos) {
 pctEl.textContent = '—';
 pctEl.style.color = 'var(--gray-400)';
 msgEl.textContent = 'Sin datos suficientes para calcular esta semana.';
 } else {
 pctEl.textContent = d.porcentaje + '%';
 pctEl.style.color = d.bajo_umbral ? 'var(--red-500)'
 : (d.porcentaje >= 80 ? 'var(--green-500)' : 'var(--amber-500)');
 msgEl.textContent = d.bajo_umbral
 ? 'Cumplimiento por debajo de lo recomendado. Quizá necesite apoyo.'
 : 'Buen seguimiento del tratamiento.';
 }

 // Tomas de hoy
 const th = document.getElementById('vf-tomas-hoy');
 if (!d.tomas_hoy.length) {
 th.innerHTML = '<p style="color:var(--gray-500);">No hay tomas '
 + 'programadas para hoy.</p>';
 } else {
 th.innerHTML = d.tomas_hoy.map(t => {
 const e = ESTADOS[t.estado] || ESTADOS.pendiente;
 return `<div style="display:flex;justify-content:space-between;
 align-items:center;padding:10px 0;border-bottom:1px solid var(--gray-100);">
 <div><strong>${escapar(t.medicamento)}</strong>
 <span style="color:var(--gray-500);">· ${fmtHora(t.hora_programada)}</span></div>
 <div style="color:${e[1]};font-weight:600;font-size:13px;">
 <i class="bi ${e[2]}"></i> ${e[0]}</div></div>`;
 }).join('');
 }

 // Próximas
 const pr = document.getElementById('vf-proximas');
 if (!d.proximas.length) {
 pr.innerHTML = '<p style="color:var(--gray-500);">No hay próximas '
 + 'tomas programadas.</p>';
 } else {
 pr.innerHTML = d.proximas.map(t => `
 <div style="display:flex;justify-content:space-between;padding:10px 0;
 border-bottom:1px solid var(--gray-100);">
 <strong>${escapar(t.medicamento)}</strong>
 <span style="color:var(--gray-600);">${fmtFecha(t.fecha_programada)}
 · ${fmtHora(t.hora_programada)}</span></div>`).join('');
 }
}
function escapar(s){ return String(s).replace(/[<>&]/g,
 c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }

document.addEventListener('DOMContentLoaded', () => {
 if (!document.getElementById('vf-contenido')) return;
 const sp = document.getElementById('sel-paciente');
 if (sp.tagName === 'SELECT') sp.addEventListener('change', cargar);
 cargar();
});
</script>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>
