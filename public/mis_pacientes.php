<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Medico']);
$tituloPagina  = 'Mis pacientes';
$paginaActiva  = 'mis_pacientes';

$idMedico = (int)$usuarioActual['id_usuario'];
$cedulaValidada = !empty($usuarioActual['cedula_validada']);

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<?php if (!$cedulaValidada): ?>
<div class="banner banner-amarillo">
  <i class="bi bi-patch-exclamation"></i>
  <div class="banner-body">
    <h3>Credenciales sin validar</h3>
    <p>Para gestionar pacientes primero debes tener tu cédula aprobada.
       Ve a <a href="perfil.php"><strong>Mi perfil</strong></a> para revisarla.</p>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1>Mis pacientes</h1>
    <p class="page-subtitle">
      Pacientes que el administrador te asignó. Desde aquí puedes ver su tratamiento, editar lo que tú recetaste y consultar el indicador de adherencia.
    </p>
  </div>
</div>

<div id="lista-pacientes" class="med-grid">
  <div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>
</div>

<!-- Modal detalle paciente -->
<div class="modal-overlay" id="modal-detalle" hidden>
  <div class="modal-card is-grande">
    <div class="modal-head">
      <h3 id="detalle-titulo">Detalle del paciente</h3>
      <button class="modal-cerrar" type="button" data-cerrar-detalle>&times;</button>
    </div>
    <div class="modal-body" id="detalle-cuerpo">Cargando…</div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" data-cerrar-detalle>Cerrar</button>
    </div>
  </div>
</div>

