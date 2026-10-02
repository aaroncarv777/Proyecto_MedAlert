<?php
/**
 * Motor de cálculo de adherencia
 * Base de (reporte semanal), (panel multi-paciente),
 * (comparativo entre semanas) y (alerta familiar al 70%).
 *
 * Reglas de negocio confirmadas con el cliente:
 * · Umbral fijo de cumplimiento aceptable: 70%
 * · Diferencia significativa entre semanas: > 5%
 * · Si no hay tomas marcadas en el periodo → "Sin datos suficientes"
 * · Las omisiones se distinguen: con motivo (justificada) vs sin motivo
 */

require_once __DIR__. '/db.php';

class Adherencia
{
 /** Umbral fijo de cumplimiento aceptable (estudios de adherencia farmacológica). */
 const UMBRAL_CUMPLIMIENTO = 70;

 /** Diferencia mínima para considerar una tendencia significativa entre semanas. */
 const UMBRAL_SIGNIFICANCIA = 5;

 /**
 * Calcula el resumen de adherencia de un paciente en un rango de fechas.
 * Devuelve porcentaje general, desglose por día, desglose por medicamento,
 * y el conteo de omisiones justificadas / sin justificar.
 */
 public static function resumen(int $idPaciente, string $desde, string $hasta): array
 {
 $tomas = DB::todos(
 "SELECT vc.fecha_programada, vc.medicamento_nombre, vc.id_medicamento,
 vc.clasificacion
 FROM v_tomas_clasificadas vc
 WHERE vc.id_paciente = :p
 AND vc.fecha_programada BETWEEN :d AND :h
 AND vc.estado IN ('cumplida','no_cumplida','vencida')
 ORDER BY vc.fecha_programada, vc.hora_programada",
 ['p' => $idPaciente, 'd' => $desde, 'h' => $hasta]
 );

 $total = count($tomas);
 $cumplidas = 0;
 $omitJust = 0; // omisiones con motivo
 $omitSin = 0; // omisiones sin motivo

 $porDia = []; // fecha => ['total'=>x,'cumplidas'=>y]
 $porMed = []; // id_med => ['nombre','total','cumplidas','omit_just','omit_sin']

 foreach ($tomas as $t) {
 $f = $t['fecha_programada'];
 $im = (int)$t['id_medicamento'];

 if (!isset($porDia[$f])) {
 $porDia[$f] = ['fecha' => $f, 'total' => 0, 'cumplidas' => 0];
 }
 if (!isset($porMed[$im])) {
 $porMed[$im] = [
 'id_medicamento' => $im,
 'nombre' => $t['medicamento_nombre'],
 'total' => 0, 'cumplidas' => 0,
 'omit_just' => 0, 'omit_sin' => 0,
 ];
 }

 $porDia[$f]['total']++;
 $porMed[$im]['total']++;

 if ($t['clasificacion'] === 'cumplida') {
 $cumplidas++;
 $porDia[$f]['cumplidas']++;
 $porMed[$im]['cumplidas']++;
 } elseif ($t['clasificacion'] === 'omitida_justificada') {
 $omitJust++;
 $porMed[$im]['omit_just']++;
 } else { // omitida_sin_justificar
 $omitSin++;
 $porMed[$im]['omit_sin']++;
 }
 }

 $hayDatos = $total > 0;
 $porcentaje = $hayDatos ? round($cumplidas * 100 / $total, 1) : null;

 // Porcentaje por día (solo los días con tomas reales: no se inventan días en cero)
 $dias = [];
 foreach ($porDia as $d) {
 $d['porcentaje'] = $d['total'] > 0
 ? round($d['cumplidas'] * 100 / $d['total'], 1) : 0;
 $dias[] = $d;
 }

 // Porcentaje por medicamento
 $meds = [];
 foreach ($porMed as $m) {
 $m['porcentaje'] = $m['total'] > 0
 ? round($m['cumplidas'] * 100 / $m['total'], 1) : 0;
 $meds[] = $m;
 }
 // Ordenar peor cumplimiento primero (el que más atención requiere)
 usort($meds, fn($a, $b) => $a['porcentaje'] <=> $b['porcentaje']);

 return [
 'hay_datos' => $hayDatos,
 'desde' => $desde,
 'hasta' => $hasta,
 'total_tomas' => $total,
 'cumplidas' => $cumplidas,
 'omisiones_justificadas' => $omitJust,
 'omisiones_sin_justificar' => $omitSin,
 'porcentaje' => $porcentaje,
 'por_dia' => $dias,
 'por_medicamento' => $meds,
 'mensaje_sin_datos' => $hayDatos ? null
 : 'Sin datos suficientes para calcular',
 ];
 }

