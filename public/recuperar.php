<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (Auth::usuarioActual()) {
    header('Location: dashboard.php');
    exit;
}

$err = '';
$enviado = false;
$segsRestantes = 0;
$paso = $_SESSION['recuperar_paso'] ?? 'correo';
$correoSesion = $_SESSION['recuperar_correo'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Helpers::post('accion') === 'solicitar') {
    $correo = mb_strtolower(Helpers::post('correo'));
    if (!Helpers::validarCorreo($correo)) {
        $err = filter_var($correo, FILTER_VALIDATE_EMAIL)
            ? Helpers::errorCorreoNoGmail()
            : 'Ingresa un correo válido.';
    } else {
        $usuario = DB::uno("SELECT id_usuario, nombre_completo FROM usuarios
                             WHERE correo=:c AND estado_cuenta='activa'", ['c' => $correo]);
        if (!$usuario) {
            $err = 'No existe una cuenta activa con ese correo.';
        } else {

            $rec = DB::uno(
                "SELECT * FROM recuperacion_codigos
                  WHERE correo=:c AND used=FALSE
               ORDER BY id_codigo DESC LIMIT 1",
                ['c' => $correo]
            );

            if ($rec && $rec['blocked_until'] && strtotime($rec['blocked_until']) > time()) {
                $segsRestantes = strtotime($rec['blocked_until']) - time();
                $err = 'Tienes demasiados intentos fallidos. Espera antes de volver a intentarlo.';
                $_SESSION['recuperar_correo'] = $correo;
            } elseif ($rec && strtotime($rec['expires_at']) > time()) {
                $segsRestantes = strtotime($rec['expires_at']) - time();
                $err = 'Ya tienes un código activo. Úsalo antes de solicitar uno nuevo.';
                $_SESSION['recuperar_correo'] = $correo;
                $_SESSION['recuperar_paso'] = 'codigo';
                header('Location: recuperar.php');
                exit;
            } elseif ($rec && $rec['send_count'] >= RECUPERACION_MAX_ENVIOS_HORA
                      && strtotime($rec['last_sent_at']) > time() - 3600) {
                $err = 'Límite de envíos alcanzado (máximo ' . RECUPERACION_MAX_ENVIOS_HORA . ' por hora). Intenta más tarde.';
            } else {
                $token = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $exp = date('Y-m-d H:i:s', time() + RECUPERACION_DURACION_MINUTOS * 60);

                DB::consultar("UPDATE recuperacion_codigos SET used=TRUE WHERE correo=:c", ['c' => $correo]);

                $sendCount = ($rec && strtotime($rec['last_sent_at']) > time() - 3600)
                    ? (int)$rec['send_count'] + 1 : 1;

                DB::consultar(
                    "INSERT INTO recuperacion_codigos (correo, token, expires_at, last_sent_at, send_count)
                     VALUES (:c, :t, :e, NOW(), :s)",
                    ['c' => $correo, 't' => $token, 'e' => $exp, 's' => $sendCount]
                );

                $ok = Mailer::enviarCodigoRecuperacion($correo, $token);
                if ($ok) {
                    $enviado = true;
                    $_SESSION['recuperar_correo'] = $correo;
                    $_SESSION['recuperar_paso'] = 'codigo';
                    unset($_SESSION['recuperar_token'], $_SESSION['recuperar_record_id']);
                    header('Location: recuperar.php');
                    exit;
                } else {
                    $err = 'No se pudo enviar el correo. Verifica las credenciales SMTP en includes/config.php.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Helpers::post('accion') === 'verificar_codigo' && $correoSesion !== '') {
    $rec = DB::uno("SELECT * FROM recuperacion_codigos WHERE correo=:c AND used=FALSE
                    ORDER BY id_codigo DESC LIMIT 1", ['c' => $correoSesion]);
    if (!$rec) {
        header('Location: recuperar.php?reset=1');
        exit;
    }

    $bloqueado = $rec['blocked_until'] && strtotime($rec['blocked_until']) > time();
    if ($bloqueado) {
        $segsRestantes = strtotime($rec['blocked_until']) - time();
        $err = 'Demasiados intentos fallidos. Espera ' . ceil($segsRestantes/60) . ' minuto(s).';
    } else {
        $token = preg_replace('/\D/', '', Helpers::post('token'));
        if (strlen($token) !== 6) {
            $err = 'Ingresa el código de 6 dígitos.';
        } else {
            $valido = DB::uno(
                "SELECT * FROM recuperacion_codigos
                  WHERE id_codigo=:id AND token=:t AND used=FALSE AND expires_at > NOW()",
                ['id' => $rec['id_codigo'], 't' => $token]
            );

            if (!$valido) {
                $nuevos = (int)$rec['failed_attempts'] + 1;
                if ($nuevos >= RECUPERACION_MAX_INTENTOS) {
                    $hasta = date('Y-m-d H:i:s', time() + RECUPERACION_BLOQUEO_MINUTOS * 60);
                    DB::consultar(
                        "UPDATE recuperacion_codigos SET failed_attempts=:f, blocked_until=:b WHERE id_codigo=:id",
                        ['f' => $nuevos, 'b' => $hasta, 'id' => $rec['id_codigo']]
                    );
                    $segsRestantes = RECUPERACION_BLOQUEO_MINUTOS * 60;
                    $err = 'Demasiados intentos fallidos. Deberás esperar ' . RECUPERACION_BLOQUEO_MINUTOS . ' minutos antes de volver a intentarlo.';
                } else {
                    DB::consultar(
                        "UPDATE recuperacion_codigos SET failed_attempts=:f WHERE id_codigo=:id",
                        ['f' => $nuevos, 'id' => $rec['id_codigo']]
                    );
                    $rest = RECUPERACION_MAX_INTENTOS - $nuevos;
                    $err = "Código incorrecto, ya fue utilizado o expiró. Te quedan {$rest} intento(s).";
                }
            } else {

                DB::consultar(
                    "UPDATE recuperacion_codigos SET failed_attempts=0, blocked_until=NULL WHERE id_codigo=:id",
                    ['id' => $rec['id_codigo']]
                );
                $_SESSION['recuperar_token'] = $token;
                $_SESSION['recuperar_record_id'] = $rec['id_codigo'];
                $_SESSION['recuperar_paso'] = 'preguntas';
                header('Location: recuperar.php');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Helpers::post('accion') === 'cambiar_password' && $correoSesion !== '') {
    $recordId = (int)($_SESSION['recuperar_record_id'] ?? 0);
    $token    = $_SESSION['recuperar_token'] ?? '';

    $rec = DB::uno(
        "SELECT * FROM recuperacion_codigos
          WHERE id_codigo=:id AND correo=:c AND token=:t AND used=FALSE AND expires_at > NOW()",
        ['id' => $recordId, 'c' => $correoSesion, 't' => $token]
    );
    if (!$rec) {
        $err = 'El código expiró o ya fue utilizado. Solicita uno nuevo.';
        unset($_SESSION['recuperar_token'], $_SESSION['recuperar_record_id'], $_SESSION['recuperar_paso']);
        header('Location: recuperar.php');
        exit;
    }

    $usuario = DB::uno("SELECT * FROM usuarios WHERE correo=:c", ['c' => $correoSesion]);
    $preguntas = DB::todos(
        "SELECT ps.orden, ps.respuesta_hash, c.pregunta
           FROM preguntas_seguridad ps
           INNER JOIN catalogo_preguntas_seguridad c ON c.id_pregunta = ps.id_pregunta
          WHERE ps.id_usuario=:u ORDER BY ps.orden",
        ['u' => $usuario['id_usuario']]
    );

    $todasOk = true;
    foreach ($preguntas as $p) {
        $resp = mb_strtolower(trim(Helpers::post('respuesta'.$p['orden'])));
        if (!Auth::verificarContrasena($resp, $p['respuesta_hash'])) {
            $todasOk = false;
            break;
        }
    }

    $nueva  = Helpers::post('contrasena_nueva');
    $confir = Helpers::post('contrasena_confirma');
    $val = Helpers::validarContrasena($nueva);

    if (!$todasOk) {
        $err = 'Una o más respuestas de seguridad son incorrectas.';
    } elseif (!$val['valida']) {
        $err = $val['error'];
    } elseif ($nueva !== $confir) {
        $err = 'Las contraseñas no coinciden.';
    } elseif (Auth::verificarContrasena($nueva, $usuario['contrasena_hash'])) {
        $err = 'La nueva contraseña no puede ser igual a la anterior.';
    } else {
        DB::consultar(
            "UPDATE usuarios SET contrasena_hash=:h, intentos_fallidos=0, fecha_bloqueo=NULL WHERE id_usuario=:id",
            ['h' => Auth::hashearContrasena($nueva), 'id' => $usuario['id_usuario']]
        );
        DB::consultar("UPDATE recuperacion_codigos SET used=TRUE WHERE id_codigo=:id", ['id' => $rec['id_codigo']]);
        DB::consultar("UPDATE sesiones_activas SET activa=FALSE WHERE id_usuario=:id",
                      ['id' => $usuario['id_usuario']]);
        Mailer::notificarRecuperacion($usuario['correo'], $usuario['nombre_completo']);
        unset($_SESSION['recuperar_correo'], $_SESSION['recuperar_token'],
              $_SESSION['recuperar_record_id'], $_SESSION['recuperar_paso']);
        header('Location: login.php?recuperada=1');
        exit;
    }
}

$preguntasUsuario = [];
if ($paso === 'preguntas' && $correoSesion !== '') {
    $u = DB::uno("SELECT id_usuario FROM usuarios WHERE correo=:c", ['c' => $correoSesion]);
    if ($u) {
        $preguntasUsuario = DB::todos(
            "SELECT ps.orden, c.pregunta
               FROM preguntas_seguridad ps
               INNER JOIN catalogo_preguntas_seguridad c ON c.id_pregunta = ps.id_pregunta
              WHERE ps.id_usuario=:u ORDER BY ps.orden",
            ['u' => $u['id_usuario']]
        );
    }
}

if (Helpers::get('reset') === '1') {
    unset($_SESSION['recuperar_correo'], $_SESSION['recuperar_token'],
          $_SESSION['recuperar_record_id'], $_SESSION['recuperar_paso']);
    header('Location: recuperar.php');
    exit;
}

if ($paso !== 'correo' && $correoSesion === '') $paso = 'correo';

if ($paso === 'codigo' && $correoSesion !== '' && $segsRestantes === 0) {
    $rec = DB::uno("SELECT blocked_until FROM recuperacion_codigos
                    WHERE correo=:c AND used=FALSE ORDER BY id_codigo DESC LIMIT 1",
                   ['c' => $correoSesion]);
    if ($rec && $rec['blocked_until'] && strtotime($rec['blocked_until']) > time()) {
        $segsRestantes = strtotime($rec['blocked_until']) - time();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar contraseña · MedAlert</title>
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
      <h1>Recuperar acceso<br><span class="accent">de forma segura.</span></h1>
      <p class="auth-subtitle">Recibe un código de 6 dígitos en tu correo, responde tus preguntas de seguridad y crea una nueva contraseña.</p>
    </div>
    <div class="auth-footer">© 2026 MedAlert</div>
  </aside>

  <main class="auth-form-side">
    <div class="auth-card">
      <a href="login.php" class="auth-back"><i class="bi bi-arrow-left"></i> Volver al inicio de sesión</a>

      <h2>Recuperar contraseña</h2>

      <?php if ($paso === 'correo'): ?>
      <p class="auth-card-subtitle">Ingresa tu correo y te enviaremos un código.</p>

      <?php if ($err): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= Auth::escapar($err) ?></div></div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="accion" value="solicitar">
        <div class="form-group">
          <label><i class="bi bi-envelope-fill"></i> Correo de tu cuenta</label>
          <input type="email" name="correo" class="form-input"
                 placeholder="tucorreo@gmail.com" required autofocus>
        </div>
        <button type="submit" class="btn btn-danger btn-block btn-lg">
          <i class="bi bi-envelope-arrow-up"></i> Enviar código
        </button>
      </form>

      <?php elseif ($paso === 'codigo'): ?>
      <p class="auth-card-subtitle">Te enviamos un código a <strong><?= Auth::escapar($correoSesion) ?></strong>.</p>

      <?php if ($err): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= Auth::escapar($err) ?></div></div>
      <?php endif; ?>

      <?php if ($segsRestantes > 0): ?>
        <div class="alert alert-warning" style="text-align:center;">
          <div style="font-size:.85rem;margin-bottom:.5rem;">Podrás volver a intentar en:</div>
          <div id="countdown" style="font-family:monospace;font-size:2rem;font-weight:700;color:var(--amber-500);letter-spacing:.05em;">--:--</div>
        </div>
        <button class="btn btn-danger btn-block" disabled style="opacity:.5;cursor:not-allowed;">Verificar código</button>
        <script>
          (function(){
            var secs = <?= (int)$segsRestantes ?>;
            var el = document.getElementById('countdown');
            (function tick(){
              if(secs <= 0){ el.textContent='00:00'; location.reload(); return; }
              var m = Math.floor(secs/60), s = secs%60;
              el.textContent = (m<10?'0':'')+m+':'+(s<10?'0':'')+s;
              secs--;
              setTimeout(tick, 1000);
            })();
          })();
        </script>
      <?php else: ?>
        <form method="POST" novalidate>
          <input type="hidden" name="accion" value="verificar_codigo">
          <div class="form-group">
            <label><i class="bi bi-shield-lock"></i> Código de 6 dígitos</label>
            <input type="text" name="token" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                   class="form-input" placeholder="000000" required autofocus
                   style="font-family:monospace; font-size:28px; text-align:center; letter-spacing:.8em; padding-left:.8em;">
          </div>
          <button type="submit" class="btn btn-danger btn-block btn-lg">
            <i class="bi bi-check-lg"></i> Verificar código
          </button>
        </form>

        <div class="alert alert-info mt-3">
          <i class="bi bi-info-circle-fill"></i>
          <div style="font-size:12.5px;">El código es válido por <?= RECUPERACION_DURACION_MINUTOS ?> minutos. Tras <?= RECUPERACION_MAX_INTENTOS ?> intentos fallidos se bloquea por <?= RECUPERACION_BLOQUEO_MINUTOS ?> minutos.</div>
        </div>

        <p class="text-center mt-2">
          <a href="recuperar.php?reset=1" style="font-size:13px; color:var(--gray-500);">Solicitar nuevo código</a>
        </p>
      <?php endif; ?>

      <?php elseif ($paso === 'preguntas'): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <div>Código verificado. Ahora responde tus preguntas y elige tu nueva contraseña.</div>
      </div>

      <?php if ($err): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><div><?= Auth::escapar($err) ?></div></div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="accion" value="cambiar_password">
        <h3 style="font-size:14px; color:var(--navy-900); margin: 14px 0;
                   text-transform:uppercase; letter-spacing:1px;">
          <i class="bi bi-shield-lock"></i> Preguntas de seguridad
        </h3>

        <?php foreach ($preguntasUsuario as $p): ?>
        <div class="form-group">
          <label><i class="bi bi-question-circle"></i> <?= Auth::escapar($p['pregunta']) ?></label>
          <input type="text" name="respuesta<?= $p['orden'] ?>" class="form-input"
                 placeholder="Tu respuesta" required minlength="2" maxlength="100" autocomplete="off">
        </div>
        <?php endforeach; ?>

        <h3 style="font-size:14px; color:var(--navy-900); margin: 24px 0 14px;
                   text-transform:uppercase; letter-spacing:1px;">
          <i class="bi bi-lock-fill"></i> Nueva contraseña
        </h3>

        <div class="form-group">
          <label><i class="bi bi-key-fill"></i> Nueva contraseña</label>
          <div class="input-wrap">
            <input type="password" id="contrasena_nueva" name="contrasena_nueva" class="form-input"
                   placeholder="Mínimo 8 caracteres" required minlength="8" style="padding-left:14px;">
            <button type="button" class="toggle-password" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
          <div class="password-strength" id="password-strength">
            <div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div>
          </div>
        </div>

        <div class="form-group">
          <label><i class="bi bi-key-fill"></i> Confirmar contraseña</label>
          <input type="password" name="contrasena_confirma" class="form-input"
                 placeholder="Repite tu contraseña" required minlength="8">
        </div>

        <button type="submit" class="btn btn-danger btn-block btn-lg">
          <i class="bi bi-check-circle-fill"></i> Cambiar contraseña
        </button>
      </form>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="assets/js/app.js"></script>
<?php if ($paso === 'preguntas'): ?>
<script>
MedAlert.pintarFuerzaContrasena(
  document.getElementById('contrasena_nueva'),
  document.getElementById('password-strength')
);
</script>
<?php endif; ?>
</body>
</html>
