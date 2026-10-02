<?php
/**
 * MedAlert · API de medicamentos (Sprint 2)
 * --------------------------------------------------------------
 * HU-05  CRUD básico (Sprint 1)
 * HU-06  Médico solo gestiona pacientes asignados
 * HU-07  Horarios avanzados (diario, días semana, alternos, ciclo, comida)
 * HU-08  Detección de interacciones al guardar
 * HU-09  Cálculo automático de dosis por peso
 * HU-10  Stock y alerta cuando baja
 * HU-11  Historial detallado de cada cambio
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notificaciones.php';
require_once __DIR__ . '/../../includes/interacciones.php';
require_once __DIR__ . '/../../includes/calculo_dosis.php';
require_once __DIR__ . '/../../includes/stock.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];
$metodo = $_SERVER['REQUEST_METHOD'];

const UNIDADES_DOSIS_VALIDAS = [
    'mg','g','mcg','ml',
    'tableta','cápsula','gota','aplicación','inhalación'
];

const PRESENTACIONES_VALIDAS = [
    'Tableta','Tableta masticable','Cápsula','Jarabe',
    'Inyectable','Gotas','Crema','Inhalador','Otro'
];

const TIPOS_HORARIO_VALIDOS = [
    'diario','dias_semana','dias_alternos','ciclo','referencia_comida',
];

const REFERENCIAS_COMIDA_VALIDAS = [
    'antes_desayuno','despues_desayuno',
    'antes_comida','despues_comida',
    'antes_cena','despues_cena',
];

function normalizarNombreMedicamento(string $nombre): string
{
    $nombre = trim($nombre);
    $nombre = mb_strtolower($nombre, 'UTF-8');
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    $reemplazos = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u',
    ];
    return strtr($nombre, $reemplazos);
}

if ($metodo === 'GET') {
    $accion = Helpers::get('accion');
    $id = (int)Helpers::get('id');

    if ($accion === 'obtener' && $id > 0) {
        $m = obtenerMedicamento($id, $usuario);
        if (!$m) Helpers::jsonRespuesta(['error' => 'No encontrado o sin permisos.'], 404);
        Helpers::jsonRespuesta(['ok' => true, 'medicamento' => $m]);
    }

    if ($accion === 'historial' && $id > 0) {
        consultarHistorial($id, $usuario);
    }

    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'crear')    crearMedicamento($datos, $usuario);
if ($accion === 'editar')   editarMedicamento($datos, $usuario);
if ($accion === 'eliminar') eliminarMedicamento($datos, $usuario);

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);

/* HU-05 / HU-06 — Obtener con control de permisos */
function obtenerMedicamento(int $id, array $usuario): ?array
{
    $tipo = $usuario['tipo_nombre'];
    $idUsuario = (int)$usuario['id_usuario'];
    $m = DB::uno(
        "SELECT m.*, v.unidades_actuales AS stock_actuales,
                v.dias_restantes AS stock_dias, v.estado_stock
           FROM medicamentos m
           LEFT JOIN v_stock_medicamentos v ON v.id_medicamento = m.id_medicamento
          WHERE m.id_medicamento = :id",
        ['id' => $id]
    );
    if (!$m) return null;

    if ($tipo === 'Paciente' && (int)$m['id_usuario_paciente'] !== $idUsuario) return null;
    if ($tipo === 'Medico') {
        if ((int)$m['id_usuario_creador'] !== $idUsuario) {
            $rel = DB::uno(
                "SELECT 1 FROM medico_paciente
                  WHERE id_medico = :med AND id_paciente = :pac AND activo = TRUE",
                ['med' => $idUsuario, 'pac' => (int)$m['id_usuario_paciente']]
            );
            if (!$rel) return null;
        }
    }
    return $m;
}

