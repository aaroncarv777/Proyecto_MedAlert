<?php
/**
 * Motor de prescripciones digitales
 *
 * El médico crea una prescripción desde su panel. El sistema:
 * 1. Registra la prescripción
 * 2. Crea automáticamente el medicamento en la lista del paciente
 * 3. Genera los horarios y las tomas pendientes
 * 4. Marca el medicamento como originado por prescripción (no editable
 * por el paciente)
 * 5. Notifica al paciente
 *
 * El médico puede modificar o cancelar la prescripción en cualquier momento.
 */

require_once __DIR__. '/db.php';
require_once __DIR__. '/notificaciones.php';

class Prescripciones
{
 /**
 * Crea una prescripción digital y la convierte en medicamento + recordatorios.
 * @return array ['ok'=>bool, 'id_prescripcion'=>int, 'id_medicamento'=>int] | ['error'=>...]
 */
 public static function crear(int $idMedico, int $idPaciente, array $d): array
 {
 $nombre = trim($d['nombre_medicamento'] ?? '');
 if ($nombre === '' || mb_strlen($nombre) > NOMBRE_MEDICAMENTO_MAX) {
 return ['error' => 'El nombre del medicamento es obligatorio (máx. '. NOMBRE_MEDICAMENTO_MAX. ' caracteres).'];
 }

 $presentaciones = ['Tableta','Tableta masticable','Cápsula','Jarabe',
 'Inyectable','Gotas','Crema','Inhalador','Otro'];
 $unidades = ['mg','g','mcg','ml','tableta','cápsula','gota','inhalación','aplicación'];

 $presentacion = in_array($d['presentacion'] ?? '', $presentaciones, true)
 ? $d['presentacion'] : 'Tableta';
 $unidad = in_array($d['unidad_dosis'] ?? '', $unidades, true)
 ? $d['unidad_dosis'] : 'mg';

 $cantidad = (float)($d['cantidad_dosis'] ?? 0);
 $frecuencia = (float)($d['frecuencia_horas'] ?? 0);
 $horaInicio = $d['hora_inicio'] ?? '08:00';
 $fechaInicio= $d['fecha_inicio'] ?? date('Y-m-d');
 $duracion = isset($d['duracion_dias']) && $d['duracion_dias'] !== ''
 ? (int)$d['duracion_dias'] : null;
 $instrucciones = trim($d['instrucciones'] ?? '');

 if ($cantidad <= 0) {
 return ['error' => 'La cantidad de dosis debe ser mayor a 0.'];
 }
 if ($frecuencia < 1 || $frecuencia > 168) {
 return ['error' => 'La frecuencia debe estar entre 1 y 168 horas.'];
 }
 if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $horaInicio)) {
 return ['error' => 'La hora de inicio no es válida (formato HH:MM).'];
 }
 if (!self::fechaValida($fechaInicio)) {
 return ['error' => 'La fecha de inicio no es válida.'];
 }
 if ($duracion !== null && ($duracion < 1 || $duracion > 365)) {
 return ['error' => 'La duración debe estar entre 1 y 365 días.'];
 }
 if (mb_strlen($instrucciones) > INSTRUCCION_MAX_CARACTERES) {
 return ['error' => 'Las instrucciones no pueden superar '. INSTRUCCION_MAX_CARACTERES. ' caracteres.'];
 }

 // El médico debe tener al paciente asignado
 $rel = DB::uno(
 "SELECT 1 FROM medico_paciente
 WHERE id_medico = :m AND id_paciente = :p AND activa = TRUE",
 ['m' => $idMedico, 'p' => $idPaciente]
 );
 if (!$rel) return ['error' => 'Este paciente no está asignado a ti.'];

 $fechaFin = $duracion !== null
 ? (new DateTime($fechaInicio))->modify('+'. ($duracion - 1). ' days')->format('Y-m-d')
 : null;
 $horaInicioFull = $horaInicio. ':00';

 $pdo = DB::obtener();
 $pdo->beginTransaction();
 try {
 // 1. Prescripción
 DB::consultar(
 "INSERT INTO prescripciones_digitales
 (id_medico, id_paciente, nombre_medicamento, presentacion,
 cantidad_dosis, unidad_dosis, frecuencia_horas, hora_inicio,
 fecha_inicio, duracion_dias, fecha_fin, instrucciones, estado)
 VALUES (:me,:pa,:no,:pr,:ca,:un,:fr,:hi,:fi,:du,:ff,:ins,'activa')",
 ['me'=>$idMedico,'pa'=>$idPaciente,'no'=>$nombre,'pr'=>$presentacion,
 'ca'=>$cantidad,'un'=>$unidad,'fr'=>$frecuencia,'hi'=>$horaInicioFull,
 'fi'=>$fechaInicio,'du'=>$duracion,'ff'=>$fechaFin,
 'ins'=>$instrucciones ?: null]
 );
 $idPresc = DB::ultimoId();

 // 2. Medicamento generado (origen prescripción → no editable por paciente)
 DB::consultar(
 "INSERT INTO medicamentos
 (id_usuario_paciente, id_usuario_creador, nombre, presentacion,
 cantidad_dosis, unidad_dosis, frecuencia_horas, hora_inicio,
 fecha_inicio, fecha_fin, instrucciones_medico, tipo_horario,
 unidades_por_toma)
 VALUES (:pa,:me,:no,:pr,:ca,:un,:fr,:hi,:fi,:ff,:ins,'diario',1)",
 ['pa'=>$idPaciente,'me'=>$idMedico,'no'=>$nombre,'pr'=>$presentacion,
 'ca'=>$cantidad,'un'=>$unidad,'fr'=>$frecuencia,'hi'=>$horaInicioFull,
 'fi'=>$fechaInicio,'ff'=>$fechaFin,'ins'=>$instrucciones ?: null]
 );
 $idMed = DB::ultimoId();

 DB::consultar(
 "UPDATE prescripciones_digitales
 SET id_medicamento_generado = :m WHERE id_prescripcion = :p",
 ['m' => $idMed, 'p' => $idPresc]
 );

 // 3. Horarios del día según frecuencia
 $horas = self::calcularHorarios($horaInicio, $frecuencia);
 foreach ($horas as $h) {
 DB::consultar(
 "INSERT INTO horarios_medicamento (id_medicamento, hora_programada)
 VALUES (:m, :h)",
 ['m' => $idMed, 'h' => $h]
 );
 }

 // 4. Tomas pendientes del día de hoy (si la prescripción empieza hoy o antes)
 if ($fechaInicio <= date('Y-m-d')) {
 foreach ($horas as $h) {
 DB::consultar(
 "INSERT INTO registro_tomas
 (id_medicamento, id_usuario, fecha_programada, hora_programada,
 estado, id_prescripcion_origen)
 VALUES (:m, :u, CURDATE(), :h, 'pendiente', :pr)",
 ['m' => $idMed, 'u' => $idPaciente, 'h' => $h, 'pr' => $idPresc]
 );
 }
 }

 $pdo->commit();
 } catch (Throwable $e) {
 $pdo->rollBack();
 return ['error' => 'No se pudo crear la prescripción: '. $e->getMessage()];
 }

 // 5. Notificar al paciente (fuera de la transacción)
 self::notificarPaciente($idPaciente, $idMedico, $nombre, $cantidad,
 $unidad, $frecuencia, $fechaInicio, $duracion, $instrucciones);

 return ['ok' => true, 'id_prescripcion' => $idPresc, 'id_medicamento' => $idMed];
 }

 /**
 * Modifica una prescripción activa (y su medicamento generado).
 * Reescribe horarios y regenera las tomas pendientes futuras de hoy.
 */
 public static function modificar(int $idMedico, int $idPresc, array $d): array
 {
 $p = DB::uno(
 "SELECT * FROM prescripciones_digitales
 WHERE id_prescripcion = :id AND id_medico = :m",
 ['id' => $idPresc, 'm' => $idMedico]
 );
 if (!$p) return ['error' => 'Prescripción no encontrada.'];
 if ($p['estado'] === 'cancelada') {
 return ['error' => 'No se puede modificar una prescripción cancelada.'];
 }

 // Reutilizamos validaciones de crear sobre los campos editables
 $cantidad = (float)($d['cantidad_dosis'] ?? $p['cantidad_dosis']);
 $frecuencia = (float)($d['frecuencia_horas'] ?? $p['frecuencia_horas']);
 $horaInicio = $d['hora_inicio'] ?? substr($p['hora_inicio'], 0, 5);
 $instrucciones = trim($d['instrucciones'] ?? (string)$p['instrucciones']);
 $duracion = isset($d['duracion_dias']) && $d['duracion_dias'] !== ''
 ? (int)$d['duracion_dias'] : ($p['duracion_dias'] !== null ? (int)$p['duracion_dias'] : null);

 if ($cantidad <= 0) return ['error' => 'La cantidad de dosis debe ser mayor a 0.'];
 if ($frecuencia < 1 || $frecuencia > 168) {
 return ['error' => 'La frecuencia debe estar entre 1 y 168 horas.'];
 }
 if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $horaInicio)) {
 return ['error' => 'La hora de inicio no es válida.'];
 }
 if (mb_strlen($instrucciones) > INSTRUCCION_MAX_CARACTERES) {
 return ['error' => 'Las instrucciones son demasiado largas.'];
 }

 $idMed = (int)$p['id_medicamento_generado'];
 $horaFull = $horaInicio. ':00';
 $fechaFin = $duracion !== null
 ? (new DateTime($p['fecha_inicio']))->modify('+'. ($duracion - 1). ' days')->format('Y-m-d')
 : null;

 $pdo = DB::obtener();
 $pdo->beginTransaction();
 try {
 DB::consultar(
 "UPDATE prescripciones_digitales
 SET cantidad_dosis=:c, frecuencia_horas=:f, hora_inicio=:h,
 duracion_dias=:du, fecha_fin=:ff, instrucciones=:i,
 estado='modificada', fecha_modificacion=NOW()
 WHERE id_prescripcion=:id",
 ['c'=>$cantidad,'f'=>$frecuencia,'h'=>$horaFull,'du'=>$duracion,
 'ff'=>$fechaFin,'i'=>$instrucciones ?: null,'id'=>$idPresc]
 );
 if ($idMed > 0) {
 DB::consultar(
 "UPDATE medicamentos
 SET cantidad_dosis=:c, frecuencia_horas=:f, hora_inicio=:h,
 fecha_fin=:ff, instrucciones_medico=:i
 WHERE id_medicamento=:m",
 ['c'=>$cantidad,'f'=>$frecuencia,'h'=>$horaFull,
 'ff'=>$fechaFin,'i'=>$instrucciones ?: null,'m'=>$idMed]
 );
 // Regenerar horarios
 DB::consultar("DELETE FROM horarios_medicamento WHERE id_medicamento=:m",
 ['m'=>$idMed]);
 foreach (self::calcularHorarios($horaInicio, $frecuencia) as $h) {
 DB::consultar(
 "INSERT INTO horarios_medicamento (id_medicamento, hora_programada)
 VALUES (:m,:h)", ['m'=>$idMed,'h'=>$h]);
 }
 // Regenerar tomas pendientes de hoy aún no marcadas
 DB::consultar(
 "DELETE FROM registro_tomas
 WHERE id_medicamento=:m AND fecha_programada=CURDATE()
 AND estado='pendiente'", ['m'=>$idMed]);
 foreach (self::calcularHorarios($horaInicio, $frecuencia) as $h) {
 DB::consultar(
 "INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,
 estado,id_prescripcion_origen)
 VALUES (:m,:u,CURDATE(),:h,'pendiente',:pr)",
 ['m'=>$idMed,'u'=>$p['id_paciente'],'h'=>$h,'pr'=>$idPresc]);
 }
 }
 $pdo->commit();
 } catch (Throwable $e) {
 $pdo->rollBack();
 return ['error' => 'No se pudo modificar: '. $e->getMessage()];
 }
 return ['ok' => true];
 }

 /**
 * Cancela una prescripción: desactiva el medicamento generado y elimina
 * las tomas pendientes futuras.
 */
 public static function cancelar(int $idMedico, int $idPresc): array
 {
 $p = DB::uno(
 "SELECT * FROM prescripciones_digitales
 WHERE id_prescripcion = :id AND id_medico = :m",
 ['id' => $idPresc, 'm' => $idMedico]
 );
 if (!$p) return ['error' => 'Prescripción no encontrada.'];
 if ($p['estado'] === 'cancelada') {
 return ['error' => 'Esta prescripción ya está cancelada.'];
 }

 $idMed = (int)$p['id_medicamento_generado'];
 $pdo = DB::obtener();
 $pdo->beginTransaction();
 try {
 DB::consultar(
 "UPDATE prescripciones_digitales
 SET estado='cancelada', fecha_cancelacion=NOW()
 WHERE id_prescripcion=:id", ['id'=>$idPresc]);
 if ($idMed > 0) {
 DB::consultar(
 "UPDATE medicamentos SET activo=FALSE WHERE id_medicamento=:m",
 ['m'=>$idMed]);
 DB::consultar(
 "DELETE FROM registro_tomas
 WHERE id_medicamento=:m AND estado='pendiente'
 AND fecha_programada >= CURDATE()", ['m'=>$idMed]);
 }
 $pdo->commit();
 } catch (Throwable $e) {
 $pdo->rollBack();
 return ['error' => 'No se pudo cancelar: '. $e->getMessage()];
 }
 return ['ok' => true];
 }

 /** Lista de prescripciones que un médico emitió a un paciente. */
 public static function listarPorPaciente(int $idMedico, int $idPaciente): array
 {
 return DB::todos(
 "SELECT * FROM prescripciones_digitales
 WHERE id_medico=:m AND id_paciente=:p
 ORDER BY fecha_emision DESC",
 ['m' => $idMedico, 'p' => $idPaciente]
 );
 }

 /** Lista de prescripciones que recibió un paciente (vista de solo lectura). */
 public static function listarParaPaciente(int $idPaciente): array
 {
 return DB::todos(
 "SELECT pd.*, u.nombre_completo AS medico_nombre, u.especialidad
 FROM prescripciones_digitales pd
 JOIN usuarios u ON u.id_usuario = pd.id_medico
 WHERE pd.id_paciente = :p
 ORDER BY pd.fecha_emision DESC",
 ['p' => $idPaciente]
 );
 }

 // ─── Internos ────────────────────────────────────────────────────────────

 private static function calcularHorarios(string $horaInicio, float $frecuencia): array
 {
 $horas = [];
 $base = strtotime($horaInicio. ':00');
 // Una toma cada `frecuencia` horas dentro de las 24 h del día
 $n = max(1, (int)floor(24 / max(1, $frecuencia)));
 for ($i = 0; $i < $n; $i++) {
 $ts = $base + (int)round($i * $frecuencia * 3600);
 $horas[] = date('H:i:s', $ts);
 if (date('Y-m-d', $ts) !== date('Y-m-d', $base)) {
 array_pop($horas);
 break;
 }
 }
 return $horas ?: [$horaInicio. ':00'];
 }

 private static function fechaValida(string $f): bool
 {
 $d = DateTime::createFromFormat('Y-m-d', $f);
 return $d && $d->format('Y-m-d') === $f;
 }

 private static function notificarPaciente(int $idPaciente, int $idMedico,
 string $nombre, float $cant, string $unidad, float $frec,
 string $fechaInicio, ?int $duracion, string $instrucciones): void
 {
 $medico = DB::uno(
 "SELECT nombre_completo, correo FROM usuarios WHERE id_usuario=:m",
 ['m' => $idMedico]
 );
 $pac = DB::uno(
 "SELECT nombre_completo, correo, canal_correo_activo
 FROM usuarios WHERE id_usuario=:p",
 ['p' => $idPaciente]
 );
 $medNom = $medico['nombre_completo'] ?? 'Tu médico';
 $cantStr = rtrim(rtrim(number_format($cant, 2, '.', ''), '0'), '.');
 $durStr = $duracion ? " durante {$duracion} días" : '';

 $titulo = '💊 Tu médico te emitió una nueva prescripción';
 $mensaje = "{$medNom} te emitió una nueva prescripción: {$nombre} ". "{$cantStr} {$unidad} cada {$frec} h{$durStr}. ". "Ya aparece en tus medicamentos con recordatorios configurados.";
 if ($instrucciones !== '') {
 $mensaje.= "\nIndicaciones: {$instrucciones}";
 }

 // Notificación in-app
 Notificaciones::crearGenerica($idPaciente, $titulo, $mensaje, 'nueva_prescripcion');

 // Correo (si el canal está activo)
 if (!empty($pac['canal_correo_activo']) && !empty($pac['correo'])) {
 require_once __DIR__. '/mailer.php';
 Mailer::enviarNuevaPrescripcion(
 $pac['correo'], $pac['nombre_completo'] ?? '',
 $medNom, $nombre, "{$cantStr} {$unidad}", $frec,
 $fechaInicio, $duracion, $instrucciones
 );
 }
 }
}
