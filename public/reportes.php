<?php
/**
 * Pantalla de reportes de adherencia
 * (reporte semanal con gráficas) (comparativo entre semanas)
 * Visible para Médico (elige paciente), Paciente (su propio reporte) y
 * Cuidador (paciente al que tiene acceso).
 */
require_once __DIR__. '/../includes/auth.php';
require_once __DIR__. '/../includes/helpers.php';
require_once __DIR__. '/../includes/adherencia.php';
require_once __DIR__. '/../includes/familiar.php';

$usuarioActual = Auth::requerirTipo(['Medico', 'Paciente', 'Cuidador']);
$tituloPagina = 'Reportes';
$paginaActiva = 'reportes';
$tipo = $usuarioActual['tipo_nombre'];
$idUsuario = (int)$usuarioActual['id_usuario'];

// Lista de pacientes seleccionables según el rol
$pacientes = [];
if ($tipo === 'Medico') {
 $pacientes = DB::todos(
 "SELECT p.id_usuario, p.nombre_completo
 FROM medico_paciente mp
 JOIN usuarios p ON p.id_usuario = mp.id_paciente
 WHERE mp.id_medico = :m AND mp.activa = TRUE
 ORDER BY p.nombre_completo",
 ['m' => $idUsuario]
 );
} elseif ($tipo === 'Cuidador') {
 $pacientes = Familiar::pacientesDe($idUsuario);
 foreach ($pacientes as &$p) {
 $p['id_usuario'] = $p['id_usuario'] ?? $p['id_usuario'];
 }
 unset($p);
} else { // Paciente
 $pacientes = [['id_usuario' => $idUsuario,
 'nombre_completo' => $usuarioActual['nombre_completo']]];
}

require __DIR__. '/_partials/_layout_inicio.php';
?>

<div class="page-header">
 <h1><i class="bi bi-graph-up-arrow"></i> Reportes de adherencia</h1>
 <p>Cumplimiento semanal, tendencia y comparativo entre semanas.</p>
</div>

<?php if (empty($pacientes)): ?>
 <div class="empty-state">
 <i class="bi bi-people"></i>
 <h3>Aún no hay pacientes que mostrar</h3>
 <p><?= $tipo === 'Medico'
 ? 'Cuando tengas pacientes asignados aparecerán aquí para consultar sus reportes.'
 : 'Cuando un paciente te dé acceso a su seguimiento podrás ver su reporte aquí.' ?></p>
 </div>
<?php else: ?>

<div class="card" style="margin-bottom:18px;">
 <div class="card-body" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
 <?php if ($tipo !== 'Paciente'): ?>
 <div class="form-group" style="margin:0;min-width:220px;">
 <label>Paciente</label>
 <select id="sel-paciente" class="form-select">
 <?php foreach ($pacientes as $p): ?>
 <option value="<?= (int)$p['id_usuario'] ?>">
 <?= htmlspecialchars($p['nombre_completo']) ?>
 </option>
 <?php endforeach; ?>
 </select>
 </div>
 <?php else: ?>
 <input type="hidden" id="sel-paciente" value="<?= $idUsuario ?>">
 <?php endif; ?>

 <div class="form-group" style="margin:0;">
 <label>Desde</label>
 <input type="date" id="f-desde" class="form-select">
 </div>
 <div class="form-group" style="margin:0;">
 <label>Hasta</label>
 <input type="date" id="f-hasta" class="form-select">
 </div>
 <button class="btn btn-primary" id="btn-aplicar">
 <i class="bi bi-search"></i> Ver reporte
 </button>
 <button class="btn btn-outline" id="btn-esta-semana">Esta semana</button>

 <div style="margin-left:auto;display:flex;gap:8px;">
 <button class="btn btn-outline btn-sm" id="btn-pdf">
 <i class="bi bi-file-earmark-pdf"></i> PDF
 </button>
 <button class="btn btn-outline btn-sm" id="btn-excel">
 <i class="bi bi-file-earmark-spreadsheet"></i> Excel
 </button>
 </div>
 </div>
