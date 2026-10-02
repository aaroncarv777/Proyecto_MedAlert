<?php
/**
 * Detección de interacciones medicamentosas — HU-08
 *
 * Cada vez que se agrega o modifica un medicamento, este servicio revisa el catálogo
 * y detecta si existe interacción conocida con los demás medicamentos activos del paciente.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificaciones.php';

class Interacciones
{
    /**
     * Detecta y registra alertas de interacción para un medicamento recién creado o editado.
     * Devuelve el listado de alertas detectadas (para mostrarlas en la UI).
     */
    public static function detectarParaMedicamento(int $idMedicamento): array
    {
        $med = DB::uno(
            "SELECT id_medicamento, id_usuario_paciente, nombre
               FROM medicamentos WHERE id_medicamento = :id AND activo = TRUE",
            ['id' => $idMedicamento]
        );
        if (!$med) return [];

        // Otros medicamentos activos del mismo paciente
        $otros = DB::todos(
            "SELECT id_medicamento, nombre FROM medicamentos
              WHERE id_usuario_paciente = :p AND activo = TRUE
                AND id_medicamento <> :m",
            ['p' => $med['id_usuario_paciente'], 'm' => $idMedicamento]
        );
        if (empty($otros)) return [];

        $detectadas = [];
        foreach ($otros as $otro) {
            $coincidencias = DB::todos(
                "SELECT * FROM catalogo_interacciones
                  WHERE activa = TRUE
                    AND ((LOWER(medicamento_a) = LOWER(:a) AND LOWER(medicamento_b) = LOWER(:b))
                         OR (LOWER(medicamento_a) = LOWER(:b) AND LOWER(medicamento_b) = LOWER(:a)))",
                ['a' => $med['nombre'], 'b' => $otro['nombre']]
            );

            foreach ($coincidencias as $inter) {
                // Evitar duplicar alertas: mismo paciente + mismos medicamentos + misma interacción
                $existe = DB::uno(
                    "SELECT id_alerta FROM alertas_interaccion
                      WHERE id_paciente = :p
                        AND ((id_medicamento_a = :ma AND id_medicamento_b = :mb)
                             OR (id_medicamento_a = :mb AND id_medicamento_b = :ma))
                        AND id_interaccion = :i
                        AND estado IN ('activa','aceptada')",
                    [
                        'p'  => $med['id_usuario_paciente'],
                        'ma' => $med['id_medicamento'],
                        'mb' => $otro['id_medicamento'],
                        'i'  => $inter['id_interaccion'],
                    ]
                );
                if ($existe) continue;

                DB::consultar(
                    "INSERT INTO alertas_interaccion
                      (id_paciente, id_medicamento_a, id_medicamento_b, id_interaccion, nivel_riesgo)
                      VALUES (:p, :ma, :mb, :i, :n)",
                    [
                        'p'  => $med['id_usuario_paciente'],
                        'ma' => $med['id_medicamento'],
                        'mb' => $otro['id_medicamento'],
                        'i'  => $inter['id_interaccion'],
                        'n'  => $inter['nivel_riesgo'],
                    ]
                );

                $idAlerta = DB::ultimoId();
                $detectadas[] = [
                    'id_alerta'            => $idAlerta,
                    'medicamento_a'        => $med['nombre'],
                    'medicamento_b'        => $otro['nombre'],
                    'nivel_riesgo'         => $inter['nivel_riesgo'],
                    'descripcion'          => $inter['descripcion'],
                    'consecuencia_clinica' => $inter['consecuencia_clinica'] ?? null,
                    'recomendacion'        => $inter['recomendacion'],
                    'fuente'               => $inter['fuente'],
                ];

                // Notificación in-app al paciente — incluye la frase de consecuencia
                $consecuencia = $inter['consecuencia_clinica']
                    ? ' ' . $inter['consecuencia_clinica']
                    : '';
                Notificaciones::crearGenerica(
                    $med['id_usuario_paciente'],
                    '⚠️ Posible interacción medicamentosa (' . ucfirst($inter['nivel_riesgo']) . ')',
                    "'{$med['nombre']}' y '{$otro['nombre']}' pueden interactuar." . $consecuencia
                  . ' ' . ($inter['recomendacion'] ?? ''),
                    'interaccion',
                    $med['id_medicamento']
                );
            }
        }
        return $detectadas;
    }

    /**
     * El médico acepta la alerta dejando una justificación clínica.
     */
    public static function aceptarAlerta(int $idAlerta, int $idMedico, string $justificacion): array
    {
        $just = trim($justificacion);
        if (mb_strlen($just) < JUSTIFICACION_INTERACCION_MIN) {
            return ['ok' => false,
                    'error' => 'La justificación debe tener al menos '
                                . JUSTIFICACION_INTERACCION_MIN . ' caracteres.'];
        }
        if (mb_strlen($just) > JUSTIFICACION_INTERACCION_MAX) {
            return ['ok' => false,
                    'error' => 'La justificación no puede superar '
                                . JUSTIFICACION_INTERACCION_MAX . ' caracteres.'];
        }

        $alerta = DB::uno(
            "SELECT a.*, m.id_usuario_creador
               FROM alertas_interaccion a
               INNER JOIN medicamentos m ON m.id_medicamento = a.id_medicamento_a
              WHERE a.id_alerta = :id AND a.estado = 'activa'",
            ['id' => $idAlerta]
        );
        if (!$alerta) return ['ok' => false, 'error' => 'La alerta no existe o ya fue procesada.'];

        // Verificación: el médico debe ser el creador de al menos uno de los dos medicamentos
        $autorizado = DB::uno(
            "SELECT id_medicamento FROM medicamentos
              WHERE (id_medicamento = :ma OR id_medicamento = :mb)
                AND id_usuario_creador = :me LIMIT 1",
            ['ma' => $alerta['id_medicamento_a'],
             'mb' => $alerta['id_medicamento_b'],
             'me' => $idMedico]
        );
        if (!$autorizado) {
            return ['ok' => false,
                    'error' => 'Sólo el médico que prescribió alguno de estos medicamentos puede aceptar la alerta.'];
        }

        DB::consultar(
            "UPDATE alertas_interaccion
                SET estado = 'aceptada',
                    justificacion_clinica = :j,
                    id_medico_acepta = :me,
                    fecha_aceptacion = NOW()
              WHERE id_alerta = :id",
            ['j' => $just, 'me' => $idMedico, 'id' => $idAlerta]
        );

        // Registrar la aceptación en el historial del medicamento principal
        DB::consultar(
            "INSERT INTO historial_cambios_medicamento
              (id_medicamento, id_usuario_modificador, campo_modificado,
               valor_anterior, valor_nuevo, tipo_accion)
              VALUES (:m, :u, 'alerta_interaccion', 'activa', :v, 'modificacion')",
            ['m' => $alerta['id_medicamento_a'], 'u' => $idMedico,
             'v' => 'aceptada con justificación: ' . mb_substr($just, 0, 200)]
        );

        return ['ok' => true];
    }

    /**
     * Marca como obsoletas las alertas de un medicamento al eliminarse o desactivarse.
     */
    public static function marcarObsoletasPorMedicamento(int $idMedicamento): int
    {
        $r = DB::consultar(
            "UPDATE alertas_interaccion SET estado = 'obsoleta'
              WHERE (id_medicamento_a = :id OR id_medicamento_b = :id)
                AND estado IN ('activa','aceptada')",
            ['id' => $idMedicamento]
        );
        return $r->rowCount();
    }

    /** Lista de alertas activas para un paciente. */
    public static function alertasPaciente(int $idPaciente, ?string $estadoFiltro = null): array
    {
        $sql = "SELECT * FROM v_alertas_interaccion_activas WHERE id_paciente = :p";
        $params = ['p' => $idPaciente];
        if ($estadoFiltro && in_array($estadoFiltro, ['activa','aceptada'], true)) {
            $sql .= " AND estado = :e";
            $params['e'] = $estadoFiltro;
        }
        $sql .= " ORDER BY FIELD(nivel_riesgo,'grave','moderada','leve'), fecha_deteccion DESC";
        return DB::todos($sql, $params);
    }
}
