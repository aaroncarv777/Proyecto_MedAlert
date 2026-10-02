<?php
/**
 * Módulo de stock de medicamentos — HU-10
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificaciones.php';

class Stock
{
    /**
     * Descuenta unidades del stock al marcarse una toma como cumplida.
     * Lanza alerta cuando los días disponibles ≤ alerta_stock_dias.
     */
    public static function descontarPorToma(int $idMedicamento): array
    {
        $m = DB::uno(
            "SELECT * FROM medicamentos WHERE id_medicamento = :id AND activo = TRUE",
            ['id' => $idMedicamento]
        );
        if (!$m) return ['ok' => false, 'error' => 'Medicamento no encontrado.'];

        // Si no se lleva control de stock, salir silenciosamente
        if ($m['unidades_actuales'] === null) return ['ok' => true, 'sin_seguimiento' => true];

        $nuevas = max(0, (int)$m['unidades_actuales'] - (int)$m['unidades_por_toma']);
        DB::consultar(
            "UPDATE medicamentos SET unidades_actuales = :n WHERE id_medicamento = :id",
            ['n' => $nuevas, 'id' => $idMedicamento]
        );

        return self::evaluarYAlertar($idMedicamento);
    }

    /**
     * Devuelve las unidades_por_toma al stock cuando el paciente deshace
     * una toma previamente marcada como cumplida (ventana 30 min — Sprint 1).
     * No supera el máximo de STOCK_UNIDADES_MAX.
     */
    public static function revertirDescuentoPorToma(int $idMedicamento): array
    {
        $m = DB::uno(
            "SELECT * FROM medicamentos WHERE id_medicamento = :id AND activo = TRUE",
            ['id' => $idMedicamento]
        );
        if (!$m) return ['ok' => false, 'error' => 'Medicamento no encontrado.'];
        if ($m['unidades_actuales'] === null) return ['ok' => true, 'sin_seguimiento' => true];

        $nuevas = min(STOCK_UNIDADES_MAX,
            (int)$m['unidades_actuales'] + (int)$m['unidades_por_toma']);
        DB::consultar(
            "UPDATE medicamentos
                SET unidades_actuales   = :n,
                    alerta_stock_enviada = FALSE
              WHERE id_medicamento = :id",
            ['n' => $nuevas, 'id' => $idMedicamento]
        );
        return ['ok' => true, 'unidades_actuales' => $nuevas];
    }

    /**
     * Suma unidades al stock manualmente (recarga del paciente).
     */
    public static function recargar(int $idMedicamento, int $unidades, int $idUsuarioAccion): array
    {
        if ($unidades <= 0 || $unidades > STOCK_UNIDADES_MAX) {
            return ['ok' => false,
                    'error' => 'Las unidades deben estar entre 1 y ' . STOCK_UNIDADES_MAX . '.'];
        }
        $m = DB::uno(
            "SELECT id_medicamento, id_usuario_paciente, unidades_actuales, nombre
               FROM medicamentos WHERE id_medicamento = :id AND activo = TRUE",
            ['id' => $idMedicamento]
        );
        if (!$m) return ['ok' => false, 'error' => 'Medicamento no encontrado.'];

        $previas = (int)($m['unidades_actuales'] ?? 0);
        $totales = $previas + $unidades;
        if ($totales > STOCK_UNIDADES_MAX) {
            return ['ok' => false,
                    'error' => 'El stock total no puede superar ' . STOCK_UNIDADES_MAX . ' unidades.'];
        }

        DB::consultar(
            "UPDATE medicamentos
                SET unidades_actuales = :n,
                    alerta_stock_enviada = FALSE
              WHERE id_medicamento = :id",
            ['n' => $totales, 'id' => $idMedicamento]
        );

        DB::consultar(
            "INSERT INTO historial_cambios_medicamento
              (id_medicamento, id_usuario_modificador, campo_modificado,
               valor_anterior, valor_nuevo, tipo_accion)
              VALUES (:m, :u, 'unidades_actuales', :ant, :nuevo, 'modificacion')",
            ['m' => $idMedicamento, 'u' => $idUsuarioAccion,
             'ant' => (string)$previas, 'nuevo' => (string)$totales]
        );

        return ['ok' => true, 'unidades' => $totales];
    }

    /**
     * Establece manualmente el stock (creación o ajuste fino).
     */
    public static function establecer(int $idMedicamento, ?int $unidades, int $unidadesPorToma,
                                       int $alertaDias, int $idUsuarioAccion): array
    {
        if ($unidades !== null && ($unidades < 0 || $unidades > STOCK_UNIDADES_MAX)) {
            return ['ok' => false,
                    'error' => 'Las unidades deben estar entre 0 y ' . STOCK_UNIDADES_MAX . '.'];
        }
        if ($unidadesPorToma < 1 || $unidadesPorToma > 999) {
            return ['ok' => false, 'error' => 'Unidades por toma fuera de rango (1–999).'];
        }
        if ($alertaDias < STOCK_DIAS_ALERTA_MIN || $alertaDias > STOCK_DIAS_ALERTA_MAX) {
            return ['ok' => false,
                    'error' => 'Los días de alerta deben estar entre '
                                . STOCK_DIAS_ALERTA_MIN . ' y ' . STOCK_DIAS_ALERTA_MAX . '.'];
        }

        DB::consultar(
            "UPDATE medicamentos
                SET unidades_actuales = :n,
                    unidades_por_toma = :upt,
                    alerta_stock_dias = :ad,
                    alerta_stock_enviada = FALSE
              WHERE id_medicamento = :id",
            ['n' => $unidades, 'upt' => $unidadesPorToma,
             'ad' => $alertaDias, 'id' => $idMedicamento]
        );

        return ['ok' => true];
    }

    /**
     * Revisa si el medicamento está en alerta y dispara notificaciones.
     * También avisa al médico tratante y a los cuidadores activos.
     */
    public static function evaluarYAlertar(int $idMedicamento): array
    {
        $row = DB::uno(
            "SELECT s.*, m.nombre, m.id_usuario_paciente, m.id_usuario_creador,
                    u.nombre_completo AS paciente_nombre
               FROM v_stock_medicamentos s
               INNER JOIN medicamentos m ON m.id_medicamento = s.id_medicamento
               INNER JOIN usuarios u ON u.id_usuario = m.id_usuario_paciente
              WHERE s.id_medicamento = :id",
            ['id' => $idMedicamento]
        );
        if (!$row) return ['ok' => false, 'error' => 'No se pudo consultar stock.'];

        $resp = [
            'ok' => true,
            'estado' => $row['estado_stock'],
            'unidades' => $row['unidades_actuales'],
            'dias_restantes' => $row['dias_restantes'],
            'alerta_disparada' => false,
        ];

        if (!in_array($row['estado_stock'], ['bajo','agotado'], true)) {
            return $resp;
        }

        // Si ya se notificó recientemente, no spamear
        if ($row['alerta_stock_enviada']) return $resp;

        DB::consultar(
            "UPDATE medicamentos
                SET alerta_stock_enviada = TRUE,
                    fecha_alerta_stock = NOW()
              WHERE id_medicamento = :id",
            ['id' => $idMedicamento]
        );

        $titulo = $row['estado_stock'] === 'agotado'
                  ? "📦 Stock agotado: {$row['nombre']}"
                  : "📦 Stock bajo: {$row['nombre']} (te quedan {$row['dias_restantes']} día(s))";
        $mensaje = $row['estado_stock'] === 'agotado'
                  ? "Ya no tienes unidades del medicamento {$row['nombre']}. Reabastece cuanto antes para no interrumpir el tratamiento."
                  : "Te quedan aproximadamente {$row['dias_restantes']} día(s) de tratamiento de {$row['nombre']}. Reabastece pronto.";

        // Paciente
        Notificaciones::crearGenerica($row['id_usuario_paciente'], $titulo, $mensaje,
                                        'stock_bajo', $idMedicamento);
        // Médico (creador, si es distinto del paciente)
        if ((int)$row['id_usuario_creador'] !== (int)$row['id_usuario_paciente']) {
            Notificaciones::crearGenerica(
                $row['id_usuario_creador'],
                "📦 Stock bajo de paciente: {$row['paciente_nombre']}",
                "El paciente {$row['paciente_nombre']} tiene stock bajo de {$row['nombre']} "
              . "(le quedan {$row['dias_restantes']} día(s)).",
                'stock_bajo', $idMedicamento
            );
        }
        // Cuidadores activos
        $cuidadores = DB::todos(
            "SELECT id_cuidador FROM cuidador_paciente
              WHERE id_paciente = :p AND activa = TRUE",
            ['p' => $row['id_usuario_paciente']]
        );
        foreach ($cuidadores as $c) {
            Notificaciones::crearGenerica(
                (int)$c['id_cuidador'],
                "📦 Stock bajo de {$row['paciente_nombre']}",
                "El paciente {$row['paciente_nombre']} tiene stock bajo de {$row['nombre']}.",
                'stock_bajo', $idMedicamento
            );
        }

        $resp['alerta_disparada'] = true;
        return $resp;
    }

    public static function consultar(int $idMedicamento): ?array
    {
        return DB::uno(
            "SELECT * FROM v_stock_medicamentos WHERE id_medicamento = :id",
            ['id' => $idMedicamento]
        );
    }
}
