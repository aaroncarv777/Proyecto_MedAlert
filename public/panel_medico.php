<?php
/**
 * Panel multi-paciente del médico
 * Tabla ordenable de todos los pacientes con su % de la semana, omisiones
 * y alertas. Filas < 70% en rojo. "Sin datos" al final. Click abre el
 * reporte completo con notas clínicas (visibles al paciente, con fecha),
 * prescripción digital e historial de efectos adversos.
 */
require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';
require_once __DIR__. '/../includes/adherencia.php';

$usuarioActual = Auth::requerirTipo(['Medico']);
$tituloPagina = 'Panel de pacientes';
$paginaActiva = 'mis_pacientes';
$idMedico = (int)$usuarioActual['id_usuario'];
$cedulaValidada = !empty($usuarioActual['cedula_validada']);

require __DIR__. '/_partials/_layout_inicio.php';
?>

<div class="page-header">
 <h1><i class="bi bi-clipboard2-pulse"></i> Panel de pacientes</h1>
 <p>Vista general del cumplimiento semanal. Toca un paciente para ver
 su reporte completo, notas clínicas y prescripciones.</p>
</div>

<?php if (!$cedulaValidada): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
 <i class="bi bi-patch-exclamation"></i>
 <div>Tu cédula aún no está validada. Podrás ver a tus pacientes pero
 no emitir prescripciones digitales hasta que se apruebe.</div>
</div>
<?php endif; ?>

<div id="pm-cargando" class="empty-state">
 <i class="bi bi-hourglass-split"></i><h3>Cargando pacientes…</h3>
</div>

<div id="pm-vacio" class="empty-state" hidden>
 <i class="bi bi-people"></i>
 <h3>Aún no tienes pacientes asignados</h3>
 <p>Cuando el administrador te asigne pacientes aparecerán aquí.</p>
</div>

<div class="card" id="pm-tabla-card" hidden>
 <div class="card-body" style="padding:0;">
 <div class="data-table-wrap">
 <table class="data-table" id="pm-tabla">
 <thead><tr>
 <th data-sort="nombre" style="cursor:pointer;">Paciente ⇅</th>
 <th data-sort="porcentaje" style="cursor:pointer;">Cumplimiento ⇅</th>
 <th data-sort="omisiones" style="cursor:pointer;">Omisiones ⇅</th>
 <th data-sort="alertas_activas" style="cursor:pointer;">Alertas ⇅</th>
 <th>Notas clínicas</th>
 <th></th>
 </tr></thead>
 <tbody></tbody>
 </table>
 </div>
 </div>
</div>

<!-- Modal detalle -->
<div class="modal-backdrop" id="modal-pac">
 <div class="modal" style="max-width:760px;">
 <div class="modal-header">
 <h3 id="mp-titulo">Paciente</h3>
 <button class="modal-close" data-modal-close><i class="bi bi-x-lg"></i></button>
 </div>
 <div class="modal-body" id="mp-cuerpo" style="max-height:70vh;overflow:auto;">
 </div>
 </div>
</div>

<script>
const UMBRAL = <?= Adherencia::UMBRAL_CUMPLIMIENTO ?>;
const CEDULA_OK = <?= $cedulaValidada ? 'true' : 'false' ?>;
let pacientes = [];
let ordenCol = 'porcentaje', ordenAsc = true;

