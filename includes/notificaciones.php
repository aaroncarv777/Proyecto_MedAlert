<?php
/**
 * Motor de notificaciones
 * Implementa (multicanal) y (avisos escalonados)
 *
 * Genera, envía y cancela notificaciones automáticas asociadas a tomas de medicamento.
 * Soporta tres canales: app, correo, sms. Y tres tipos de aviso: temprano (15 min antes),
 * recordatorio (5 min antes) y tardío (10 min después).
 */

require_once __DIR__. '/db.php';
require_once __DIR__. '/mailer.php';
require_once __DIR__. '/twilio.php';
require_once __DIR__. '/helpers.php';

class Notificaciones
{
 /**
 * Genera las notificaciones programadas para un registro de toma específico.
 * Se llama al crear medicamento o al generar las tomas del día.
 */
 public static function programarParaToma(int $idRegistroToma): int
 {
 $reg = DB::uno(
 "SELECT rt.*, m.id_usuario_paciente, m.nombre AS medicamento_nombre,
 CONCAT(m.cantidad_dosis, ' ', m.unidad_dosis) AS dosis,
 m.id_medicamento, u.canal_app_activo, u.canal_correo_activo, u.canal_sms_activo,
 u.aviso_temprano_activo, u.aviso_recordatorio_activo, u.aviso_tardio_activo,
 u.correo, u.telefono,
 pn.canal_app AS pref_app,
 pn.canal_correo AS pref_correo,
 pn.canal_sms AS pref_sms
 FROM registro_tomas rt
 INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 INNER JOIN usuarios u ON u.id_usuario = rt.id_usuario
 LEFT JOIN preferencias_notificacion_medicamento pn ON pn.id_medicamento = m.id_medicamento
 WHERE rt.id_registro = :id",
 ['id' => $idRegistroToma]
 );
 if (!$reg) return 0;

 // Sólo programamos para tomas pendientes (no cumplidas/no_cumplidas)
 if ($reg['estado'] !== 'pendiente') return 0;

 $base = $reg['fecha_programada']. ' '. $reg['hora_programada'];
 $tsBase = strtotime($base);
 if ($tsBase === false) return 0;

 // No programar si la hora ya pasó hace mucho
 if ($tsBase < time() - 3600) return 0;

 $tipos = [
 'temprano' => [
 'activo' => $reg['aviso_temprano_activo'],
 'fecha' => date('Y-m-d H:i:s', $tsBase - AVISO_MINUTOS_TEMPRANO * 60),
 'titulo' => '⏰ Tu medicamento es en '. AVISO_MINUTOS_TEMPRANO. ' minutos',
 ],
 'recordatorio' => [
 'activo' => $reg['aviso_recordatorio_activo'],
 'fecha' => date('Y-m-d H:i:s', $tsBase - AVISO_MINUTOS_RECORDATORIO * 60),
 'titulo' => '🔔 Tu medicamento es en '. AVISO_MINUTOS_RECORDATORIO. ' minutos',
 ],
 'tardio' => [
 'activo' => $reg['aviso_tardio_activo'],
 'fecha' => date('Y-m-d H:i:s', $tsBase + AVISO_MINUTOS_TARDIO * 60),
 'titulo' => '⚠️ Aún no registras tu medicamento',
 ],
 ];

 $canales = [
 'app' => ($reg['canal_app_activo'] && ($reg['pref_app'] ?? 1)),
 'correo' => ($reg['canal_correo_activo'] && ($reg['pref_correo'] ?? 1)),
 'sms' => ($reg['canal_sms_activo'] && ($reg['pref_sms'] ?? 0)),
 ];

 $programadas = 0;
 foreach ($tipos as $tipo => $cfg) {
 if (!$cfg['activo']) continue;
 foreach ($canales as $canal => $habilitado) {
 if (!$habilitado) continue;

 $mensaje = self::componerMensaje($tipo, $reg);
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, id_registro_toma, id_medicamento, tipo_aviso, canal,
 titulo, mensaje, fecha_programada)
 VALUES (:u, :reg, :m, :tipo, :canal, :titulo, :mensaje, :fecha)",
 [
 'u' => $reg['id_usuario_paciente'],
 'reg' => $idRegistroToma,
 'm' => $reg['id_medicamento'],
 'tipo' => $tipo,
 'canal' => $canal,
 'titulo' => mb_substr($cfg['titulo'], 0, NOTIFICACION_TITULO_MAX),
 'mensaje' => mb_substr($mensaje, 0, NOTIFICACION_MENSAJE_MAX),
 'fecha' => $cfg['fecha'],
 ]
 );
 $programadas++;
 }
 }
 return $programadas;
 }

 /**
 * Cancela los avisos pendientes asociados a una toma cuando ésta ya fue marcada.
 * Esto evita que después de marcar la toma le sigan llegando recordatorios al paciente.
 */
 public static function cancelarParaToma(int $idRegistroToma, string $motivo = 'toma_registrada'): int
 {
 $r = DB::consultar(
 "UPDATE notificaciones
 SET estado_envio = 'cancelada',
 error_envio = :motivo
 WHERE id_registro_toma = :id
 AND estado_envio = 'pendiente'",
 ['id' => $idRegistroToma, 'motivo' => $motivo]
 );
 return $r->rowCount();
 }

 /**
 * Reactiva (vuelve a programar) avisos cancelados cuando se deshace una toma.
 */
 public static function reactivarParaToma(int $idRegistroToma): int
 {
 // Borramos las viejas y volvemos a generar (más limpio que recalcular)
 DB::consultar(
 "DELETE FROM notificaciones
 WHERE id_registro_toma = :id
 AND estado_envio IN ('pendiente','cancelada')",
 ['id' => $idRegistroToma]
 );
 return self::programarParaToma($idRegistroToma);
 }

 /**
 * Procesador a llamarse vía cron / al cargar el dashboard.
 * Envía las notificaciones cuya fecha_programada ya pasó.
 */
 public static function procesarPendientes(): array
    {
 $pendientes = DB::todos(
 "SELECT n.*, u.correo, u.telefono, u.nombre_completo
 FROM notificaciones n
 INNER JOIN usuarios u ON u.id_usuario = n.id_usuario
 WHERE n.estado_envio = 'pendiente'
 AND n.fecha_programada <= NOW()
 ORDER BY n.fecha_programada
 LIMIT ". NOTIFICACIONES_LOTE_MAX
 );

 $resumen = ['enviadas' => 0, 'fallidas' => 0, 'app' => 0, 'correo' => 0, 'sms' => 0];

 foreach ($pendientes as $n) {
 $exito = false;
 $error = null;
 $sid = null;

 try {
 if ($n['canal'] === 'app') {
 // Las notificaciones in-app sólo se marcan como "enviadas".
 // El cliente las leerá al abrir el dashboard / la campanita.
 $exito = true;
 } elseif ($n['canal'] === 'correo') {
 $tipoAviso = (string)($n['tipo_aviso'] ?? 'recordatorio');
 $exito = Mailer::enviarNotificacionMedicamento(
 $n['correo'], $n['nombre_completo'],
 $n['titulo'], $n['mensaje'],
 $tipoAviso
 );
 if (!$exito) $error = 'Falló envío SMTP';
 } elseif ($n['canal'] === 'sms') {
 // — envío real con Twilio.
 // El equipo (Patricio) integró este cliente el viernes 8/Mayo.
 if (empty($n['telefono'])) {
 $error = 'El usuario no tiene teléfono registrado';
 $exito = false;
 } else {
 $cuerpoSMS = 'MedAlert: '. self::truncarSMS(
 ($n['titulo'] ?? ''). '. '. ($n['mensaje'] ?? '')
 );
 $r = Twilio::enviar($n['telefono'], $cuerpoSMS);
 $exito = $r['ok'];
 $sid = $r['sid'];
 $error = $r['error'];
 }
 }
 } catch (Throwable $e) {
 $error = $e->getMessage();
 $exito = false;
 }

 DB::consultar(
 "UPDATE notificaciones
 SET estado_envio = :estado,
 fecha_envio = NOW(),
 error_envio = :err,
 proveedor_sid = :sid
 WHERE id_notificacion = :id",
 [
 'estado' => $exito ? 'enviada' : 'fallida',
 'err' => $error,
 'sid' => $sid,
 'id' => $n['id_notificacion'],
 ]
 );

 if ($exito) {
 $resumen['enviadas']++;
 $resumen[$n['canal']]++;
 } else {
 $resumen['fallidas']++;
 }
 }

 // revisar el cumplimiento semanal de los pacientes que tienen
 // familiares con alerta activa, y notificar si bajaron del umbral.
 // Se ejecuta aquí (en el cron) además de al marcar cada toma, para
 // cubrir las caídas por tomas que vencen sin marcarse.
 require_once __DIR__. '/familiar.php';
 $pacientesConFamiliar = DB::todos(
 "SELECT DISTINCT id_paciente FROM familiar_paciente
 WHERE estado = 'activa' AND alerta_70_activa = TRUE"
 );
 $resumen['familiar_alertas'] = 0;
 foreach ($pacientesConFamiliar as $pf) {
 $resumen['familiar_alertas'] +=
 Familiar::revisarUmbralYNotificar((int)$pf['id_paciente']);
 }

 return $resumen;
 }

 private static function truncarSMS(string $texto): string
 {
 $texto = preg_replace('/\s+/', ' ', trim($texto));
 return mb_substr($texto, 0, TWILIO_SMS_MAX_CARACTERES - 10);
 }

 /**
 * Crea una notificación in-app suelta (no asociada a un horario de toma).
 * Útil para (validación de cédula), (interacción), (stock).
 */
 public static function crearGenerica(int $idUsuario, string $titulo, string $mensaje,
 string $tipo = 'generico',
 ?int $idMedicamento = null): int
 {
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, id_medicamento, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada)
 VALUES (:u, :m, :tipo, 'app', :titulo, :mensaje, 'enviada', NOW())",
 [
 'u' => $idUsuario,
 'm' => $idMedicamento,
 'tipo' => $tipo,
 'titulo' => mb_substr($titulo, 0, NOTIFICACION_TITULO_MAX),
 'mensaje' => mb_substr($mensaje, 0, NOTIFICACION_MENSAJE_MAX),
 ]
 );
 return DB::ultimoId();
 }

 /**
 * Compone el cuerpo del mensaje del aviso según el tipo.
 */
 private static function componerMensaje(string $tipo, array $reg): string
 {
 $hora = date('g:i A', strtotime($reg['fecha_programada']. ' '. $reg['hora_programada']));
 $base = sprintf("Medicamento: %s\nDosis: %s\nHora programada: %s",
 $reg['medicamento_nombre'], $reg['dosis'], $hora);

 switch ($tipo) {
 case 'temprano':
 return $base. "\n\nFaltan ". AVISO_MINUTOS_TEMPRANO. " minutos para tu próxima toma. Prepara tu medicamento.";
 case 'recordatorio':
 return $base. "\n\nFaltan sólo ". AVISO_MINUTOS_RECORDATORIO. " minutos. Es momento de preparar tu medicamento.";
 case 'tardio':
 return $base. "\n\nHan pasado ". AVISO_MINUTOS_TARDIO. " minutos desde la hora programada y aún no has registrado esta toma. ". "Marca como cumplida o no cumplida en la app cuanto antes.";
 }
 return $base;
 }

 /**
 * Devuelve el listado de notificaciones in-app no leídas para mostrar en la campanita.
 */
 public static function pendientesUsuario(int $idUsuario, int $limite = 20): array
 {
 return DB::todos(
 "SELECT id_notificacion, tipo_aviso, titulo, mensaje, leida, fecha_envio
 FROM notificaciones
 WHERE id_usuario = :u
 AND canal = 'app'
 AND estado_envio = 'enviada'
 ORDER BY fecha_envio DESC
 LIMIT $limite",
 ['u' => $idUsuario]
 );
 }

 public static function contarNoLeidas(int $idUsuario): int
 {
 $r = DB::uno(
 "SELECT COUNT(*) AS n FROM notificaciones
 WHERE id_usuario = :u AND canal = 'app'
 AND estado_envio = 'enviada' AND leida = FALSE",
 ['u' => $idUsuario]
 );
 return (int)($r['n'] ?? 0);
 }

 public static function marcarLeida(int $idNotificacion, int $idUsuario): bool
 {
 $r = DB::consultar(
 "UPDATE notificaciones SET leida = TRUE, fecha_leida = NOW()
 WHERE id_notificacion = :id AND id_usuario = :u",
 ['id' => $idNotificacion, 'u' => $idUsuario]
 );
 return $r->rowCount() > 0;
 }

 public static function marcarTodasLeidas(int $idUsuario): int
 {
 $r = DB::consultar(
 "UPDATE notificaciones SET leida = TRUE, fecha_leida = NOW()
 WHERE id_usuario = :u AND canal = 'app' AND leida = FALSE",
 ['u' => $idUsuario]
 );
 return $r->rowCount();
 }
}
