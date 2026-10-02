<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (Auth::usuarioActual()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$mensaje = null;
$correoIngresado = '';

if (isset($_GET['expirada']))   $mensaje = 'Tu sesión expiró. Inicia sesión nuevamente.';
if (isset($_GET['verificada'])) $mensaje = '¡Cuenta verificada! Ya puedes iniciar sesión.';
if (isset($_GET['recuperada']))$mensaje = 'Tu contraseña fue restablecida. Inicia sesión con tu nueva contraseña.';
if (!empty($_SESSION['mensaje_sesion_cerrada'])) {
    $mensaje = 'Tu sesión fue cerrada porque iniciaste sesión en otro dispositivo.';
    unset($_SESSION['mensaje_sesion_cerrada']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correoIngresado = Helpers::post('correo');
    $contrasena      = Helpers::post('contrasena');

    if ($correoIngresado === '' || $contrasena === '') {
        $error = 'Ingresa correo y contraseña.';
    } elseif (!Helpers::validarCorreo($correoIngresado)) {
        $error = filter_var($correoIngresado, FILTER_VALIDATE_EMAIL)
            ? Helpers::errorCorreoNoGmail()
            : 'El correo no tiene un formato válido.';
    } else {
        $usuario = DB::uno(
            "SELECT u.*, t.nombre AS tipo_nombre
               FROM usuarios u
               INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
              WHERE u.correo = :correo",
            ['correo' => $correoIngresado]
        );

        if (!$usuario) {
            $error = 'Correo o contraseña incorrectos.';
        } else {
            if ($usuario['fecha_bloqueo']) {
                $segRest = strtotime($usuario['fecha_bloqueo']) + (TIEMPO_BLOQUEO_MINUTOS * 60) - time();
                if ($segRest > 0) {
                    $min = (int)ceil($segRest / 60);
                    $error = "Tu cuenta está bloqueada temporalmente. Intenta nuevamente en {$min} minuto(s).";
                } else {
                    DB::consultar("UPDATE usuarios SET fecha_bloqueo=NULL, intentos_fallidos=0 WHERE id_usuario=:id",
                                  ['id' => $usuario['id_usuario']]);
                    $usuario['fecha_bloqueo'] = null;
                    $usuario['intentos_fallidos'] = 0;
                }
            }

            if (!$error) {
                if ($usuario['estado_cuenta'] === 'pendiente_verificacion') {
                    $error = 'Tu cuenta aún no está verificada. Revisa tu correo.';
                } elseif (in_array($usuario['estado_cuenta'], ['suspendida','bloqueada'], true)) {
                    $error = 'Esta cuenta está suspendida. Contacta al administrador.';
                } elseif (!Auth::verificarContrasena($contrasena, $usuario['contrasena_hash'])) {
                    $intentos = (int)$usuario['intentos_fallidos'] + 1;
                    $bloquear = $intentos >= MAX_INTENTOS_LOGIN;
                    DB::consultar(
                        "UPDATE usuarios SET intentos_fallidos=:i, fecha_bloqueo=" . ($bloquear ? 'NOW()' : 'NULL') . " WHERE id_usuario=:id",
                        ['i' => $intentos, 'id' => $usuario['id_usuario']]
                    );
                    if ($bloquear) {
                        $error = 'Tu cuenta fue bloqueada por seguridad tras 3 intentos fallidos. Intenta nuevamente en ' . TIEMPO_BLOQUEO_MINUTOS . ' minutos.';
                    } else {
                        $rest = MAX_INTENTOS_LOGIN - $intentos;
                        $error = "Correo o contraseña incorrectos. Te quedan {$rest} intento(s).";
                    }
                } else {
                    DB::consultar("UPDATE usuarios SET intentos_fallidos=0, fecha_bloqueo=NULL WHERE id_usuario=:id",
                                  ['id' => $usuario['id_usuario']]);
                    Auth::crearSesion((int)$usuario['id_usuario'],
                                       Auth::dispositivoCliente(), Auth::ipCliente());
                    header('Location: dashboard.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar sesión · MedAlert</title>
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
      <h1>Tu medicación,<br><span class="accent">siempre a tiempo.</span></h1>
      <p class="auth-subtitle">El puente entre lo que tu médico prescribe y lo que tú haces en casa. Diseñado para que ningún paciente vuelva a olvidar una toma.</p>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-bell-fill"></i><span>Recordatorios inteligentes para cada toma</span></div>
        <div class="auth-feature"><i class="bi bi-shield-fill-check"></i><span>Información compartida con tu médico</span></div>
        <div class="auth-feature"><i class="bi bi-people-fill"></i><span>Tu familia siempre al tanto del tratamiento</span></div>
      </div>
    </div>
    <div class="auth-footer">© 2026 MedAlert</div>
  </aside>

  <main class="auth-form-side">
    <div class="auth-card">
      <h2>Bienvenido</h2>
      <p class="auth-card-subtitle">Inicia sesión para continuar.</p>

      <?php if ($mensaje): ?>
      <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i><div><?= Auth::escapar($mensaje) ?></div></div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= Auth::escapar($error) ?></div></div>
      <?php endif; ?>

      <form method="POST" action="login.php" novalidate id="form-login">
        <div class="form-group">
          <label for="correo"><i class="bi bi-envelope-fill"></i> Correo electrónico</label>
          <div class="input-wrap">
            <i class="bi bi-envelope input-icon"></i>
            <input type="email" id="correo" name="correo" class="form-input"
                   placeholder="tucorreo@gmail.com"
                   value="<?= Auth::escapar($correoIngresado) ?>" required autofocus>
          </div>
          <div class="form-error"></div>
        </div>

        <div class="form-group">
          <label for="contrasena"><i class="bi bi-lock-fill"></i> Contraseña</label>
          <div class="input-wrap">
            <i class="bi bi-shield-lock input-icon"></i>
            <input type="password" id="contrasena" name="contrasena" class="form-input"
                   placeholder="Tu contraseña" required minlength="8">
            <button type="button" class="toggle-password" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
          <div class="form-error"></div>
        </div>

        <div style="text-align:right; margin-bottom:18px;">
          <a href="recuperar.php" style="font-size:13px; color:var(--navy-700); font-weight:600;">¿Olvidaste tu contraseña?</a>
        </div>

        <button type="submit" class="btn btn-danger btn-block btn-lg">
          <i class="bi bi-box-arrow-in-right"></i> Iniciar sesión
        </button>

        <div style="margin: 24px 0; text-align:center; color:var(--gray-400); font-size:12.5px; display:flex; align-items:center; gap:12px;">
          <span style="flex:1; height:1px; background:var(--gray-200);"></span>o<span style="flex:1; height:1px; background:var(--gray-200);"></span>
        </div>

        <a href="registro.php" class="btn btn-outline btn-block">
          <i class="bi bi-person-plus"></i> Crear cuenta nueva
        </a>

        <div class="alert alert-info mt-3" style="margin-top:20px;">
          <i class="bi bi-shield-check"></i>
          <div style="font-size:12.5px;">Tu cuenta se bloquea automáticamente tras 3 intentos fallidos para proteger tu información médica.</div>
        </div>
      </form>
    </div>
  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
document.getElementById('form-login').addEventListener('submit', function (e) {
  const correo = document.getElementById('correo');
  const contrasena = document.getElementById('contrasena');
  let valido = true;
  MedAlert.limpiarErrorCampo(correo);
  MedAlert.limpiarErrorCampo(contrasena);
  if (!MedAlert.validarCorreoSimple(correo.value)) {
    MedAlert.mostrarErrorCampo(correo, MedAlert.errorCorreoNoGmail());
    valido = false;
  }
  if (contrasena.value.length < 8) {
    MedAlert.mostrarErrorCampo(contrasena, 'La contraseña tiene mínimo 8 caracteres.');
    valido = false;
  }
  if (!valido) e.preventDefault();
});
</script>
</body>
</html>
