<?php

require_once __DIR__ . '/config.php';

/*
 * Manejo global de errores para las peticiones de datos (API).
 * Garantiza que el servidor SIEMPRE responda con un mensaje claro en
 * formato JSON y nunca con una página de error, de modo que la interfaz
 * pueda mostrar el motivo real al usuario.
 */
if (!defined('MEDALERT_MANEJADOR_ERRORES')) {
    define('MEDALERT_MANEJADOR_ERRORES', true);

    $esPeticionDatos = function (): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strpos($uri, '/api/') !== false
            || strtolower($xrw) === 'xmlhttprequest';
    };

    $responderJson = function (string $mensaje, int $codigo) {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    };

    set_exception_handler(function ($e) use ($esPeticionDatos, $responderJson) {
        error_log('MedAlert error: ' . $e->getMessage());
        if ($esPeticionDatos()) {
            $responderJson(
                'No se pudo completar la operación. Vuelve a intentarlo; '
                . 'si el problema continúa, contacta al administrador.',
                500
            );
        }
        http_response_code(500);
        echo 'Ocurrió un error. Recarga la página e inténtalo de nuevo.';
        exit;
    });

    register_shutdown_function(function () use ($esPeticionDatos, $responderJson) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('MedAlert fatal: ' . $e['message']);
            if ($esPeticionDatos()) {
                $responderJson(
                    'No se pudo completar la operación. Vuelve a intentarlo; '
                    . 'si el problema continúa, contacta al administrador.',
                    500
                );
            }
        }
    });
}

class Helpers
{

    public static function validarDosis(string $dosis): array
    {
        $dosis = trim($dosis);

        if ($dosis === '') {
            return ['valido' => false, 'error' => 'La dosis es obligatoria.'];
        }
        if (mb_strlen($dosis) > DOSIS_MAX_CARACTERES) {
            return ['valido' => false,
                    'error' => 'La dosis no puede tener más de ' . DOSIS_MAX_CARACTERES . ' caracteres.'];
        }
        if (!preg_match(DOSIS_PATRON_REGEX, $dosis)) {
            return ['valido' => false,
                    'error' => 'La dosis sólo admite números, letras y unidades comunes (ej. 500mg, 10ml, 1 tableta).'];
        }
        return ['valido' => true, 'error' => null];
    }

    public static function validarCorreo(string $correo): bool
    {
        $correo = trim(mb_strtolower($correo));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return false;

        return preg_match('/@gmail\.com$/', $correo) === 1;
    }

    public static function errorCorreoNoGmail(): string
    {
        return 'Por ahora sólo aceptamos cuentas de Gmail. Usa una dirección que termine en @gmail.com';
    }

    public static function validarContrasena(string $contrasena): array
    {
        if (strlen($contrasena) < 8) {
            return ['valida' => false, 'error' => 'Mínimo 8 caracteres.'];
        }
        if (!preg_match('/[A-Z]/', $contrasena)) {
            return ['valida' => false, 'error' => 'Debe incluir al menos una mayúscula.'];
        }
        if (!preg_match('/[0-9]/', $contrasena)) {
            return ['valida' => false, 'error' => 'Debe incluir al menos un número.'];
        }
        return ['valida' => true, 'error' => null];
    }

    public static function validarTelefono(string $telefono): bool
    {
        $limpio = preg_replace('/\s+/', '', $telefono);
        return preg_match('/^[0-9]{10}$/', $limpio) === 1;
    }

    public static function post(string $clave, string $valorPredeterminado = ''): string
    {
        return isset($_POST[$clave]) ? trim((string)$_POST[$clave]) : $valorPredeterminado;
    }

    public static function get(string $clave, string $valorPredeterminado = ''): string
    {
        return isset($_GET[$clave]) ? trim((string)$_GET[$clave]) : $valorPredeterminado;
    }

    public static function jsonRespuesta(array $datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function leerJson(): array
    {
        $cuerpo = file_get_contents('php://input');
        $decodificado = json_decode($cuerpo, true);
        return is_array($decodificado) ? $decodificado : [];
    }

    public static function hora12(string $hora24): string
    {
        try {
            return (new DateTime($hora24))->format('g:i A');
        } catch (Exception $e) {
            return $hora24;
        }
    }

    public static function hace(string $fecha): string
    {
        try {
            $entonces = new DateTime($fecha);
            $ahora = new DateTime();
            $dif = $ahora->diff($entonces);

            if ($dif->days === 0) {
                if ($dif->h === 0) {
                    if ($dif->i === 0) return 'Justo ahora';
                    return 'Hace ' . $dif->i . ' minuto' . ($dif->i === 1 ? '' : 's');
                }
                return 'Hace ' . $dif->h . ' hora' . ($dif->h === 1 ? '' : 's');
            }
            if ($dif->days === 1) return 'Ayer';
            if ($dif->days < 7) return 'Hace ' . $dif->days . ' días';
            return $entonces->format('d/m/Y');
        } catch (Exception $e) {
            return $fecha;
        }
    }

    public static function calcularEstadoToma(array $toma): string
    {
        if (!empty($toma['estado']) && $toma['estado'] !== 'pendiente') {
            return $toma['estado'];
        }
        $programada = $toma['fecha_programada'] . ' ' . $toma['hora_programada'];
        $tsProg = strtotime($programada);
        if ($tsProg !== false && $tsProg < time()) {
            return 'vencida';
        }
        return 'pendiente';
    }

    public static function mensajeMotivacional(int $cumplidas, int $total): ?string
    {
        if ($total === 0) return null;
        if ($cumplidas < $total) return null;
        $mensajes = [
            '¡Excelente! Cumpliste todas tus tomas del día. 🎉',
            'Día completado. Tu salud te lo agradece. 💪',
            '¡Felicidades! 100% de adherencia hoy.',
            'Constancia que se nota: todas las tomas marcadas. ✨'
        ];
        return $mensajes[array_rand($mensajes)];
    }
}
