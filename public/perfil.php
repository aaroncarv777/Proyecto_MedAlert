<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$usuarioActual = Auth::requerirSesion();
$tituloPagina = 'Mi perfil';
$paginaActiva = 'perfil';

$tipo = $usuarioActual['tipo_nombre'];
$idUsuario = (int)$usuarioActual['id_usuario'];

$u = DB::uno("SELECT * FROM usuarios WHERE id_usuario = :id", ['id' => $idUsuario]);

// Datos resumen para roles sin sección propia (panel visual de "Mi perfil")
$resumenPerfil = [];
if ($tipo === 'Administrador') {
    $resumenPerfil = [
        ['icono' => 'bi-people', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM usuarios")['n'] ?? 0),
         'etiqueta' => 'Usuarios en el sistema'],
        ['icono' => 'bi-patch-check', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM usuarios u JOIN tipos_usuario t
               ON t.id_tipo=u.id_tipo WHERE t.nombre='Medico'
               AND u.cedula_validada=TRUE")['n'] ?? 0),
         'etiqueta' => 'Médicos validados'],
        ['icono' => 'bi-hourglass-split', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM usuarios u JOIN tipos_usuario t
               ON t.id_tipo=u.id_tipo WHERE t.nombre='Medico'
               AND (u.cedula_validada=FALSE OR u.cedula_validada IS NULL)
               AND u.cedula_profesional IS NOT NULL")['n'] ?? 0),
         'etiqueta' => 'Cédulas por revisar'],
        ['icono' => 'bi-link-45deg', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM medico_paciente WHERE activa=TRUE")['n'] ?? 0),
         'etiqueta' => 'Asignaciones activas'],
    ];
} elseif ($tipo === 'Enfermero') {
    $resumenPerfil = [
        ['icono' => 'bi-person-lines-fill', 'valor' => (int)(DB::uno(
            "SELECT COUNT(DISTINCT id_paciente) n FROM turno_enfermeria
              WHERE id_enfermero=:e AND activo=TRUE", ['e'=>$idUsuario])['n'] ?? 0),
         'etiqueta' => 'Pacientes en tu turno'],
        ['icono' => 'bi-clipboard-pulse', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM turno_enfermeria te
               JOIN registro_tomas rt ON rt.id_usuario=te.id_paciente
                 AND rt.fecha_programada=CURDATE() AND rt.estado='pendiente'
              WHERE te.id_enfermero=:e AND te.activo=TRUE",
            ['e'=>$idUsuario])['n'] ?? 0),
         'etiqueta' => 'Tomas pendientes hoy'],
        ['icono' => 'bi-check2-circle', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM registro_tomas
              WHERE id_enfermero_administra=:e
                AND fecha_programada=CURDATE() AND estado='cumplida'",
            ['e'=>$idUsuario])['n'] ?? 0),
         'etiqueta' => 'Tomas registradas hoy'],
    ];
} elseif ($tipo === 'Cuidador') {
    $resumenPerfil = [
        ['icono' => 'bi-person-heart', 'valor' => (int)(DB::uno(
            "SELECT COUNT(*) n FROM familiar_paciente
              WHERE id_familiar=:f AND estado='activa'",
            ['f'=>$idUsuario])['n'] ?? 0),
         'etiqueta' => 'Pacientes que sigues'],
    ];
}

require __DIR__ . '/_partials/_layout_inicio.php';
?>

<div class="page-header">
  <div>
    <h1>Mi perfil</h1>
    <p class="page-subtitle">
      Cuenta de <strong><?= Auth::escapar($u['nombre_completo']) ?></strong> ·
      <?= Auth::escapar($u['correo']) ?> · Rol: <strong><?= Auth::escapar($tipo) ?></strong>
    </p>
  </div>
</div>

