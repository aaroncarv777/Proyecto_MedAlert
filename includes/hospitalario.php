<?php
/**
 * Motor del modo hospitalario
 *
 * Solo para usuarios con rol Enfermero. Muestra todos los pacientes del
 * turno con sus medicamentos pendientes del día. El enfermero puede marcar
 * varias tomas de distintos pacientes en una sola acción (transacción
 * atómica). Cada toma queda registrada con el nombre del enfermero.
 */

require_once __DIR__. '/db.php';

class Hospitalario
{
 /**
 * Tomas pendientes de hoy de todos los pacientes del turno del enfermero,
 * agrupadas por paciente.
 */
 public static function tablaTurno(int $idEnfermero): array
 {
 $filas = DB::todos(
 "SELECT p.id_usuario AS id_paciente, p.nombre_completo AS paciente,
 rt.id_registro, rt.hora_programada, rt.estado,
 m.nombre AS medicamento, m.presentacion,
 CONCAT(
 CASE WHEN m.cantidad_dosis = FLOOR(m.cantidad_dosis)
 THEN CAST(m.cantidad_dosis AS UNSIGNED)
 ELSE m.cantidad_dosis END,
 ' ', m.unidad_dosis) AS dosis
 FROM turno_enfermeria te
 JOIN usuarios p ON p.id_usuario = te.id_paciente
 JOIN registro_tomas rt ON rt.id_usuario = p.id_usuario
 AND rt.fecha_programada = CURDATE()
 AND rt.estado = 'pendiente'
 JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE te.id_enfermero = :e AND te.activo = TRUE
 ORDER BY p.nombre_completo, rt.hora_programada",
 ['e' => $idEnfermero]
 );

 $porPaciente = [];
 foreach ($filas as $f) {
 $idp = (int)$f['id_paciente'];
 if (!isset($porPaciente[$idp])) {
 $porPaciente[$idp] = [
 'id_paciente' => $idp,
 'paciente' => $f['paciente'],
 'tomas' => [],
 ];
 }
 $porPaciente[$idp]['tomas'][] = [
 'id_registro' => (int)$f['id_registro'],
 'medicamento' => $f['medicamento'],
 'presentacion' => $f['presentacion'],
 'dosis' => $f['dosis'],
 'hora_programada' => $f['hora_programada'],
 ];
 }
 return array_values($porPaciente);
 }

 /**
 * Marca varias tomas como cumplidas en una sola transacción atómica,
 * registrando el enfermero que las administró. Si una falla, se revierte
 * toda la operación.
 * @return array ['ok'=>true,'marcadas'=>n] | ['error'=>...]
 */
 public static function registrarMasivo(int $idEnfermero, array $idsRegistro): array
 {
 $ids = array_values(array_unique(array_filter(
 array_map('intval', $idsRegistro), fn($v) => $v > 0
 )));
 if (empty($ids)) {
 return ['error' => 'No seleccionaste ninguna toma para registrar.'];
 }

 // Validar que todas las tomas pertenezcan a pacientes del turno del enfermero
 $place = implode(',', array_fill(0, count($ids), '?'));
 $validas = DB::todos(
 "SELECT rt.id_registro, rt.id_medicamento, rt.estado
 FROM registro_tomas rt
 JOIN turno_enfermeria te ON te.id_paciente = rt.id_usuario
 WHERE te.id_enfermero = ? AND te.activo = TRUE
 AND rt.id_registro IN ($place)",
 array_merge([$idEnfermero], $ids)
 );
 $idsValidos = array_column($validas, 'id_registro');
 $faltan = array_diff($ids, array_map('intval', $idsValidos));
 if (!empty($faltan)) {
 return ['error' => 'Algunas tomas no pertenecen a pacientes de tu turno.'];
 }
 foreach ($validas as $v) {
 if ($v['estado'] !== 'pendiente') {
 return ['error' => 'Una de las tomas seleccionadas ya estaba registrada. '. 'Recarga la pantalla e inténtalo de nuevo.'];
 }
 }

 $pdo = DB::obtener();
 $pdo->beginTransaction();
 try {
 $marcadas = 0;
 foreach ($validas as $v) {
 DB::consultar(
 "UPDATE registro_tomas
 SET estado='cumplida',
 fecha_real_toma=NOW(),
 diferencia_minutos=
 TIMESTAMPDIFF(MINUTE,
 TIMESTAMP(fecha_programada,hora_programada), NOW()),
 fecha_marcado=NOW(),
 id_enfermero_administra=:enf
 WHERE id_registro=:r AND estado='pendiente'",
 ['enf' => $idEnfermero, 'r' => $v['id_registro']]
 );
 // Descontar stock igual que una toma normal
 require_once __DIR__. '/stock.php';
 Stock::descontarPorToma((int)$v['id_medicamento']);
 $marcadas++;
 }
 $pdo->commit();
 } catch (Throwable $e) {
 $pdo->rollBack();
 return ['error' => 'No se pudo completar el registro. No se marcó '. 'ninguna toma (la operación se revirtió completa).'];
 }

 // Cancelar avisos pendientes de esas tomas (ya están administradas)
 require_once __DIR__. '/notificaciones.php';
 foreach ($ids as $r) {
 Notificaciones::cancelarParaToma($r, 'administrada por enfermero');
 }

 return ['ok' => true, 'marcadas' => $marcadas];
 }

 /** ¿El usuario tiene rol Enfermero? (control de acceso ) */
 public static function esEnfermero(array $usuario): bool
 {
 return ($usuario['tipo_nombre'] ?? '') === 'Enfermero';
 }
}
