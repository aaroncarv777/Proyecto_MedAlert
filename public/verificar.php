<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$correo = $_SESSION['verificar_correo'] ?? '';
$envioOk = $_SESSION['verificar_envio_ok'] ?? true;

if ($correo === '') {
    header('Location: login.php');
    exit;
}

$error = null;
$reenviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = Helpers::post('accion');

    if ($accion === 'verificar') {
        $codigo = preg_replace('/\D/', '', Helpers::post('codigo'));
        if (strlen($codigo) !== 6) {
            $error = 'El código debe tener 6 dígitos.';
        } else {
            $usuario = DB::uno(
                "SELECT * FROM usuarios WHERE correo=:c", ['c' => $correo]
            );
            if (!$usuario) {
                $error = 'Cuenta no encontrada.';
            } elseif ($usuario['estado_cuenta'] === 'activa') {

                unset($_SESSION['verificar_correo'], $_SESSION['verificar_envio_ok']);
                header('Location: login.php?verificada=1');
                exit;
            } elseif ($usuario['fecha_expiracion_verificacion']
                      && strtotime($usuario['fecha_expiracion_verificacion']) < time()) {
                $error = 'El código expiró. Solicita uno nuevo.';
            } elseif ($usuario['codigo_verificacion'] !== $codigo) {
                DB::consultar(
                    "UPDATE usuarios SET intentos_verificacion = intentos_verificacion + 1
                      WHERE id_usuario = :id",
                    ['id' => $usuario['id_usuario']]
                );
                $error = 'Código incorrecto. Verifica los 6 dígitos.';
            } else {
                DB::consultar(
                    "UPDATE usuarios
                        SET estado_cuenta = 'activa',
                            codigo_verificacion = NULL,
                            fecha_expiracion_verificacion = NULL,
                            intentos_verificacion = 0
                      WHERE id_usuario = :id",
                    ['id' => $usuario['id_usuario']]
                );
                unset($_SESSION['verificar_correo'], $_SESSION['verificar_envio_ok']);
                header('Location: login.php?verificada=1');
                exit;
            }
        }
    }

    if ($accion === 'reenviar') {
        $usuario = DB::uno("SELECT * FROM usuarios WHERE correo=:c", ['c' => $correo]);
        if ($usuario && $usuario['estado_cuenta'] === 'pendiente_verificacion') {
            $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exp = (new DateTime())->modify('+'.EXPIRACION_VERIFICACION_HORAS.' hours')->format('Y-m-d H:i:s');
            DB::consultar(
                "UPDATE usuarios
                    SET codigo_verificacion=:c,
                        fecha_codigo_verificacion=NOW(),
                        fecha_expiracion_verificacion=:e,
                        intentos_verificacion=0
                  WHERE id_usuario=:id",
                ['c' => $codigo, 'e' => $exp, 'id' => $usuario['id_usuario']]
            );
            $envioOk = Mailer::enviarVerificacion($correo, $usuario['nombre_completo'], $codigo);
            $_SESSION['verificar_envio_ok'] = $envioOk;
            $reenviado = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verifica tu correo · MedAlert</title>
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
      <h1>Un último paso<br><span class="accent">y entramos.</span></h1>
      <p class="auth-subtitle">Te enviamos un código de 6 dígitos a tu correo. Ingrésalo aquí para activar tu cuenta.</p>
    </div>
    <div class="auth-footer">© 2026 MedAlert</div>
  </aside>

  <main class="auth-form-side">
    <div class="auth-card">
      <div style="width:84px; height:84px; margin:0 auto 24px; background:var(--green-50);
                  color:var(--green-500); border-radius:50%; display:flex;
                  align-items:center; justify-content:center; font-size:42px;">
        <i class="bi bi-envelope-check-fill"></i>
      </div>
      <h2 class="text-center">¡Casi listo!</h2>
      <p class="auth-card-subtitle text-center">Ingresa el código de 6 dígitos que enviamos a:</p>

      <div class="text-center" style="background:var(--navy-50); padding:12px 18px; border-radius:var(--r-md);
                  color:var(--navy-700); font-weight:600; margin: 18px auto 24px;
                  display:inline-block; font-size:14.5px;">
        <?= Auth::escapar($correo) ?>
      </div>

      <?php if ($reenviado && $envioOk): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Código reenviado. Revisa tu bandeja de entrada.</div>
        </div>
      <?php elseif (!$envioOk): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>No pudimos enviar el correo en este momento. Verifica las credenciales SMTP en <code>includes/config.php</code> e intenta reenviar.</div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= Auth::escapar($error) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="accion" value="verificar">
        <div class="form-group">
          <label><i class="bi bi-shield-lock"></i> Código de 6 dígitos</label>
          <input type="text" name="codigo" class="form-input"
                 maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                 placeholder="000000" required autofocus
                 style="font-family:monospace; font-size:28px; text-align:center; letter-spacing:.8em; padding-left:.8em;">
        </div>
        <button type="submit" class="btn btn-danger btn-block btn-lg">
          <i class="bi bi-check-circle-fill"></i> Verificar cuenta
        </button>
      </form>

      <form method="POST" style="margin-top: 14px;">
        <input type="hidden" name="accion" value="reenviar">
        <button type="submit" class="btn btn-outline btn-block">
          <i class="bi bi-arrow-clockwise"></i> Reenviar código
        </button>
      </form>

      <div class="alert alert-info mt-3" style="margin-top:18px;">
        <i class="bi bi-info-circle-fill"></i>
        <div style="font-size:12.5px;">El código expira en 24 horas. Si tarda en llegar, revisa la carpeta de spam.</div>
      </div>

      <p class="text-center" style="margin-top:18px;">
        <a href="login.php" style="font-size:13px; color:var(--gray-500);">
          <i class="bi bi-arrow-left"></i> Volver al inicio de sesión
        </a>
      </p>
    </div>
  </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
