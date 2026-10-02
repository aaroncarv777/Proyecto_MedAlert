<?php
/**
 * MedAlert · API de cálculo de dosis (Sprint 2 — HU-09)
 * --------------------------------------------------------------
 * POST {accion: calcular, nombre, mg_kg_dia, id_paciente, frecuencia_horas}
 * GET  ?accion=listar_medicamentos     — devuelve medicamentos con dosis máximas registradas
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/calculo_dosis.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = Helpers::get('accion');
    if ($accion === 'listar_medicamentos') {
        Helpers::jsonRespuesta([
            'ok' => true,
            'medicamentos' => CalculoDosis::listarMedicamentosConDosisMax(),
        ]);
    }
    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'calcular') {
    if ($tipo !== 'Medico') {
        Helpers::jsonRespuesta([
            'error' => 'Solo el médico puede usar la calculadora de dosis.'
        ], 403);
    }
    if (empty($usuario['cedula_validada'])) {
        Helpers::jsonRespuesta([
            'error' => 'Tus credenciales aún no han sido validadas.'
        ], 403);
    }

    $nombre = trim($datos['nombre'] ?? '');
    $mgKg = (float)($datos['mg_kg_dia'] ?? 0);
    $idPaciente = (int)($datos['id_paciente'] ?? 0);
    $frec = (float)($datos['frecuencia_horas'] ?? 0);

    if ($nombre === '') Helpers::jsonRespuesta(['error' => 'Nombre del medicamento requerido.'], 400);
    if ($mgKg <= 0)     Helpers::jsonRespuesta(['error' => 'Debes indicar la dosis en mg/kg/día.'], 400);
    if ($idPaciente<= 0)Helpers::jsonRespuesta(['error' => 'Debes indicar el paciente.'], 400);
    if ($frec <= 0)     Helpers::jsonRespuesta(['error' => 'Frecuencia inválida.'], 400);

    // Verificar relación médico-paciente
    $rel = DB::uno(
        "SELECT 1 FROM medico_paciente
          WHERE id_medico = :m AND id_paciente = :p AND activo = TRUE",
        ['m' => $idUsuario, 'p' => $idPaciente]
    );
    if (!$rel) Helpers::jsonRespuesta(['error' => 'No tienes a este paciente asignado.'], 403);

    $p = DB::uno(
        "SELECT peso_kg, fecha_nacimiento, nombre_completo
           FROM usuarios WHERE id_usuario = :id",
        ['id' => $idPaciente]
    );
    if (!$p) Helpers::jsonRespuesta(['error' => 'Paciente no encontrado.'], 404);

    if (empty($p['peso_kg']) || empty($p['fecha_nacimiento'])) {
        Helpers::jsonRespuesta([
            'error' => 'El paciente debe tener peso y fecha de nacimiento registrados para usar el cálculo.'
        ], 400);
    }

    $edad = CalculoDosis::calcularEdad((string)$p['fecha_nacimiento']);
    $resultado = CalculoDosis::calcular($nombre, $mgKg, (float)$p['peso_kg'], $edad, $frec);
    if (!$resultado['ok']) Helpers::jsonRespuesta(['error' => $resultado['error']], 400);

    $resultado['paciente'] = [
        'nombre' => $p['nombre_completo'],
        'peso_kg' => (float)$p['peso_kg'],
        'edad' => $edad,
    ];
    Helpers::jsonRespuesta($resultado);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
