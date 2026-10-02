<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Paciente', 'Medico']);
$tituloPagina = $usuarioActual['tipo_nombre'] === 'Medico' ? 'Recetas y prescripciones' : 'Mis medicamentos';
$paginaActiva = 'medicamentos';

$idUsuario = (int)$usuarioActual['id_usuario'];
$tipo = $usuarioActual['tipo_nombre'];
$cedulaValidada = !empty($usuarioActual['cedula_validada']);

if ($tipo === 'Paciente') {
    $medicamentos = DB::todos(
        "SELECT m.*, v.estado_stock, v.dias_restantes, v.unidades_actuales AS stock_actuales,
                creador.nombre_completo AS creador_nombre,
                tc.nombre AS creador_tipo
           FROM medicamentos m
           LEFT JOIN usuarios creador ON creador.id_usuario = m.id_usuario_creador
           LEFT JOIN tipos_usuario tc ON tc.id_tipo = creador.id_tipo
           LEFT JOIN v_stock_medicamentos v ON v.id_medicamento = m.id_medicamento
          WHERE m.id_usuario_paciente = :u
          ORDER BY m.activo DESC, m.fecha_registro DESC",
        ['u' => $idUsuario]
    );
    $pacientesMedico = [];
} else {
    $medicamentos = DB::todos(
        "SELECT m.*, v.estado_stock, v.dias_restantes, v.unidades_actuales AS stock_actuales,
                u.nombre_completo AS paciente_nombre
           FROM medicamentos m
           INNER JOIN usuarios u ON u.id_usuario = m.id_usuario_paciente
           LEFT JOIN v_stock_medicamentos v ON v.id_medicamento = m.id_medicamento
          WHERE m.id_usuario_creador = :u
          ORDER BY m.activo DESC, m.fecha_registro DESC",
        ['u' => $idUsuario]
    );
    $pacientesMedico = DB::todos(
        "SELECT u.id_usuario, u.nombre_completo, u.peso_kg, u.fecha_nacimiento
           FROM medico_paciente mp
           JOIN usuarios u ON u.id_usuario = mp.id_paciente
          WHERE mp.id_medico = :m AND mp.activa = TRUE
          ORDER BY u.nombre_completo",
        ['m' => $idUsuario]
    );
}

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<?php if ($tipo === 'Medico' && !$cedulaValidada): ?>
<div class="banner banner-amarillo">
  <i class="bi bi-patch-exclamation"></i>
  <div class="banner-body">
    <h3>Tus credenciales aún no han sido validadas</h3>
    <p>No podrás prescribir medicamentos hasta que el administrador valide tu cédula profesional.
       Visita <a href="perfil.php"><strong>Mi perfil</strong></a> para revisar el estado.</p>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1><?= $tipo === 'Medico' ? 'Recetas y prescripciones' : 'Mis medicamentos' ?></h1>
    <p class="page-subtitle">
      <?php if ($tipo === 'Medico'): ?>
        Medicamentos que has recetado a tus pacientes asignados.
      <?php else: ?>
        Tus medicamentos en curso. Los que aparecen con candado fueron prescritos por tu médico.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($tipo === 'Paciente' || ($tipo === 'Medico' && $cedulaValidada)): ?>
  <div class="page-actions">
    <button class="btn btn-primary" id="btn-nuevo-medicamento" type="button">
      <i class="bi bi-plus-lg"></i> Nuevo medicamento
    </button>
  </div>
  <?php endif; ?>
</div>

<?php if ($tipo === 'Paciente'): ?>
<div class="card" id="presc-recibidas" style="margin-bottom:18px;" hidden>
  <div class="card-header">
    <h3><i class="bi bi-prescription2"></i> Prescripciones de tu médico</h3>
  </div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--gray-500);margin:0 0 12px;">
      <i class="bi bi-lock-fill"></i> Estas las administra tu médico.
      Aparecen automáticamente en tus medicamentos con recordatorios y
      no se pueden editar desde tu cuenta.</p>
    <div id="presc-lista"></div>
  </div>
