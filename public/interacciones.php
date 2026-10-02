<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Paciente','Medico']);
$tituloPagina = 'Interacciones medicamentosas';
$paginaActiva = 'interacciones';

$tipo = $usuarioActual['tipo_nombre'];
$idUsuario = (int)$usuarioActual['id_usuario'];
$cedulaValidada = !empty($usuarioActual['cedula_validada']);

$idPacienteVisto = $tipo === 'Paciente'
    ? $idUsuario
    : (int)($_GET['id_paciente'] ?? 0);

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="page-header">
  <div>
    <h1>Alertas de interacciones</h1>
    <p class="page-subtitle">
      <?php if ($tipo === 'Paciente'): ?>
        Interacciones que el sistema ha detectado entre tus medicamentos.
        Si una alerta es grave acude a tu médico antes de combinarlos.
      <?php else: ?>
        Alertas detectadas en los pacientes que tienes asignados.
        Puedes aceptar una interacción registrando una justificación clínica.
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if ($tipo === 'Medico' && !$idPacienteVisto): ?>
  <div class="card">
    <h3 style="margin:0 0 4px;font-size:16px;">Selecciona un paciente</h3>
    <p class="text-muted" style="margin:0 0 16px;font-size:13px;">Elige al paciente cuyas alertas quieres revisar.</p>
    <div id="selector-pacientes">Cargando…</div>
  </div>
<?php else: ?>
  <div id="lista-alertas">
    <div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>
  </div>
<?php endif; ?>

<!-- Modal aceptación -->
<div class="modal-overlay" id="modal-aceptar" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Aceptar interacción con justificación</h3>
      <button class="modal-cerrar" type="button" data-cerrar-aceptar>&times;</button>
    </div>
    <form id="form-aceptar">
      <div class="modal-body">
        <input type="hidden" id="alerta-id">
        <p id="alerta-resumen" style="margin:0 0 14px;color:var(--gray-700);font-size:13.5px;background:var(--gray-100);padding:10px 12px;border-radius:8px;"></p>
        <div class="form-group">
          <label>Justificación clínica <span style="color:var(--red-600);">*</span></label>
          <textarea id="justificacion" rows="5" minlength="10" maxlength="1000" required
                    placeholder="Explica el motivo clínico para mantener la combinación a pesar de la interacción."></textarea>
          <small class="text-muted">
            Mínimo 10 caracteres. La justificación quedará registrada de forma permanente en el historial del paciente.
          </small>
        </div>
        <div id="aceptar-error" class="alert alert-error" hidden></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-secondary" data-cerrar-aceptar>Cancelar</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check-circle"></i> Aceptar interacción
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const ES_MEDICO = <?= $tipo === 'Medico' ? 'true' : 'false' ?>;
const PUEDE_ACEPTAR = <?= ($tipo === 'Medico' && $cedulaValidada) ? 'true' : 'false' ?>;
const ID_PACIENTE = <?= (int)$idPacienteVisto ?>;
const escapar = (s) => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