<?php
$inicialesPerfil = '';
foreach (explode(' ', trim($u['nombre_completo'])) as $parte) {
    if ($parte !== '' && mb_strlen($inicialesPerfil) < 2) {
        $inicialesPerfil .= mb_strtoupper(mb_substr($parte, 0, 1));
    }
}
?>
<div class="card" style="margin-bottom:16px;">
  <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
    <div style="width:64px;height:64px;border-radius:50%;
      background:var(--navy-600);color:#fff;display:flex;
      align-items:center;justify-content:center;font-size:24px;
      font-weight:700;flex-shrink:0;">
      <?= Auth::escapar($inicialesPerfil) ?>
    </div>
    <div style="flex:1;min-width:200px;">
      <div style="font-size:20px;font-weight:700;color:var(--navy-900);">
        <?= Auth::escapar($u['nombre_completo']) ?>
      </div>
      <div style="font-size:13px;color:var(--gray-600);margin-top:2px;">
        <i class="bi bi-envelope"></i> <?= Auth::escapar($u['correo']) ?>
        <?php if (!empty($u['telefono'])): ?>
          &nbsp;·&nbsp; <i class="bi bi-telephone"></i>
          <?= Auth::escapar($u['telefono']) ?>
        <?php endif; ?>
      </div>
      <span class="badge-navy" style="margin-top:8px;display:inline-block;">
        <?= Auth::escapar($tipo) ?>
      </span>
    </div>
  </div>

  <?php if (!empty($resumenPerfil)): ?>
  <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:18px;
    padding-top:18px;border-top:1px solid var(--gray-100);">
    <?php foreach ($resumenPerfil as $rp): ?>
    <div style="flex:1;min-width:140px;background:var(--gray-50);
      border-radius:10px;padding:14px;text-align:center;">
      <i class="bi <?= $rp['icono'] ?>" style="font-size:22px;
        color:var(--navy-600);"></i>
      <div style="font-size:26px;font-weight:800;color:var(--navy-900);
        line-height:1.2;margin-top:4px;"><?= $rp['valor'] ?></div>
      <div style="font-size:12px;color:var(--gray-600);">
        <?= Auth::escapar($rp['etiqueta']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($tipo === 'Administrador'): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
    <a class="btn btn-outline btn-sm" href="usuarios.php">
      <i class="bi bi-people"></i> Gestión de usuarios</a>
    <a class="btn btn-outline btn-sm" href="cedulas.php">
      <i class="bi bi-patch-check"></i> Validar cédulas</a>
    <a class="btn btn-outline btn-sm" href="medico_paciente.php">
      <i class="bi bi-link-45deg"></i> Médicos y pacientes</a>
  </div>
  <?php elseif ($tipo === 'Enfermero'): ?>
  <div style="margin-top:16px;">
    <a class="btn btn-primary btn-sm" href="modo_hospitalario.php">
      <i class="bi bi-hospital"></i> Ir al modo hospitalario</a>
  </div>
  <?php elseif ($tipo === 'Cuidador'): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
    <a class="btn btn-primary btn-sm" href="vista_familiar.php">
      <i class="bi bi-people"></i> Vista familiar</a>
    <a class="btn btn-outline btn-sm" href="reportes.php">
      <i class="bi bi-graph-up-arrow"></i> Reportes</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($tipo === 'Medico'): ?>
<div class="card">
  <h2 style="margin:0 0 4px;font-size:18px;"><i class="bi bi-patch-check"></i> Credenciales profesionales</h2>
  <p class="text-muted" style="margin:0 0 14px;font-size:13px;">
    Sube tu cédula profesional para que el administrador la apruebe. Sin esa aprobación no podrás prescribir medicamentos.
  </p>

  <?php if ($u['cedula_validada']): ?>
    <div class="banner banner-verde">
      <i class="bi bi-check-circle-fill"></i>
      <div class="banner-body">
        <h3>Credenciales aprobadas</h3>
        <p>Tus credenciales fueron aprobadas el <?= date('d/m/Y H:i', strtotime($u['fecha_validacion_cedula'])) ?>. Ya puedes prescribir medicamentos a tus pacientes asignados.</p>
      </div>
    </div>
  <?php elseif (!empty($u['motivo_rechazo_cedula'])): ?>
    <div class="banner banner-rojo">
      <i class="bi bi-x-circle-fill"></i>
      <div class="banner-body">
        <h3>El administrador rechazó tu cédula</h3>
        <p><strong>Motivo:</strong> <?= Auth::escapar($u['motivo_rechazo_cedula']) ?>. Corrige la información y vuelve a enviarla.</p>
      </div>
    </div>
  <?php elseif (!empty($u['cedula_profesional'])): ?>
    <div class="banner banner-azul">
      <i class="bi bi-hourglass-split"></i>
      <div class="banner-body">
        <h3>Pendiente de revisión</h3>
        <p>Tu cédula está siendo revisada por el administrador. Te avisaremos por correo y en la campanita cuando haya una decisión.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="banner banner-amarillo">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div class="banner-body">
        <h3>Aún no has cargado tu cédula</h3>
        <p>Mientras no esté validada no podrás prescribir medicamentos a tus pacientes.</p>
      </div>
    </div>
  <?php endif; ?>

  <form id="form-cedula" style="margin-top:6px;">
    <div class="grid-2">
      <div class="form-group">
        <label>Cédula profesional <span style="color:var(--red-600);">*</span></label>
        <input type="text" id="cedula" name="cedula" maxlength="8" pattern="\d{7,8}"
               value="<?= Auth::escapar($u['cedula_profesional'] ?? '') ?>" required
               placeholder="7 u 8 dígitos">
      </div>
      <div class="form-group">
        <label>Especialidad <span style="color:var(--red-600);">*</span></label>
        <input type="text" id="especialidad" name="especialidad" maxlength="80"
               value="<?= Auth::escapar($u['especialidad'] ?? '') ?>"
               placeholder="Ej.: Medicina Interna" required>
      </div>
    </div>

    <div class="form-group">
      <label>Imagen de la cédula</label>
      <input type="file" id="imagen-cedula" accept="image/png,image/jpeg">
      <small class="text-muted">
        Formato JPG o PNG, máximo 5 MB.
        <?php if (!empty($u['imagen_cedula'])): ?>
          Ya tienes una imagen cargada. <a href="<?= Auth::escapar($u['imagen_cedula']) ?>" target="_blank">Verla</a>.
        <?php endif; ?>
      </small>
    </div>

    <div id="cedula-error" class="alert alert-error" hidden></div>
    <div id="cedula-ok" class="alert alert-success" hidden></div>

    <button type="submit" class="btn btn-primary">
      <i class="bi bi-cloud-upload"></i> Guardar y enviar a revisión
    </button>
  </form>
</div>
<?php endif; ?>

<?php if ($tipo === 'Paciente'): ?>
<div class="card" style="margin-top:16px;">
  <h2 style="margin:0 0 4px;font-size:18px;"><i class="bi bi-person-vcard"></i> Datos clínicos y rutina</h2>
  <p class="text-muted" style="margin:0 0 18px;font-size:13px;">
    El peso y la fecha de nacimiento permiten al médico calcular dosis correctas. Las horas de comida se usan para programar medicamentos relativos a las comidas.
  </p>

  <form id="form-paciente">
    <h4 class="card-section-title">Datos clínicos</h4>
    <div class="grid-2">
      <div class="form-group">
        <label>Peso (kg)</label>
        <input type="number" id="peso_kg" step="0.1" min="1" max="300"
               value="<?= Auth::escapar((string)($u['peso_kg'] ?? '')) ?>"
               placeholder="Ej.: 72.5">
        <small class="text-muted">Necesario si tu médico usa cálculo de dosis por peso.</small>
      </div>
      <div class="form-group">
        <label>Fecha de nacimiento</label>
        <input type="date" id="fecha_nacimiento"
               value="<?= Auth::escapar((string)($u['fecha_nacimiento'] ?? '')) ?>">
      </div>
    </div>

    <h4 class="card-section-title" style="margin-top:18px;">Horas habituales de tus comidas</h4>
    <p class="text-muted" style="font-size:12.5px;margin:-8px 0 12px;">
      Las usamos para programar medicamentos del tipo "30 minutos antes del desayuno" o "después de la cena".
    </p>
    <div class="grid-3">
      <div class="form-group">
        <label>Desayuno</label>
        <input type="time" id="hora_desayuno"
               value="<?= substr((string)($u['hora_desayuno'] ?? '08:00'), 0, 5) ?>">
      </div>
      <div class="form-group">
        <label>Comida</label>
        <input type="time" id="hora_comida"
               value="<?= substr((string)($u['hora_comida'] ?? '14:00'), 0, 5) ?>">
      </div>
      <div class="form-group">
        <label>Cena</label>
        <input type="time" id="hora_cena"
               value="<?= substr((string)($u['hora_cena'] ?? '20:00'), 0, 5) ?>">
      </div>
    </div>

    <div id="paciente-ok" class="alert alert-success" hidden></div>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> Guardar mis datos
    </button>
  </form>
</div>
<?php endif; ?>

<?php if ($tipo === 'Paciente'): ?>
<div class="card" style="margin-top:16px;">
  <h2 style="margin:0 0 4px;font-size:18px;"><i class="bi bi-people"></i>
    Acceso de familiares</h2>
  <p class="text-muted" style="margin:0 0 16px;font-size:13px;">
    Invita a un familiar o cuidador para que pueda ver un resumen sencillo
    de tu tratamiento. Podrás quitarle el acceso cuando quieras; el cambio
    es inmediato.</p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;
    margin-bottom:16px;">
    <div class="form-group" style="margin:0;flex:1;min-width:200px;">
      <label>Correo del familiar</label>
      <input type="email" id="fam-correo" class="form-select"
        placeholder="correo@ejemplo.com">
    </div>
    <div class="form-group" style="margin:0;">
      <label>Parentesco (opcional)</label>
      <input type="text" id="fam-parentesco" class="form-select"
        maxlength="40" placeholder="Hijo, esposa…">
    </div>
    <button class="btn btn-primary" id="fam-invitar">
      <i class="bi bi-person-plus"></i> Invitar</button>
  </div>

  <div id="fam-lista">
    <p class="text-muted" style="font-size:13px;">Cargando…</p>
  </div>
</div>

<script>
async function cargarFamiliares() {
  const cont = document.getElementById('fam-lista');
  const r = await MedAlert.api('api/familiar.php?accion=mis_familiares');
  if (!r.ok) { cont.innerHTML = '<p class="text-muted">No se pudo cargar.</p>';
    return; }
  const f = r.datos.familiares || [];
  if (!f.length) {
    cont.innerHTML = '<p class="text-muted" style="font-size:13px;">'
      + 'Todavía no has dado acceso a ningún familiar.</p>';
    return;
  }
  cont.innerHTML = '<table class="data-table"><thead><tr>'
    + '<th>Familiar</th><th>Correo</th><th>Parentesco</th><th></th>'
    + '</tr></thead><tbody>' + f.map(x => `<tr>
      <td><strong>${esc(x.nombre_completo)}</strong></td>
      <td>${esc(x.correo)}</td>
      <td>${esc(x.parentesco||'—')}</td>
      <td><button class="btn btn-danger btn-sm fam-revocar"
        data-id="${x.id_relacion}">Quitar acceso</button></td>
    </tr>`).join('') + '</tbody></table>';
  cont.querySelectorAll('.fam-revocar').forEach(b =>
    b.addEventListener('click', async () => {
      if (!await MedAlert.confirmar('¿Quitar el acceso de este familiar? '
        + 'Dejará de ver tu información de inmediato.')) return;
      const r = await MedAlert.api('api/familiar.php', {
        method:'POST',
        body:{ accion:'revocar', id_relacion:+b.dataset.id }
      });
      if (r.ok) { MedAlert.toast('Acceso revocado.', 'success');
        cargarFamiliares(); }
      else MedAlert.toast(r.datos.error || 'No se pudo revocar.', 'error');
    }));
}
function esc(s){ return String(s ?? '').replace(/[<>&]/g,
  c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])); }

