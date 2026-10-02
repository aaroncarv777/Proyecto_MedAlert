<?php
/**
 * Modo hospitalario
 * Solo rol Enfermero. Tabla de pacientes del turno con medicamentos
 * pendientes; registro masivo en una sola acción (transacción atómica).
 */
require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Enfermero']);
$tituloPagina = 'Modo hospitalario';
$paginaActiva = 'modo_hospitalario';

require __DIR__. '/_partials/_layout_inicio.php';
?>

<div class="page-header">
 <h1><i class="bi bi-hospital"></i> Modo hospitalario</h1>
 <p>Registra las tomas administradas a los pacientes de tu turno.</p>
</div>

<div id="mh-cargando" class="empty-state">
 <i class="bi bi-hourglass-split"></i><h3>Cargando turno…</h3>
</div>

<div id="mh-vacio" class="empty-state" hidden>
 <i class="bi bi-clipboard-check"></i>
 <h3>No hay tomas pendientes en tu turno</h3>
 <p>Cuando haya pacientes con medicamentos por administrar aparecerán aquí.</p>
</div>

<div id="mh-contenido" hidden>
 <div class="card" style="margin-bottom:16px;">
 <div class="card-body" style="display:flex;justify-content:space-between;
 align-items:center;flex-wrap:wrap;gap:12px;">
 <div>
 <strong id="mh-conteo">0</strong> tomas seleccionadas de
 <strong id="mh-total">0</strong> pendientes
 </div>
 <div style="display:flex;gap:8px;">
 <button class="btn btn-outline btn-sm" id="mh-todos">
 Seleccionar todo</button>
 <button class="btn btn-primary" id="mh-guardar" disabled>
 <i class="bi bi-check2-all"></i> Guardar marcados
 </button>
 </div>
 </div>
 </div>
 <div id="mh-pacientes"></div>
</div>

<script>
let datosTurno = [];

async function cargarTurno() {
 document.getElementById('mh-cargando').hidden = false;
 document.getElementById('mh-contenido').hidden = true;
 document.getElementById('mh-vacio').hidden = true;
 const r = await MedAlert.api('api/hospitalario.php?accion=turno');
 document.getElementById('mh-cargando').hidden = true;
 if (!r.ok) {
 MedAlert.toast(r.datos.error || 'No se pudo cargar el turno.', 'error');
 return;
 }
 datosTurno = r.datos.pacientes || [];
 const total = datosTurno.reduce((s,p)=>s+p.tomas.length,0);
 if (!total) {
 document.getElementById('mh-vacio').hidden = false;
 return;
 }
 document.getElementById('mh-contenido').hidden = false;
 document.getElementById('mh-total').textContent = total;
 pintar();
}

function pintar() {
 const cont = document.getElementById('mh-pacientes');
 cont.innerHTML = datosTurno.map(p => `
 <div class="card" style="margin-bottom:14px;">
 <div class="card-header" style="display:flex;justify-content:space-between;
 align-items:center;">
 <h3><i class="bi bi-person"></i> ${escapar(p.paciente)}</h3>
 <label style="font-size:12px;font-weight:600;cursor:pointer;">
 <input type="checkbox" class="chk-paciente"
 data-pac="${p.id_paciente}"> Todos de este paciente</label>
 </div>
 <div class="card-body" style="padding:0;">
 <table class="data-table">
 <thead><tr><th style="width:42px;"></th><th>Medicamento</th>
 <th>Dosis</th><th>Hora</th></tr></thead>
 <tbody>
 ${p.tomas.map(t => `<tr>
 <td><input type="checkbox" class="chk-toma"
 data-id="${t.id_registro}" data-pac="${p.id_paciente}"></td>
 <td><strong>${escapar(t.medicamento)}</strong>
 <div style="font-size:11px;color:var(--gray-500);">
 ${escapar(t.presentacion||'')}</div></td>
 <td>${escapar(t.dosis||'')}</td>
 <td>${fmtHora(t.hora_programada)}</td>
 </tr>`).join('')}
 </tbody>
 </table>
 </div>
 </div>`).join('');

 cont.querySelectorAll('.chk-toma').forEach(c =>
 c.addEventListener('change', actualizarConteo));
 cont.querySelectorAll('.chk-paciente').forEach(c =>
 c.addEventListener('change', e => {
 const pac = e.target.dataset.pac;
 cont.querySelectorAll(`.chk-toma[data-pac="${pac}"]`).forEach(t => t.checked = e.target.checked);
 actualizarConteo();
 }));
}

function seleccionadas() {
 return [...document.querySelectorAll('.chk-toma:checked')].map(c => parseInt(c.dataset.id));
}
function actualizarConteo() {
 const n = seleccionadas().length;
 document.getElementById('mh-conteo').textContent = n;
 document.getElementById('mh-guardar').disabled = (n === 0);
}

document.addEventListener('DOMContentLoaded', () => {
 if (!document.getElementById('mh-contenido')) return;

 document.getElementById('mh-todos').addEventListener('click', () => {
 const all = document.querySelectorAll('.chk-toma');
 const marcar = [...all].some(c => !c.checked);
 all.forEach(c => c.checked = marcar);
 document.querySelectorAll('.chk-paciente').forEach(c => c.checked = marcar);
 actualizarConteo();
 });

 document.getElementById('mh-guardar').addEventListener('click', async () => {
 const ids = seleccionadas();
 if (!ids.length) return;
 if (!await MedAlert.confirmar(
 `¿Registrar ${ids.length} toma(s) como administradas? `
 + `Esta acción quedará a tu nombre.`)) return;
 const r = await MedAlert.api('api/hospitalario.php', {
 method: 'POST',
 body: { accion: 'registrar_masivo', ids }
 });
 if (r.ok) {
 MedAlert.toast(
 `${r.datos.marcadas} toma(s) registradas correctamente.`, 'success');
 setTimeout(cargarTurno, 1200);
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo registrar.', 'error');
 }
 });

 cargarTurno();
});

function fmtHora(h) {
 const [H,M] = h.split(':'); let n=+H;
 const am = n<12?'a.m.':'p.m.'; n=n%12||12;
 return `${n}:${M} ${am}`;
}
function escapar(s){ return String(s).replace(/[<>&]/g,
 c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }
</script>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>
