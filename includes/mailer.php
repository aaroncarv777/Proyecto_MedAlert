<?php

require_once __DIR__. '/config.php';
require_once __DIR__. '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__. '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__. '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer
{

 public static function enviarVerificacion(string $correo, string $nombre, string $codigo): bool
 {
 return self::enviar(
 $correo, $nombre,
 'MedAlert · Verifica tu correo',
 self::plantilla('Verifica tu correo',
 'Hola '. htmlspecialchars($nombre, ENT_QUOTES). ', tu código de verificación es:',
 $codigo,
 'Este código es válido por 24 horas. Ingrésalo en la pantalla de verificación para activar tu cuenta.'
 )
 );
 }

 public static function enviarCodigoRecuperacion(string $correo, string $codigo): bool
 {
 return self::enviar(
 $correo, '',
 'MedAlert · Código de recuperación',
 self::plantilla('Código de recuperación',
 'Recibiste este correo porque solicitaste restablecer tu contraseña. Usa el siguiente código:',
 $codigo,
 'Este código es válido por 20 minutos y solo puede usarse una vez. Si no solicitaste este cambio, ignora este correo.'
 )
 );
 }

 public static function enviarCodigoCambioPerfil(string $correo, string $nombre, string $codigo, string $tipoCambio): bool
 {
 $titulo = match ($tipoCambio) {
 'correo' => 'Confirma el cambio de correo',
 'contrasena' => 'Confirma el cambio de contraseña',
 'nombre' => 'Confirma el cambio de nombre',
 default => 'Confirma el cambio en tu cuenta',
 };
 return self::enviar(
 $correo, $nombre,
 'MedAlert · '. $titulo,
 self::plantilla($titulo,
 'Hola '. htmlspecialchars($nombre, ENT_QUOTES). ', ingresa este código en la pantalla para confirmar el cambio:',
 $codigo,
 'Este código es válido por 20 minutos. Si tú no solicitaste este cambio, ignora este correo y revisa la seguridad de tu cuenta.'
 )
 );
 }

 public static function notificarRecuperacion(string $correo, string $nombre): bool
 {
 $fecha = date('d/m/Y H:i');
 $body = self::plantillaSimple('Tu contraseña fue restablecida',
 'Hola '. htmlspecialchars($nombre, ENT_QUOTES). ', te confirmamos que tu contraseña fue cambiada exitosamente el '. $fecha. '.',
 'Si tú no realizaste este cambio, comunícate con soporte de inmediato.'
 );
 return self::enviar($correo, $nombre, 'MedAlert · Tu contraseña fue restablecida', $body);
 }

 /* =====================================================
 * SPRINT 2 — Notificación de medicamento
 * ===================================================== */
 public static function enviarNotificacionMedicamento(
 string $correo,
 string $nombre,
 string $titulo,
 string $mensaje,
 string $tipoAviso = 'recordatorio'
 ): bool {
 $color = match ($tipoAviso) {
 'temprano' => '#1a3a6e',
 'recordatorio'=> '#1a3a6e',
 'tardio' => '#c1272d',
 default => '#1a3a6e',
 };
 $etiqueta = match ($tipoAviso) {
 'temprano' => 'Aviso anticipado',
 'recordatorio'=> 'Recordatorio',
 'tardio' => 'AVISO TARDÍO',
 default => 'Notificación',
 };

 $body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,'.$color.' 0%,#0f2447 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">MedAlert</h1>
 <p style="color:rgba(255,255,255,.78);margin:.3rem 0 0;font-size:.85rem">'.$etiqueta.'</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <h2 style="color:'.$color.';font-size:1.2rem;margin:0 0.8rem">'.htmlspecialchars($titulo, ENT_QUOTES).'</h2>
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6;margin-bottom:1rem">Hola '.htmlspecialchars($nombre, ENT_QUOTES).',</p>
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">'.nl2br(htmlspecialchars($mensaje, ENT_QUOTES)).'</p>
 <p style="color:#64748b;font-size:.82rem;margin:1.2rem 0 0">Abre la aplicación MedAlert para registrar tu toma como cumplida o no realizada.</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 return self::enviar($correo, $nombre, 'MedAlert · '. $titulo, $body);
 }

 /* =====================================================
 * SPRINT 2 — Validación de credenciales médicas
 * ===================================================== */
 public static function enviarCedulaAprobada(string $correo, string $nombre, string $cedula, string $especialidad): bool
 {
 $body = self::plantillaSimple(
 'Tus credenciales han sido aprobadas',
 'Hola Dr(a). '. htmlspecialchars($nombre, ENT_QUOTES). ', tus credenciales profesionales han sido validadas por el administrador.<br><br>'. '<strong>Cédula:</strong> '. htmlspecialchars($cedula, ENT_QUOTES). '<br>'. '<strong>Especialidad:</strong> '. htmlspecialchars($especialidad, ENT_QUOTES). '<br><br>'. 'A partir de ahora tienes acceso completo a las funcionalidades de médico: gestión de pacientes asignados, prescripción digital, cálculo de dosis por peso y aceptación de alertas de interacción.',
 'Tu perfil ya es visible y verificable para tus pacientes asignados.'
 );
 return self::enviar($correo, $nombre, 'MedAlert · Credenciales aprobadas', $body);
 }

 public static function enviarCedulaRechazada(string $correo, string $nombre, string $motivo): bool
 {
 $body = self::plantillaSimple(
 'Credenciales rechazadas',
 'Hola '. htmlspecialchars($nombre, ENT_QUOTES). ', el administrador ha revisado tus credenciales profesionales y no pudo validarlas por la siguiente razón:<br><br>'. '<em style="color:#c1272d;background:#ffe8ea;padding:.6rem.8rem;border-radius:6px;display:block">'. htmlspecialchars($motivo, ENT_QUOTES). '</em><br>'. 'Por favor revisa tu información, corrígela desde la sección "Mi perfil" y vuelve a enviar tus credenciales para una nueva revisión.',
 'Mientras no sean validadas, mantendrás acceso limitado al sistema.'
 );
 return self::enviar($correo, $nombre, 'MedAlert · Credenciales rechazadas', $body);
 }

 /* =====================================================
 * SPRINT 2 — Stock bajo / agotado
 * ===================================================== */
 public static function enviarAvisoStock(string $correo, string $nombre, string $medicamento, int $unidades, int $diasRestantes, string $estado): bool
 {
 $titulo = $estado === 'agotado'
 ? 'Medicamento agotado'
 : 'Stock bajo: '. $medicamento;
 $cuerpo = $estado === 'agotado'
 ? 'El medicamento <strong>'. htmlspecialchars($medicamento, ENT_QUOTES). '</strong> ha llegado a 0 unidades. Es indispensable reabastecer cuanto antes para no interrumpir el tratamiento.'
 : 'Quedan únicamente <strong>'. $unidades. ' unidades</strong> de <strong>'. htmlspecialchars($medicamento, ENT_QUOTES). '</strong>, equivalente a aproximadamente <strong>'. $diasRestantes. ' días</strong> de tratamiento. Te sugerimos reabastecer pronto.';
 $body = self::plantillaSimple(
 $titulo, $cuerpo,
 'Puedes actualizar las unidades disponibles desde la sección Medicamentos en la app.'
 );
 return self::enviar($correo, $nombre, 'MedAlert · '. $titulo, $body);
 }

 /* =====================================================
 * SPRINT 2 — Alerta de interacción medicamentosa
 * ===================================================== */
 public static function enviarAlertaInteraccion(string $correo, string $nombre, string $medA, string $medB, string $riesgo, string $descripcion): bool
 {
 $color = match ($riesgo) {
 'grave' => '#c1272d',
 'moderada' => '#f59e0b',
 default => '#3b82f6',
 };
 $etiqueta = strtoupper($riesgo);
 $body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,'.$color.' 0%,#0f2447 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">MedAlert</h1>
 <p style="color:#fff;margin:.3rem 0 0;font-size:.95rem;font-weight:700">⚠ Interacción medicamentosa · '.$etiqueta.'</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">Hola '.htmlspecialchars($nombre, ENT_QUOTES).',</p>
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">El sistema detectó una interacción de nivel <strong style="color:'.$color.'">'.$etiqueta.'</strong> entre los siguientes medicamentos de tu tratamiento:</p>
 <div style="background:#f1f5f9;border-left:4px solid '.$color.';padding:.9rem 1rem;margin:1rem 0;border-radius:6px">
 <strong>'.htmlspecialchars($medA, ENT_QUOTES).'</strong> &nbsp;+&nbsp; <strong>'.htmlspecialchars($medB, ENT_QUOTES).'</strong>
 </div>
 <p style="color:#1e293b;font-size:.93rem;line-height:1.55"><strong>Descripción:</strong> '.htmlspecialchars($descripcion, ENT_QUOTES).'</p>
 <p style="color:#64748b;font-size:.82rem;margin:1.2rem 0 0">No suspendas tu medicación sin consultar a tu médico. Esta alerta es informativa.</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 return self::enviar($correo, $nombre, 'MedAlert · Alerta de interacción ('.$etiqueta.')', $body);
 }

 /* =====================================================
 * SPRINT 3 — Nueva prescripción digital
 * ===================================================== */
 public static function enviarNuevaPrescripcion(
 string $correo, string $nombre, string $medico, string $medicamento,
 string $dosis, float $frecuencia, string $fechaInicio,
 ?int $duracion, string $instrucciones): bool
 {
 $durTxt = $duracion ? $duracion. ' días' : 'indefinida';
 $instHtml = $instrucciones !== ''
 ? '<p style="color:#1e293b;font-size:.92rem;line-height:1.55;margin-top:.8rem"><strong>Indicaciones:</strong> '. htmlspecialchars($instrucciones, ENT_QUOTES). '</p>' : '';
 $body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,#1a3a6e 0%,#0f2447 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">MedAlert</h1>
 <p style="color:rgba(255,255,255,.78);margin:.3rem 0 0;font-size:.85rem">Nueva prescripción digital</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <h2 style="color:#0f2447;font-size:1.2rem;margin:0 0.8rem">Tu médico te emitió una nueva prescripción</h2>
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">Hola '.htmlspecialchars($nombre, ENT_QUOTES).', '.htmlspecialchars($medico, ENT_QUOTES).' configuró un nuevo medicamento en tu tratamiento. Ya aparece en la app con sus recordatorios listos.</p>
 <div style="background:#f1f5f9;border-left:4px solid #1a3a6e;padding:.9rem 1rem;margin:1rem 0;border-radius:6px">
 <strong style="color:#0f2447">'.htmlspecialchars($medicamento, ENT_QUOTES).'</strong><br>
 <span style="color:#475569;font-size:.9rem">Dosis: '.htmlspecialchars($dosis, ENT_QUOTES).' &nbsp;·&nbsp; Cada '.$frecuencia.' h &nbsp;·&nbsp; Inicio: '.htmlspecialchars($fechaInicio, ENT_QUOTES).' &nbsp;·&nbsp; Duración: '.$durTxt.'</span>
 </div>
 '.$instHtml.'
 <p style="color:#64748b;font-size:.82rem;margin:1.2rem 0 0">Esta prescripción la administra tu médico. No es editable desde tu cuenta, pero puedes verla en la sección Medicamentos.</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 return self::enviar($correo, $nombre, 'MedAlert · Nueva prescripción de tu médico', $body);
 }

 /* =====================================================
 * SPRINT 3 — Alerta al familiar (cumplimiento < 70%)
 * ===================================================== */
 public static function enviarAlertaFamiliar(
 string $correo, string $nombre, string $paciente, int $porcentaje): bool
 {
 $body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,#f59e0b 0%,#b45309 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">MedAlert</h1>
 <p style="color:#fff;margin:.3rem 0 0;font-size:.9rem;font-weight:700">Alerta de cumplimiento</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">Hola '.htmlspecialchars($nombre, ENT_QUOTES).',</p>
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">El cumplimiento del tratamiento de <strong>'.htmlspecialchars($paciente, ENT_QUOTES).'</strong> bajó a <strong style="color:#b45309">'.$porcentaje.'%</strong> esta semana, por debajo del umbral recomendado del 70%.</p>
 <p style="color:#1e293b;font-size:.93rem;line-height:1.55">Te sugerimos contactarlo para apoyarlo a retomar su medicación. Puedes ver el detalle en la sección "Vista familiar" de la app.</p>
 <p style="color:#64748b;font-size:.82rem;margin:1.2rem 0 0">Recibes este aviso porque el paciente te dio acceso a su seguimiento. Puede revocar este acceso en cualquier momento.</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 return self::enviar($correo, $nombre, 'MedAlert · Alerta: cumplimiento bajo de '. $paciente, $body);
 }

 /* =====================================================
 * SPRINT 3 — Aviso URGENTE por reacción alérgica
 * ===================================================== */
 public static function enviarAvisoUrgenteAlergia(
 string $correo, string $nombre, string $paciente,
 string $medicamento, string $descripcion): bool
 {
 $descHtml = $descripcion !== ''
 ? '<p style="color:#1e293b;font-size:.93rem;line-height:1.55"><strong>Descripción del paciente:</strong> '. htmlspecialchars($descripcion, ENT_QUOTES). '</p>' : '';
 $body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#fef2f2;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,#d62828 0%,#7f1d1d 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">⚠ MedAlert</h1>
 <p style="color:#fff;margin:.3rem 0 0;font-size:1rem;font-weight:800;letter-spacing:.05em">URGENTE · REACCIÓN ALÉRGICA</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem;border:2px solid #d62828">
 <p style="color:#1e293b;font-size:.95rem;line-height:1.6">Dr(a). '.htmlspecialchars($nombre, ENT_QUOTES).',</p>
 <p style="color:#1e293b;font-size:.97rem;line-height:1.6">Su paciente <strong style="color:#b71c1c">'.htmlspecialchars($paciente, ENT_QUOTES).'</strong> reportó una <strong>REACCIÓN ALÉRGICA</strong> tras tomar:</p>
 <div style="background:#fee2e2;border-left:5px solid #d62828;padding:.9rem 1rem;margin:1rem 0;border-radius:6px">
 <strong style="color:#b71c1c;font-size:1.05rem">'.htmlspecialchars($medicamento, ENT_QUOTES).'</strong>
 </div>
 '.$descHtml.'
 <p style="color:#b71c1c;font-size:.92rem;font-weight:700;margin:1.2rem 0 0">Este aviso requiere su atención inmediata. Una reacción alérgica puede agravarse en minutos.</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert · Aviso prioritario automático</p>
</div>';
 return self::enviar($correo, $nombre,
 'MedAlert · URGENTE: Reacción alérgica de '. $paciente, $body);
 }

 private static function enviar(string $correo, string $nombre, string $asunto, string $cuerpoHtml): bool
 {
 $mail = new PHPMailer(true);
 try {
 $mail->isSMTP();
 $mail->Host = MAIL_HOST;
 $mail->SMTPAuth = true;
 $mail->Username = MAIL_USER;
 $mail->Password = MAIL_PASS;
 $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
 $mail->Port = MAIL_PORT;
 $mail->CharSet = 'UTF-8';
 $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
 $mail->addAddress($correo, $nombre);
 $mail->Subject = $asunto;
 $mail->isHTML(true);
 $mail->Body = $cuerpoHtml;
 $mail->AltBody = trim(html_entity_decode(strip_tags($cuerpoHtml)));
 $mail->send();
 self::registrar('OK', $correo, $asunto);
 return true;
 } catch (Exception $e) {
 self::registrar('FALLO', $correo, $asunto, $mail->ErrorInfo);
 error_log('MedAlert mailer error: '. $mail->ErrorInfo);
 return false;
 }
 }

 private static function plantilla(string $titulo, string $intro, string $codigo, string $nota): string
 {
 return '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,#1a3a6e 0%,#0f2447 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem;font-weight:700">MedAlert</h1>
 <p style="color:rgba(255,255,255,.78);margin:.3rem 0 0;font-size:.85rem">Recordatorio de medicamentos</p>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <h2 style="color:#0f2447;font-size:1.15rem;margin:0 0.8rem">'.$titulo.'</h2>
 <p style="color:#475569;font-size:.93rem;margin-bottom:1.2rem;line-height:1.55">'.$intro.'</p>
 <div style="background:#dbe5f3;border:2px dashed #1a3a6e;border-radius:10px;padding:1rem;text-align:center;margin:1rem 0">
 <span style="font-family:monospace;font-size:2.4rem;font-weight:700;color:#0f2447;letter-spacing:.4em">'.$codigo.'</span>
 </div>
 <p style="color:#64748b;font-size:.82rem;line-height:1.55;margin:1rem 0 0">'.$nota.'</p>
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 }

 private static function plantillaSimple(string $titulo, string $cuerpo, string $nota = ''): string
 {
 $notaHtml = $nota !== ''
 ? '<p style="color:#64748b;font-size:.82rem;margin:1rem 0 0">'.$nota.'</p>' : '';
 return '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;background:#eef3fb;padding:2rem;border-radius:10px">
 <div style="background:linear-gradient(135deg,#1a3a6e 0%,#0f2447 100%);border-radius:10px;padding:1.4rem;text-align:center;margin-bottom:1.5rem">
 <h1 style="color:#fff;margin:0;font-size:1.5rem">MedAlert</h1>
 </div>
 <div style="background:#fff;border-radius:10px;padding:1.8rem 1.5rem">
 <h2 style="color:#0f2447;font-size:1.15rem;margin:0 0.8rem">'.$titulo.'</h2>
 <p style="color:#1e293b;font-size:.93rem;line-height:1.55">'.$cuerpo.'</p>
 '.$notaHtml.'
 </div>
 <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem">© 2026 MedAlert</p>
</div>';
 }

 private static function registrar(string $resultado, string $destino, string $asunto, string $extra = ''): void
 {
 $dir = __DIR__. '/../logs';
 if (!is_dir($dir)) @mkdir($dir, 0775, true);
 $linea = sprintf("[%s] %s · %s · %s%s\n",
 date('Y-m-d H:i:s'), $resultado, $destino, $asunto,
 $extra ? ' :: '. $extra : '');
 @file_put_contents($dir. '/correos.log', $linea, FILE_APPEND);
 }
}