document.addEventListener('DOMContentLoaded', () => {
  const bi = document.getElementById('fam-invitar');
  if (!bi) return;
  bi.addEventListener('click', async () => {
    const correo = document.getElementById('fam-correo').value.trim();
    const par = document.getElementById('fam-parentesco').value.trim();
    if (!correo) { MedAlert.toast('Escribe el correo del familiar.','error');
      return; }
    const r = await MedAlert.api('api/familiar.php', {
      method:'POST',
      body:{ accion:'invitar', correo, parentesco: par }
    });
    if (r.ok) {
      MedAlert.toast('Listo. ' + (r.datos.familiar || 'El familiar')
        + ' ya tiene acceso.', 'success');
      document.getElementById('fam-correo').value = '';
      document.getElementById('fam-parentesco').value = '';
      cargarFamiliares();
    } else {
      MedAlert.toast(r.datos.error || 'No se pudo invitar.', 'error');
    }
  });
  cargarFamiliares();
});
</script>
<?php endif; ?>

<?php if ($tipo === 'Paciente' || $tipo === 'Cuidador'): ?>
<div class="card" style="margin-top:16px;">
  <h2 style="margin:0 0 4px;font-size:18px;"><i class="bi bi-bell"></i> Notificaciones</h2>
  <p class="text-muted" style="margin:0 0 18px;font-size:13px;">
    Configura por qué canales quieres recibir los recordatorios. Estos ajustes son globales; cada medicamento también te deja activar o desactivar cada canal individualmente.
  </p>
  <form id="form-notif">
    <h4 class="card-section-title">Canales de notificación</h4>
    <div class="grid-3">
      <label class="toggle-card">
        <input type="checkbox" id="canal_app" <?= $u['canal_app_activo']    ? 'checked' : '' ?>>
        <i class="bi bi-phone"></i> <span>Aplicación</span>
      </label>
      <label class="toggle-card">
        <input type="checkbox" id="canal_correo" <?= $u['canal_correo_activo'] ? 'checked' : '' ?>>
        <i class="bi bi-envelope"></i> <span>Correo electrónico</span>
      </label>
      <label class="toggle-card">
        <input type="checkbox" id="canal_sms" <?= $u['canal_sms_activo']    ? 'checked' : '' ?>>
        <i class="bi bi-chat-dots"></i> <span>Mensaje SMS</span>
      </label>
    </div>
    <small class="text-muted">Para recibir SMS necesitas tener registrado tu teléfono en formato +52...</small>

    <h4 class="card-section-title" style="margin-top:18px;">Avisos escalonados</h4>
    <p class="text-muted" style="font-size:12.5px;margin:-8px 0 12px;">
      Por cada toma programada puedes recibir hasta tres avisos. Activa o desactiva cada uno por separado.
    </p>
    <div class="grid-3">
      <label class="toggle-card">
        <input type="checkbox" id="av_temprano" <?= $u['aviso_temprano_activo']     ? 'checked' : '' ?>>
        <i class="bi bi-bell"></i> <span>15 min antes</span>
      </label>
      <label class="toggle-card">
        <input type="checkbox" id="av_recordatorio" <?= $u['aviso_recordatorio_activo'] ? 'checked' : '' ?>>
        <i class="bi bi-alarm"></i> <span>5 min antes</span>
      </label>
      <label class="toggle-card">
        <input type="checkbox" id="av_tardio" <?= $u['aviso_tardio_activo']         ? 'checked' : '' ?>>
        <i class="bi bi-exclamation-circle"></i> <span>10 min después</span>
      </label>
    </div>

    <div id="notif-ok" class="alert alert-success" hidden></div>
    <button type="submit" class="btn btn-primary" style="margin-top:14px;">
      <i class="bi bi-save"></i> Guardar preferencias
    </button>
  </form>
