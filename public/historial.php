<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Paciente','Medico']);
$tituloPagina = 'Historial de cambios';
$paginaActiva = 'medicamentos';

$idMed = (int)($_GET['id'] ?? 0);
if ($idMed <= 0) {
    header('Location: medicamentos.php');
    exit;
}

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="page-header">
  <div>
    <a href="medicamentos.php" class="btn btn-secondary btn-sm" style="margin-bottom:8px;">
      <i class="bi bi-arrow-left"></i> Volver a medicamentos
    </a>
    <h1 id="med-titulo">Historial</h1>
    <p class="page-subtitle" id="med-info">Cargando…</p>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 14px;font-size:16px;"><i class="bi bi-funnel"></i> Filtros</h3>
  <div class="grid-3">
    <div class="form-group">
      <label>Campo modificado</label>
      <select id="f-campo">
        <option value="">Todos los campos</option>
        <option value="nombre">Nombre</option>
        <option value="cantidad_dosis">Cantidad de dosis</option>
        <option value="unidad_dosis">Unidad</option>
        <option value="frecuencia_horas">Frecuencia</option>
        <option value="hora_inicio">Hora de inicio</option>
        <option value="fecha_inicio">Fecha de inicio</option>
        <option value="fecha_fin">Fecha de fin</option>
        <option value="tipo_horario">Tipo de horario</option>
        <option value="instrucciones_medico">Instrucciones del médico</option>
        <option value="instrucciones_paciente">Instrucciones del paciente</option>
        <option value="unidades_por_toma">Unidades por toma</option>
        <option value="alerta_stock_dias">Alerta de stock</option>
      </select>
    </div>
    <div class="form-group">
      <label>Desde</label>
      <input type="date" id="f-desde">
    </div>
    <div class="form-group">
      <label>Hasta</label>
      <input type="date" id="f-hasta">
    </div>
  </div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-primary" id="btn-filtrar">
      <i class="bi bi-search"></i> Aplicar filtros
    </button>
    <button class="btn btn-secondary" id="btn-limpiar">
      <i class="bi bi-x-circle"></i> Limpiar
    </button>
  </div>
</div>

<div id="lista-historial" style="margin-top:16px;">
  <div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>
</div>

<script>
const ID_MED = <?= (int)$idMed ?>;
const escapar = (s) => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

const ETIQ_CAMPO = {
  'nombre':'Nombre','cantidad_dosis':'Cantidad de dosis','unidad_dosis':'Unidad',
  'frecuencia_horas':'Frecuencia (horas)','hora_inicio':'Hora de inicio',
  'fecha_inicio':'Fecha de inicio','fecha_fin':'Fecha de fin',
  'instrucciones_medico':'Instrucciones del médico','instrucciones_paciente':'Instrucciones del paciente',
  'tipo_horario':'Tipo de horario','dias_semana_bitmask':'Días de la semana',
  'intervalo_dias':'Intervalo de días','ciclo_dias_activo':'Días activos del ciclo',
  'ciclo_dias_descanso':'Días de descanso','referencia_comida':'Referencia a comida',
  'minutos_referencia':'Minutos de referencia','unidades_por_toma':'Unidades por toma',
  'alerta_stock_dias':'Alerta de stock (días)','activo':'Estado','presentacion':'Presentación',
  'unidades_actuales':'Stock actual'
};

const ETIQ_ACCION = {
  'creacion':'Creación','modificacion':'Modificación','eliminacion':'Eliminación'
};

async function cargar() {
  const cont = document.getElementById('lista-historial');
  cont.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass-split"></i><h3>Cargando…</h3></div>';

  const params = new URLSearchParams({ accion: 'historial', id: ID_MED });
  const campo = document.getElementById('f-campo').value;
  const desde = document.getElementById('f-desde').value;
  const hasta = document.getElementById('f-hasta').value;
  if (campo) params.append('campo', campo);
  if (desde) params.append('desde', desde);
  if (hasta) params.append('hasta', hasta);

  try {
    const r = await fetch('api/medicamentos.php?' + params.toString());
    const d = await r.json();
    if (!d.ok) {
      cont.innerHTML = '<div class="empty-state"><h3>' + escapar(d.error) + '</h3></div>';
      return;
    }

    const m = d.medicamento;
    document.getElementById('med-titulo').textContent = 'Historial de "' + m.nombre + '"';
    document.getElementById('med-info').textContent =
      m.cantidad_dosis + ' ' + m.unidad_dosis + ' cada ' + m.frecuencia_horas + ' h · ' + m.presentacion;

    if (d.historial.length === 0) {
      cont.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i><h3>Sin cambios registrados</h3><p>Este medicamento todavía no tiene modificaciones registradas con los filtros aplicados.</p></div>';
      return;
    }

    cont.innerHTML = '<div class="card" style="padding:0;overflow-x:auto;"><table class="tabla-datos"><thead><tr>'
      + '<th>Fecha</th><th>Tipo</th><th>Campo</th><th>Antes</th><th>Después</th><th>Por</th>'
      + '</tr></thead><tbody>'
      + d.historial.map(h => `
        <tr>
          <td style="font-size:12.5px;color:var(--gray-600);">${escapar(h.fecha_cambio)}</td>
          <td><span class="pill pill-activa">${escapar(ETIQ_ACCION[h.tipo_accion] || h.tipo_accion)}</span></td>
          <td><strong>${escapar(ETIQ_CAMPO[h.campo_modificado] || h.campo_modificado)}</strong></td>
          <td style="color:#991b1b;font-size:13px;">${escapar(h.valor_anterior || '—')}</td>
          <td style="color:#166534;font-size:13px;">${escapar(h.valor_nuevo || '—')}</td>
          <td>${escapar(h.modificador_nombre)}<br>
              <small class="text-muted">${escapar(h.modificador_tipo)}</small></td>
        </tr>`).join('')
      + '</tbody></table></div>';
  } catch (e) {
    cont.innerHTML = '<div class="empty-state"><i class="bi bi-wifi-off"></i><h3>Error de conexión</h3></div>';
  }
}

document.getElementById('btn-filtrar').addEventListener('click', cargar);
document.getElementById('btn-limpiar').addEventListener('click', () => {
  document.getElementById('f-campo').value = '';
  document.getElementById('f-desde').value = '';
  document.getElementById('f-hasta').value = '';
  cargar();
});

cargar();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