</div>

<div id="reporte-cargando" class="empty-state" hidden>
 <i class="bi bi-hourglass-split"></i><h3>Cargando reporte…</h3>
</div>

<div id="reporte-sin-datos" class="empty-state" hidden>
 <i class="bi bi-clipboard-x"></i>
 <h3>Sin datos suficientes para calcular</h3>
 <p>No hay tomas registradas en el periodo seleccionado.</p>
</div>

<div id="reporte-contenido" hidden>
 <!-- Resumen general -->
 <div class="card" style="margin-bottom:18px;">
 <div class="card-body">
 <div style="display:flex;gap:30px;flex-wrap:wrap;align-items:center;">
 <div>
 <div style="font-size:13px;color:var(--gray-500);font-weight:600;">
 Cumplimiento general de la semana
 </div>
 <div id="pct-general" style="font-size:46px;font-weight:800;line-height:1.1;">—</div>
 <div id="pct-rango" style="font-size:12px;color:var(--gray-500);"></div>
 </div>
 <div style="flex:1;min-width:240px;">
 <div class="stat-row" style="display:flex;gap:18px;flex-wrap:wrap;">
 <div><div class="stat-card-value" id="st-cumplidas">0</div>
 <div class="stat-card-label">Tomas cumplidas</div></div>
 <div><div class="stat-card-value" id="st-total">0</div>
 <div class="stat-card-label">Tomas totales</div></div>
 <div><div class="stat-card-value" id="st-just"
 style="color:var(--amber-500);">0</div>
 <div class="stat-card-label">Omisiones c/motivo</div></div>
 <div><div class="stat-card-value" id="st-sin"
 style="color:var(--red-500);">0</div>
 <div class="stat-card-label">Omisiones s/motivo</div></div>
 </div>
 </div>
 </div>
 </div>
 </div>

 <!-- Gráfica por medicamento -->
 <div class="card" style="margin-bottom:18px;">
 <div class="card-header"><h3><i class="bi bi-capsule"></i>
 Cumplimiento por medicamento</h3></div>
 <div class="card-body">
 <div id="g-medicamentos"></div>
 <div style="display:flex;gap:22px;margin-top:14px;font-size:12px;">
 <span><span style="display:inline-block;width:11px;height:11px;
 background:var(--green-500);border-radius:2px;"></span> ≥ 80%</span>
 <span><span style="display:inline-block;width:11px;height:11px;
 background:var(--amber-500);border-radius:2px;"></span> 70–79%</span>
 <span><span style="display:inline-block;width:11px;height:11px;
 background:var(--red-500);border-radius:2px;"></span> &lt; 70%</span>
 </div>
 </div>
 </div>

 <!-- Tendencia -->
 <div class="card" style="margin-bottom:18px;">
 <div class="card-header"><h3><i class="bi bi-activity"></i>
 Tendencia de las últimas semanas</h3></div>
 <div class="card-body">
 <div id="g-tendencia"></div>
 </div>
 </div>

 <!-- Comparativo entre semanas -->
 <div class="card">
 <div class="card-header" style="display:flex;justify-content:space-between;
 align-items:center;">
 <h3><i class="bi bi-arrow-left-right"></i> Comparativo entre semanas</h3>
 <div style="display:flex;gap:8px;align-items:center;">
 <button class="btn btn-outline btn-sm" id="cmp-prev"
 title="Semanas anteriores"><i class="bi bi-chevron-left"></i></button>
 <span id="cmp-rango" style="font-size:12px;color:var(--gray-600);
 min-width:160px;text-align:center;"></span>
 <button class="btn btn-outline btn-sm" id="cmp-next"
 title="Semanas más recientes"><i class="bi bi-chevron-right"></i></button>
 </div>
 </div>
 <div class="card-body">
 <div id="cmp-resumen" style="display:flex;gap:24px;flex-wrap:wrap;
 align-items:center;margin-bottom:16px;"></div>
 <div class="data-table-wrap">
 <table class="data-table" id="cmp-tabla">
 <thead><tr>
 <th>Medicamento</th><th>Semana anterior</th>
 <th>Semana actual</th><th>Cambio</th><th>Tendencia</th>
 </tr></thead>
 <tbody></tbody>
 </table>
 </div>
 </div>
 </div>