<script>
(async function () {
  const cont = document.getElementById('lista-pacientes');
  const modal = document.getElementById('modal-detalle');
  modal.querySelectorAll('[data-cerrar-detalle]').forEach(b =>
    b.addEventListener('click', () => modal.hidden = true));

  function adherenciaClase(p) {
    if (p === null || p === undefined) return 'adherencia-sin';
    if (p >= 80) return 'adherencia-alta';
    if (p >= 60) return 'adherencia-media';
    return 'adherencia-baja';
  }

  function escapar(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  try {
    const r = await fetch('api/medico_paciente.php?accion=mis_pacientes');
    const d = await r.json();
    if (!d.ok) {
      cont.innerHTML = `<div class="empty-state"><i class="bi bi-exclamation-triangle"></i><h3>${escapar(d.error)}</h3></div>`;
      return;
    }
    if (d.pacientes.length === 0) {
      cont.innerHTML = `<div class="empty-state">
        <i class="bi bi-people"></i>
        <h3>Aún no tienes pacientes asignados</h3>
        <p>Pídele al administrador que te asigne pacientes desde el panel de gestión.</p>
      </div>`;
      return;
    }
    cont.innerHTML = d.pacientes.map(p => {
      const ad = p.porcentaje_adherencia;
      const adTexto = ad === null ? 'Sin registros' : ad + '%';
      return `
        <div class="card med-card">
          <div class="med-card-head">
            <div>
              <h3>${escapar(p.nombre_completo)}</h3>
              <p class="text-muted" style="font-size:12.5px;margin:2px 0 0;">${escapar(p.correo)}</p>
            </div>
            <span class="adherencia-pill ${adherenciaClase(ad)}">
              <i class="bi bi-graph-up"></i> ${adTexto}
            </span>
          </div>
          ${p.notas_clinicas ? `<p style="margin:10px 0;font-size:13px;color:var(--gray-700);background:var(--gray-100);padding:10px 12px;border-radius:8px;">
              <i class="bi bi-journal-text"></i> ${escapar(p.notas_clinicas)}
            </p>` : ''}
          <div class="med-actions" style="margin-top:12px;">
            <button class="btn btn-primary btn-sm js-detalle" data-id="${p.id_usuario}">
              <i class="bi bi-eye"></i> Ver detalle
            </button>
            <button class="btn btn-secondary btn-sm js-notas"
                    data-relacion="${p.id_relacion}"
                    data-notas="${escapar(p.notas_clinicas || '')}">
              <i class="bi bi-pencil-square"></i> Editar notas
            </button>
          </div>
        </div>`;
    }).join('');

    document.querySelectorAll('.js-detalle').forEach(btn => {
      btn.addEventListener('click', () => abrirDetalle(parseInt(btn.dataset.id, 10)));
    });

    document.querySelectorAll('.js-notas').forEach(btn => {
      btn.addEventListener('click', async () => {
        const idRel = parseInt(btn.dataset.relacion, 10);
        const previo = btn.dataset.notas;
        const nuevo = prompt('Notas clínicas (visibles solo para ti):', previo);
        if (nuevo === null) return;
        const r = await fetch('api/medico_paciente.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accion: 'actualizar_notas', id_relacion: idRel, notas_clinicas: nuevo })
        });
        const d = await r.json();
        if (d.ok) location.reload();
        else alert(d.error);
      });
    });
  } catch (e) {
    cont.innerHTML = `<div class="empty-state"><i class="bi bi-wifi-off"></i><h3>Error de conexión</h3></div>`;
  }

  async function abrirDetalle(idPaciente) {
    document.getElementById('detalle-cuerpo').innerHTML = 'Cargando…';
    modal.hidden = false;
    try {
      const r = await fetch('api/medico_paciente.php?accion=detalle_paciente&id=' + idPaciente);
      const d = await r.json();
      if (!d.ok) {
        document.getElementById('detalle-cuerpo').innerHTML = '<p>' + escapar(d.error) + '</p>';
        return;
      }
      document.getElementById('detalle-titulo').textContent = d.relacion.nombre_completo;
      const meds = d.medicamentos.map(m => `
        <div style="border:1px solid var(--gray-200);border-radius:10px;padding:12px 14px;margin-bottom:8px;">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <strong>${escapar(m.nombre)} ${m.cantidad_dosis} ${escapar(m.unidad_dosis)}</strong>
            ${m.estado_stock && m.estado_stock !== 'sin_seguimiento'
              ? `<span class="stock-indicator stock-${m.estado_stock}">${m.stock_actuales || 0} u.</span>`
              : ''}
          </div>
          <small class="text-muted">cada ${m.frecuencia_horas} h · inicio ${m.hora_inicio.substring(0,5)} · ${escapar(m.presentacion)}</small>
        </div>`).join('');
      document.getElementById('detalle-cuerpo').innerHTML = `
        <div style="margin-bottom:14px;font-size:13.5px;">
          <p style="margin:0;"><strong>Correo:</strong> ${escapar(d.relacion.correo)}</p>
          <p style="margin:2px 0;"><strong>Teléfono:</strong> ${escapar(d.relacion.telefono || '—')}</p>
          <p style="margin:2px 0;"><strong>Peso:</strong> ${d.relacion.peso_kg || '—'} kg ·
             <strong>Nacimiento:</strong> ${d.relacion.fecha_nacimiento || '—'}</p>
          <p style="margin:2px 0;"><strong>Horas de comida:</strong>
             Desayuno ${(d.relacion.hora_desayuno||'').substring(0,5)} ·
             Comida ${(d.relacion.hora_comida||'').substring(0,5)} ·
             Cena ${(d.relacion.hora_cena||'').substring(0,5)}</p>
        </div>
        <h4 style="margin:14px 0 8px;color:var(--navy-900);">Medicamentos activos</h4>
        ${meds || '<p class="text-muted">Sin medicamentos activos.</p>'}
        <div style="margin-top:14px;">
          <a class="btn btn-primary btn-sm" href="interacciones.php?id_paciente=${idPaciente}">
            <i class="bi bi-exclamation-triangle"></i> Ver alertas de interacciones
          </a>
        </div>`;
    } catch (e) {
      document.getElementById('detalle-cuerpo').innerHTML = '<p>Error de conexión.</p>';
    }
  }
})();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