function escapar(s){ return String(s ?? '').replace(/[<>&]/g,
 c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }

async function cargar() {
 const r = await MedAlert.api('api/reportes.php?accion=panel_medico');
 document.getElementById('pm-cargando').hidden = true;
 if (!r.ok) {
 MedAlert.toast(r.datos.error || 'No se pudo cargar.', 'error');
 return;
 }
 pacientes = r.datos.panel.pacientes;
 if (!pacientes.length) {
 document.getElementById('pm-vacio').hidden = false;
 return;
 }
 document.getElementById('pm-tabla-card').hidden = false;
 render();
}

function render() {
 // Pacientes con datos se ordenan por la columna; los "sin datos"
 // siempre van agrupados al final.
 const conDatos = pacientes.filter(p => p.hay_datos);
 const sinDatos = pacientes.filter(p => !p.hay_datos);
 conDatos.sort((a,b) => {
 let va = a[ordenCol], vb = b[ordenCol];
 if (ordenCol === 'nombre') { va = (va||'').toLowerCase;
 vb = (vb||'').toLowerCase; }
 if (va < vb) return ordenAsc ? -1 : 1;
 if (va > vb) return ordenAsc ? 1 : -1;
 return 0;
 });
 const tb = document.querySelector('#pm-tabla tbody');
 tb.innerHTML = '';

 conDatos.forEach(p => tb.insertAdjacentHTML('beforeend', filaHtml(p)));
 if (sinDatos.length) {
 tb.insertAdjacentHTML('beforeend',
 `<tr><td colspan="6" style="background:var(--gray-50);
 font-size:12px;font-weight:700;color:var(--gray-500);
 padding:6px 12px;">Sin datos esta semana</td></tr>`);
 sinDatos.forEach(p => tb.insertAdjacentHTML('beforeend', filaHtml(p)));
 }
 tb.querySelectorAll('tr[data-id]').forEach(tr =>
 tr.addEventListener('click', () => abrirDetalle(+tr.dataset.id,
 tr.dataset.nombre)));
}

function filaHtml(p) {
 const bajo = p.bajo_umbral;
 const pct = !p.hay_datos ? '<span style="color:var(--gray-400);">'
 + 'Sin datos</span>'
 : `<strong style="color:${bajo?'var(--red-500)':
 (p.porcentaje>=80?'var(--green-500)':'var(--amber-500)')};">
 ${p.porcentaje}%</strong>`;
 const notas = p.notas_clinicas
 ? `<span title="${escapar(p.notas_clinicas)}">`
 + escapar(p.notas_clinicas.slice(0,40))
 + (p.notas_clinicas.length>40?'…':'') + '</span>'
 : '<span style="color:var(--gray-400);">—</span>';
 return `<tr data-id="${p.id_paciente}"
 data-nombre="${escapar(p.nombre)}" style="cursor:pointer;
 ${bajo?'background:var(--red-50);':''}">
 <td><strong>${escapar(p.nombre)}</strong></td>
 <td>${pct}</td>
 <td>${p.hay_datos ? p.omisiones : '—'}
 ${p.omisiones_justificadas>0?`<span style="font-size:11px;
 color:var(--amber-500);">(${p.omisiones_justificadas} c/motivo)</span>`:''}</td>
 <td>${p.alertas_activas>0
 ?`<span class="badge-red">${p.alertas_activas}</span>`:'0'}</td>
 <td style="font-size:12px;">${notas}</td>
 <td><i class="bi bi-chevron-right" style="color:var(--gray-400);"></i></td>
 </tr>`;
}

// ─── Detalle del paciente ──────────────────────────────────────────────────
async function abrirDetalle(id, nombre) {
 document.getElementById('mp-titulo').textContent = nombre;
 const cuerpo = document.getElementById('mp-cuerpo');
 cuerpo.innerHTML = '<p style="text-align:center;color:var(--gray-500);">'
 + 'Cargando…</p>';
 MedAlert.abrirModal('modal-pac');

 const [rep, presc, efe, p] = await Promise.all([
 MedAlert.api(`api/reportes.php?accion=semanal&id_paciente=${id}`),
 MedAlert.api(`api/prescripciones.php?accion=por_paciente&id_paciente=${id}`),
 MedAlert.api(`api/reportes.php?accion=efectos&id_paciente=${id}`),
 Promise.resolve(pacientes.find(x => x.id_paciente === id)),
 ]);

 let h = '';
 // Resumen
 if (rep.ok && rep.datos.resumen.hay_datos) {
 const res = rep.datos.resumen;
 const col = res.porcentaje < UMBRAL ? 'var(--red-500)'
 : (res.porcentaje>=80?'var(--green-500)':'var(--amber-500)');
 h += `<div style="display:flex;gap:24px;align-items:center;
 flex-wrap:wrap;margin-bottom:16px;">
 <div><div class="stat-card-label">Cumplimiento semanal</div>
 <div style="font-size:34px;font-weight:800;color:${col};">
 ${res.porcentaje}%</div></div>
 <div style="font-size:13px;color:var(--gray-600);">
 Cumplidas: ${res.cumplidas}/${res.total_tomas}<br>
 Omisiones c/motivo: ${res.omisiones_justificadas}<br>
 Omisiones s/motivo: ${res.omisiones_sin_justificar}</div>
 <a class="btn btn-outline btn-sm" target="_blank"
 href="reportes.php">Ver reporte completo</a></div>`;
 } else {
 h += `<p style="color:var(--gray-500);margin-bottom:16px;">`
 + `Sin datos suficientes para calcular esta semana.</p>`;
 }

 // Notas clínicas (visibles al paciente, con fecha)
 h += `<div class="card" style="margin-bottom:14px;">
 <div class="card-header"><h3><i class="bi bi-journal-medical"></i>
 Notas clínicas</h3></div>
 <div class="card-body">
 <p style="font-size:12px;color:var(--gray-500);margin-bottom:8px;">
 El paciente puede leer estas notas. Se guarda la fecha de la última
 actualización.</p>
 <textarea id="mp-notas" class="form-textarea" rows="3"
 placeholder="Escribe indicaciones o seguimiento para el paciente…"
 >${escapar(p?.notas_clinicas || '')}</textarea>
 <div style="display:flex;justify-content:space-between;
 align-items:center;margin-top:8px;">
 <span style="font-size:12px;color:var(--gray-500);">
 ${p?.notas_fecha ? 'Última actualización: '
 + new Date(p.notas_fecha).toLocaleString('es-MX') : 'Sin notas aún'}</span>
 <button class="btn btn-primary btn-sm" id="mp-guardar-notas">
 Guardar notas</button>
 </div>
 </div></div>`;

 // Prescripción digital
 let listaP = (presc.ok ? presc.datos.prescripciones : []) || [];
 h += `<div class="card" style="margin-bottom:14px;">
 <div class="card-header" style="display:flex;justify-content:space-between;
 align-items:center;">
 <h3><i class="bi bi-prescription2"></i> Prescripciones digitales</h3>
 ${CEDULA_OK ? `<button class="btn btn-primary btn-sm"
 id="mp-nueva-presc">+ Nueva</button>` : ''}
 </div>
 <div class="card-body">
 <div id="mp-form-presc" hidden style="background:var(--gray-50);
 padding:14px;border-radius:8px;margin-bottom:12px;">
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
 <div class="form-group" style="margin:0;">
 <label>Medicamento *</label>
 <input id="np-nombre" class="form-select" maxlength="100"></div>
 <div class="form-group" style="margin:0;">
 <label>Presentación</label>
 <select id="np-present" class="form-select">
 <option>Tableta</option><option>Cápsula</option>
 <option>Jarabe</option><option>Inyectable</option>
 <option>Gotas</option><option>Otro</option></select></div>
 <div class="form-group" style="margin:0;">
 <label>Cantidad *</label>
 <input id="np-cant" type="number" step="0.5" min="0.5"
 class="form-select"></div>
 <div class="form-group" style="margin:0;">
 <label>Unidad</label>
 <select id="np-unidad" class="form-select">
 <option>mg</option><option>g</option><option>mcg</option>
 <option>ml</option><option>tableta</option>
 <option>cápsula</option><option>gota</option></select></div>
 <div class="form-group" style="margin:0;">
 <label>Cada cuántas horas *</label>
 <input id="np-frec" type="number" min="1" max="168"
 class="form-select"></div>
 <div class="form-group" style="margin:0;">
 <label>Hora de inicio</label>
 <input id="np-hora" type="time()" value="08:00"
 class="form-select"></div>
 <div class="form-group" style="margin:0;">
 <label>Duración (días, opcional)</label>
 <input id="np-dur" type="number" min="1" max="365"
 class="form-select"></div>
 <div class="form-group" style="margin:0;">
 <label>Inicio</label>
 <input id="np-inicio" type="date" class="form-select"></div>
 </div>
 <div class="form-group" style="margin:10px 0 0;">
 <label>Instrucciones</label>
 <textarea id="np-instr" class="form-textarea" rows="2"
 maxlength="500"></textarea></div>
 <div style="display:flex;gap:8px;margin-top:10px;">
 <button class="btn btn-outline btn-sm"
 id="mp-cancelar-presc">Cancelar</button>
 <button class="btn btn-primary btn-sm" data-pac="${id}"
 id="mp-crear-presc">Emitir prescripción</button>
 </div>
 </div>
 <div id="mp-lista-presc">${prescHtml(listaP)}</div>
 </div></div>`;

 // Efectos adversos
 let listaE = (efe.ok ? efe.datos.efectos : []) || [];
 h += `<div class="card">
 <div class="card-header"><h3><i class="bi bi-heart-pulse"></i>
 Efectos adversos reportados</h3></div>
 <div class="card-body">${efectosHtml(listaE)}</div></div>`;

 cuerpo.innerHTML = h;
 conectarDetalle(id);
}

function prescHtml(lista) {
 if (!lista.length) return '<p style="color:var(--gray-500);font-size:13px;">'
 + 'Sin prescripciones emitidas.</p>';
 return `<table class="data-table"><thead><tr>
 <th>Medicamento</th><th>Pauta</th><th>Estado</th><th></th></tr></thead>
 <tbody>${lista.map(p => {
 const est = {activa:['Activa','var(--green-500)'],
 modificada:['Modificada','var(--amber-500)'],
 cancelada:['Cancelada','var(--gray-400)']}[p.estado]
 || ['—','var(--gray-400)'];
 return `<tr>
 <td><strong>${escapar(p.nombre_medicamento)}</strong></td>
 <td style="font-size:12px;">${p.cantidad_dosis} ${escapar(p.unidad_dosis)}
 · cada ${p.frecuencia_horas}h</td>
 <td><span style="color:${est[1]};font-weight:600;">${est[0]}</span></td>
 <td>${p.estado!=='cancelada'
 ?`<button class="btn btn-outline btn-sm mp-cancel-presc"
 data-id="${p.id_prescripcion}">Cancelar</button>`:''}</td>
 </tr>`;
 }).join('')}</tbody></table>`;
}

function efectosHtml(lista) {
 if (!lista.length) return '<p style="color:var(--gray-500);font-size:13px;">'
 + 'El paciente no ha reportado efectos adversos.</p>';
 return lista.map(e => `<div style="padding:10px 0;border-bottom:1px solid
 var(--gray-100);">
 <div style="display:flex;justify-content:space-between;">
 <strong style="color:${e.es_reaccion_alergica?'var(--red-500)':
 'var(--gray-800)'};">
 ${e.es_reaccion_alergica?'<i class=\"bi bi-exclamation-octagon-fill\"></i> ':''}
 ${escapar(e.efecto_etiqueta)}</strong>
 <span style="font-size:12px;color:var(--gray-500);">
 ${new Date(e.fecha_reporte).toLocaleString('es-MX')}</span></div>
 <div style="font-size:13px;color:var(--gray-600);">
 ${escapar(e.medicamento_nombre)}
 ${e.descripcion?' · '+escapar(e.descripcion):''}</div></div>`).join('');
}

function conectarDetalle(idPac) {
 // Guardar notas
 const bg = document.getElementById('mp-guardar-notas');
 if (bg) bg.addEventListener('click', async () => {
 const txt = document.getElementById('mp-notas').value.trim();
 const r = await MedAlert.api('api/medico_paciente.php', {
 method: 'POST',
 body: { accion: 'notas', id_paciente: idPac, notas: txt }
 });
 if (r.ok) {
 MedAlert.toast('Notas guardadas. El paciente podrá verlas.', 'success');
 const px = pacientes.find(x=>x.id_paciente===idPac);
 if (px) { px.notas_clinicas = txt;
 px.notas_fecha = new Date.toISOString; }
 render();
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo guardar.', 'error');
 }
 });

 // Form prescripción
 const bn = document.getElementById('mp-nueva-presc');
 if (bn) bn.addEventListener('click', () => {
 document.getElementById('mp-form-presc').hidden = false;
 document.getElementById('np-inicio').value =
 new Date.toISOString.slice(0,10);
 });
 const bc = document.getElementById('mp-cancelar-presc');
 if (bc) bc.addEventListener('click', () =>  document.getElementById('mp-form-presc').hidden = true);

 const bcrear = document.getElementById('mp-crear-presc');
 if (bcrear) bcrear.addEventListener('click', async () => {
 const body = {
 accion: 'crear', id_paciente: idPac,
 nombre_medicamento: document.getElementById('np-nombre').value.trim(),
 presentacion: document.getElementById('np-present').value,
 cantidad_dosis: document.getElementById('np-cant').value,
 unidad_dosis: document.getElementById('np-unidad').value,
 frecuencia_horas: document.getElementById('np-frec').value,
 hora_inicio: document.getElementById('np-hora').value,
 fecha_inicio: document.getElementById('np-inicio').value,
 duracion_dias: document.getElementById('np-dur').value,
 instrucciones: document.getElementById('np-instr').value.trim(),
 };
 if (!body.nombre_medicamento || !body.cantidad_dosis || !body.frecuencia_horas) {
 MedAlert.toast('Completa medicamento, cantidad y frecuencia.', 'error');
 return;
 }
 const r = await MedAlert.api('api/prescripciones.php',
 { method:'POST', body });
 if (r.ok) {
 MedAlert.toast('Prescripción emitida. El paciente fue notificado.',
 'success');
 abrirDetalle(idPac,
 document.getElementById('mp-titulo').textContent);
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo emitir.', 'error');
 }
 });

 // Cancelar prescripciones
 document.querySelectorAll('.mp-cancel-presc').forEach(b =>
 b.addEventListener('click', async () => {
 if (!await MedAlert.confirmar('¿Cancelar esta prescripción? El '
 + 'medicamento dejará de aparecerle al paciente.')) return;
 const r = await MedAlert.api('api/prescripciones.php', {
 method:'POST',
 body:{ accion:'cancelar', id_prescripcion: +b.dataset.id }
 });
 if (r.ok) {
 MedAlert.toast('Prescripción cancelada.', 'success');
 abrirDetalle(idPac,
 document.getElementById('mp-titulo').textContent);
 } else {
 MedAlert.toast(r.datos.error || 'No se pudo cancelar.', 'error');
 }
 }));
}

document.addEventListener('DOMContentLoaded', () => {
 document.querySelectorAll('#pm-tabla th[data-sort]').forEach(th =>
 th.addEventListener('click', () => {
 const c = th.dataset.sort;
 if (ordenCol === c) ordenAsc = !ordenAsc;
 else { ordenCol = c; ordenAsc = (c === 'nombre'); }
 render();
 }));
 cargar();
});
</script>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>
