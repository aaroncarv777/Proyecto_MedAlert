<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (Auth::usuarioActual()) {
    header('Location: dashboard.php');
    exit;
}

$errores = [];
$datos = ['nombre' => '', 'correo' => '', 'telefono' => '', 'tipo_cuenta' => 'Paciente'];
$preguntasSeleccionadas = ['', '', ''];
$respuestasIngresadas = ['', '', ''];

$catalogo = DB::todos("SELECT id_pregunta, pregunta FROM catalogo_preguntas_seguridad WHERE activa = TRUE ORDER BY orden");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datos['nombre']      = Helpers::post('nombre_completo');
    $datos['correo']      = mb_strtolower(Helpers::post('correo'));
    $datos['telefono']    = Helpers::post('telefono');
    $datos['tipo_cuenta'] = Helpers::post('tipo_cuenta');
    $contrasena           = Helpers::post('contrasena');
    $aceptaTerminos       = isset($_POST['acepta_terminos']);

    if (!in_array($datos['tipo_cuenta'], ['Paciente', 'Cuidador'], true)) {
        $errores['tipo_cuenta'] = 'Selecciona Paciente o Cuidador.';
    }

    for ($i = 0; $i < 3; $i++) {
        $preguntasSeleccionadas[$i] = Helpers::post('pregunta'.($i+1));
        $respuestasIngresadas[$i]   = Helpers::post('respuesta'.($i+1));
    }

    if (mb_strlen($datos['nombre']) < 3 || mb_strlen($datos['nombre']) > 100) {
        $errores['nombre_completo'] = 'Ingresa tu nombre completo (3-100 caracteres).';
    }
    if (!Helpers::validarCorreo($datos['correo'])) {
        $errores['correo'] = filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)
            ? Helpers::errorCorreoNoGmail()
            : 'Ingresa un correo válido (ejemplo: nombre@gmail.com).';
    } else {
        $existe = DB::uno("SELECT 1 FROM usuarios WHERE correo = :c", ['c' => $datos['correo']]);
        if ($existe) $errores['correo'] = 'Ya existe una cuenta registrada con este correo.';
    }
    if (!Helpers::validarTelefono($datos['telefono'])) {
        $errores['telefono'] = 'Ingresa un teléfono de 10 dígitos.';
    }
    $valContra = Helpers::validarContrasena($contrasena);
    if (!$valContra['valida']) $errores['contrasena'] = $valContra['error'];
    if (!$aceptaTerminos) $errores['terminos'] = 'Debes aceptar los términos y la política de privacidad.';

    $idsPreguntas = [];
    for ($i = 0; $i < 3; $i++) {
        $idP = (int)$preguntasSeleccionadas[$i];
        if ($idP <= 0) {
            $errores['pregunta'.($i+1)] = 'Selecciona la pregunta '.($i+1).'.';
            continue;
        }
        if (in_array($idP, $idsPreguntas, true)) {
            $errores['pregunta'.($i+1)] = 'No puedes repetir la misma pregunta.';
            continue;
        }

        $existePreg = DB::uno("SELECT 1 FROM catalogo_preguntas_seguridad WHERE id_pregunta=:id AND activa=TRUE",
                              ['id' => $idP]);
        if (!$existePreg) {
            $errores['pregunta'.($i+1)] = 'La pregunta seleccionada no es válida.';
            continue;
        }
        $idsPreguntas[] = $idP;
        if (mb_strlen(trim($respuestasIngresadas[$i])) < 2) {
            $errores['respuesta'.($i+1)] = 'La respuesta '.($i+1).' es obligatoria.';
        }
    }

    if (empty($errores)) {
        $db = DB::obtener();
        $db->beginTransaction();
        try {
            $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiracion = (new DateTime())
                ->modify('+' . EXPIRACION_VERIFICACION_HORAS . ' hours')
                ->format('Y-m-d H:i:s');

            $idTipo = $datos['tipo_cuenta'] === 'Cuidador' ? 2 : 1;

            DB::consultar(
                "INSERT INTO usuarios (nombre_completo, correo, telefono, contrasena_hash,
                                       id_tipo, estado_cuenta, codigo_verificacion,
                                       fecha_codigo_verificacion, fecha_expiracion_verificacion)
                 VALUES (:nom, :cor, :tel, :hash, :tip, 'pendiente_verificacion', :cod, NOW(), :exp)",
                [
                    'nom'  => $datos['nombre'],
                    'cor'  => $datos['correo'],
                    'tel'  => preg_replace('/\s+/', '', $datos['telefono']),
                    'hash' => Auth::hashearContrasena($contrasena),
                    'tip'  => $idTipo,
                    'cod'  => $codigo,
                    'exp'  => $expiracion,
                ]
            );
            $idUsuario = DB::ultimoId();

            for ($i = 0; $i < 3; $i++) {
                DB::consultar(
                    "INSERT INTO preguntas_seguridad (id_usuario, id_pregunta, respuesta_hash, orden)
                     VALUES (:u, :p, :r, :o)",
                    [
                        'u' => $idUsuario,
                        'p' => (int)$preguntasSeleccionadas[$i],
                        'r' => Auth::hashearContrasena(mb_strtolower(trim($respuestasIngresadas[$i]))),
                        'o' => $i + 1,
                    ]
                );
            }
            $db->commit();

            $enviado = Mailer::enviarVerificacion($datos['correo'], $datos['nombre'], $codigo);

            $_SESSION['verificar_correo'] = $datos['correo'];
            $_SESSION['verificar_envio_ok'] = $enviado;

            header('Location: verificar.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errores['_general'] = 'Error al crear la cuenta: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crear cuenta · MedAlert</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="auth-layout">
  <aside class="auth-hero">
    <div class="auth-brand">
      <div class="auth-logo"><img src="assets/img/logo.png" alt="MedAlert"></div>
      <span>MedAlert</span>
    </div>
    <div>
      <h1>Crea tu cuenta<br><span class="accent">en menos de un minuto.</span></h1>
      <p class="auth-subtitle">Configura tres preguntas de seguridad y verifica tu correo. Listo, tendrás tus medicamentos organizados y conectados con tu médico.</p>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-lock-fill"></i><span>Tu información médica está cifrada</span></div>
        <div class="auth-feature"><i class="bi bi-key-fill"></i><span>Recuperas tu cuenta sin depender del correo</span></div>
        <div class="auth-feature"><i class="bi bi-check2-circle"></i><span>Sin costo, para siempre</span></div>
      </div>
    </div>
    <div class="auth-footer">© 2026 MedAlert</div>
  </aside>

  <main class="auth-form-side">
    <div class="auth-card">
      <a href="login.php" class="auth-back"><i class="bi bi-arrow-left"></i> Volver al inicio de sesión</a>

      <h2>Crea tu cuenta</h2>
      <p class="auth-card-subtitle">Te tomará menos de un minuto. Empezamos.</p>

      <?php if (!empty($errores['_general'])): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= Auth::escapar($errores['_general']) ?></div></div>
      <?php endif; ?>

      <form method="POST" action="registro.php" novalidate id="form-registro">
        <div class="form-group">
          <label for="nombre_completo"><i class="bi bi-person-fill"></i> Nombre completo</label>
          <input type="text" id="nombre_completo" name="nombre_completo"
                 class="form-input <?= isset($errores['nombre_completo']) ? 'is-invalid' : '' ?>"
                 placeholder="Juan Pérez Martínez"
                 value="<?= Auth::escapar($datos['nombre']) ?>" required minlength="3" maxlength="100">
          <div class="form-error <?= isset($errores['nombre_completo']) ? 'is-shown' : '' ?>">
            <i class="bi bi-exclamation-circle-fill"></i><span><?= Auth::escapar($errores['nombre_completo'] ?? '') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="correo"><i class="bi bi-envelope-fill"></i> Correo electrónico</label>
          <input type="email" id="correo" name="correo"
                 class="form-input <?= isset($errores['correo']) ? 'is-invalid' : '' ?>"
                 placeholder="tucorreo@gmail.com"
                 value="<?= Auth::escapar($datos['correo']) ?>" required>
          <div class="form-help">
            <i class="bi bi-google" style="color:#ea4335;"></i>
            <span>Por ahora sólo aceptamos cuentas de Gmail.</span>
          </div>
          <div class="form-error <?= isset($errores['correo']) ? 'is-shown' : '' ?>">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= Auth::escapar($errores['correo'] ?? 'Ingresa un correo válido (ejemplo: nombre@gmail.com)') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="telefono"><i class="bi bi-telephone-fill"></i> Teléfono</label>
          <input type="tel" id="telefono" name="telefono"
                 class="form-input <?= isset($errores['telefono']) ? 'is-invalid' : '' ?>"
                 placeholder="999 123 4567"
                 value="<?= Auth::escapar($datos['telefono']) ?>" required>
          <div class="form-help"><i class="bi bi-info-circle"></i><span>Lo usamos para enviar recordatorios por SMS</span></div>
          <div class="form-error <?= isset($errores['telefono']) ? 'is-shown' : '' ?>">
            <i class="bi bi-exclamation-circle-fill"></i><span><?= Auth::escapar($errores['telefono'] ?? '') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="contrasena"><i class="bi bi-lock-fill"></i> Contraseña</label>
          <div class="input-wrap">
            <input type="password" id="contrasena" name="contrasena"
                   class="form-input <?= isset($errores['contrasena']) ? 'is-invalid' : '' ?>"
                   placeholder="Mínimo 8 caracteres" required minlength="8" style="padding-left:14px;">
            <button type="button" class="toggle-password" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
          <div class="password-strength" id="password-strength">
            <div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div>
          </div>
          <div class="form-help"><i class="bi bi-info-circle"></i><span>Usa mayúsculas, números y al menos 8 caracteres</span></div>
          <div class="form-error <?= isset($errores['contrasena']) ? 'is-shown' : '' ?>">
            <i class="bi bi-exclamation-circle-fill"></i><span><?= Auth::escapar($errores['contrasena'] ?? '') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="tipo_cuenta"><i class="bi bi-person-badge-fill"></i> Tipo de cuenta</label>
          <select class="form-select <?= isset($errores['tipo_cuenta']) ? 'is-invalid' : '' ?>"
                  id="tipo_cuenta" name="tipo_cuenta" required>
            <option value="Paciente" <?= ($datos['tipo_cuenta'] ?? 'Paciente') === 'Paciente' ? 'selected' : '' ?>>
              Paciente — Yo soy quien toma los medicamentos
            </option>
            <option value="Cuidador" <?= ($datos['tipo_cuenta'] ?? '') === 'Cuidador' ? 'selected' : '' ?>>
              Cuidador — Acompaño a un paciente con su tratamiento
            </option>
          </select>
          <div class="form-help">
            <i class="bi bi-shield-lock"></i>
            <span>Las cuentas de médico y administrador las crea el administrador del sistema directamente.</span>
          </div>
          <?php if (isset($errores['tipo_cuenta'])): ?>
          <div class="form-error is-shown">
            <i class="bi bi-exclamation-circle-fill"></i><span><?= Auth::escapar($errores['tipo_cuenta']) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Preguntas de seguridad: SELECT del catálogo, no editables -->
        <div style="margin: 28px 0 18px; padding-top: 24px; border-top: 1px solid var(--gray-200);">
          <h3 style="font-size:14px; color:var(--navy-900); margin-bottom: 6px;
                     text-transform:uppercase; letter-spacing:1px;">
            <i class="bi bi-shield-lock"></i> Preguntas de seguridad
          </h3>
          <p class="text-muted" style="font-size:13px; margin-bottom:18px;">
            Selecciona 3 preguntas distintas y respóndelas. Las usaremos si olvidas tu contraseña — recuerda exactamente cómo escribiste tus respuestas.
          </p>
        </div>

        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="form-group">
          <label><i class="bi bi-question-circle-fill"></i> Pregunta <?= $i ?></label>
          <select name="pregunta<?= $i ?>"
                  class="form-select js-pregunta-select mb-1 <?= isset($errores['pregunta'.$i]) ? 'is-invalid' : '' ?>"
                  required>
            <option value="">— Selecciona una pregunta —</option>
            <?php foreach ($catalogo as $p): ?>
              <option value="<?= (int)$p['id_pregunta'] ?>"
                <?= (int)$preguntasSeleccionadas[$i-1] === (int)$p['id_pregunta'] ? 'selected' : '' ?>>
                <?= Auth::escapar($p['pregunta']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="respuesta<?= $i ?>"
                 class="form-input <?= isset($errores['respuesta'.$i]) ? 'is-invalid' : '' ?>"
                 placeholder="Tu respuesta"
                 value="<?= Auth::escapar($respuestasIngresadas[$i-1]) ?>"
                 required minlength="2" maxlength="100" autocomplete="off">
          <?php if (isset($errores['pregunta'.$i]) || isset($errores['respuesta'.$i])): ?>
          <div class="form-error is-shown">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= Auth::escapar($errores['pregunta'.$i] ?? $errores['respuesta'.$i]) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>

        <label class="form-check" style="margin-top:8px;">
          <input type="checkbox" name="acepta_terminos"
                 <?= isset($_POST['acepta_terminos']) ? 'checked' : '' ?> required>
          <span>Acepto los <a href="#">términos de uso</a> y la <a href="#">política de privacidad</a>.</span>
        </label>
        <?php if (isset($errores['terminos'])): ?>
        <div class="form-error is-shown" style="margin-top:-10px; margin-bottom:12px;">
          <i class="bi bi-exclamation-circle-fill"></i><span><?= Auth::escapar($errores['terminos']) ?></span>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-danger btn-block btn-lg">
          <i class="bi bi-check-circle-fill"></i> Crear cuenta
        </button>

        <p class="text-center mt-2" style="font-size:13.5px; color:var(--gray-500);">
          ¿Ya tienes una cuenta?
          <a href="login.php" style="color:var(--navy-700); font-weight:600;">Inicia sesión</a>
        </p>
      </form>
    </div>
  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
const inpContra = document.getElementById('contrasena');
const indFuerza = document.getElementById('password-strength');
MedAlert.pintarFuerzaContrasena(inpContra, indFuerza);

const selects = document.querySelectorAll('.js-pregunta-select');
function actualizarOpcionesPreguntas() {
  const seleccionadas = Array.from(selects).map(s => s.value).filter(v => v !== '');
  selects.forEach(sel => {
    Array.from(sel.options).forEach(opt => {
      if (opt.value === '') return;
      const elegidaEnOtro = seleccionadas().includes(opt.value) && opt.value !== sel.value;
      opt.disabled = elegidaEnOtro;
      opt.style.color = elegidaEnOtro ? '#94a3b8' : '';
    });
  });
}
selects.forEach(s => s.addEventListener('change', actualizarOpcionesPreguntas));
actualizarOpcionesPreguntas();

document.getElementById('form-registro').addEventListener('submit', function (e) {
  let valido = true;
  const validar = (id, fn, msg) => {
    const el = document.getElementById(id);
    if (!el) return;
    MedAlert.limpiarErrorCampo(el);
    if (!fn(el.value)) { MedAlert.mostrarErrorCampo(el, msg); valido = false; }
  };
  validar('nombre_completo', v => v.trim().length >= 3, 'Ingresa tu nombre completo.');
  validar('correo', MedAlert.validarCorreoSimple, MedAlert.errorCorreoNoGmail());
  validar('telefono', MedAlert.validarTelefono, 'Ingresa un teléfono de 10 dígitos.');
  validar('contrasena', v => v.length >= 8 && /[A-Z]/.test(v) && /[0-9]/.test(v),
          'Mínimo 8 caracteres, una mayúscula y un número.');

  const valores = Array.from(selects).map(s => s.value);
  if (valores.some(v => v === '')) {
    MedAlert.toast('Selecciona las 3 preguntas de seguridad.', 'error');
    valido = false;
  } else if (new Set(valores).size !== 3) {
    MedAlert.toast('Las 3 preguntas deben ser distintas.', 'error');
    valido = false;
  }

  if (!document.querySelector('[name="acepta_terminos"]').checked) {
    valido = false;
    MedAlert.toast('Debes aceptar los términos para continuar.', 'error');
  }
  if (!valido) e.preventDefault();
});
</script>
</body>
</html>
