<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Administrador']);
$tituloPagina = 'Validar cédulas médicas';
$paginaActiva = 'cedulas';

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="page-header">
  <div>
    <h1>Validar cédulas médicas</h1>
    <p class="page-subtitle">
      Revisa, aprueba o rechaza las credenciales que los médicos han cargado en su perfil.
      Solo un médico con cédula validada puede prescribir medicamentos.
    </p>
  </div>
</div>

<div class="tabs" id="tabs-cedulas">
  <button type="button" class="tab-btn is-active" data-estado="pendientes">
    <i class="bi bi-hourglass-split"></i> Pendientes
  </button>
  <button type="button" class="tab-btn" data-estado="aprobadas">
    <i class="bi bi-check-circle"></i> Aprobadas
  </button>
  <button type="button" class="tab-btn" data-estado="rechazadas">
    <i class="bi bi-x-circle"></i> Rechazadas
  </button>
</div>

<div id="lista-cedulas" class="lista-vertical">
  <div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>
</div>

<!-- Modal rechazo -->
<div class="modal-overlay" id="modal-rechazo" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Rechazar cédula</h3>
      <button class="modal-cerrar" type="button" data-cerrar-rechazo>&times;</button>
    </div>
    <form id="form-rechazo">
      <div class="modal-body">
        <input type="hidden" id="rechazo-id">
        <p style="margin-top:0;color:var(--gray-600);">Indica al médico el motivo del rechazo para que pueda corregirlo y reenviarlo.</p>
        <div class="form-group">
          <label>Motivo del rechazo <span style="color:var(--red-600);">*</span></label>
          <textarea id="rechazo-motivo" rows="5" minlength="10" maxlength="500" required
                    placeholder="Ejemplo: La imagen está borrosa, no se distingue el número de cédula. Por favor sube una foto más clara."></textarea>
          <small class="text-muted">Mínimo 10 caracteres, máximo 500.</small>
        </div>
        <div id="rechazo-error" class="alert alert-error" hidden></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-secondary" data-cerrar-rechazo>Cancelar</button>
        <button type="submit" class="btn btn-danger">
          <i class="bi bi-x-circle"></i> Rechazar cédula
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const escapar = (s) => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

let estadoActual = 'pendientes';

document.querySelectorAll('#tabs-cedulas .tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tabs-cedulas .tab-btn').forEach(b => b.classList.remove('is-active'));
    btn.classList.add('is-active');
    estadoActual = btn.dataset.estado;
    cargar();
  });
});

async function cargar() {
  const cont = document.getElementById('lista-cedulas');
  cont.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>';
  try {
    const r = await fetch('api/cedulas.php?accion=pendientes&estado=' + estadoActual);
    const d = await r.json();
    if (!d.ok) {
      cont.innerHTML = '<div class="empty-state"><h3>' + escapar(d.error) + '</h3></div>';
      return;
    }
    if (d.medicos.length === 0) {
      const mensajes = {
        pendientes: 'No hay cédulas pendientes de revisión',
        aprobadas: 'Aún no has aprobado ninguna cédula',
        rechazadas: 'No hay cédulas rechazadas',
      };
      cont.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i><h3>'
        + mensajes[estadoActual] + '</h3></div>';
      return;
    }
    cont.innerHTML = d.medicos.map(m => {
      const pillEstado = m.cedula_validada == 1
        ? '<span class="pill pill-aprobada"><i class="bi bi-check-circle"></i> Aprobada</span>'
        : (m.motivo_rechazo_cedula
            ? '<span class="pill pill-rechazada"><i class="bi bi-x-circle"></i> Rechazada</span>'
            : '<span class="pill pill-pendiente"><i class="bi bi-hourglass-split"></i> Pendiente</span>');
      const botones = estadoActual === 'pendientes' ? `
        <div class="lista-card-actions">
          <button class="btn btn-success btn-sm js-aprobar" data-id="${m.id_usuario}"
                  ${!m.cedula_profesional ? 'disabled title="El médico aún no cargó su cédula"' : ''}>
            <i class="bi bi-check-lg"></i> Aprobar
          </button>
          <button class="btn btn-danger btn-sm js-rechazar" data-id="${m.id_usuario}">
            <i class="bi bi-x-lg"></i> Rechazar
          </button>
        </div>` : '';
      return `
        <div class="lista-card">
          <div class="lista-card-info">
            <h3>${escapar(m.nombre_completo)}</h3>
            <p class="text-muted" style="font-size:13px;margin:0 0 8px;">${escapar(m.correo)}</p>
            <div class="lista-card-meta">
              <strong>Cédula:</strong> ${escapar(m.cedula_profesional || '— no cargada —')}<br>
              <strong>Especialidad:</strong> ${escapar(m.especialidad || '—')}
              ${m.imagen_cedula
                ? `<br><a href="${escapar(m.imagen_cedula)}" target="_blank">
                    <i class="bi bi-image"></i> Ver imagen de la cédula
                  </a>` : ''}
              ${m.motivo_rechazo_cedula
                ? `<br><span style="color:#991b1b;"><strong>Motivo del rechazo:</strong> ${escapar(m.motivo_rechazo_cedula)}</span>` : ''}
              ${m.fecha_validacion_cedula
                ? `<br><span class="text-muted" style="font-size:12px;">Revisada el ${m.fecha_validacion_cedula}</span>` : ''}
            </div>
            ${pillEstado}
          </div>
          ${botones}
        </div>`;
    }).join('');

    document.querySelectorAll('.js-aprobar').forEach(b => b.addEventListener('click', () => aprobar(parseInt(b.dataset.id, 10))));
    document.querySelectorAll('.js-rechazar').forEach(b => b.addEventListener('click', () => {
      document.getElementById('rechazo-id').value = b.dataset.id;
      document.getElementById('rechazo-motivo').value = '';
      document.getElementById('rechazo-error').hidden = true;
      document.getElementById('modal-rechazo').hidden = false;
    }));
  } catch (e) {
    cont.innerHTML = '<div class="empty-state"><h3>Error de conexión</h3></div>';
  }
}

async function aprobar(id) {
  if (!confirm('¿Aprobar las credenciales de este médico?')) return;
  const r = await fetch('api/cedulas.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accion: 'aprobar', id_medico: id })
  });
  const d = await r.json();
  if (d.ok) cargar();
  else alert(d.error);
}

document.querySelectorAll('[data-cerrar-rechazo]').forEach(b =>
  b.addEventListener('click', () => document.getElementById('modal-rechazo').hidden = true));

document.getElementById('form-rechazo').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('rechazo-error');
  err.hidden = true;
  const id = parseInt(document.getElementById('rechazo-id').value, 10);
  const motivo = document.getElementById('rechazo-motivo').value.trim();
  if (motivo.length < 10) {
    err.textContent = 'El motivo debe tener al menos 10 caracteres.'; err.hidden = false; return;
  }
  const r = await fetch('api/cedulas.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accion: 'rechazar', id_medico: id, motivo })
  });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('modal-rechazo').hidden = true;
    cargar();
  } else {
    err.textContent = d.error; err.hidden = false;
  }
});

cargar();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