(async function () {
  if (ES_MEDICO && !ID_PACIENTE) {
    const sel = document.getElementById('selector-pacientes');
    try {
      const r = await fetch('api/medico_paciente.php?accion=mis_pacientes');
      const d = await r.json();
      if (!d.ok || d.pacientes.length === 0) {
        sel.innerHTML = '<p class="text-muted">No tienes pacientes asignados.</p>';
        return;
      }
      sel.innerHTML = '<div class="med-grid">' + d.pacientes.map(p => `
        <a class="card med-card" href="interacciones.php?id_paciente=${p.id_usuario}"
           style="cursor:pointer;text-decoration:none;color:inherit;display:block;">
          <h3 style="margin:0 0 6px;">${escapar(p.nombre_completo)}</h3>
          <p class="text-muted" style="margin:0;font-size:13px;">
            <i class="bi bi-arrow-right-circle"></i> Ver alertas
          </p>
        </a>`).join('') + '</div>';
    } catch (e) { sel.innerHTML = '<p>Error de conexión.</p>'; }
    return;
  }

  const cont = document.getElementById('lista-alertas');
  await cargarAlertas();

  async function cargarAlertas() {
    cont.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>';
    try {
      const url = 'api/interacciones.php?accion=listar'
        + (ID_PACIENTE ? '&id_paciente=' + ID_PACIENTE : '');
      const r = await fetch(url);
      const d = await r.json();
      if (!d.ok) {
        cont.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-triangle"></i><h3>'
          + escapar(d.error) + '</h3></div>';
        return;
      }
      if (d.alertas.length === 0) {
        cont.innerHTML = `<div class="empty-state">
          <i class="bi bi-shield-check" style="color:#16a34a;"></i>
          <h3>Sin interacciones detectadas</h3>
          <p>No hay alertas activas en este momento.</p>
        </div>`;
        return;
      }

      const activas   = d.alertas.filter(a => a.estado === 'activa');
      const aceptadas = d.alertas.filter(a => a.estado === 'aceptada');

      cont.innerHTML = '';
      if (activas.length) {
        const cActivas = document.createElement('div');
        cActivas.innerHTML = '<h3 style="margin:8px 0 12px;color:var(--navy-900);font-size:15px;">'
          + '<i class="bi bi-exclamation-triangle"></i> Alertas activas ('+activas.length+')</h3>'
          + activas.map(renderAlerta).join('');
        cont.appendChild(cActivas);
      }
      if (aceptadas.length) {
        const cAcept = document.createElement('div');
        cAcept.innerHTML = '<h3 style="margin:24px 0 12px;color:var(--navy-900);font-size:15px;">'
          + '<i class="bi bi-check-circle"></i> Aceptadas con justificación ('+aceptadas.length+')</h3>'
          + aceptadas.map(renderAlerta).join('');
        cont.appendChild(cAcept);
      }

      document.querySelectorAll('.js-aceptar-alerta').forEach(btn => {
        btn.addEventListener('click', () => {
          document.getElementById('alerta-id').value = btn.dataset.id;
          document.getElementById('alerta-resumen').textContent =
            btn.dataset.med_a + ' + ' + btn.dataset.med_b + ' — Nivel: ' + btn.dataset.nivel;
          document.getElementById('justificacion').value = '';
          document.getElementById('aceptar-error').hidden = true;
          document.getElementById('modal-aceptar').hidden = false;
        });
      });
    } catch (e) {
      cont.innerHTML = '<div class="empty-state"><i class="bi bi-wifi-off"></i><h3>Error de conexión</h3></div>';
    }
  }

  function renderAlerta(a) {
    const consecuencia = a.consecuencia_clinica
      ? `<div class="alerta-consecuencia">⚠ ${escapar(a.consecuencia_clinica)}</div>` : '';
    const justificada = a.estado === 'aceptada' ? `
      <div class="justificacion-existente">
        <strong>Aceptada por Dr(a). ${escapar(a.medico_nombre || '')}:</strong>
        ${escapar(a.justificacion_medico || '')}
      </div>` : '';
    const accionBtn = (a.estado === 'activa' && ES_MEDICO && PUEDE_ACEPTAR) ? `
      <div style="margin-top:12px;">
        <button class="btn btn-primary btn-sm js-aceptar-alerta"
                data-id="${a.id_alerta}"
                data-med_a="${escapar(a.medicamento_a)}"
                data-med_b="${escapar(a.medicamento_b)}"
                data-nivel="${a.nivel_riesgo}">
          <i class="bi bi-pen"></i> Aceptar con justificación
        </button>
      </div>` : '';
    return `
      <div class="alerta-interaccion nivel-${a.nivel_riesgo}">
        <h4>
          <i class="bi bi-exclamation-triangle"></i>
          ${escapar(a.medicamento_a)} + ${escapar(a.medicamento_b)}
          <span class="badge-riesgo badge-riesgo-${a.nivel_riesgo}">${a.nivel_riesgo}</span>
        </h4>
        ${consecuencia}
        <p style="font-size:13px;margin:6px 0;">${escapar(a.descripcion)}</p>
        ${a.recomendacion ? `<p class="alerta-recomendacion"><strong>Recomendación clínica:</strong> ${escapar(a.recomendacion)}</p>` : ''}
        ${justificada}
        ${accionBtn()}
      </div>`;
  }

  const modal = document.getElementById('modal-aceptar');
  modal.querySelectorAll('[data-cerrar-aceptar]').forEach(b =>
    b.addEventListener('click', () => modal.hidden = true));

  document.getElementById('form-aceptar').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('aceptar-error');
    err.hidden = true;
    const idAlerta = parseInt(document.getElementById('alerta-id').value, 10);
    const justificacion = document.getElementById('justificacion').value.trim();
    if (justificacion.length < 10) {
      err.textContent = 'La justificación debe tener al menos 10 caracteres.'; err.hidden = false; return;
    }
    try {
      const r = await fetch('api/interacciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'aceptar', id_alerta: idAlerta, justificacion })
      });
      const d = await r.json();
      if (d.ok) { modal.hidden = true; cargarAlertas(); }
      else { err.textContent = d.error; err.hidden = false; }
    } catch (ex) {
      err.textContent = 'Error de conexión.'; err.hidden = false;
    }
  });
})();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