 /**
 * Tendencia de las últimas N semanas (por defecto 4) para la gráfica de línea.
 * Solo devuelve semanas con datos reales (no conecta líneas hacia cero).
 */
 public static function tendenciaSemanal(int $idPaciente, int $semanas = 4): array
 {
 $puntos = [];
 for ($i = $semanas - 1; $i >= 0; $i--) {
 [$ini, $fin] = self::rangoSemana($i);
 $r = self::resumen($idPaciente, $ini, $fin);
 if ($r['hay_datos']) {
 $puntos[] = [
 'etiqueta' => self::etiquetaSemana($ini, $fin, $i),
 'desde' => $ini,
 'hasta' => $fin,
 'porcentaje' => $r['porcentaje'],
 ];
 }
 }
 return $puntos;
 }

 /**
 * Comparativo entre dos semanas consecutivas.
 * desplazamiento = 0 → semana actual vs anterior.
 * desplazamiento = 1 → semana anterior vs la previa, etc.
 */
 public static function comparativo(int $idPaciente, int $desplazamiento = 0): array
 {
 [$actIni, $actFin] = self::rangoSemana($desplazamiento);
 [$antIni, $antFin] = self::rangoSemana($desplazamiento + 1);

 $act = self::resumen($idPaciente, $actIni, $actFin);
 $ant = self::resumen($idPaciente, $antIni, $antFin);

 // Tendencia general
 $tendencia = self::clasificarTendencia(
 $ant['porcentaje'], $act['porcentaje']
 );

 // Diferencia por medicamento (combinando ambas semanas)
 $mapAnt = [];
 foreach ($ant['por_medicamento'] as $m) $mapAnt[$m['id_medicamento']] = $m;

 $medsComparados = [];
 $idsVistos = [];
 foreach ($act['por_medicamento'] as $m) {
 $idsVistos[$m['id_medicamento']] = true;
 $pctAnt = $mapAnt[$m['id_medicamento']]['porcentaje'] ?? null;
 $diff = ($pctAnt === null) ? null
 : round($m['porcentaje'] - $pctAnt, 1);
 $medsComparados[] = [
 'id_medicamento' => $m['id_medicamento'],
 'nombre' => $m['nombre'],
 'pct_anterior' => $pctAnt,
 'pct_actual' => $m['porcentaje'],
 'diferencia' => $diff,
 'tendencia' => self::clasificarTendencia($pctAnt, $m['porcentaje']),
 ];
 }
 // Medicamentos que estaban la semana anterior pero ya no esta
 foreach ($ant['por_medicamento'] as $m) {
 if (isset($idsVistos[$m['id_medicamento']])) continue;
 $medsComparados[] = [
 'id_medicamento' => $m['id_medicamento'],
 'nombre' => $m['nombre'],
 'pct_anterior' => $m['porcentaje'],
 'pct_actual' => null,
 'diferencia' => null,
 'tendencia' => 'sin_dato',
 ];
 }
 // El medicamento con mayor cambio absoluto va destacado
 $mayor = null;
 foreach ($medsComparados as &$mc) {
 $mc['destacado'] = false;
 if ($mc['diferencia'] !== null) {
 if ($mayor === null || abs($mc['diferencia']) > abs($mayor['diferencia'])) {
 $mayor = $mc;
 }
 }
 }
 unset($mc);
 if ($mayor !== null && abs($mayor['diferencia']) >= self::UMBRAL_SIGNIFICANCIA) {
 foreach ($medsComparados as &$mc) {
 if ($mc['id_medicamento'] === $mayor['id_medicamento']) {
 $mc['destacado'] = true;
 }
 }
 unset($mc);
 }

 return [
 'desplazamiento' => $desplazamiento,
 'semana_actual' => [
 'desde' => $actIni, 'hasta' => $actFin,
 'etiqueta' => self::etiquetaSemana($actIni, $actFin, $desplazamiento),
 'porcentaje' => $act['porcentaje'],
 'hay_datos' => $act['hay_datos'],
 'cumplidas' => $act['cumplidas'],
 'total' => $act['total_tomas'],
 'omisiones_justificadas' => $act['omisiones_justificadas'],
 'omisiones_sin_justificar' => $act['omisiones_sin_justificar'],
 ],
 'semana_anterior' => [
 'desde' => $antIni, 'hasta' => $antFin,
 'etiqueta' => self::etiquetaSemana($antIni, $antFin, $desplazamiento + 1),
 'porcentaje' => $ant['porcentaje'],
 'hay_datos' => $ant['hay_datos'],
 'cumplidas' => $ant['cumplidas'],
 'total' => $ant['total_tomas'],
 'omisiones_justificadas' => $ant['omisiones_justificadas'],
 'omisiones_sin_justificar' => $ant['omisiones_sin_justificar'],
 ],
 'tendencia_general' => $tendencia,
 'medicamentos' => $medsComparados,
 'hay_mas_atras' => self::hayDatosAntesDe($idPaciente, $antIni),
 ];
 }

