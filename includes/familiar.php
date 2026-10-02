<?php
/**
 * Motor de vista familiar
 *
 * El familiar tiene una vista simplificada (solo lectura):
 * · Tomas del día del paciente
 * · Porcentaje de cumplimiento de la semana
 * · Próximas 3 tomas programadas
 * Si el cumplimiento semanal baja del 70%, el familiar recibe una alerta
 * automática (app + correo + SMS), una sola vez mientras siga bajo.
 * El paciente puede revocar el acceso del familiar en cualquier momento.
 */

require_once __DIR__. '/db.php';
require_once __DIR__. '/adherencia.php';
require_once __DIR__. '/notificaciones.php';

class Familiar
{
 /**
 * Relación activa familiar→paciente. Devuelve null si no existe o fue
 * revocada (el familiar deja de ver los datos al instante).
 */
 public static function relacionActiva(int $idFamiliar, int $idPaciente): ?array
 {
 return DB::uno(
 "SELECT fp.*, p.nombre_completo AS paciente_nombre
 FROM familiar_paciente fp
 JOIN usuarios p ON p.id_usuario = fp.id_paciente
 WHERE fp.id_familiar = :f AND fp.id_paciente = :p
 AND fp.estado = 'activa'",
 ['f' => $idFamiliar, 'p' => $idPaciente]
 );
 }

 /** Pacientes a los que un familiar tiene acceso activo. */
 public static function pacientesDe(int $idFamiliar): array
 {
 return DB::todos(
 "SELECT fp.id_relacion, fp.parentesco, p.id_usuario, p.nombre_completo
 FROM familiar_paciente fp
 JOIN usuarios p ON p.id_usuario = fp.id_paciente
 WHERE fp.id_familiar = :f AND fp.estado = 'activa'
 ORDER BY p.nombre_completo",
 ['f' => $idFamiliar]
 );
 }

 /**
 * Resumen simplificado que ve el familiar de un paciente.
 */
 public static function resumenPaciente(int $idPaciente): array
 {
 $hoy = date('Y-m-d');

 // Tomas del día
 $tomasHoy = DB::todos(
 "SELECT m.nombre AS medicamento, rt.hora_programada, rt.estado,
 rt.motivo_omision_codigo
 FROM registro_tomas rt
 JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE rt.id_usuario = :p AND rt.fecha_programada = :h
 ORDER BY rt.hora_programada",
 ['p' => $idPaciente, 'h' => $hoy]
 );

 // Cumplimiento de la semana en curso
 [$ini, $fin] = Adherencia::rangoSemana(0);
 $resumen = Adherencia::resumen($idPaciente, $ini, $fin);

 // Próximas 3 tomas (futuras, pendientes) con su fecha para contexto
 $proximas = DB::todos(
 "SELECT m.nombre AS medicamento, rt.fecha_programada, rt.hora_programada
 FROM registro_tomas rt
 JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE rt.id_usuario = :p AND rt.estado = 'pendiente'
 AND TIMESTAMP(rt.fecha_programada, rt.hora_programada) >= NOW()
 ORDER BY rt.fecha_programada, rt.hora_programada
 LIMIT 3",
 ['p' => $idPaciente]
 );

 return [
 'tomas_hoy' => $tomasHoy,
 'semana_desde' => $ini,
 'semana_hasta' => $fin,
 'hay_datos' => $resumen['hay_datos'],
 'porcentaje' => $resumen['porcentaje'],
 'mensaje_sin_datos'=> $resumen['mensaje_sin_datos'],
 'bajo_umbral' => $resumen['hay_datos']
 && $resumen['porcentaje'] < Adherencia::UMBRAL_CUMPLIMIENTO,
 'proximas' => $proximas,
 ];
 }

