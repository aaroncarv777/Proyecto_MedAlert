<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Administrador']);
$tituloPagina = 'Médicos y pacientes';
$paginaActiva = 'asignaciones';

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="page-header">
  <div>
    <h1>Médicos y pacientes</h1>
    <p class="page-subtitle">
      Asigna a tus médicos los pacientes que atenderán. Solo desde aquí se pueden crear o revocar estas relaciones.
    </p>
  </div>
</div>

<div class="card">
  <h2 style="margin:0 0 14px;font-size:18px;"><i class="bi bi-plus-circle"></i> Nueva asignación</h2>
  <form id="form-asignar">
    <div class="grid-2">
      <div class="form-group">
        <label>Médico</label>
        <select id="sel-medico" required>
          <option value="">Cargando…</option>
        </select>
        <small class="text-muted">Solo aparecen los médicos con cédula validada.</small>
      </div>
      <div class="form-group">
        <label>Paciente</label>
        <select id="sel-paciente" required>
          <option value="">Cargando…</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Notas clínicas iniciales (opcional)</label>
      <textarea id="notas" rows="2" maxlength="1000"
                placeholder="Ej.: Paciente con diabetes tipo 2 en tratamiento por el Dr. Pérez."></textarea>
    </div>
    <div id="asignar-error" class="alert alert-error" hidden></div>
    <div id="asignar-ok" class="alert alert-success" hidden></div>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-link-45deg"></i> Asignar
    </button>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h2 style="margin:0 0 14px;font-size:18px;"><i class="bi bi-list-ul"></i> Asignaciones existentes</h2>
  <div style="overflow-x:auto;">
    <table class="tabla-datos" id="tabla-asignaciones">
      <thead>
        <tr>
          <th>Médico</th><th>Especialidad</th><th>Paciente</th>
          <th>Asignado el</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody><tr><td colspan="6">Cargando…</td></tr></tbody>
    </table>
  </div>
</div>

<script>
const escapar = (s) => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

async function cargarSelects() {
  try {
    const [rm, rp] = await Promise.all([
      fetch('api/medico_paciente.php?accion=medicos'),
      fetch('api/medico_paciente.php?accion=pacientes'),
    ]);
    const [dm, dp] = [await rm.json(), await rp.json()];
    const selM = document.getElementById('sel-medico');
    const selP = document.getElementById('sel-paciente');
    selM.innerHTML = '<option value="">— Selecciona un médico —</option>' +
      (dm.medicos || []).map(m =>
        `<option value="${m.id_usuario}">${escapar(m.nombre_completo)} · ${escapar(m.especialidad || 'sin especialidad')}</option>`
      ).join('');
    selP.innerHTML = '<option value="">— Selecciona un paciente —</option>' +
      (dp.pacientes || []).map(p =>
        `<option value="${p.id_usuario}">${escapar(p.nombre_completo)}</option>`
      ).join('');
  } catch (e) { /* silencioso */ }
}

async function cargarTabla() {
  const tbody = document.querySelector('#tabla-asignaciones tbody');
  try {
    const r = await fetch('api/medico_paciente.php?accion=todas');
    const d = await r.json();
    if (!d.ok) { tbody.innerHTML = '<tr><td colspan="6">' + escapar(d.error) + '</td></tr>'; return; }
    if (d.relaciones.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px;">Aún no hay asignaciones registradas.</td></tr>';
      return;
    }
    tbody.innerHTML = d.relaciones.map(r => `
      <tr>
        <td><strong>${escapar(r.medico_nombre)}</strong></td>
        <td>${escapar(r.especialidad || '—')}</td>
        <td>${escapar(r.paciente_nombre)}</td>
        <td style="font-size:12.5px;color:var(--gray-600);">${r.fecha_asignacion}</td>
        <td>${r.activa == 1
          ? '<span class="pill pill-activa">Activa</span>'
          : '<span class="pill pill-revocada">Revocada</span>'}</td>
        <td>${r.activa == 1
          ? `<button class="btn btn-danger btn-sm js-revocar" data-id="${r.id_relacion}">
                <i class="bi bi-x-circle"></i> Revocar
             </button>` : '<span class="text-muted">—</span>'}</td>
      </tr>`).join('');
    document.querySelectorAll('.js-revocar').forEach(b =>
      b.addEventListener('click', () => revocar(parseInt(b.dataset.id, 10))));
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="6">Error de conexión.</td></tr>';
  }
}

document.getElementById('form-asignar').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('asignar-error');
  const ok = document.getElementById('asignar-ok');
  err.hidden = true; ok.hidden = true;
  const id_medico = parseInt(document.getElementById('sel-medico').value, 10);
  const id_paciente = parseInt(document.getElementById('sel-paciente').value, 10);
  const notas = document.getElementById('notas').value.trim();
  if (!id_medico || !id_paciente) {
    err.textContent = 'Debes seleccionar tanto médico como paciente.'; err.hidden = false; return;
  }
  const r = await fetch('api/medico_paciente.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accion: 'asignar', id_medico, id_paciente, notas_clinicas: notas })
  });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('form-asignar').reset();
    ok.textContent = 'Asignación creada correctamente.';
    ok.hidden = false;
    setTimeout(() => { ok.hidden = true; }, 2500);
    cargarTabla();
  } else {
    err.textContent = d.error; err.hidden = false;
  }
});

async function revocar(id) {
  if (!confirm('¿Revocar esta asignación? El médico dejará de poder gestionar a este paciente.')) return;
  const r = await fetch('api/medico_paciente.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accion: 'revocar', id_relacion: id })
  });
  const d = await r.json();
  if (d.ok) cargarTabla();
  else alert(d.error);
}

cargarSelects();
cargarTabla();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