/* HU-11 — Historial completo de un medicamento (orden DESC por petición del cliente) */
function consultarHistorial(int $idMed, array $usuario): void
{
    $m = obtenerMedicamento($idMed, $usuario);
    if (!$m) Helpers::jsonRespuesta(['error' => 'No encontrado o sin permisos.'], 404);

    $filtroCampo = trim((string)Helpers::get('campo'));
    $filtroDesde = trim((string)Helpers::get('desde'));
    $filtroHasta = trim((string)Helpers::get('hasta'));

    $sql = "SELECT h.*, u.nombre_completo AS modificador_nombre, t.nombre AS modificador_tipo
              FROM historial_cambios_medicamento h
              JOIN usuarios u      ON u.id_usuario = h.id_usuario_modificador
              JOIN tipos_usuario t ON t.id_tipo    = u.id_tipo
             WHERE h.id_medicamento = :id";
    $params = ['id' => $idMed];

    if ($filtroCampo !== '') {
        $sql .= " AND h.campo_modificado = :campo";
        $params['campo'] = $filtroCampo;
    }
    if ($filtroDesde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroDesde)) {
        $sql .= " AND DATE(h.fecha_cambio) >= :desde";
        $params['desde'] = $filtroDesde;
    }
    if ($filtroHasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroHasta)) {
        $sql .= " AND DATE(h.fecha_cambio) <= :hasta";
        $params['hasta'] = $filtroHasta;
    }
    // Cliente pidió: lo más reciente primero
    $sql .= " ORDER BY h.fecha_cambio DESC";

    $hist = DB::todos($sql, $params);
    Helpers::jsonRespuesta(['ok' => true, 'historial' => $hist, 'medicamento' => $m]);
}