 /**
 * Revisa el cumplimiento del paciente y, si bajó del 70%, dispara la
 * alerta automática a los familiares activos (una sola vez por caída).
 * Se llama desde el cron de notificaciones tras actualizar tomas.
 */
 public static function revisarUmbralYNotificar(int $idPaciente): int
 {
 [$ini, $fin] = Adherencia::rangoSemana(0);
 $r = Adherencia::resumen($idPaciente, $ini, $fin);
 if (!$r['hay_datos']) return 0;

 $bajo = $r['porcentaje'] < Adherencia::UMBRAL_CUMPLIMIENTO;

 $familiares = DB::todos(
 "SELECT fp.id_relacion, fp.id_familiar, fp.ultimo_aviso_bajo_70,
 u.nombre_completo, u.correo, u.telefono,
 p.nombre_completo AS paciente_nombre
 FROM familiar_paciente fp
 JOIN usuarios u ON u.id_usuario = fp.id_familiar
 JOIN usuarios p ON p.id_usuario = fp.id_paciente
 WHERE fp.id_paciente = :p AND fp.estado = 'activa'
 AND fp.alerta_70_activa = TRUE",
 ['p' => $idPaciente]
 );

 $enviados = 0;
 foreach ($familiares as $f) {
 if (!$bajo) {
 // Si subió de nuevo, se "rearma" el aviso para una próxima caída
 if ($f['ultimo_aviso_bajo_70'] !== null) {
 DB::consultar(
 "UPDATE familiar_paciente SET ultimo_aviso_bajo_70 = NULL
 WHERE id_relacion = :r", ['r' => $f['id_relacion']]);
 }
 continue;
 }
 // Ya está bajo: solo avisar si no se ha avisado en esta caída
 if ($f['ultimo_aviso_bajo_70'] !== null) continue;

 $titulo = '👥 Alerta: cumplimiento bajo de '. $f['paciente_nombre'];
 $mensaje = "El cumplimiento semanal de {$f['paciente_nombre']} bajó a ". round($r['porcentaje']). "%. Te sugerimos contactarlo para ". "apoyarlo con su tratamiento.";

 // App
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio)
 VALUES (:u,'familiar_bajo_70','app',:t,:m,'enviada',NOW(),NOW())",
 ['u' => $f['id_familiar'], 't' => $titulo, 'm' => $mensaje]
 );

 require_once __DIR__. '/mailer.php';
 require_once __DIR__. '/twilio.php';

 if (!empty($f['correo'])) {
 $ok = Mailer::enviarAlertaFamiliar(
 $f['correo'], $f['nombre_completo'],
 $f['paciente_nombre'], round($r['porcentaje'])
 );
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio)
 VALUES (:u,'familiar_bajo_70','correo',:t,:m,:e,NOW(),NOW())",
 ['u' => $f['id_familiar'], 't' => $titulo, 'm' => $mensaje,
 'e' => $ok ? 'enviada' : 'fallida']
 );
 }
 if (!empty($f['telefono'])) {
 $cuerpo = 'MedAlert: El cumplimiento de '. $f['paciente_nombre']. ' bajo a '. round($r['porcentaje']). '%. Apoyalo con su tratamiento.';
 $sms = Twilio::enviar($f['telefono'], mb_substr($cuerpo, 0, 160));
 DB::consultar(
 "INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje,
 estado_envio, fecha_programada, fecha_envio, proveedor_sid)
 VALUES (:u,'familiar_bajo_70','sms',:t,:m,:e,NOW(),NOW(),:s)",
 ['u' => $f['id_familiar'], 't' => $titulo, 'm' => $mensaje,
 'e' => $sms['ok'] ? 'enviada' : 'fallida', 's' => $sms['sid'] ?? null]
 );
 }

 DB::consultar(
 "UPDATE familiar_paciente SET ultimo_aviso_bajo_70 = NOW()
 WHERE id_relacion = :r", ['r' => $f['id_relacion']]);
 $enviados++;
 }
 return $enviados;
 }

 /**
 * El paciente invita a un familiar por correo.
 */
 public static function invitar(int $idPaciente, string $correoFamiliar,
 string $parentesco = ''): array
 {
 $correoFamiliar = trim(mb_strtolower($correoFamiliar));
 if (!filter_var($correoFamiliar, FILTER_VALIDATE_EMAIL)) {
 return ['error' => 'El correo del familiar no es válido.'];
 }
 $fam = DB::uno(
 "SELECT u.id_usuario, u.nombre_completo, t.nombre AS tipo
 FROM usuarios u JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE u.correo = :c",
 ['c' => $correoFamiliar]
 );
 if (!$fam) {
 return ['error' => 'No existe una cuenta con ese correo. Pídele a tu '. 'familiar que se registre primero como Cuidador.'];
 }
 if ((int)$fam['id_usuario'] === $idPaciente) {
 return ['error' => 'No puedes invitarte a ti mismo.'];
 }

 $existe = DB::uno(
 "SELECT id_relacion, estado FROM familiar_paciente
 WHERE id_familiar = :f AND id_paciente = :p",
 ['f' => $fam['id_usuario'], 'p' => $idPaciente]
 );
 if ($existe && $existe['estado'] === 'activa') {
 return ['error' => 'Ese familiar ya tiene acceso a tu información.'];
 }

 $token = bin2hex(random_bytes(16));
 if ($existe) {
 DB::consultar(
 "UPDATE familiar_paciente
 SET estado='activa', parentesco=:pa, token_invitacion=:tk,
 fecha_invitacion=NOW(), fecha_aceptacion=NOW(),
 fecha_revocacion=NULL, ultimo_aviso_bajo_70=NULL
 WHERE id_relacion=:r",
 ['pa' => $parentesco ?: null, 'tk' => $token,
 'r' => $existe['id_relacion']]
 );
 } else {
 DB::consultar(
 "INSERT INTO familiar_paciente
 (id_familiar, id_paciente, parentesco, invitado_por,
 token_invitacion, estado, fecha_aceptacion)
 VALUES (:f,:p,:pa,:p,:tk,'activa',NOW())",
 ['f' => $fam['id_usuario'], 'p' => $idPaciente,
 'pa' => $parentesco ?: null, 'tk' => $token]
 );
 }

 // Aviso al familiar de que fue vinculado
 $pac = DB::uno("SELECT nombre_completo, correo FROM usuarios WHERE id_usuario=:p",
 ['p' => $idPaciente]);
 Notificaciones::crearGenerica(
 (int)$fam['id_usuario'],
 '👥 Ahora puedes seguir el tratamiento de '. ($pac['nombre_completo'] ?? ''),
 ($pac['nombre_completo'] ?? 'Un paciente'). ' te dio acceso para ver '. 'el resumen de su medicación. Entra a "Vista familiar" para verlo.',
 'generico'
 );

 return ['ok' => true, 'familiar' => $fam['nombre_completo']];
 }

 /** El paciente revoca el acceso de un familiar (efecto inmediato). */
 public static function revocar(int $idPaciente, int $idRelacion): array
 {
 $rel = DB::uno(
 "SELECT * FROM familiar_paciente
 WHERE id_relacion = :r AND id_paciente = :p",
 ['r' => $idRelacion, 'p' => $idPaciente]
 );
 if (!$rel) return ['error' => 'Relación no encontrada.'];

 DB::consultar(
 "UPDATE familiar_paciente
 SET estado='revocada', fecha_revocacion=NOW()
 WHERE id_relacion=:r", ['r' => $idRelacion]
 );
 return ['ok' => true];
 }

 /** Familiares con acceso a un paciente (para gestionar desde el perfil). */
 public static function familiaresDe(int $idPaciente): array
 {
 return DB::todos(
 "SELECT fp.id_relacion, fp.parentesco, fp.estado, fp.fecha_aceptacion,
 u.nombre_completo, u.correo
 FROM familiar_paciente fp
 JOIN usuarios u ON u.id_usuario = fp.id_familiar
 WHERE fp.id_paciente = :p AND fp.estado = 'activa'
 ORDER BY u.nombre_completo",
 ['p' => $idPaciente]
 );
 }
}
