<?php
/**
 * MedAlert · API de tomas 
 * --------------------------------------------------------------
 * marcar cumplida / no cumplida con ventana 30 min para deshacer
 * * · al marcar 'cumplida' se descuenta el stock
 * · al marcar se cancelan las notificaciones pendientes asociadas
 * · al deshacer se reactivan las notificaciones futuras
 * · motivo de omisión validado contra dropdown
 */

require_once __DIR__. '/../../includes/auth.php';
require_once __DIR__. '/../../includes/helpers.php';
require_once __DIR__. '/../../includes/notificaciones.php';
require_once __DIR__. '/../../includes/stock.php';
require_once __DIR__. '/../../includes/familiar.php';

$usuario = Auth::usuarioActual();
if (!$usuario) {
 Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}

// motivos predefinidos confirmados con el cliente
const MOTIVOS_OMISION_VALIDOS = [
 'olvido', 'efecto_adverso', 'medicamento_no_disponible',
 'indicacion_medica', 'otro'
];

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';
$idRegistro = (int)($datos['id_registro'] ?? 0);

if ($idRegistro <= 0) {
 Helpers::jsonRespuesta(['error' => 'ID de registro no válido.'], 400);
}

$registro = DB::uno(
 "SELECT rt.*, m.id_usuario_paciente, m.id_medicamento, m.nombre AS nombre_medicamento
 FROM registro_tomas rt
 INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
 WHERE rt.id_registro = :id",
 ['id' => $idRegistro]
);

if (!$registro) {
 Helpers::jsonRespuesta(['error' => 'Registro no encontrado.'], 404);
}
if ((int)$registro['id_usuario_paciente'] !== (int)$usuario['id_usuario']) {
 Helpers::jsonRespuesta(['error' => 'No tienes permiso sobre este registro.'], 403);
}

if ($accion === 'marcar') {
 $estado = $datos['estado'] ?? '';
 $motivo = trim($datos['motivo'] ?? '');
 $motivoCodigo = trim($datos['motivo_codigo'] ?? '');

 if (!in_array($estado, ['cumplida', 'no_cumplida'], true)) {
 Helpers::jsonRespuesta(['error' => 'Estado no válido.'], 400);
 }
 if ($registro['estado'] === 'cumplida' || $registro['estado'] === 'no_cumplida') {
 Helpers::jsonRespuesta(['error' => 'Esta toma ya fue registrada.'], 409);
 }

 // si es no cumplida, el motivo es OBLIGATORIO y debe ser del catálogo
 if ($estado === 'no_cumplida') {
 if ($motivoCodigo === '') {
 Helpers::jsonRespuesta([
 'error' => 'Debes indicar el motivo de la omisión para completar el registro.'
 ], 400);
 }
 if (!in_array($motivoCodigo, MOTIVOS_OMISION_VALIDOS, true)) {
 Helpers::jsonRespuesta(['error' => 'Motivo de omisión no válido.'], 400);
 }
 if ($motivoCodigo === 'otro' && mb_strlen($motivo) < 5) {
 Helpers::jsonRespuesta([
 'error' => 'Si eliges "Otro" debes describir el motivo con al menos 5 caracteres.'
 ], 400);
 }
 }

 $tsProg = strtotime($registro['fecha_programada']. ' '. $registro['hora_programada']);
 $diferencia = (int)round((time() - $tsProg) / 60);

 // Concatenar motivo_codigo + texto libre cuando aplica
 $motivoGuardado = null;
 if ($estado === 'no_cumplida') {
 if ($motivoCodigo !== '') {
 $motivoGuardado = $motivoCodigo;
 if ($motivo !== '') $motivoGuardado.= ': '. $motivo;
 } elseif ($motivo !== '') {
 $motivoGuardado = $motivo;
 }
 }

 $params = [
 'estado' => $estado,
 'fecha_real' => $estado === 'cumplida' ? date('Y-m-d H:i:s') : null,
 'diferencia' => $estado === 'cumplida' ? $diferencia : null,
 'motivo' => $motivoGuardado,
 'motivo_cod' => $estado === 'no_cumplida' ? $motivoCodigo : null,
 'motivo_desc' => ($estado === 'no_cumplida' && $motivo !== '') ? $motivo : null,
 'fecha_marcado'=> date('Y-m-d H:i:s'),
 'id' => $idRegistro,
 ];
 DB::consultar(
 "UPDATE registro_tomas
 SET estado = :estado,
 fecha_real_toma = :fecha_real,
 diferencia_minutos = :diferencia,
 motivo_omision = :motivo,
 motivo_omision_codigo = :motivo_cod,
 motivo_omision_descripcion = :motivo_desc,
 fecha_marcado = :fecha_marcado,
 deshecho = FALSE,
 fecha_deshecho = NULL
 WHERE id_registro = :id",
 $params
 );

 // cancelar notificaciones pendientes asociadas (los avisos posteriores ya no aplican)
 Notificaciones::cancelarParaToma($idRegistro, 'toma marcada como '. $estado);

 // descontar stock cuando se cumplió
 $resultadoStock = null;
 if ($estado === 'cumplida') {
 $resultadoStock = Stock::descontarPorToma((int)$registro['id_medicamento']);
 }

 // revisar si el cumplimiento semanal cruzó el umbral del 70%
 // y, si bajó, notificar automáticamente al familiar.
 Familiar::revisarUmbralYNotificar((int)$registro['id_usuario_paciente']);

 Helpers::jsonRespuesta([
 'ok' => true,
 'estado' => $estado,
 'diferencia_minutos' => $diferencia,
 'puede_deshacer_hasta' => date('Y-m-d H:i:s', time() + VENTANA_DESHACER_MINUTOS * 60),
 'stock' => $resultadoStock,
 // si fue cumplida, el front ofrece reportar efecto adverso (opcional)
 'ofrecer_efecto_adverso' => ($estado === 'cumplida'),
 'id_registro' => $idRegistro,
 ]);
}

if ($accion === 'deshacer') {
 if (!$registro['fecha_marcado']) {
 Helpers::jsonRespuesta(['error' => 'Esta toma no tiene marcado para deshacer.'], 400);
 }
 if ($registro['deshecho']) {
 Helpers::jsonRespuesta(['error' => 'Este registro ya fue deshecho.'], 400);
 }

 $minTrans = (time() - strtotime($registro['fecha_marcado'])) / 60;
 if ($minTrans > VENTANA_DESHACER_MINUTOS) {
 Helpers::jsonRespuesta([
 'error' => 'La ventana de '. VENTANA_DESHACER_MINUTOS. ' minutos para deshacer ya expiró.'
 ], 400);
 }

 $estadoAnterior = $registro['estado'];

 DB::consultar(
 "UPDATE registro_tomas
 SET estado = 'pendiente',
 fecha_real_toma = NULL,
 diferencia_minutos = NULL,
 motivo_omision = NULL,
 motivo_omision_codigo = NULL,
 motivo_omision_descripcion = NULL,
 deshecho = TRUE,
 fecha_deshecho = NOW()
 WHERE id_registro = :id",
 ['id' => $idRegistro]
 );

 // re-programar las notificaciones futuras
 Notificaciones::reactivarParaToma($idRegistro);

 // si la toma anterior era cumplida, devolver al stock
 if ($estadoAnterior === 'cumplida') {
 Stock::revertirDescuentoPorToma((int)$registro['id_medicamento']);
 }

 Helpers::jsonRespuesta(['ok' => true]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