/* HU-05 / HU-06 / HU-07 / HU-08 / HU-09 / HU-10 — Crear */
function crearMedicamento(array $d, array $usuario): void
{
    $tipo = $usuario['tipo_nombre'];
    $idUsuario = (int)$usuario['id_usuario'];

    // HU-04: el médico sin cédula validada no puede prescribir
    if ($tipo === 'Medico' && empty($usuario['cedula_validada'])) {
        Helpers::jsonRespuesta([
            'error' => 'Tus credenciales aún no han sido validadas por el administrador. No puedes prescribir medicamentos hasta entonces.'
        ], 403);
    }

    $errores = validarCamposMedicamento($d);
    if (!empty($errores)) Helpers::jsonRespuesta(['error' => implode(' ', $errores)], 400);

    $idPaciente = $tipo === 'Medico'
        ? (int)($d['id_paciente'] ?? 0)
        : $idUsuario;
    if ($idPaciente <= 0) Helpers::jsonRespuesta(['error' => 'Paciente no válido.'], 400);

    // HU-06: solo médico asignado puede prescribir
    if ($tipo === 'Medico') {
        $rel = DB::uno(
            "SELECT 1 FROM medico_paciente
              WHERE id_medico = :m AND id_paciente = :p AND activo = TRUE",
            ['m' => $idUsuario, 'p' => $idPaciente]
        );
        if (!$rel) {
            Helpers::jsonRespuesta([
                'error' => 'No tienes a este paciente asignado. Solicita al administrador que te lo asigne.'
            ], 403);
        }
    }

    // Validación duplicado (Sprint 1)
    $duplicadoAceptado = !empty($d['duplicado_aceptado']);
    if (!$duplicadoAceptado) {
        $nombreNorm = normalizarNombreMedicamento($d['nombre']);
        $activos = DB::todos(
            "SELECT id_medicamento, nombre, cantidad_dosis, unidad_dosis, frecuencia_horas, hora_inicio
               FROM medicamentos
              WHERE id_usuario_paciente = :p AND activo = TRUE",
            ['p' => $idPaciente]
        );
        foreach ($activos as $existente) {
            if (normalizarNombreMedicamento($existente['nombre']) === $nombreNorm) {
                $cant = (float)$existente['cantidad_dosis'];
                $cantStr = $cant == floor($cant) ? (string)(int)$cant : rtrim(rtrim((string)$cant, '0'), '.');
                Helpers::jsonRespuesta([
                    'error' => 'Ya le asignaste este medicamento al paciente.',
                    'duplicado' => [
                        'id' => (int)$existente['id_medicamento'],
                        'nombre' => $existente['nombre'],
                        'dosis' => $cantStr . ' ' . $existente['unidad_dosis'],
                        'frecuencia' => rtrim(rtrim((string)$existente['frecuencia_horas'], '0'), '.'),
                        'hora' => substr($existente['hora_inicio'], 0, 5),
                    ],
                ], 409);
            }
        }
    }

    $instMed = $tipo === 'Medico'   ? trim($d['instrucciones'] ?? '') : null;
    $instPac = $tipo === 'Paciente' ? trim($d['instrucciones'] ?? '') : null;

    // HU-07 — Horario avanzado
    $tipoHorario = $d['tipo_horario'] ?? 'diario';
    if (!in_array($tipoHorario, TIPOS_HORARIO_VALIDOS, true)) {
        Helpers::jsonRespuesta(['error' => 'Tipo de horario no válido.'], 400);
    }
    [$diasSemana, $intervaloDias, $cicloAct, $cicloDes, $refComida, $minRef] =
        parsearHorarioAvanzado($d, $tipoHorario);

    // HU-07 — Si es referencia a comida, recalcular hora_inicio según perfil del paciente
    $horaInicio = $d['hora_inicio'];
    if ($tipoHorario === 'referencia_comida') {
        $horaInicio = calcularHoraDesdeReferenciaComida($idPaciente, $refComida, $minRef);
    }

    // HU-09 — Cálculo de dosis por peso
    $dosisMgKg = null;
    $dosisCalcAuto = false;
    $cantidadFinal = (float)$d['cantidad_dosis'];
    if ($tipo === 'Medico' && !empty($d['dosis_mg_por_kg'])) {
        $mgKg = (float)$d['dosis_mg_por_kg'];
        $paciente = DB::uno(
            "SELECT peso_kg, fecha_nacimiento FROM usuarios WHERE id_usuario = :id",
            ['id' => $idPaciente]
        );
        if (!$paciente || !$paciente['peso_kg'] || !$paciente['fecha_nacimiento']) {
            Helpers::jsonRespuesta([
                'error' => 'Para usar cálculo por peso el paciente debe tener registrados peso y fecha de nacimiento en su perfil.'
            ], 400);
        }
        $edad = CalculoDosis::calcularEdad((string)$paciente['fecha_nacimiento']);
        $resultado = CalculoDosis::calcular(
            $d['nombre'], $mgKg,
            (float)$paciente['peso_kg'], $edad,
            (float)$d['frecuencia_horas']
        );
        if (!$resultado['ok']) {
            Helpers::jsonRespuesta(['error' => $resultado['error']], 400);
        }
        $dosisMgKg = $mgKg;
        $dosisCalcAuto = empty($d['ajuste_manual_dosis']);
        if ($dosisCalcAuto) {
            $cantidadFinal = (float)$resultado['dosis_por_toma_mg'];
            $d['unidad_dosis'] = 'mg';
        }
    }

    // HU-10 — Stock inicial
    $unidadesActuales  = isset($d['unidades_actuales']) ? (int)$d['unidades_actuales'] : 0;
    $unidadesPorToma   = isset($d['unidades_por_toma']) ? (int)$d['unidades_por_toma'] : 0;
    $alertaStockDias   = isset($d['alerta_stock_dias']) ? (int)$d['alerta_stock_dias'] : STOCK_DIAS_ALERTA_DEFAULT;
    if ($unidadesActuales < 0 || $unidadesActuales > STOCK_UNIDADES_MAX) {
        Helpers::jsonRespuesta([
            'error' => 'Las unidades actuales deben estar entre 0 y ' . STOCK_UNIDADES_MAX . '.'
        ], 400);
    }
    if ($unidadesPorToma < 0 || $unidadesPorToma > 999) {
        Helpers::jsonRespuesta(['error' => 'Las unidades por toma deben estar entre 0 y 999.'], 400);
    }
    if ($alertaStockDias < STOCK_DIAS_ALERTA_MIN || $alertaStockDias > STOCK_DIAS_ALERTA_MAX) {
        Helpers::jsonRespuesta([
            'error' => 'El umbral de alerta de stock debe estar entre '
                . STOCK_DIAS_ALERTA_MIN . ' y ' . STOCK_DIAS_ALERTA_MAX . ' días.'
        ], 400);
    }

    $db = DB::obtener();
    $db->beginTransaction();
    try {
        DB::consultar(
            "INSERT INTO medicamentos
              (id_usuario_paciente, id_usuario_creador, nombre, presentacion,
               cantidad_dosis, unidad_dosis,
               frecuencia_horas, hora_inicio, fecha_inicio, fecha_fin,
               instrucciones_medico, instrucciones_paciente, duplicado_aceptado,
               tipo_horario, dias_semana_bitmask, intervalo_dias,
               ciclo_dias_activo, ciclo_dias_descanso,
               referencia_comida, minutos_referencia,
               dosis_mg_por_kg, dosis_calculada_automatica,
               unidades_actuales, unidades_por_toma, alerta_stock_dias)
             VALUES (:pac, :cre, :nom, :pres, :cant, :uni, :frec, :hora, :ini, :fin,
                     :insMed, :insPac, :dupAcept,
                     :tipoH, :dsb, :idias, :cAct, :cDes, :rcom, :minR,
                     :mgKg, :calcAuto,
                     :unAct, :unToma, :aler)",
            [
                'pac'=>$idPaciente,'cre'=>$idUsuario,
                'nom'=>$d['nombre'],'pres'=>$d['presentacion'],
                'cant'=>$cantidadFinal,'uni'=>$d['unidad_dosis'],
                'frec'=>(float)$d['frecuencia_horas'],'hora'=>$horaInicio,
                'ini'=>$d['fecha_inicio'],'fin'=>$d['fecha_fin'] ?: null,
                'insMed'=>$instMed ?: null,'insPac'=>$instPac ?: null,
                'dupAcept'=>$duplicadoAceptado ? 1 : 0,
                'tipoH'=>$tipoHorario,'dsb'=>$diasSemana,'idias'=>$intervaloDias,
                'cAct'=>$cicloAct,'cDes'=>$cicloDes,
                'rcom'=>$refComida,'minR'=>$minRef,
                'mgKg'=>$dosisMgKg,'calcAuto'=>$dosisCalcAuto ? 1 : 0,
                'unAct'=>$unidadesActuales,'unToma'=>$unidadesPorToma,'aler'=>$alertaStockDias,
            ]
        );
        $idMed = DB::ultimoId();

        DB::consultar(
            "INSERT INTO horarios_medicamento (id_medicamento, hora_programada) VALUES (:m, :h)",
            ['m' => $idMed, 'h' => $horaInicio]
        );
        $idHorario = DB::ultimoId();

        $tomasGeneradas = generarTomasSegunHorario(
            $idMed, $idHorario, $idPaciente,
            $tipoHorario, $d, $horaInicio,
            $diasSemana, $intervaloDias, $cicloAct, $cicloDes
        );

        foreach ($tomasGeneradas as $idToma) {
            Notificaciones::programarParaToma($idToma);
        }

        // HU-11 — Historial creación
        DB::consultar(
            "INSERT INTO historial_cambios_medicamento
              (id_medicamento, id_usuario_modificador, campo_modificado, valor_nuevo, tipo_accion)
             VALUES (:m, :u, 'creacion', :v, 'creacion')",
            ['m' => $idMed, 'u' => $idUsuario, 'v' => $d['nombre']]
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        Helpers::jsonRespuesta(['error' => 'Error al crear: ' . $e->getMessage()], 500);
    }

    // HU-08 — Detectar interacciones
    $alertas = Interacciones::detectarParaMedicamento($idMed);

    // HU-10 — Evaluar stock inicial
    Stock::evaluarYAlertar($idMed);

    $respuesta = ['ok' => true, 'id_medicamento' => $idMed];
    if (!empty($alertas)) {
        $respuesta['alertas_interaccion'] = $alertas;
        $respuesta['mensaje_interaccion'] = 'Se detectaron interacciones medicamentosas. Por favor revísalas.';
    }
    Helpers::jsonRespuesta($respuesta);
}

/* HU-05 / HU-06 / HU-07 / HU-08 / HU-11 — Editar */
function editarMedicamento(array $d, array $usuario): void
{
    $tipo = $usuario['tipo_nombre'];
    $idUsuario = (int)$usuario['id_usuario'];
    $idMed = (int)($d['id_medicamento'] ?? 0);

    if ($idMed <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    $existente = DB::uno("SELECT * FROM medicamentos WHERE id_medicamento = :id", ['id' => $idMed]);
    if (!$existente) Helpers::jsonRespuesta(['error' => 'Medicamento no encontrado.'], 404);

    $esCreadorPaciente = (int)$existente['id_usuario_creador'] === (int)$existente['id_usuario_paciente'];
    if ($tipo === 'Paciente') {
        if ((int)$existente['id_usuario_paciente'] !== $idUsuario) {
            Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
        }
        if (!$esCreadorPaciente) {
            Helpers::jsonRespuesta([
                'error' => 'No puedes modificar un medicamento que prescribió tu médico.'
            ], 403);
        }
    } elseif ($tipo === 'Medico') {
        // HU-06 — el servidor valida que el médico solicitante es quien recetó el medicamento
        if ((int)$existente['id_usuario_creador'] !== $idUsuario) {
            Helpers::jsonRespuesta([
                'error' => 'Sólo puedes editar tus propias prescripciones.'
            ], 403);
        }
        if (empty($usuario['cedula_validada'])) {
            Helpers::jsonRespuesta([
                'error' => 'Tus credenciales aún no han sido validadas por el administrador.'
            ], 403);
        }
    } else {
        Helpers::jsonRespuesta(['error' => 'Tipo no autorizado.'], 403);
    }

    $errores = validarCamposMedicamento($d);
    if (!empty($errores)) Helpers::jsonRespuesta(['error' => implode(' ', $errores)], 400);

    $tipoHorario = $d['tipo_horario'] ?? $existente['tipo_horario'] ?? 'diario';
    if (!in_array($tipoHorario, TIPOS_HORARIO_VALIDOS, true)) {
        Helpers::jsonRespuesta(['error' => 'Tipo de horario no válido.'], 400);
    }
    [$diasSemana, $intervaloDias, $cicloAct, $cicloDes, $refComida, $minRef] =
        parsearHorarioAvanzado($d, $tipoHorario);

    $horaInicio = $d['hora_inicio'];
    if ($tipoHorario === 'referencia_comida') {
        $horaInicio = calcularHoraDesdeReferenciaComida(
            (int)$existente['id_usuario_paciente'], $refComida, $minRef
        );
    }

    $unidadesPorToma = isset($d['unidades_por_toma'])
        ? (int)$d['unidades_por_toma']
        : (int)$existente['unidades_por_toma'];
    $alertaStockDias = isset($d['alerta_stock_dias'])
        ? (int)$d['alerta_stock_dias']
        : (int)$existente['alerta_stock_dias'];
    if ($unidadesPorToma < 0 || $unidadesPorToma > 999) {
        Helpers::jsonRespuesta(['error' => 'Las unidades por toma deben estar entre 0 y 999.'], 400);
    }
    if ($alertaStockDias < STOCK_DIAS_ALERTA_MIN || $alertaStockDias > STOCK_DIAS_ALERTA_MAX) {
        Helpers::jsonRespuesta([
            'error' => 'El umbral de alerta de stock debe estar entre '
                . STOCK_DIAS_ALERTA_MIN . ' y ' . STOCK_DIAS_ALERTA_MAX . ' días.'
        ], 400);
    }

    // HU-11 — comparación de cambios
    $valoresNuevos = [
        'nombre'              => $d['nombre'],
        'presentacion'        => $d['presentacion'],
        'cantidad_dosis'      => (float)$d['cantidad_dosis'],
        'unidad_dosis'        => $d['unidad_dosis'],
        'frecuencia_horas'    => (float)$d['frecuencia_horas'],
        'hora_inicio'         => $horaInicio,
        'fecha_inicio'        => $d['fecha_inicio'],
        'fecha_fin'           => $d['fecha_fin'] ?: null,
        'tipo_horario'        => $tipoHorario,
        'dias_semana_bitmask' => $diasSemana,
        'intervalo_dias'      => $intervaloDias,
        'ciclo_dias_activo'   => $cicloAct,
        'ciclo_dias_descanso' => $cicloDes,
        'referencia_comida'   => $refComida,
        'minutos_referencia'  => $minRef,
        'unidades_por_toma'   => $unidadesPorToma,
        'alerta_stock_dias'   => $alertaStockDias,
    ];
    $cambios = [];
    foreach ($valoresNuevos as $campo => $nue) {
        $ant = (string)($existente[$campo] ?? '');
        if ($ant !== (string)$nue) {
            $cambios[$campo] = ['anterior' => $ant, 'nuevo' => (string)$nue];
        }
    }
    $instCampo = $tipo === 'Medico' ? 'instrucciones_medico' : 'instrucciones_paciente';
    $instNuevo = trim($d['instrucciones'] ?? '');
    if ($instNuevo !== (string)($existente[$instCampo] ?? '')) {
        $cambios[$instCampo] = ['anterior' => $existente[$instCampo], 'nuevo' => $instNuevo];
    }

    $db = DB::obtener();
    $db->beginTransaction();
    try {
        DB::consultar(
            "UPDATE medicamentos SET
                 nombre=:nom, presentacion=:pres,
                 cantidad_dosis=:cant, unidad_dosis=:uni,
                 frecuencia_horas=:frec, hora_inicio=:hora,
                 fecha_inicio=:ini, fecha_fin=:fin,
                 {$instCampo}=:inst,
                 tipo_horario=:tipoH, dias_semana_bitmask=:dsb, intervalo_dias=:idias,
                 ciclo_dias_activo=:cAct, ciclo_dias_descanso=:cDes,
                 referencia_comida=:rcom, minutos_referencia=:minR,
                 unidades_por_toma=:unToma, alerta_stock_dias=:aler
              WHERE id_medicamento=:id",
            [
                'nom'=>$d['nombre'],'pres'=>$d['presentacion'],
                'cant'=>(float)$d['cantidad_dosis'],'uni'=>$d['unidad_dosis'],
                'frec'=>(float)$d['frecuencia_horas'],'hora'=>$horaInicio,
                'ini'=>$d['fecha_inicio'],'fin'=>$d['fecha_fin'] ?: null,
                'inst'=>$instNuevo ?: null,
                'tipoH'=>$tipoHorario,'dsb'=>$diasSemana,'idias'=>$intervaloDias,
                'cAct'=>$cicloAct,'cDes'=>$cicloDes,
                'rcom'=>$refComida,'minR'=>$minRef,
                'unToma'=>$unidadesPorToma,'aler'=>$alertaStockDias,
                'id'=>$idMed,
            ]
        );

        foreach ($cambios as $campo => $valores) {
            DB::consultar(
                "INSERT INTO historial_cambios_medicamento
                  (id_medicamento, id_usuario_modificador, campo_modificado,
                   valor_anterior, valor_nuevo, tipo_accion)
                 VALUES (:m, :u, :c, :va, :vn, 'modificacion')",
                ['m'=>$idMed,'u'=>$idUsuario,'c'=>$campo,
                 'va'=>(string)($valores['anterior'] ?? ''),
                 'vn'=>(string)($valores['nuevo'] ?? '')]
            );
        }

        // HU-06 — notificar al paciente cuando el médico modifica
        if ($tipo === 'Medico' && !empty($cambios)) {
            $listaCampos = implode(', ', array_keys($cambios));
            Notificaciones::crearGenerica(
                (int)$existente['id_usuario_paciente'],
                'Tu médico modificó tu medicamento',
                'El Dr(a). ' . ($usuario['nombre_completo'] ?? '') . ' modificó "' . $existente['nombre']
                    . '". Campos actualizados: ' . $listaCampos . '. Revísalo en la sección de medicamentos.',
                'generico',
                $idMed
            );
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        Helpers::jsonRespuesta(['error' => 'Error al editar: ' . $e->getMessage()], 500);
    }

    // HU-08 — re-detectar interacciones
    $alertas = Interacciones::detectarParaMedicamento($idMed);

    $respuesta = ['ok' => true];
    if (!empty($alertas)) {
        $respuesta['alertas_interaccion'] = $alertas;
        $respuesta['mensaje_interaccion'] = 'Tras la modificación se detectaron interacciones nuevas.';
    }
    Helpers::jsonRespuesta($respuesta);
}

/* HU-05 — Eliminar */
function eliminarMedicamento(array $d, array $usuario): void
{
    $tipo = $usuario['tipo_nombre'];
    $idUsuario = (int)$usuario['id_usuario'];
    $idMed = (int)($d['id_medicamento'] ?? 0);

    if ($idMed <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    $existente = DB::uno("SELECT * FROM medicamentos WHERE id_medicamento = :id", ['id' => $idMed]);
    if (!$existente) Helpers::jsonRespuesta(['error' => 'No encontrado.'], 404);

    if ($tipo === 'Paciente' && (int)$existente['id_usuario_paciente'] !== $idUsuario) {
        Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
    }
    if ($tipo === 'Medico' && (int)$existente['id_usuario_creador'] !== $idUsuario) {
        Helpers::jsonRespuesta(['error' => 'Sólo puedes eliminar tus prescripciones.'], 403);
    }

    DB::consultar("UPDATE medicamentos SET activo=FALSE WHERE id_medicamento=:id", ['id' => $idMed]);
    Interacciones::marcarObsoletasPorMedicamento($idMed);

    $tomasFuturas = DB::todos(
        "SELECT id_registro FROM registro_tomas
          WHERE id_medicamento = :m AND estado = 'pendiente'
            AND CONCAT(fecha_programada, ' ', hora_programada) >= NOW()",
        ['m' => $idMed]
    );
    foreach ($tomasFuturas as $t) {
        Notificaciones::cancelarParaToma((int)$t['id_registro'], 'medicamento eliminado');
    }

    DB::consultar(
        "INSERT INTO historial_cambios_medicamento
          (id_medicamento, id_usuario_modificador, campo_modificado,
           valor_anterior, valor_nuevo, tipo_accion)
         VALUES (:m, :u, 'activo', '1', '0', 'eliminacion')",
        ['m' => $idMed, 'u' => $idUsuario]
    );
    Helpers::jsonRespuesta(['ok' => true]);
}

/* Validaciones */
function validarCamposMedicamento(array $d): array
{
    $errores = [];
    if (empty($d['nombre']) || mb_strlen($d['nombre']) < 2) $errores[] = 'Nombre obligatorio.';
    if (mb_strlen($d['nombre'] ?? '') > NOMBRE_MEDICAMENTO_MAX) {
        $errores[] = 'El nombre no puede superar ' . NOMBRE_MEDICAMENTO_MAX . ' caracteres.';
    }
    if (!in_array($d['presentacion'] ?? '', PRESENTACIONES_VALIDAS, true)) {
        $errores[] = 'Presentación no válida.';
    }
    $cantidad = (float)($d['cantidad_dosis'] ?? 0);
    if ($cantidad <= 0) $errores[] = 'La cantidad de dosis debe ser mayor a 0.';
    if ($cantidad > 100000) $errores[] = 'La cantidad de dosis es demasiado alta.';
    if (!in_array($d['unidad_dosis'] ?? '', UNIDADES_DOSIS_VALIDAS, true)) {
        $errores[] = 'Unidad de dosis no válida.';
    }
    if (!isset($d['frecuencia_horas']) || (float)$d['frecuencia_horas'] <= 0) {
        $errores[] = 'Frecuencia inválida.';
    }
    if ((float)($d['frecuencia_horas'] ?? 0) > 168) {
        $errores[] = 'La frecuencia no puede superar 168 horas (1 semana).';
    }
    // Si es referencia a comida no se valida hora_inicio porque la calcula el sistema
    if (($d['tipo_horario'] ?? 'diario') !== 'referencia_comida') {
        if (empty($d['hora_inicio']) || !preg_match('/^\d{2}:\d{2}/', $d['hora_inicio'])) {
            $errores[] = 'Hora de inicio inválida.';
        }
    }
    if (empty($d['fecha_inicio'])) {
        $errores[] = 'Fecha de inicio obligatoria.';
    } else {
        $hoy = date('Y-m-d');
        $fi = $d['fecha_inicio'];
        $fechaOk = DateTime::createFromFormat('Y-m-d', $fi);
        if (!$fechaOk || $fechaOk->format('Y-m-d') !== $fi) {
            $errores[] = 'La fecha de inicio no es válida.';
        } elseif ($fi < $hoy) {
            $errores[] = 'La fecha de inicio no puede ser anterior a hoy.';
        } elseif ($fi === $hoy
                  && ($d['tipo_horario'] ?? 'diario') !== 'referencia_comida'
                  && !empty($d['hora_inicio'])
                  && preg_match('/^\d{2}:\d{2}/', $d['hora_inicio'])) {
            // Si empieza hoy, la hora de inicio no puede ser anterior a la hora actual
            if (substr($d['hora_inicio'], 0, 5) < date('H:i')) {
                $errores[] = 'La hora de inicio ya pasó. Elige una hora posterior a la actual o una fecha futura.';
            }
        }
        // Fecha de fin: si se indica, debe ser igual o posterior a la de inicio
        if (!empty($d['fecha_fin'])) {
            $ff = $d['fecha_fin'];
            $ffOk = DateTime::createFromFormat('Y-m-d', $ff);
            if (!$ffOk || $ffOk->format('Y-m-d') !== $ff) {
                $errores[] = 'La fecha de fin no es válida.';
            } elseif ($ff < $fi) {
                $errores[] = 'La fecha de fin debe ser igual o posterior a la de inicio.';
            }
        }
    }
    if (!empty($d['instrucciones']) && mb_strlen($d['instrucciones']) > INSTRUCCION_MAX_CARACTERES) {
        $errores[] = 'Las instrucciones no pueden superar ' . INSTRUCCION_MAX_CARACTERES . ' caracteres.';
    }
    return $errores;
}

/* HU-07 — Parsear configuración de horario avanzado */
function parsearHorarioAvanzado(array $d, string $tipo): array
{
    $diasSemana=null; $intervaloDias=null; $cicloAct=null;
    $cicloDes=null; $refComida=null; $minRef=null;

    if ($tipo === 'dias_semana') {
        if (isset($d['dias_semana']) && is_array($d['dias_semana'])) {
            $bm = 0;
            foreach ($d['dias_semana'] as $dia) {
                $n = (int)$dia;
                if ($n >= 1 && $n <= 7) $bm |= (1 << ($n - 1));
            }
            $diasSemana = $bm;
        } elseif (isset($d['dias_semana_bitmask'])) {
            $diasSemana = (int)$d['dias_semana_bitmask'];
        }
        if ($diasSemana === null || $diasSemana <= 0 || $diasSemana > 127) {
            Helpers::jsonRespuesta(['error' => 'Debes seleccionar al menos un día de la semana.'], 400);
        }
    }

    if ($tipo === 'dias_alternos') {
        $intervaloDias = isset($d['intervalo_dias']) ? (int)$d['intervalo_dias'] : 2;
        if ($intervaloDias < 2 || $intervaloDias > 30) {
            Helpers::jsonRespuesta(['error' => 'El intervalo de días alternos debe estar entre 2 y 30.'], 400);
        }
    }

    if ($tipo === 'ciclo') {
        $cicloAct = isset($d['ciclo_dias_activo']) ? (int)$d['ciclo_dias_activo'] : 0;
        $cicloDes = isset($d['ciclo_dias_descanso']) ? (int)$d['ciclo_dias_descanso'] : 0;
        if ($cicloAct < 1 || $cicloAct > 90) {
            Helpers::jsonRespuesta(['error' => 'Los días activos del ciclo deben estar entre 1 y 90.'], 400);
        }
        if ($cicloDes < 1 || $cicloDes > 90) {
            Helpers::jsonRespuesta(['error' => 'Los días de descanso del ciclo deben estar entre 1 y 90.'], 400);
        }
    }

    if ($tipo === 'referencia_comida') {
        $refComida = $d['referencia_comida'] ?? '';
        if (!in_array($refComida, REFERENCIAS_COMIDA_VALIDAS, true)) {
            Helpers::jsonRespuesta(['error' => 'Referencia de comida no válida.'], 400);
        }
        $minRef = isset($d['minutos_referencia']) ? (int)$d['minutos_referencia'] : 0;
        if ($minRef < 0 || $minRef > 240) {
            Helpers::jsonRespuesta(['error' => 'Los minutos de referencia deben estar entre 0 y 240.'], 400);
        }
    }

    return [$diasSemana, $intervaloDias, $cicloAct, $cicloDes, $refComida, $minRef];
}

/* HU-07 — Calcular hora_inicio según la hora de comida del paciente */
function calcularHoraDesdeReferenciaComida(int $idPaciente, string $referencia, int $minutos): string
{
    $p = DB::uno(
        "SELECT hora_desayuno, hora_comida, hora_cena FROM usuarios WHERE id_usuario = :id",
        ['id' => $idPaciente]
    );
    if (!$p) Helpers::jsonRespuesta(['error' => 'Paciente no encontrado.'], 404);

    $horaBase = match (true) {
        str_contains($referencia, 'desayuno') => (string)($p['hora_desayuno'] ?? '08:00:00'),
        str_contains($referencia, 'comida')   => (string)($p['hora_comida']   ?? '14:00:00'),
        str_contains($referencia, 'cena')     => (string)($p['hora_cena']     ?? '20:00:00'),
        default => '08:00:00',
    };

    $signo = str_starts_with($referencia, 'antes_') ? -1 : 1;
    $ts = strtotime('2000-01-01 ' . $horaBase) + ($signo * $minutos * 60);
    return date('H:i:s', $ts);
}

/* HU-07 — Generar tomas según tipo de horario */
function generarTomasSegunHorario(
    int $idMed, int $idHorario, int $idPaciente,
    string $tipo, array $d, string $horaInicio,
    ?int $diasSemana, ?int $intervaloDias, ?int $cicloAct, ?int $cicloDes
): array {
    $hoy = date('Y-m-d');
    $diaSemana = (int)date('N');

    $aplicaHoy = true;
    if ($tipo === 'dias_semana' && $diasSemana !== null) {
        $bitDia = 1 << ($diaSemana - 1);
        $aplicaHoy = ($diasSemana & $bitDia) !== 0;
    }
    if (!$aplicaHoy) return [];

    if ($tipo === 'referencia_comida') {
        return [insertarToma($idMed, $idHorario, $idPaciente, $hoy, $horaInicio)];
    }

    $horaActual = strtotime($hoy . ' ' . $horaInicio);
    $finDia = strtotime($hoy . ' 23:59:59');
    $segFrec = (int)(((float)$d['frecuencia_horas']) * 3600);
    if ($segFrec <= 0) $segFrec = 24 * 3600;

    $ids = [];
    while ($horaActual <= $finDia) {
        $ids[] = insertarToma($idMed, $idHorario, $idPaciente,
            date('Y-m-d', $horaActual), date('H:i:s', $horaActual));
        $horaActual += $segFrec;
        if ((float)$d['frecuencia_horas'] >= 24) break;
    }
    return $ids;
}

function insertarToma(int $idMed, int $idHorario, int $idPaciente, string $fecha, string $hora): int
{
    DB::consultar(
        "INSERT INTO registro_tomas
          (id_medicamento, id_usuario, id_horario, fecha_programada, hora_programada, estado)
         VALUES (:m, :u, :h, :fp, :hp, 'pendiente')",
        ['m' => $idMed, 'u' => $idPaciente, 'h' => $idHorario, 'fp' => $fecha, 'hp' => $hora]
    );
    return (int)DB::ultimoId();
}