</div>
<?php endif; ?>

<script>
const UMBRAL = <?= Adherencia::UMBRAL_CUMPLIMIENTO ?>;
let cmpDesp = 0;

function colorPct(p) {
 if (p === null) return 'var(--gray-400)';
 if (p >= 80) return 'var(--green-500)';
 if (p >= UMBRAL) return 'var(--amber-500)';
 return 'var(--red-500)';
}
function idPaciente() {
 const el = document.getElementById('sel-paciente');
 return el ? el.value : null;
}

// ─── Gráfica de barras horizontales por medicamento (SVG nativo) ───────────
function dibujarMedicamentos(meds) {
 const cont = document.getElementById('g-medicamentos');
 if (!meds.length) {
 cont.innerHTML = '<p style="color:var(--gray-500);font-size:13px;">'
 + 'Sin medicamentos con registros en el periodo.</p>';
 return;
 }
 const fila = 38, ancho = 560, etq = 140, barMax = ancho - etq - 56;
 let svg = `<svg viewBox="0 0 ${ancho} ${meds.length*fila()+10}" width="100%" `
 + `style="max-width:680px;">`;
 meds.forEach((m, i) => {
 const y = i*fila() + 8;
 const w = Math.max(2, barMax * m.porcentaje / 100);
 const c = m.porcentaje >= 80 ? '#16a34a'
 : (m.porcentaje >= UMBRAL ? '#f59e0b' : '#d62828');
 svg += `<text x="0" y="${y+16}" font-size="12" fill="#334155">`
 + `${escapar(recortar(m.nombre,18))}</text>`;
 svg += `<rect x="${etq}" y="${y+4}" width="${barMax}" height="16" `
 + `rx="3" fill="#e2e8f0"/>`;
 svg += `<rect x="${etq}" y="${y+4}" width="${w}" height="16" rx="3" `
 + `fill="${c}"/>`;
 svg += `<text x="${etq+barMax+8}" y="${y+16}" font-size="12" `
 + `font-weight="700" fill="#334155">${m.porcentaje}%</text>`;
 });
 svg += `</svg>`;
 cont.innerHTML = svg;
}

// ─── Gráfica de línea de tendencia (SVG nativo) ────────────────────────────
function dibujarTendencia(puntos) {
 const cont = document.getElementById('g-tendencia');
 if (!puntos.length) {
 cont.innerHTML = '<p style="color:var(--gray-500);font-size:13px;">'
 + 'Aún no hay suficientes semanas con datos para mostrar una tendencia.</p>';
 return;
 }
 const W=620, H=210, mx=44, my=28;
 const gw=W-mx*2, gh=H-my*2;
 const n=puntos.length;
 const x = i => mx + (n>1 ? i*gw/(n-1) : gw/2);
 const y = v => my + gh - (gh*v/100);
 let svg=`<svg viewBox="0 0 ${W} ${H}" width="100%" style="max-width:760px;">`;
 // Rejilla y eje Y
 [0,25,50,75,100].forEach(v=>{
 svg+=`<line x1="${mx}" y1="${y(v)}" x2="${W-mx}" y2="${y(v)}" `
 +`stroke="#e2e8f0" stroke-width="1"/>`;
 svg+=`<text x="${mx-8}" y="${y(v)+4}" font-size="10" fill="#94a3b8" `
 +`text-anchor="end">${v}</text>`;
 });
 // Línea de umbral
 svg+=`<line x1="${mx}" y1="${y(UMBRAL)}" x2="${W-mx}" y2="${y(UMBRAL)}" `
 +`stroke="#f59e0b" stroke-width="1.4" stroke-dasharray="5 4"/>`;
 svg+=`<text x="${W-mx}" y="${y(UMBRAL)-5}" font-size="10" `
 +`fill="#f59e0b" text-anchor="end">Umbral ${UMBRAL}%</text>`;
 // Polilínea (no se conecta hacia cero: solo puntos con datos)
 let pts = puntos.map((p,i)=>`${x(i)},${y(p.porcentaje)}`).join(' ');
 svg+=`<polyline points="${pts}" fill="none" stroke="#1a3a6e" `
 +`stroke-width="2.5"/>`;
 puntos.forEach((p,i)=>{
 svg+=`<circle cx="${x(i)}" cy="${y(p.porcentaje)}" r="4.5" `
 +`fill="#1a3a6e"/>`;
 svg+=`<text x="${x(i)}" y="${y(p.porcentaje)-12}" font-size="11" `
 +`font-weight="700" fill="#1e293b" text-anchor="middle">`
 +`${p.porcentaje}%</text>`;
 svg+=`<text x="${x(i)}" y="${H-6}" font-size="10" fill="#64748b" `
 +`text-anchor="middle">${escapar(p.etiqueta)}</text>`;
 });
 svg+=`</svg>`;
 cont.innerHTML=svg;
}