 /**
 * Métricas resumidas por paciente para el panel multi-paciente.
 * Semana en curso. Marca a quienes están por debajo del umbral.
 */
 public static function panelMedico(int $idMedico): array
 {
 $pacientes = DB::todos(
 "SELECT mp.id_relacion, mp.notas_clinicas, mp.notas_clinicas_fecha,
 p.id_usuario, p.nombre_completo, p.correo
 FROM medico_paciente mp
 JOIN usuarios p ON p.id_usuario = mp.id_paciente
 WHERE mp.id_medico = :m AND mp.activa = TRUE
 ORDER BY p.nombre_completo",
 ['m' => $idMedico]
 );

 [$ini, $fin] = self::rangoSemana(0);
 $filas = [];
 foreach ($pacientes as $p) {
 $idp = (int)$p['id_usuario'];
 $r = self::resumen($idp, $ini, $fin);

 $alertas = (int)(DB::uno(
 "SELECT COUNT(*) n FROM alertas_interaccion
 WHERE id_paciente = :p AND estado = 'activa'",
 ['p' => $idp]
 )['n'] ?? 0);

 $filas[] = [
 'id_relacion' => (int)$p['id_relacion'],
 'id_paciente' => $idp,
 'nombre' => $p['nombre_completo'],
 'correo' => $p['correo'],
 'notas_clinicas' => $p['notas_clinicas'],
 'notas_fecha' => $p['notas_clinicas_fecha'],
 'hay_datos' => $r['hay_datos'],
 'porcentaje' => $r['porcentaje'],
 'omisiones' => $r['omisiones_justificadas'] + $r['omisiones_sin_justificar'],
 'omisiones_justificadas' => $r['omisiones_justificadas'],
 'alertas_activas'=> $alertas,
 'bajo_umbral' => $r['hay_datos']
 && $r['porcentaje'] < self::UMBRAL_CUMPLIMIENTO,
 ];
 }
 return [
 'desde' => $ini, 'hasta' => $fin,
 'umbral' => self::UMBRAL_CUMPLIMIENTO,
 'pacientes' => $filas,
 ];
 }

 // ─── Utilidades de fechas ────────────────────────────────────────────────

 /**
 * Rango [lunes, domingo] de la semana desplazada N semanas hacia atrás.
 * 0 = semana en curso.
 */
 public static function rangoSemana(int $atras): array
 {
 $hoy = new DateTime('today');
 // ISO: lunes = 1... domingo = 7
 $diaSemana = (int)$hoy->format('N');
 $lunesActual = (clone $hoy)->modify('-'. ($diaSemana - 1). ' days');
 $lunes = (clone $lunesActual)->modify('-'. (7 * $atras). ' days');
 $domingo = (clone $lunes)->modify('+6 days');
 return [$lunes->format('Y-m-d'), $domingo->format('Y-m-d')];
 }

 private static function etiquetaSemana(string $ini, string $fin, int $atras): string
 {
 if ($atras === 0) return 'Esta semana';
 if ($atras === 1) return 'Semana anterior';
 $d = new DateTime($ini);
 $h = new DateTime($fin);
 return $d->format('d'). '–'. $h->format('d M');
 }

 private static function clasificarTendencia(?float $ant, ?float $act): string
 {
 if ($ant === null || $act === null) return 'sin_dato';
 $diff = $act - $ant;
 if ($diff > self::UMBRAL_SIGNIFICANCIA) return 'mejora';
 if ($diff < -self::UMBRAL_SIGNIFICANCIA) return 'empeora';
 return 'estable';
 }

 private static function hayDatosAntesDe(int $idPaciente, string $fecha): bool
 {
 $r = DB::uno(
 "SELECT COUNT(*) n FROM registro_tomas
 WHERE id_usuario = :p AND fecha_programada < :f
 AND estado IN ('cumplida','no_cumplida','vencida')",
 ['p' => $idPaciente, 'f' => $fecha]
 );
 return (int)($r['n'] ?? 0) > 0;
 }

 /**
 * ¿El médico tiene acceso a este paciente? (relación activa)
 */
 public static function medicoPuedeVer(int $idMedico, int $idPaciente): bool
 {
 $r = DB::uno(
 "SELECT 1 FROM medico_paciente
 WHERE id_medico = :m AND id_paciente = :p AND activa = TRUE",
 ['m' => $idMedico, 'p' => $idPaciente]
 );
 return $r !== null;
 }

 /** Etiqueta legible de un código de motivo de omisión. */
 public static function etiquetaMotivo(?string $codigo): string
 {
 return [
 'olvido' => 'Olvido',
 'efecto_adverso' => 'Efecto adverso',
 'medicamento_no_disponible' => 'Medicamento no disponible',
 'indicacion_medica' => 'Indicación médica',
 'otro' => 'Otro',
 ][$codigo] ?? 'Sin motivo';
 }

 /** Etiqueta legible de un código de efecto adverso. */
 public static function etiquetaEfecto(string $codigo): string
 {
 return [
 'nausea' => 'Náusea',
 'mareo' => 'Mareo',
 'dolor_cabeza' => 'Dolor de cabeza',
 'somnolencia' => 'Somnolencia',
 'malestar_estomacal' => 'Malestar estomacal',
 'reaccion_alergica' => 'Reacción alérgica',
 'otro' => 'Otro',
 ][$codigo] ?? $codigo;
 }
}
