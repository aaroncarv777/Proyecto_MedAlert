<?php
/**
 * MedAlert · API de relación médico-paciente
 * --------------------------------------------------------------
 * GET ?accion=mis_pacientes — Medico: sus pacientes asignados con % adherencia
 * GET ?accion=detalle_paciente&id=X — Medico: detalle de un paciente
 * GET ?accion=todas — Admin: listado completo
 * GET ?accion=medicos — Admin: lista de médicos validados
 * GET ?accion=pacientes — Admin: lista de pacientes
 * POST {accion: asignar, id_medico, id_paciente, notas_clinicas?} — Admin
 * POST {accion: revocar, id_relacion} — Admin
 * POST {accion: actualizar_notas, id_relacion, notas_clinicas} — Medico
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
 $accion = Helpers::get('accion');

 if ($accion === 'mis_pacientes') {
 if ($tipo !== 'Medico') Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 // % adherencia de los últimos 7 días por paciente
 $sql = "SELECT mp.id_relacion, mp.notas_clinicas, mp.fecha_asignacion,
 p.id_usuario, p.nombre_completo, p.correo, p.telefono,
 p.peso_kg, p.fecha_nacimiento,
 (SELECT COUNT(*) FROM registro_tomas rt
 INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE m.id_usuario_paciente = p.id_usuario
 AND rt.fecha_programada >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
 AND rt.estado IN ('cumplida','no_cumplida')) AS total_marcadas,
 (SELECT COUNT(*) FROM registro_tomas rt
 INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE m.id_usuario_paciente = p.id_usuario
 AND rt.fecha_programada >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
 AND rt.estado = 'cumplida') AS cumplidas
 FROM medico_paciente mp
 JOIN usuarios p ON p.id_usuario = mp.id_paciente
 WHERE mp.id_medico = :m AND mp.activa = TRUE
 ORDER BY p.nombre_completo";
 $pacientes = DB::todos($sql, ['m' => $idUsuario]);
 foreach ($pacientes as &$p) {
 $tot = (int)$p['total_marcadas'];
 $p['porcentaje_adherencia'] = $tot > 0
 ? round(((int)$p['cumplidas'] / $tot) * 100)
 : null;
 }
 Helpers::jsonRespuesta(['ok' => true, 'pacientes' => $pacientes]);
 }

 if ($accion === 'detalle_paciente') {
 if ($tipo !== 'Medico') Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 $idPaciente = (int)Helpers::get('id');
 $rel = DB::uno(
 "SELECT mp.*, u.nombre_completo, u.correo, u.telefono, u.peso_kg,
 u.fecha_nacimiento, u.hora_desayuno, u.hora_comida, u.hora_cena
 FROM medico_paciente mp
 JOIN usuarios u ON u.id_usuario = mp.id_paciente
 WHERE mp.id_medico = :m AND mp.id_paciente = :p AND mp.activa = TRUE",
 ['m' => $idUsuario, 'p' => $idPaciente]
 );
 if (!$rel) Helpers::jsonRespuesta(['error' => 'Paciente no asignado.'], 404);

 $meds = DB::todos(
 "SELECT m.*, v.estado_stock, v.dias_restantes, v.unidades_actuales AS stock_actuales
 FROM medicamentos m
 LEFT JOIN v_stock_medicamentos v ON v.id_medicamento = m.id_medicamento
 WHERE m.id_usuario_paciente = :p AND m.activo = TRUE
 ORDER BY m.fecha_inicio DESC",
 ['p' => $idPaciente]
 );

 Helpers::jsonRespuesta(['ok' => true, 'relacion' => $rel, 'medicamentos' => $meds]);
 }

 if ($accion === 'todas') {
 if ($tipo !== 'Administrador') Helpers::jsonRespuesta(['error' => 'Solo administrador.'], 403);
 $lista = DB::todos(
 "SELECT mp.*, m.nombre_completo AS medico_nombre, p.nombre_completo AS paciente_nombre,
 m.especialidad
 FROM medico_paciente mp
 JOIN usuarios m ON m.id_usuario = mp.id_medico
 JOIN usuarios p ON p.id_usuario = mp.id_paciente
 ORDER BY mp.fecha_asignacion DESC"
 );
 Helpers::jsonRespuesta(['ok' => true, 'relaciones' => $lista]);
 }

 if ($accion === 'medicos') {
 if ($tipo !== 'Administrador') Helpers::jsonRespuesta(['error' => 'Solo administrador.'], 403);
 $meds = DB::todos(
 "SELECT u.id_usuario, u.nombre_completo, u.correo, u.cedula_profesional, u.especialidad
 FROM usuarios u
 JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE t.nombre = 'Medico' AND u.cedula_validada = TRUE AND u.estado_cuenta = 'activa'
 ORDER BY u.nombre_completo"
 );
 Helpers::jsonRespuesta(['ok' => true, 'medicos' => $meds]);
 }

 if ($accion === 'pacientes') {
 if ($tipo !== 'Administrador') Helpers::jsonRespuesta(['error' => 'Solo administrador.'], 403);
 $pacs = DB::todos(
 "SELECT u.id_usuario, u.nombre_completo, u.correo
 FROM usuarios u
 JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE t.nombre = 'Paciente' AND u.estado_cuenta = 'activa'
 ORDER BY u.nombre_completo"
 );
 Helpers::jsonRespuesta(['ok' => true, 'pacientes' => $pacs]);
 }

 Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'asignar') {
 if ($tipo !== 'Administrador') Helpers::jsonRespuesta(['error' => 'Solo administrador.'], 403);
 $idMedico = (int)($datos['id_medico'] ?? 0);
 $idPaciente = (int)($datos['id_paciente'] ?? 0);
 $notas = trim($datos['notas_clinicas'] ?? '');

 if ($idMedico <= 0 || $idPaciente <= 0) {
 Helpers::jsonRespuesta(['error' => 'IDs inválidos.'], 400);
 }
 if (mb_strlen($notas) > NOTAS_CLINICAS_MAX) {
 Helpers::jsonRespuesta([
 'error' => 'Las notas clínicas no pueden superar '. NOTAS_CLINICAS_MAX. ' caracteres.'
 ], 400);
 }

 // Validar tipos
 $m = DB::uno(
 "SELECT u.* FROM usuarios u JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE u.id_usuario = :id AND t.nombre = 'Medico'",
 ['id' => $idMedico]
 );
 if (!$m) Helpers::jsonRespuesta(['error' => 'Médico no válido.'], 400);
 if (!$m['cedula_validada']) {
 Helpers::jsonRespuesta([
 'error' => 'Este médico aún no tiene cédula validada. Apruébala primero.'
 ], 400);
 }

 $p = DB::uno(
 "SELECT u.* FROM usuarios u JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
 WHERE u.id_usuario = :id AND t.nombre = 'Paciente'",
 ['id' => $idPaciente]
 );
 if (!$p) Helpers::jsonRespuesta(['error' => 'Paciente no válido.'], 400);

 // Reactivar si ya existía
 $existe = DB::uno(
 "SELECT id_relacion, activa FROM medico_paciente
 WHERE id_medico = :m AND id_paciente = :p",
 ['m' => $idMedico, 'p' => $idPaciente]
 );
 if ($existe) {
 if ($existe['activa']) {
 Helpers::jsonRespuesta([
 'error' => 'Este médico ya tiene asignado a este paciente.'
 ], 409);
 }
 DB::consultar(
 "UPDATE medico_paciente
 SET activa = TRUE,
 fecha_asignacion = NOW(),
 asignada_por = :a,
 notas_clinicas = :n
 WHERE id_relacion = :r",
 ['a' => $idUsuario, 'n' => $notas ?: null, 'r' => $existe['id_relacion']]
 );
 Helpers::jsonRespuesta(['ok' => true, 'id_relacion' => (int)$existe['id_relacion']]);
 }

 DB::consultar(
 "INSERT INTO medico_paciente
 (id_medico, id_paciente, asignada_por, notas_clinicas)
 VALUES (:m, :p, :a, :n)",
 ['m' => $idMedico, 'p' => $idPaciente, 'a' => $idUsuario, 'n' => $notas ?: null]
 );
 Helpers::jsonRespuesta(['ok' => true, 'id_relacion' => DB::ultimoId()]);
}

if ($accion === 'revocar') {
 if ($tipo !== 'Administrador') Helpers::jsonRespuesta(['error' => 'Solo administrador.'], 403);
 $idRel = (int)($datos['id_relacion'] ?? 0);
 if ($idRel <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);

 DB::consultar(
 "UPDATE medico_paciente SET activa = FALSE, fecha_revocacion = NOW() WHERE id_relacion = :r",
 ['r' => $idRel]
 );
 Helpers::jsonRespuesta(['ok' => true]);
}

if ($accion === 'actualizar_notas') {
 if ($tipo !== 'Medico') Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 $idRel = (int)($datos['id_relacion'] ?? 0);
 $notas = trim($datos['notas_clinicas'] ?? '');

 if ($idRel <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
 if (mb_strlen($notas) > NOTAS_CLINICAS_MAX) {
 Helpers::jsonRespuesta([
 'error' => 'Las notas no pueden superar '. NOTAS_CLINICAS_MAX. ' caracteres.'
 ], 400);
 }

 // Verificar que la relación es del médico
 $rel = DB::uno(
 "SELECT * FROM medico_paciente WHERE id_relacion = :r AND id_medico = :m AND activa = TRUE",
 ['r' => $idRel, 'm' => $idUsuario]
 );
 if (!$rel) Helpers::jsonRespuesta(['error' => 'Relación no válida.'], 404);

 DB::consultar(
 "UPDATE medico_paciente SET notas_clinicas = :n,
 notas_clinicas_fecha = NOW() WHERE id_relacion = :r",
 ['n' => $notas ?: null, 'r' => $idRel]
 );
 Helpers::jsonRespuesta(['ok' => true]);
}

// guardar notas clínicas usando id_paciente (panel multi-paciente).
// Las notas son visibles al paciente y guardan fecha de actualización.
if ($accion === 'notas') {
 if ($tipo !== 'Medico') Helpers::jsonRespuesta(['error' => 'Solo médicos.'], 403);
 $idPac = (int)($datos['id_paciente'] ?? 0);
 $notas = trim($datos['notas'] ?? '');

 if ($idPac <= 0) Helpers::jsonRespuesta(['error' => 'Paciente inválido.'], 400);
 if (mb_strlen($notas) > NOTAS_CLINICAS_MAX) {
 Helpers::jsonRespuesta([
 'error' => 'Las notas no pueden superar '. NOTAS_CLINICAS_MAX. ' caracteres.'
 ], 400);
 }
 $rel = DB::uno(
 "SELECT id_relacion FROM medico_paciente
 WHERE id_medico = :m AND id_paciente = :p AND activa = TRUE",
 ['m' => $idUsuario, 'p' => $idPac]
 );
 if (!$rel) Helpers::jsonRespuesta(['error' => 'Este paciente no está asignado a ti.'], 403);

 DB::consultar(
 "UPDATE medico_paciente SET notas_clinicas = :n,
 notas_clinicas_fecha = NOW() WHERE id_relacion = :r",
 ['n' => $notas ?: null, 'r' => $rel['id_relacion']]
 );
 Helpers::jsonRespuesta(['ok' => true]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
