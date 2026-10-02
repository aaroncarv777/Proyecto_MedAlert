<?php
/**
 * Cálculo automático de dosis por peso y edad — HU-09
 */

require_once __DIR__ . '/db.php';

class CalculoDosis
{
    /**
     * Calcula la dosis recomendada por toma según mg/kg/día, peso y frecuencia.
     * Devuelve también si la dosis supera el máximo recomendado para el grupo de edad.
     *
     * @param string $nombreMedicamento  Nombre exacto que aparece en el catálogo de dosis máximas.
     * @param float  $mgKgDia            mg por kilogramo por día prescritos por el médico.
     * @param float  $pesoKg             Peso del paciente en kg.
     * @param ?int   $edadAnios          Edad del paciente; null si no hay fecha de nacimiento.
     * @param float  $frecuenciaHoras    Cada cuántas horas se toma.
     */
    public static function calcular(
        string $nombreMedicamento,
        float $mgKgDia,
        float $pesoKg,
        ?int $edadAnios,
        float $frecuenciaHoras
    ): array {
        if ($pesoKg < DOSIS_PESO_MIN_KG || $pesoKg > DOSIS_PESO_MAX_KG) {
            return ['ok' => false,
                    'error' => 'El peso del paciente está fuera del rango admitido ('
                                . DOSIS_PESO_MIN_KG . '–' . DOSIS_PESO_MAX_KG . ' kg).'];
        }
        if ($mgKgDia < DOSIS_MG_KG_MIN || $mgKgDia > DOSIS_MG_KG_MAX) {
            return ['ok' => false,
                    'error' => 'La dosis en mg/kg/día está fuera del rango admitido ('
                                . DOSIS_MG_KG_MIN . '–' . DOSIS_MG_KG_MAX . ').'];
        }
        if ($frecuenciaHoras <= 0 || $frecuenciaHoras > 168) {
            return ['ok' => false, 'error' => 'La frecuencia debe estar entre 1 y 168 horas.'];
        }

        $tomasPorDia = max(1, round(24 / $frecuenciaHoras, 2));
        $dosisDiariaMg = $mgKgDia * $pesoKg;
        $dosisPorTomaMg = round($dosisDiariaMg / $tomasPorDia, 2);

        $resultado = [
            'ok'                => true,
            'mg_kg_dia'         => $mgKgDia,
            'peso_kg'           => $pesoKg,
            'tomas_por_dia'     => $tomasPorDia,
            'dosis_diaria_mg'   => round($dosisDiariaMg, 2),
            'dosis_por_toma_mg' => $dosisPorTomaMg,
            'supera_maximo'     => false,
            'mensaje_alerta'    => null,
            'grupo_edad'        => null,
            'maximo_consultado' => null,
        ];

        if ($edadAnios !== null) {
            $grupo = self::grupoEdad($edadAnios);
            $resultado['grupo_edad'] = $grupo;
            $maxRow = DB::uno(
                "SELECT * FROM dosis_maximas
                  WHERE LOWER(nombre_medicamento) = LOWER(:n)
                    AND :e BETWEEN edad_min_anios AND edad_max_anios
                    AND activa = TRUE
                  ORDER BY (edad_max_anios - edad_min_anios) ASC
                  LIMIT 1",
                ['n' => $nombreMedicamento, 'e' => $edadAnios]
            );
            if ($maxRow) {
                $maxMgKg = (float)$maxRow['dosis_max_mg_kg_dia'];
                $maxTotal = $maxRow['dosis_max_total_dia_mg'] !== null
                            ? (float)$maxRow['dosis_max_total_dia_mg'] : null;
                $resultado['maximo_consultado'] = [
                    'mg_kg_dia' => $maxMgKg,
                    'total_dia_mg' => $maxTotal,
                    'fuente' => $maxRow['fuente'],
                ];
                if ($mgKgDia > $maxMgKg) {
                    $resultado['supera_maximo'] = true;
                    $resultado['mensaje_alerta'] =
                        "La dosis prescrita ({$mgKgDia} mg/kg/día) supera el máximo recomendado "
                      . "para el grupo {$grupo} ({$maxMgKg} mg/kg/día según {$maxRow['fuente']}).";
                } elseif ($maxTotal !== null && $dosisDiariaMg > $maxTotal) {
                    $resultado['supera_maximo'] = true;
                    $resultado['mensaje_alerta'] =
                        "La dosis total diaria ({$dosisDiariaMg} mg) supera el máximo absoluto recomendado "
                      . "({$maxTotal} mg/día según {$maxRow['fuente']}).";
                }
            } else {
                $resultado['mensaje_alerta'] = 'No hay dosis máxima registrada para este medicamento '
                                              . 'en el grupo de edad. Verifica con vademécum.';
            }
        } else {
            $resultado['mensaje_alerta'] = 'El paciente no tiene fecha de nacimiento registrada. '
                                          . 'No se puede comparar contra dosis máxima recomendada.';
        }

        return $resultado;
    }

    public static function calcularEdad(?string $fechaNacimiento): ?int
    {
        if (!$fechaNacimiento) return null;
        try {
            $f = new DateTime($fechaNacimiento);
            return $f->diff(new DateTime('today'))->y;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function grupoEdad(int $anios): string
    {
        if ($anios <= 1) return 'lactante';
        if ($anios <= 11) return 'pediatrico';
        if ($anios <= 17) return 'adolescente';
        if ($anios <= 64) return 'adulto';
        return 'adulto_mayor';
    }

    public static function listarMedicamentosConDosisMax(): array
    {
        return DB::todos(
            "SELECT DISTINCT nombre_medicamento FROM dosis_maximas
              WHERE activa = TRUE ORDER BY nombre_medicamento"
        );
    }
}
