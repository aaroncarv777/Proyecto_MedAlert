<?php
/**
 * MedAlert · API de stock (Sprint 2 — HU-10)
 * --------------------------------------------------------------
 * GET  ?accion=consultar&id=X
 * GET  ?accion=panorama       — todos los medicamentos del paciente con stock
 * POST {accion: recargar, id_medicamento, unidades}
 * POST {accion: establecer, id_medicamento, unidades, unidades_por_toma, alerta_dias}
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/stock.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = Helpers::get('accion');

    if ($accion === 'consultar') {
        $idMed = (int)Helpers::get('id');
        if ($idMed <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);

        if (!puedeVerStock($idMed, $idUsuario, $tipo)) {
            Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
        }

        $stock = Stock::consultar($idMed);
        if (!$stock) Helpers::jsonRespuesta(['error' => 'Medicamento no encontrado.'], 404);
        Helpers::jsonRespuesta(['ok' => true, 'stock' => $stock]);
    }

    if ($accion === 'panorama') {
        if ($tipo !== 'Paciente') {
            Helpers::jsonRespuesta(['error' => 'Solo pacientes.'], 403);
        }
        $items = DB::todos(
            "SELECT v.*, m.nombre, m.presentacion
               FROM v_stock_medicamentos v
               JOIN medicamentos m ON m.id_medicamento = v.id_medicamento
              WHERE m.id_usuario_paciente = :p AND m.activo = TRUE
              ORDER BY FIELD(v.estado_stock, 'agotado','bajo','ok','sin_seguimiento'), m.nombre",
            ['p' => $idUsuario]
        );
        Helpers::jsonRespuesta(['ok' => true, 'panorama' => $items]);
    }

    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'recargar') {
    $idMed = (int)($datos['id_medicamento'] ?? 0);
    $unidades = (int)($datos['unidades'] ?? 0);

    if ($idMed <= 0)  Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    if (!puedeVerStock($idMed, $idUsuario, $tipo)) {
        Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
    }

    $r = Stock::recargar($idMed, $unidades, $idUsuario);
    if (!$r['ok']) Helpers::jsonRespuesta(['error' => $r['error']], 400);
    Helpers::jsonRespuesta($r);
}

if ($accion === 'establecer') {
    $idMed = (int)($datos['id_medicamento'] ?? 0);
    $unidades = isset($datos['unidades']) ? (int)$datos['unidades'] : null;
    $unidadesPorToma = (int)($datos['unidades_por_toma'] ?? 0);
    $alertaDias = (int)($datos['alerta_dias'] ?? STOCK_DIAS_ALERTA_DEFAULT);

    if ($idMed <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    if (!puedeVerStock($idMed, $idUsuario, $tipo)) {
        Helpers::jsonRespuesta(['error' => 'Sin permisos.'], 403);
    }

    $r = Stock::establecer($idMed, $unidades, $unidadesPorToma, $alertaDias, $idUsuario);
    if (!$r['ok']) Helpers::jsonRespuesta(['error' => $r['error']], 400);
    Helpers::jsonRespuesta($r);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);

function puedeVerStock(int $idMed, int $idUsuario, string $tipo): bool
{
    $m = DB::uno(
        "SELECT id_usuario_paciente, id_usuario_creador FROM medicamentos
          WHERE id_medicamento = :id",
        ['id' => $idMed]
    );
    if (!$m) return false;

    if ($tipo === 'Paciente')      return (int)$m['id_usuario_paciente'] === $idUsuario;
    if ($tipo === 'Administrador') return true;
    if ($tipo === 'Medico') {
        if ((int)$m['id_usuario_creador'] === $idUsuario) return true;
        $rel = DB::uno(
            "SELECT 1 FROM medico_paciente
              WHERE id_medico = :m AND id_paciente = :p AND activo = TRUE",
            ['m' => $idUsuario, 'p' => $m['id_usuario_paciente']]
        );
        return (bool)$rel;
    }
    return false;
}
