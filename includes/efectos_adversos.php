<?php
/**
 * Motor de efectos adversos
 *
 * Tras confirmar una toma, el paciente puede reportar (opcionalmente) un
 * efecto adverso. Si reporta una REACCIÓN ALÉRGICA, el médico asignado
 * recibe un aviso urgente de inmediato por los tres canales (app, correo,
 * SMS), fuera del cron regular de notificaciones, con formato diferenciado.
 */

require_once __DIR__. '/db.php';
require_once __DIR__. '/notificaciones.php';

class EfectosAdversos
{
 const EFECTOS_VALIDOS = [
 'nausea', 'mareo', 'dolor_cabeza', 'somnolencia',
 'malestar_estomacal', 'reaccion_alergica', 'otro'
 ];

 /**
 * Registra un efecto adverso para una toma ya marcada como cumplida.
 * @return array ['ok'=>true,'urgente'=>bool] | ['error'=>...]
 */
 public static function reportar(int $idPaciente, int $idRegistroToma,
 string $efecto, string $descripcion = ''): array
 {
 if (!in_array($efecto, self::EFECTOS_VALIDOS, true)) {
 return ['error' => 'El efecto adverso seleccionado no es válido.'];
 }
 $descripcion = trim($descripcion);
 if ($efecto === 'otro' && mb_strlen($descripcion) < 5) {
 return ['error' => 'Si eliges "Otro", describe el efecto con al menos 5 caracteres.'];
 }
 if (mb_strlen($descripcion) > 255) {
 return ['error' => 'La descripción no puede superar 255 caracteres.'];
 }

 // La toma debe existir, ser del paciente y estar cumplida
 $toma = DB::uno(
 "SELECT rt.id_registro, rt.id_medicamento, m.nombre AS medicamento,
 m.id_usuario_paciente
 FROM registro_tomas rt
 JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE rt.id_registro = :r",
 ['r' => $idRegistroToma]
 );
 if (!$toma) return ['error' => 'La toma indicada no existe.'];
 if ((int)$toma['id_usuario_paciente'] !== $idPaciente) {
 return ['error' => 'No puedes reportar sobre una toma que no es tuya.'];
 }

 $esAlergia = ($efecto === 'reaccion_alergica');

 DB::consultar(
 "INSERT INTO efectos_adversos
 (id_registro_toma, id_medicamento, id_paciente, efecto,
 descripcion, es_reaccion_alergica)
 VALUES (:r,:m,:p,:e,:d,:al)",
 ['r' => $idRegistroToma, 'm' => $toma['id_medicamento'],
 'p' => $idPaciente, 'e' => $efecto,
 'd' => $descripcion ?: null, 'al' => $esAlergia ? 1 : 0]
 );
 $idEfecto = DB::ultimoId();

 if ($esAlergia) {
 self::avisoUrgente($idEfecto, $idPaciente, $toma['medicamento'], $descripcion);
 return ['ok' => true, 'urgente' => true];
 }
 return ['ok' => true, 'urgente' => false];
 }

 /**
 * Canal prioritario fuera del cron regular: notifica de inmediato al
 * médico asignado por app, correo y SMS, con formato diferenciado.
 */
 private static function avisoUrgente(int $idEfecto, int $idPaciente,
 string $medicamento, string $desc): void
 {
 $paciente = DB::uno(
 "SELECT nombre_completo FROM usuarios WHERE id_usuario=:p",
 ['p' => $idPaciente]
 );
 $pacNombre = $paciente['nombre_completo'] ?? 'El paciente';

 // Médicos con relación activa sobre este paciente
 $medicos = DB::todos(
 "SELECT u.id_usuario, u.nombre_completo, u.correo, u.telefono
 FROM medico_paciente mp
 JOIN usuarios u ON u.id_usuario = mp.id_medico
 WHERE mp.id_paciente = :p AND mp.activa = TRUE",
 ['p' => $idPaciente]
 );

 $titulo = '🚨 URGENTE: Reacción alérgica de '. $pacNombre;
 $mensaje = "El paciente {$pacNombre} reportó una REACCIÓN ALÉRGICA ". "tras tomar {$medicamento}.";
 if ($desc !== '') $mensaje.= " Descripción: {$desc}.";
 $mensaje.= " Requiere atención inmediata.";

 require_once __DIR__. '/mailer.php';
 require_once __DIR__. '/twilio.php';

 foreach ($medicos as $m) {
 // 1. App (inmediata, marcada como enviada)
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio)
 VALUES (:u,'reaccion_alergica_urgente','app',:t,:msg,
 'enviada', NOW(), NOW())",
 ['u' => $m['id_usuario'], 't' => $titulo, 'msg' => $mensaje]
 );

 // 2. Correo inmediato con formato urgente
 if (!empty($m['correo'])) {
 $okCorreo = Mailer::enviarAvisoUrgenteAlergia(
 $m['correo'], $m['nombre_completo'], $pacNombre,
 $medicamento, $desc
 );
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio)
 VALUES (:u,'reaccion_alergica_urgente','correo',:t,:msg,
 :est, NOW(), NOW())",
 ['u' => $m['id_usuario'], 't' => $titulo, 'msg' => $mensaje,
 'est' => $okCorreo ? 'enviada' : 'fallida']
 );
 }

 // 3. SMS inmediato
 if (!empty($m['telefono'])) {
 $cuerpo = 'MedAlert URGENTE: '. mb_substr(
 $pacNombre. ' reporto reaccion alergica con '. $medicamento. '. Atencion inmediata.', 0, TWILIO_SMS_MAX_CARACTERES - 18);
 $r = Twilio::enviar($m['telefono'], $cuerpo);
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio, proveedor_sid)
 VALUES (:u,'reaccion_alergica_urgente','sms',:t,:msg,
 :est, NOW(), NOW(), :sid)",
 ['u' => $m['id_usuario'], 't' => $titulo, 'msg' => $mensaje,
 'est' => $r['ok'] ? 'enviada' : 'fallida',
 'sid' => $r['sid'] ?? null]
 );
 }
 }

 DB::consultar(
 "UPDATE efectos_adversos SET aviso_urgente_enviado=TRUE
 WHERE id_efecto=:id", ['id' => $idEfecto]
 );
 }

 /** Historial de efectos adversos de un paciente (para el panel del médico). */
 public static function historialPaciente(int $idPaciente): array
 {
 return DB::todos(
 "SELECT ea.*, m.nombre AS medicamento_nombre,
 rt.fecha_programada, rt.hora_programada
 FROM efectos_adversos ea
 JOIN medicamentos m ON m.id_medicamento = ea.id_medicamento
 JOIN registro_tomas rt ON rt.id_registro = ea.id_registro_toma
 WHERE ea.id_paciente = :p
 ORDER BY ea.fecha_reporte DESC",
 ['p' => $idPaciente]
 );
 }
}