function escapar(s){ return String(s).replace(/[<>&]/g,
 c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }
function recortar(s,n){ return s.length>n ? s.slice(0,n-1)+'…' : s; }

// ─── Cargar reporte semanal ────────────────────────────────────────────────
async function cargarReporte() {
 const id = idPaciente();
 if (!id) return;
 const desde = document.getElementById('f-desde').value;
 const hasta = document.getElementById('f-hasta').value;
 document.getElementById('reporte-cargando').hidden = false;
 document.getElementById('reporte-contenido').hidden = true;
 document.getElementById('reporte-sin-datos').hidden = true;

 let url = `api/reportes.php?accion=semanal&id_paciente=${id}`;
 if (desde && hasta) url += `&desde=${desde}&hasta=${hasta}`;
 const r = await MedAlert.api(url);
 document.getElementById('reporte-cargando').hidden = true;

 if (!r.ok) {
 MedAlert.toast(r.datos.error || 'No se pudo cargar el reporte.', 'error');
 return;
 }
 const res = r.datos.resumen;
 if (!res.hay_datos) {
 document.getElementById('reporte-sin-datos').hidden = false;
 return;
 }
 document.getElementById('reporte-contenido').hidden = false;

 const pg = document.getElementById('pct-general');
 pg.textContent = res.porcentaje + ' %';
 pg.style.color = colorPct(res.porcentaje);
 document.getElementById('pct-rango').textContent =
 res.desde + ' al ' + res.hasta;
 document.getElementById('st-cumplidas').textContent = res.cumplidas;
 document.getElementById('st-total').textContent = res.total_tomas;
 document.getElementById('st-just').textContent = res.omisiones_justificadas;
 document.getElementById('st-sin').textContent = res.omisiones_sin_justificar;

 dibujarMedicamentos(res.por_medicamento);
 dibujarTendencia(r.datos.tendencia);
 cmpDesp = 0;
 cargarComparativo();
}

// ─── Comparativo entre semanas ─────────────────────────────────────
async function cargarComparativo() {
 const id = idPaciente();
 if (!id) return;
 const r = await MedAlert.api(
 `api/reportes.php?accion=comparativo&id_paciente=${id}&desp=${cmpDesp}`);
 if (!r.ok) return;
 const c = r.datos.comparativo;

 document.getElementById('cmp-rango').textContent =
 c.semana_anterior.etiqueta + ' vs ' + c.semana_actual.etiqueta;
 document.getElementById('cmp-next').disabled = (cmpDesp === 0);
 document.getElementById('cmp-prev').disabled = !c.hay_mas_atras;

 const tInfo = {
 mejora: ['↑','var(--green-500)','Mejoró'],
 empeora: ['↓','var(--red-500)','Empeoró'],
 estable: ['→','var(--gray-500)','Se mantuvo'],
 sin_dato:['·','var(--gray-400)','Sin dato'],
 };
 const ti = tInfo[c.tendencia_general] || tInfo.sin_dato;
 const pa = c.semana_anterior.porcentaje, pc = c.semana_actual.porcentaje;
 document.getElementById('cmp-resumen').innerHTML = `
 <div><div class="stat-card-label">Semana anterior</div>
 <div style="font-size:26px;font-weight:800;color:${colorPct(pa)}">
 ${pa===null?'Sin datos':pa+'%'}</div></div>
 <div style="font-size:30px;color:${ti[1]};font-weight:800;">${ti[0]}</div>
 <div><div class="stat-card-label">Semana actual</div>
 <div style="font-size:26px;font-weight:800;color:${colorPct(pc)}">
 ${pc===null?'Sin datos':pc+'%'}</div></div>
 <div style="background:var(--gray-50);padding:8px 14px;border-radius:8px;
 font-size:13px;font-weight:600;color:${ti[1]};">
 ${ti[2]} esta semana</div>`;

 const tb = document.querySelector('#cmp-tabla tbody');
 tb.innerHTML = '';
 if (!c.medicamentos.length) {
 tb.innerHTML = '<tr><td colspan="5" style="text-align:center;'
 + 'color:var(--gray-500);">Sin medicamentos para comparar.</td></tr>';
 }
 c.medicamentos.forEach(m => {
 const tm = tInfo[m.tendencia] || tInfo.sin_dato;
 const destac = m.destacado
 ? ' style="background:var(--amber-50);font-weight:700;"' : '';
 const dif = m.diferencia===null ? '—'
 : (m.diferencia>0?'+':'') + m.diferencia + ' pts';
 tb.insertAdjacentHTML('beforeend', `<tr${destac}>
 <td>${escapar(m.nombre)}${m.destacado
 ? ' <span class="badge-amber">Mayor cambio</span>':''}</td>
 <td>${m.pct_anterior===null?'—':m.pct_anterior+'%'}</td>
 <td>${m.pct_actual===null?'—':m.pct_actual+'%'}</td>
 <td style="color:${tm[1]};font-weight:700;">${dif}</td>
 <td style="color:${tm[1]};font-weight:700;">${tm[0]} ${tm[2]}</td>
 </tr>`);
 });
}

// ─── Descargas ─────────────────────────────────────────────────────
function descargar(formato) {
 const id = idPaciente();
 if (!id) return;
 const desde = document.getElementById('f-desde').value;
 const hasta = document.getElementById('f-hasta').value;
 let url = `api/descargar_reporte.php?formato=${formato}&id_paciente=${id}`;
 if (desde && hasta) url += `&desde=${desde}&hasta=${hasta}`;
 window.open(url, '_blank');
}

// ─── Eventos ───────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
 if (!document.getElementById('reporte-contenido')) return;
 document.getElementById('btn-aplicar').addEventListener('click', cargarReporte);
 document.getElementById('btn-esta-semana').addEventListener('click', () => {
 document.getElementById('f-desde').value = '';
 document.getElementById('f-hasta').value = '';
 cargarReporte();
 });
 const sp = document.getElementById('sel-paciente');
 if (sp && sp.tagName === 'SELECT')
 sp.addEventListener('change', cargarReporte);
 document.getElementById('btn-pdf').addEventListener('click', () => descargar('pdf'));
 document.getElementById('btn-excel').addEventListener('click', () => descargar('excel'));
 document.getElementById('cmp-prev').addEventListener('click', () => {
 cmpDesp++; cargarComparativo();
 });
 document.getElementById('cmp-next').addEventListener('click', () => {
 if (cmpDesp > 0) { cmpDesp--; cargarComparativo(); }
 });
 cargarReporte();
});
</script>

<?php require __DIR__. '/_partials/_layout_fin.php'; ?>