</div>

<script>
(async function () {
  const r = await MedAlert.api('api/prescripciones.php?accion=mias');
  if (!r.ok) return;
  const lista = r.datos.prescripciones || [];
  if (!lista.length) return;
  const card = document.getElementById('presc-recibidas');
  card.hidden = false;
  const est = {
    activa:     ['Activa', 'var(--green-500)'],
    modificada: ['Modificada', 'var(--amber-500)'],
    cancelada:  ['Cancelada', 'var(--gray-400)'],
  };
  document.getElementById('presc-lista').innerHTML =
    '<table class="data-table"><thead><tr>'
    + '<th>Medicamento</th><th>Pauta</th><th>Médico</th>'
    + '<th>Estado</th></tr></thead><tbody>'
    + lista.map(p => {
        const e = est[p.estado] || ['—','var(--gray-400)'];
        const cant = (+p.cantidad_dosis).toString();
        return `<tr style="${p.estado==='cancelada'
          ?'opacity:.55;':''}">
          <td><i class="bi bi-lock-fill"
            style="color:var(--gray-400);font-size:11px;"></i>
            <strong>${esc(p.nombre_medicamento)}</strong></td>
          <td style="font-size:12px;">${cant()} ${esc(p.unidad_dosis)}
            · cada ${p.frecuencia_horas} h
            ${p.duracion_dias?`· ${p.duracion_dias} días`:''}</td>
          <td style="font-size:12px;">${esc(p.medico_nombre||'')}</td>
          <td><span style="color:${e[1]};font-weight:600;">
            ${e[0]}</span></td></tr>`;
      }).join('')
    + '</tbody></table>';
  function esc(s){ return String(s ?? '').replace(/[<>&]/g,
    c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }
})();
</script>
<?php endif; ?>

<?php /* marcador-fin-page-header */ ?>

<?php if (empty($medicamentos)): ?>
  <div class="empty-state">
    <i class="bi bi-capsule"></i>
    <h3>No hay medicamentos registrados</h3>
    <p><?= ($tipo === 'Paciente' || ($tipo === 'Medico' && $cedulaValidada))
        ? 'Pulsa "Nuevo medicamento" para registrar el primero.'
        : 'Espera a que el administrador valide tu cédula para empezar a prescribir.' ?></p>
  </div>
<?php else: ?>
  <div class="med-grid">
    <?php foreach ($medicamentos as $m):
      $esRecetadoMedico = (int)$m['id_usuario_creador'] !== (int)$m['id_usuario_paciente'];
      $estadoStock = $m['estado_stock'] ?? 'sin_seguimiento';
      $puedeEditar = $tipo === 'Paciente'
          ? !$esRecetadoMedico
          : (int)$m['id_usuario_creador'] === $idUsuario;
    ?>
      <div class="card med-card <?= !$m['activo'] ? 'is-inactivo' : '' ?>"
           data-medicamento='<?= htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
        <div class="med-card-head">
          <div>
            <h3><?= Auth::escapar($m['nombre']) ?></h3>
            <?php if ($tipo === 'Medico'): ?>
              <p class="text-muted">Paciente: <?= Auth::escapar($m['paciente_nombre']) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($esRecetadoMedico): ?>
            <span class="icono-recetado" title="Recetado por tu médico">
              <i class="bi bi-lock-fill"></i> <?= $tipo === 'Paciente' ? 'recetado' : 'tuyo' ?>
            </span>
          <?php endif; ?>
        </div>

        <div class="med-attrs">
          <div><i class="bi bi-prescription"></i> <?= (float)$m['cantidad_dosis'] ?> <?= Auth::escapar($m['unidad_dosis']) ?></div>
          <div><i class="bi bi-clock"></i> cada <?= (float)$m['frecuencia_horas'] ?> h</div>
          <div><i class="bi bi-alarm"></i> <?= substr($m['hora_inicio'], 0, 5) ?></div>
          <div><i class="bi bi-capsule"></i> <?= Auth::escapar($m['presentacion']) ?></div>
        </div>

        <?php if ($m['tipo_horario'] && $m['tipo_horario'] !== 'diario'): ?>
        <div class="text-muted" style="font-size:12.5px;">
          <i class="bi bi-calendar-range"></i>
          <?php
            $etiquetasHorario = [
              'dias_semana'       => 'Días específicos',
              'dias_alternos'     => 'Cada ' . ((int)$m['intervalo_dias']) . ' días',
              'ciclo'             => $m['ciclo_dias_activo'] . ' días activo / ' . $m['ciclo_dias_descanso'] . ' descanso',
              'referencia_comida' => 'Según hora de ' . str_replace(['antes_','despues_','_'], ['antes de ','después de ',' '], (string)$m['referencia_comida']),
            ];
            echo Auth::escapar($etiquetasHorario[$m['tipo_horario']] ?? '');
          ?>
        </div>
        <?php endif; ?>

        <?php if ($estadoStock && $estadoStock !== 'sin_seguimiento'): ?>
        <div style="margin-top:10px;">
          <span class="stock-indicator stock-<?= $estadoStock ?>">
            <i class="bi bi-box-seam"></i>
            <?php
              $etiqStock = [
                'ok' => (int)$m['stock_actuales'] . ' unidades · ' . (int)$m['dias_restantes'] . ' días',
                'bajo' => 'Stock bajo · ' . (int)$m['stock_actuales'] . ' unidades',
                'agotado' => 'Sin stock',
              ];
              echo $etiqStock[$estadoStock] ?? '';
            ?>
          </span>
        </div>
        <?php endif; ?>

        <div class="med-actions">
          <a href="historial.php?id=<?= (int)$m['id_medicamento'] ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-clock-history"></i> Historial
          </a>
          <?php if ($puedeEditar && $m['activo']): ?>
            <button class="btn btn-secondary btn-sm btn-editar-med" data-id="<?= (int)$m['id_medicamento'] ?>">
              <i class="bi bi-pencil"></i> Editar
            </button>
            <button class="btn btn-danger btn-sm btn-eliminar-med" data-id="<?= (int)$m['id_medicamento'] ?>">
              <i class="bi bi-trash"></i> Eliminar
            </button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Modal alta / edición -->
<div class="modal-overlay" id="modal-medicamento" hidden>
  <div class="modal-card is-grande">
    <div class="modal-head">
      <h3 id="modal-titulo">Nuevo medicamento</h3>
      <button class="modal-cerrar" type="button" data-cerrar>&times;</button>
    </div>
    <form id="form-medicamento" novalidate>
      <div class="modal-body">
        <input type="hidden" id="med-id" name="id_medicamento">

        <?php if ($tipo === 'Medico'): ?>
        <div class="form-group">
          <label>Paciente <span style="color:var(--red-600);">*</span></label>
          <select id="med-id-paciente" name="id_paciente" required>
            <option value="">— Selecciona uno de tus pacientes —</option>
            <?php foreach ($pacientesMedico as $p): ?>
              <option value="<?= (int)$p['id_usuario'] ?>"
                      data-peso="<?= htmlspecialchars((string)($p['peso_kg'] ?? ''), ENT_QUOTES) ?>"
                      data-nacimiento="<?= htmlspecialchars((string)($p['fecha_nacimiento'] ?? ''), ENT_QUOTES) ?>">
                <?= Auth::escapar($p['nombre_completo']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="grid-2">
          <div class="form-group">
            <label>Nombre <span style="color:var(--red-600);">*</span></label>
            <input type="text" id="med-nombre" name="nombre" required maxlength="120">
          </div>
          <div class="form-group">
            <label>Presentación <span style="color:var(--red-600);">*</span></label>
            <select id="med-presentacion" name="presentacion" required>
              <option value="">— Selecciona —</option>
              <option data-unidad="tableta">Tableta</option>
              <option data-unidad="tableta">Tableta masticable</option>
              <option data-unidad="cápsula">Cápsula</option>
              <option data-unidad="ml">Jarabe</option>
              <option data-unidad="ml">Inyectable</option>
              <option data-unidad="gota">Gotas</option>
              <option data-unidad="aplicación">Crema</option>
              <option data-unidad="inhalación">Inhalador</option>
              <option data-unidad="">Otro</option>
            </select>
          </div>
        </div>

        <div class="grid-3">
          <div class="form-group">
            <label>Cantidad <span style="color:var(--red-600);">*</span></label>
            <input type="number" id="med-cantidad" name="cantidad_dosis" step="0.01" min="0.01" required>
          </div>
          <div class="form-group">
            <label>Unidad <span style="color:var(--red-600);">*</span></label>
            <select id="med-unidad" name="unidad_dosis" required>
              <option value="">—</option>
              <option>mg</option><option>g</option><option>mcg</option><option>ml</option>
              <option>tableta</option><option>cápsula</option>
              <option>gota</option><option>aplicación</option><option>inhalación</option>
            </select>
          </div>
          <div class="form-group">
            <label>Frecuencia (h) <span style="color:var(--red-600);">*</span></label>
            <input type="number" id="med-frecuencia" name="frecuencia_horas" step="0.5" min="0.5" max="168" required>
          </div>
        </div>

        <?php if ($tipo === 'Medico'): ?>
        <details class="form-bloque" style="background:#eff6ff;border-left-color:#2563eb;">
          <summary style="cursor:pointer;font-weight:600;color:var(--navy-900);list-style:none;">
            <i class="bi bi-calculator"></i> Cálculo automático por peso y edad
          </summary>
          <div class="grid-2" style="margin-top:12px;">
            <div class="form-group">
              <label>Dosis (mg/kg/día)</label>
              <input type="number" id="med-mg-kg" name="dosis_mg_por_kg" step="0.001" min="0">
              <small class="text-muted">Si la indicas, el sistema calculará la dosis por toma automáticamente.</small>
            </div>
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:6px;margin-top:24px;">
                <input type="checkbox" id="med-ajuste-manual" name="ajuste_manual_dosis">
                Ajustar la dosis manualmente
              </label>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <div class="form-group">
          <label>Tipo de horario</label>
          <div class="tabs" id="tabs-horario">
            <button type="button" class="tab-btn is-active" data-tipo="diario"><i class="bi bi-calendar3"></i> Diario</button>
            <button type="button" class="tab-btn" data-tipo="dias_semana"><i class="bi bi-calendar-week"></i> Días de la semana</button>
            <button type="button" class="tab-btn" data-tipo="dias_alternos"><i class="bi bi-arrow-repeat"></i> Cada N días</button>
            <button type="button" class="tab-btn" data-tipo="ciclo"><i class="bi bi-arrow-clockwise"></i> Ciclo</button>
            <button type="button" class="tab-btn" data-tipo="referencia_comida"><i class="bi bi-egg-fried"></i> Según comida</button>
          </div>
          <input type="hidden" id="med-tipo-horario" name="tipo_horario" value="diario">

          <div class="contenido-horario is-active" data-contenido="diario">
            <p class="text-muted" style="font-size:12.5px;">El medicamento se toma todos los días.</p>
          </div>

          <div class="contenido-horario" data-contenido="dias_semana">
            <p class="text-muted" style="font-size:12.5px;">Marca los días en que el paciente debe tomarlo.</p>
            <div class="dias-semana-grid">
              <?php $nombres = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
              foreach ($nombres as $i => $n): ?>
                <label class="dia-checkbox">
                  <input type="checkbox" name="dias_semana[]" value="<?= $i+1 ?>">
                  <span><?= $n ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="contenido-horario" data-contenido="dias_alternos">
            <div class="form-group">
              <label>Tomar cada (días)</label>
              <input type="number" name="intervalo_dias" min="2" max="30" value="2">
              <small class="text-muted">Ej.: 2 = días alternos · 3 = uno sí, dos no.</small>
            </div>
          </div>

          <div class="contenido-horario" data-contenido="ciclo">
            <div class="grid-2">
              <div class="form-group">
                <label>Días activos</label>
                <input type="number" name="ciclo_dias_activo" min="1" max="90" value="21">
              </div>
              <div class="form-group">
                <label>Días de descanso</label>
                <input type="number" name="ciclo_dias_descanso" min="1" max="90" value="7">
              </div>
            </div>
          </div>

          <div class="contenido-horario" data-contenido="referencia_comida">
            <div class="grid-2">
              <div class="form-group">
                <label>Referencia</label>
                <select name="referencia_comida">
                  <option value="antes_desayuno">Antes del desayuno</option>
                  <option value="despues_desayuno">Después del desayuno</option>
                  <option value="antes_comida">Antes de la comida</option>
                  <option value="despues_comida">Después de la comida</option>
                  <option value="antes_cena">Antes de la cena</option>
                  <option value="despues_cena">Después de la cena</option>
                </select>
              </div>
              <div class="form-group">
                <label>Minutos antes/después</label>
                <input type="number" name="minutos_referencia" min="0" max="240" value="30">
              </div>
            </div>
            <small class="text-muted">
              El sistema usa la hora de comida del paciente registrada en su perfil.
            </small>
          </div>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label>Hora de inicio <span style="color:var(--red-600);">*</span></label>
            <input type="time" id="med-hora-inicio" name="hora_inicio">
          </div>
          <div class="form-group">
            <label>Fecha de inicio <span style="color:var(--red-600);">*</span></label>
            <input type="date" id="med-fecha-inicio" name="fecha_inicio" required>
          </div>
        </div>

        <div class="form-group">
          <label>Fecha de fin (opcional)</label>
          <input type="date" id="med-fecha-fin" name="fecha_fin">
        </div>

        <details class="form-bloque">
          <summary style="cursor:pointer;font-weight:600;color:var(--navy-900);list-style:none;">
            <i class="bi bi-box-seam"></i> Control de stock
          </summary>
          <div class="grid-3" style="margin-top:12px;">
            <div class="form-group">
              <label>Unidades actuales</label>
              <input type="number" id="med-unidades-actuales" name="unidades_actuales" min="0" max="99999" value="0">
            </div>
            <div class="form-group">
              <label>Unidades por toma</label>
              <input type="number" id="med-unidades-toma" name="unidades_por_toma" min="0" max="999" value="1">
            </div>
            <div class="form-group">
              <label>Avisar cuando queden (días)</label>
              <input type="number" id="med-alerta-dias" name="alerta_stock_dias" min="1" max="30" value="3">
            </div>
          </div>
        </details>

        <div class="form-group">
          <label>Instrucciones</label>
          <textarea id="med-instrucciones" name="instrucciones" rows="3" maxlength="500"></textarea>
        </div>

        <div id="med-error" class="alert alert-error" hidden></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-secondary" data-cerrar>Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btn-guardar-med">
          <i class="bi bi-save"></i> Guardar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal alertas de interacciones -->
<div class="modal-overlay" id="modal-interacciones" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3><i class="bi bi-exclamation-triangle"></i> Alerta de interacciones</h3>
      <button class="modal-cerrar" type="button" data-cerrar-interacciones>&times;</button>
    </div>
    <div class="modal-body" id="cuerpo-interacciones"></div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" data-cerrar-interacciones>Entendido</button>
    </div>
  </div>
</div>

<script src="assets/js/medicamentos.js?v=4"></script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