</div>
<?php endif; ?>

<script>
// Cédula del médico
(function() {
  const f = document.getElementById('form-cedula');
  if (!f) return;
  const err = document.getElementById('cedula-error');
  const ok  = document.getElementById('cedula-ok');
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.hidden = true; ok.hidden = true;
    const cedula = document.getElementById('cedula').value.trim();
    const esp    = document.getElementById('especialidad').value.trim();
    const file   = document.getElementById('imagen-cedula').files[0];

    if (!/^\d{7,8}$/.test(cedula)) {
      err.textContent = 'La cédula profesional debe tener 7 u 8 dígitos numéricos.';
      err.hidden = false; return;
    }
    if (esp.length < 3 || esp.length > 80) {
      err.textContent = 'La especialidad debe tener entre 3 y 80 caracteres.';
      err.hidden = false; return;
    }

    let imagenB64 = null;
    if (file) {
      if (file.size > 5 * 1024 * 1024) {
        err.textContent = 'La imagen supera los 5 MB.'; err.hidden = false; return;
      }
      imagenB64 = await new Promise(res => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result);
        fr.readAsDataURL(file);
      });
    }

    try {
      const r = await fetch('api/cedulas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          accion: 'actualizar_cedula',
          cedula, especialidad: esp, imagen_base64: imagenB64
        })
      });
      const d = await r.json();
      if (d.ok) {
        ok.textContent = d.mensaje || 'Cédula enviada para revisión.';
        ok.hidden = false;
        setTimeout(() => location.reload(), 1400);
      } else {
        err.textContent = d.error; err.hidden = false;
      }
    } catch (ex) {
      err.textContent = 'Error de conexión.'; err.hidden = false;
    }
  });
})();

