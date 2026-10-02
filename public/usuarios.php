<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirTipo(['Administrador']);
$tituloPagina = 'Gestión de usuarios';
$paginaActiva = 'usuarios';

$filtroTipo = Helpers::get('tipo', 'todos');
$where = ['1=1'];
$params = [];
if ($filtroTipo !== 'todos') {
    $where[] = 't.nombre = :tipo';
    $params['tipo'] = $filtroTipo;
}

$usuarios = DB::todos(
    "SELECT u.id_usuario, u.nombre_completo, u.correo, u.telefono, u.estado_cuenta,
            u.fecha_registro, u.cedula_profesional, u.especialidad,
            t.nombre AS tipo_nombre, t.id_tipo
       FROM usuarios u
       INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
      WHERE " . implode(' AND ', $where) . "
      ORDER BY t.nombre, u.nombre_completo",
    $params
);

$conteos = ['todos' => 0];
foreach (DB::todos("SELECT t.nombre, COUNT(u.id_usuario) AS n
                      FROM tipos_usuario t
                      LEFT JOIN usuarios u ON u.id_tipo = t.id_tipo
                     GROUP BY t.id_tipo, t.nombre") as $c) {
    $conteos[$c['nombre']] = (int)$c['n'];
    $conteos['todos'] += (int)$c['n'];
}

$tiposUsuario = DB::todos("SELECT id_tipo, nombre, descripcion FROM tipos_usuario WHERE activo = TRUE ORDER BY id_tipo");

$pacientes = DB::todos(
    "SELECT u.id_usuario, u.nombre_completo, u.correo
       FROM usuarios u
       INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
      WHERE t.nombre = 'Paciente' AND u.estado_cuenta = 'activa'
      ORDER BY u.nombre_completo"
);

$cuidadores = DB::todos(
    "SELECT u.id_usuario, u.nombre_completo
       FROM usuarios u
       INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
      WHERE t.nombre = 'Cuidador' AND u.estado_cuenta = 'activa'
      ORDER BY u.nombre_completo"
);

$asignaciones = DB::todos(
    "SELECT cp.id_relacion, cp.parentesco, cp.fecha_asignacion,
            c.nombre_completo AS cuidador_nombre, c.id_usuario AS id_cuidador,
            p.nombre_completo AS paciente_nombre, p.id_usuario AS id_paciente
       FROM cuidador_paciente cp
       INNER JOIN usuarios c ON c.id_usuario = cp.id_cuidador
       INNER JOIN usuarios p ON p.id_usuario = cp.id_paciente
      WHERE cp.activa = TRUE
      ORDER BY p.nombre_completo, c.nombre_completo"
);

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="search-bar">
  <div class="search-wrap">
    <i class="bi bi-search"></i>
    <input type="text" id="buscador" placeholder="Buscar por nombre o correo...">
  </div>
  <button class="btn btn-danger" id="btn-nuevo-usuario">
    <i class="bi bi-person-plus-fill"></i> Crear cuenta profesional
  </button>
</div>

<div class="tabs" id="tabs-tipo">
  <a href="?tipo=todos" class="tab <?= $filtroTipo === 'todos' ? 'is-active' : '' ?>">
    Todos <span class="count"><?= $conteos['todos'] ?? 0 ?></span>
  </a>
  <a href="?tipo=Paciente" class="tab <?= $filtroTipo === 'Paciente' ? 'is-active' : '' ?>">
    Pacientes <span class="count"><?= $conteos['Paciente'] ?? 0 ?></span>
  </a>
  <a href="?tipo=Medico" class="tab <?= $filtroTipo === 'Medico' ? 'is-active' : '' ?>">
    Médicos <span class="count"><?= $conteos['Medico'] ?? 0 ?></span>
  </a>
  <a href="?tipo=Cuidador" class="tab <?= $filtroTipo === 'Cuidador' ? 'is-active' : '' ?>">
    Cuidadores <span class="count"><?= $conteos['Cuidador'] ?? 0 ?></span>
  </a>
  <a href="?tipo=Enfermero" class="tab <?= $filtroTipo === 'Enfermero' ? 'is-active' : '' ?>">
    Enfermeros <span class="count"><?= $conteos['Enfermero'] ?? 0 ?></span>
  </a>
  <a href="?tipo=Administrador" class="tab <?= $filtroTipo === 'Administrador' ? 'is-active' : '' ?>">
    Administradores <span class="count"><?= $conteos['Administrador'] ?? 0 ?></span>
  </a>
</div>

<?php if (empty($usuarios)): ?>
  <div class="empty-state card">
    <i class="bi bi-people"></i>
    <h3>No hay usuarios para mostrar</h3>
    <p>Ajusta los filtros para ver más resultados.</p>
  </div>
<?php else: ?>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Usuario</th>
          <th>Teléfono</th>
          <th>Tipo</th>
          <th>Estado</th>
          <th class="text-right">Acciones</th>
        </tr>
      </thead>
      <tbody id="tabla-usuarios">
        <?php foreach ($usuarios as $u): ?>
        <tr data-id="<?= (int)$u['id_usuario'] ?>"
            data-nombre="<?= Auth::escapar(mb_strtolower($u['nombre_completo'])) ?>"
            data-correo="<?= Auth::escapar(mb_strtolower($u['correo'])) ?>">
          <td>
            <div class="user-row">
              <div class="user-avatar tipo-<?= Auth::escapar($u['tipo_nombre']) ?>">
                <?php
                $partes = preg_split('/\s+/', $u['nombre_completo']);
                echo mb_strtoupper(mb_substr($partes[0], 0, 1)
                                  . (count($partes) > 1 ? mb_substr($partes[1], 0, 1) : ''));
                ?>
              </div>
              <div>
                <div class="user-name"><?= Auth::escapar($u['nombre_completo']) ?></div>
                <div class="user-mail"><?= Auth::escapar($u['correo']) ?></div>
                <?php if ($u['tipo_nombre'] === 'Medico' && $u['cedula_profesional']): ?>
                  <div class="user-mail" style="font-size:11px; color:var(--gray-500);">
                    Cédula: <?= Auth::escapar($u['cedula_profesional']) ?> · <?= Auth::escapar($u['especialidad']) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= Auth::escapar($u['telefono'] ?: '—') ?></td>
          <td>
            <?php
              $colores = [
                'Paciente' => 'badge-navy', 'Medico' => 'badge-green',
                'Cuidador' => 'badge-amber', 'Administrador' => 'badge-red',
                'Enfermero' => 'badge-navy'
              ];
              $color = $colores[$u['tipo_nombre']] ?? 'badge-gray';
            ?>
            <span class="badge <?= $color ?>"><?= Auth::escapar(mb_strtoupper($u['tipo_nombre'])) ?></span>
          </td>
          <td>
            <?php if ($u['estado_cuenta'] === 'activa'): ?>
              <span class="badge badge-green"><i class="bi bi-check-circle-fill"></i> Activa</span>
            <?php elseif ($u['estado_cuenta'] === 'pendiente_verificacion'): ?>
              <span class="badge badge-amber" title="No ha validado el código de su correo">
                <i class="bi bi-clock"></i> Pendiente
              </span>
            <?php elseif ($u['estado_cuenta'] === 'suspendida'): ?>
              <span class="badge badge-red"><i class="bi bi-x-circle"></i> Suspendida</span>
            <?php else: ?>
              <span class="badge badge-red"><?= Auth::escapar($u['estado_cuenta']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-right" style="white-space: nowrap;">
            <?php if ((int)$u['id_usuario'] === (int)$usuarioActual['id_usuario']): ?>
              <span class="text-muted" style="font-size:12.5px;">Tú mismo</span>
            <?php else: ?>
              <?php

                $esProfesional = in_array($u['tipo_nombre'], ['Medico', 'Administrador'], true);
              ?>
              <?php if ($esProfesional): ?>
              <button class="btn btn-outline btn-sm js-cambiar-tipo"
                      data-id="<?= (int)$u['id_usuario'] ?>"
                      data-nombre="<?= Auth::escapar($u['nombre_completo']) ?>"
                      data-tipo="<?= Auth::escapar($u['tipo_nombre']) ?>"
                      title="Cambiar entre Médico y Administrador">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
              <?php if ($u['estado_cuenta'] === 'suspendida'): ?>
              <button class="btn btn-outline btn-sm js-reactivar"
                      data-id="<?= (int)$u['id_usuario'] ?>"
                      data-nombre="<?= Auth::escapar($u['nombre_completo']) ?>"
                      title="Reactivar cuenta" style="color:var(--green-500);">
                <i class="bi bi-arrow-clockwise"></i>
              </button>
              <?php else: ?>
              <button class="btn btn-outline btn-sm js-suspender"
                      data-id="<?= (int)$u['id_usuario'] ?>"
                      data-nombre="<?= Auth::escapar($u['nombre_completo']) ?>"
                      title="Suspender cuenta" style="color:var(--red-500);">
                <i class="bi bi-x-circle"></i>
              </button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<!-- ─────────── Vínculos cuidador ↔ paciente ─────────── -->
<div class="page-section" style="margin-top: 32px;">
  <div class="page-section-header">
    <h2 class="page-section-title"><i class="bi bi-link-45deg"></i> Cuidadores asignados a pacientes</h2>
    <button class="btn btn-primary btn-sm" id="btn-nueva-asignacion">
      <i class="bi bi-plus-lg"></i> Nueva asignación
    </button>
  </div>

  <?php if (empty($asignaciones)): ?>
    <div class="empty-state card">
      <i class="bi bi-link-45deg"></i>
      <h3>Aún no hay cuidadores asignados</h3>
      <p>Cuando un cuidador se registre, asígnale el paciente que va a acompañar.</p>
    </div>
  <?php else: ?>
    <div class="card" style="padding: 0; overflow: hidden;">
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Cuidador</th>
              <th>Paciente</th>
              <th>Parentesco</th>
              <th>Asignado el</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($asignaciones as $a): ?>
            <tr>
              <td>
                <div class="user-row">
                  <div class="user-avatar tipo-Cuidador">
                    <?= mb_strtoupper(mb_substr($a['cuidador_nombre'], 0, 1)) ?>
                  </div>
                  <div class="user-name"><?= Auth::escapar($a['cuidador_nombre']) ?></div>
                </div>
              </td>
              <td>
                <div class="user-row">
                  <div class="user-avatar tipo-Paciente">
                    <?= mb_strtoupper(mb_substr($a['paciente_nombre'], 0, 1)) ?>
                  </div>
                  <div class="user-name"><?= Auth::escapar($a['paciente_nombre']) ?></div>
                </div>
              </td>
              <td><?= Auth::escapar($a['parentesco'] ?: '—') ?></td>
              <td><?= date('d/m/Y', strtotime($a['fecha_asignacion'])) ?></td>
              <td class="text-right">
                <button class="btn btn-outline btn-sm js-revocar"
                        data-id="<?= (int)$a['id_relacion'] ?>"
                        data-cuidador="<?= Auth::escapar($a['cuidador_nombre']) ?>"
                        data-paciente="<?= Auth::escapar($a['paciente_nombre']) ?>"
                        style="color:var(--red-500);">
                  <i class="bi bi-x-lg"></i> Revocar
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal cambiar tipo -->
<div class="modal-backdrop" id="modal-cambiar-tipo">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="bi bi-person-gear"></i> Cambiar tipo de usuario</h3>
      <button class="modal-close" data-modal-close><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info">
        <i class="bi bi-info-circle-fill"></i>
        <div>Estás a punto de cambiar el tipo de <strong id="cambio-nombre"></strong>. El cambio queda registrado en el historial.</div>
      </div>
      <div class="form-group">
        <label><i class="bi bi-person"></i> Tipo actual</label>
        <input type="text" class="form-input" id="cambio-tipo-actual" disabled>
      </div>
      <div class="form-group">
        <label for="cambio-tipo-nuevo"><i class="bi bi-person-badge"></i> Nuevo tipo</label>
        <select class="form-select" id="cambio-tipo-nuevo" required>
          <?php foreach ($tiposUsuario as $t): ?>
            <?php if (!in_array($t['nombre'], ['Medico', 'Administrador'], true)) continue; ?>
          <option value="<?= (int)$t['id_tipo'] ?>" data-nombre="<?= Auth::escapar($t['nombre']) ?>">
            <?= Auth::escapar($t['nombre']) ?> — <?= Auth::escapar($t['descripcion']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-help"><i class="bi bi-info-circle"></i><span>Sólo se permite cambiar entre Médico y Administrador.</span></div>
      </div>
      <div class="form-group">
        <label for="cambio-motivo"><i class="bi bi-chat-text"></i> Motivo (opcional)</label>
        <textarea class="form-textarea" id="cambio-motivo" maxlength="255"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-modal-close>Cancelar</button>
      <button class="btn btn-danger" id="btn-confirmar-cambio">
        <i class="bi bi-check-circle-fill"></i> Aplicar cambio
      </button>
    </div>
  </div>
</div>

<!-- Modal: crear nuevo profesional -->
<div class="modal-backdrop" id="modal-nuevo-usuario">
  <div class="modal modal-large">
    <div class="modal-header">
      <h3><i class="bi bi-person-plus-fill"></i> Crear cuenta profesional</h3>
      <button class="modal-close" data-modal-close><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info">
        <i class="bi bi-shield-check"></i>
        <div>Sólo crea cuentas profesionales aquí (médicos, enfermeros y administradores). Los pacientes y cuidadores se autorregistran.</div>
      </div>

      <form id="form-nuevo-usuario" novalidate>
        <div class="form-group">
          <label><i class="bi bi-person-badge"></i> Tipo de cuenta</label>
          <select id="nu-tipo" class="form-select" required>
            <option value="3">Médico</option>
            <option value="5">Enfermero</option>
            <option value="4">Administrador</option>
          </select>
        </div>

        <div class="form-group">
          <label><i class="bi bi-person"></i> Nombre completo</label>
          <input type="text" id="nu-nombre" class="form-input" required minlength="3" maxlength="100">
        </div>

        <div class="form-group">
          <label><i class="bi bi-envelope"></i> Correo (Gmail)</label>
          <input type="email" id="nu-correo" class="form-input" placeholder="medico@gmail.com" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label><i class="bi bi-telephone"></i> Teléfono</label>
            <input type="tel" id="nu-telefono" class="form-input" placeholder="999 123 4567" required>
          </div>
          <div class="form-group">
            <label><i class="bi bi-key"></i> Contraseña inicial</label>
            <input type="password" id="nu-contrasena" class="form-input" placeholder="Mínimo 8 caracteres" required minlength="8">
          </div>
        </div>

        <!-- Campos sólo médico -->
        <div id="nu-medico-campos">
          <div class="form-row">
            <div class="form-group">
              <label><i class="bi bi-card-text"></i> Cédula profesional</label>
              <input type="text" id="nu-cedula" class="form-input" placeholder="12345678">
            </div>
            <div class="form-group">
              <label><i class="bi bi-clipboard2-pulse"></i> Especialidad</label>
              <input type="text" id="nu-especialidad" class="form-input" placeholder="Medicina Interna">
            </div>
          </div>
          <div class="alert alert-warning" style="font-size:13px;">
            <i class="bi bi-info-circle"></i>
            <div>Asegúrate de validar la cédula profesional con la fuente oficial antes de crear la cuenta.</div>
          </div>
        </div>

        <div class="alert alert-info" style="font-size:13px;">
          <i class="bi bi-shield-lock"></i>
          <div>Comparte la contraseña inicial al usuario por un canal seguro. Él podrá cambiarla después en su perfil.</div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-modal-close>Cancelar</button>
      <button class="btn btn-danger" id="btn-crear-usuario">
        <i class="bi bi-check-lg"></i> Crear cuenta
      </button>
    </div>
  </div>
</div>

<!-- Modal: nueva asignación cuidador-paciente -->
<div class="modal-backdrop" id="modal-asignar">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="bi bi-link-45deg"></i> Asignar cuidador a paciente</h3>
      <button class="modal-close" data-modal-close><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <?php if (empty($cuidadores) || empty($pacientes)): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i>
          <div>
            <?php if (empty($cuidadores)): ?>No hay cuidadores activos para asignar.<?php endif; ?>
            <?php if (empty($pacientes)): ?>No hay pacientes activos.<?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label><i class="bi bi-person"></i> Cuidador</label>
          <select id="as-cuidador" class="form-select" required>
            <option value="">— Selecciona un cuidador —</option>
            <?php foreach ($cuidadores as $c): ?>
            <option value="<?= (int)$c['id_usuario'] ?>"><?= Auth::escapar($c['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label><i class="bi bi-person-heart"></i> Paciente</label>
          <select id="as-paciente" class="form-select" required>
            <option value="">— Selecciona un paciente —</option>
            <?php foreach ($pacientes as $p): ?>
            <option value="<?= (int)$p['id_usuario'] ?>"><?= Auth::escapar($p['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label><i class="bi bi-people"></i> Parentesco (opcional)</label>
          <input type="text" id="as-parentesco" class="form-input" maxlength="50"
                 placeholder="Hijo, esposa, hermano...">
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-modal-close>Cancelar</button>
      <?php if (!empty($cuidadores) && !empty($pacientes)): ?>
      <button class="btn btn-danger" id="btn-confirmar-asignar">
        <i class="bi bi-link-45deg"></i> Asignar
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>

<script>
let cambioActual = { idUsuario: null, tipoActual: null };

document.querySelectorAll('.js-cambiar-tipo').forEach(btn => {
  btn.addEventListener('click', () => {
    cambioActual = { idUsuario: parseInt(btn.dataset.id, 10), tipoActual: btn.dataset.tipo };
    document.getElementById('cambio-nombre').textContent = btn.dataset.nombre;
    document.getElementById('cambio-tipo-actual').value = btn.dataset.tipo;
    document.getElementById('cambio-motivo').value = '';
    const sel = document.getElementById('cambio-tipo-nuevo');
    Array.from(sel.options).forEach(o => {
      if (o.dataset.nombre === btn.dataset.tipo) o.selected = true;
    });
    MedAlert.abrirModal('modal-cambiar-tipo');
  });
});

document.getElementById('btn-confirmar-cambio').addEventListener('click', async () => {
  const sel = document.getElementById('cambio-tipo-nuevo');
  const nuevoTipo = sel.options[sel.selectedIndex].dataset.nombre;
  const idTipoNuevo = parseInt(sel.value, 10);
  const motivo = document.getElementById('cambio-motivo').value.trim();
  if (nuevoTipo === cambioActual.tipoActual) {
    MedAlert.toast('Selecciona un tipo diferente.', 'warning');
    return;
  }
  const ok = await MedAlert.confirmar(`¿Cambiar el tipo a "${nuevoTipo}"?`);
  if (!ok) return;
  MedAlert.cerrarModal('modal-cambiar-tipo');
  const r = await MedAlert.api('api/usuarios.php', {
    method: 'POST',
    body: { accion: 'cambiar_tipo', id_usuario: cambioActual.idUsuario,
            id_tipo_nuevo: idTipoNuevo, motivo: motivo }
  });
  if (r.ok) { MedAlert.toast(r.datos.mensaje || 'Actualizado.', 'success'); setTimeout(() => location.reload(), 700); }
  else MedAlert.toast(r.datos.error || 'No se pudo actualizar.', 'error');
});

document.querySelectorAll('.js-suspender').forEach(btn => {
  btn.addEventListener('click', async () => {
    const ok = await MedAlert.confirmar(
      `¿Suspender la cuenta de "${btn.dataset.nombre}"? El usuario no podrá iniciar sesión, pero los datos se conservan.`
    );
    if (!ok) return;
    const r = await MedAlert.api('api/usuarios.php', {
      method: 'POST',
      body: { accion: 'suspender_usuario', id_usuario: parseInt(btn.dataset.id, 10) }
    });
    if (r.ok) { MedAlert.toast(r.datos.mensaje, 'success'); setTimeout(() => location.reload(), 700); }
    else MedAlert.toast(r.datos.error, 'error');
  });
});

document.querySelectorAll('.js-reactivar').forEach(btn => {
  btn.addEventListener('click', async () => {
    const ok = await MedAlert.confirmar(`¿Reactivar la cuenta de "${btn.dataset.nombre}"?`);
    if (!ok) return;
    const r = await MedAlert.api('api/usuarios.php', {
      method: 'POST',
      body: { accion: 'reactivar_usuario', id_usuario: parseInt(btn.dataset.id, 10) }
    });
    if (r.ok) { MedAlert.toast(r.datos.mensaje, 'success'); setTimeout(() => location.reload(), 700); }
    else MedAlert.toast(r.datos.error, 'error');
  });
});

document.getElementById('btn-nuevo-usuario').addEventListener('click', () => {
  document.getElementById('form-nuevo-usuario').reset();
  document.getElementById('nu-tipo').value = '3';
  toggleCamposMedico();
  MedAlert.abrirModal('modal-nuevo-usuario');
});
document.getElementById('nu-tipo').addEventListener('change', toggleCamposMedico);
function toggleCamposMedico() {
  const esMedico = document.getElementById('nu-tipo').value === '3';
  document.getElementById('nu-medico-campos').style.display = esMedico ? '' : 'none';
}

document.getElementById('btn-crear-usuario').addEventListener('click', async () => {
  const datos = {
    accion: 'crear_usuario',
    nombre_completo: document.getElementById('nu-nombre').value.trim(),
    correo: document.getElementById('nu-correo').value.trim().toLowerCase(),
    telefono: document.getElementById('nu-telefono').value.trim(),
    contrasena: document.getElementById('nu-contrasena').value,
    id_tipo: parseInt(document.getElementById('nu-tipo').value, 10),
    cedula_profesional: document.getElementById('nu-cedula').value.trim(),
    especialidad: document.getElementById('nu-especialidad').value.trim(),
  };
  if (datos.nombre_completo.length < 3) { MedAlert.toast('Nombre obligatorio.', 'error'); return; }
  if (!MedAlert.validarCorreoSimple(datos.correo)) { MedAlert.toast(MedAlert.errorCorreoNoGmail(), 'error'); return; }
  if (!MedAlert.validarTelefono(datos.telefono)) { MedAlert.toast('Teléfono de 10 dígitos.', 'error'); return; }
  if (datos.contrasena.length < 8) { MedAlert.toast('Contraseña mínimo 8 caracteres.', 'error'); return; }
  if (datos.id_tipo === 3 && (datos.cedula_profesional.length < 4 || datos.especialidad.length < 3)) {
    MedAlert.toast('Cédula y especialidad son obligatorias para médicos.', 'error');
    return;
  }
  MedAlert.cerrarModal('modal-nuevo-usuario');
  const r = await MedAlert.api('api/usuarios.php', { method: 'POST', body: datos });
  if (r.ok) { MedAlert.toast(r.datos.mensaje, 'success'); setTimeout(() => location.reload(), 800); }
  else MedAlert.toast(r.datos.error, 'error');
});

const btnAs = document.getElementById('btn-nueva-asignacion');
if (btnAs) btnAs.addEventListener('click', () => MedAlert.abrirModal('modal-asignar'));

const btnConfirmAs = document.getElementById('btn-confirmar-asignar');
if (btnConfirmAs) btnConfirmAs.addEventListener('click', async () => {
  const idCuidador = parseInt(document.getElementById('as-cuidador').value, 10);
  const idPaciente = parseInt(document.getElementById('as-paciente').value, 10);
  const parentesco = document.getElementById('as-parentesco').value.trim();
  if (!idCuidador || !idPaciente) { MedAlert.toast('Selecciona cuidador y paciente.', 'error'); return; }
  MedAlert.cerrarModal('modal-asignar');
  const r = await MedAlert.api('api/usuarios.php', {
    method: 'POST',
    body: { accion: 'asignar_paciente', id_cuidador: idCuidador, id_paciente: idPaciente(), parentesco }
  });
  if (r.ok) { MedAlert.toast(r.datos.mensaje, 'success'); setTimeout(() => location.reload(), 700); }
  else MedAlert.toast(r.datos.error, 'error');
});

document.querySelectorAll('.js-revocar').forEach(btn => {
  btn.addEventListener('click', async () => {
    const ok = await MedAlert.confirmar(
      `¿Revocar la asignación entre "${btn.dataset.cuidador}" y "${btn.dataset.paciente}"?`
    );
    if (!ok) return;
    const r = await MedAlert.api('api/usuarios.php', {
      method: 'POST',
      body: { accion: 'revocar_asignacion', id_relacion: parseInt(btn.dataset.id, 10) }
    });
    if (r.ok) { MedAlert.toast(r.datos.mensaje, 'success'); setTimeout(() => location.reload(), 700); }
    else MedAlert.toast(r.datos.error, 'error');
  });
});

document.getElementById('buscador').addEventListener('input', e => {
  const q = e.target.value.toLowerCase().trim();
  document.querySelectorAll('#tabla-usuarios tr').forEach(row => {
    const nombre = row.dataset.nombre || '';
    const correo = row.dataset.correo || '';
    row.style.display = (!q || nombre.includes(q) || correo.includes(q)) ? '' : 'none';
  });
});
</script>