// Datos clínicos del paciente
(function() {
  const f = document.getElementById('form-paciente');
  if (!f) return;
  const ok = document.getElementById('paciente-ok');
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    ok.hidden = true;
    const datos = {
      accion: 'guardar_datos_clinicos',
      peso_kg: document.getElementById('peso_kg').value || null,
      fecha_nacimiento: document.getElementById('fecha_nacimiento').value || null,
      hora_desayuno: document.getElementById('hora_desayuno').value,
      hora_comida:   document.getElementById('hora_comida').value,
      hora_cena:     document.getElementById('hora_cena').value,
    };
    try {
      const r = await fetch('api/perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos)
      });
      const d = await r.json();
      if (d.ok) {
        ok.textContent = 'Tus datos se guardaron correctamente.';
        ok.hidden = false;
        setTimeout(() => { ok.hidden = true; }, 3000);
      } else {
        alert(d.error || 'No se pudo guardar.');
      }
    } catch (ex) { alert('Error de conexión.'); }
  });
})();

// Canales y avisos
(function() {
  const f = document.getElementById('form-notif');
  if (!f) return;
  const ok = document.getElementById('notif-ok');
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    ok.hidden = true;
    const datos = {
      accion: 'guardar_preferencias_notificacion',
      canal_app:    document.getElementById('canal_app').checked,
      canal_correo: document.getElementById('canal_correo').checked,
      canal_sms:    document.getElementById('canal_sms').checked,
      aviso_temprano:     document.getElementById('av_temprano').checked,
      aviso_recordatorio: document.getElementById('av_recordatorio').checked,
      aviso_tardio:       document.getElementById('av_tardio').checked,
    };
    try {
      const r = await fetch('api/perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos)
      });
      const d = await r.json();
      if (d.ok) {
        ok.textContent = 'Preferencias actualizadas.';
        ok.hidden = false;
        setTimeout(() => { ok.hidden = true; }, 3000);
      } else {
        alert(d.error || 'No se pudo guardar.');
      }
    } catch (ex) { alert('Error de conexión.'); }
  });
})();
</script>

<?php require __DIR__ . '/_partials/_layout_fin.php'; ?>
