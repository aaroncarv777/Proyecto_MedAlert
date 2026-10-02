<?php
/**
 * MedAlert · Cliente Twilio (HU-12 SMS reales)
 * ----------------------------------------------------------------
 * Implementación nativa con cURL (sin SDK externo) que invoca la
 * API REST de Twilio para enviar SMS al paciente / cuidador.
 *
 *   POST https://api.twilio.com/2010-04-01/Accounts/{SID}/Messages.json
 *   Auth Basic: SID:TOKEN
 *   Body x-www-form-urlencoded: To, From, Body
 *
 * Cuando TWILIO_HABILITADO = false, el envío se simula:
 * el método devuelve éxito con un SID "SIMULADO_..." y registra
 * el mensaje en logs/sms_twilio.log para fines de pruebas locales.
 *
 * Equipo Sprint 2 — Patricio integró este cliente el viernes 8/Mayo;
 * Aaron lo conectó al cron de avisos escalonados el lunes 11/Mayo.
 */

require_once __DIR__ . '/config.php';

class Twilio
{
    /**
     * Enviar un SMS al número indicado.
     *
     * @return array{ok:bool, sid:string|null, error:string|null}
     */
    public static function enviar(string $telefono, string $mensaje): array
    {
        $telefonoLimpio = self::normalizarTelefono($telefono);
        if ($telefonoLimpio === null) {
            self::registrar('FALLO', $telefono, 'Número inválido', $mensaje);
            return ['ok' => false, 'sid' => null, 'error' => 'Número de teléfono inválido.'];
        }

        // Truncar a 160 caracteres (estándar SMS sencillo)
        $mensaje = mb_substr(trim($mensaje), 0, TWILIO_SMS_MAX_CARACTERES);
        if ($mensaje === '') {
            return ['ok' => false, 'sid' => null, 'error' => 'Mensaje vacío.'];
        }

        // Modo simulado para pruebas locales
        if (!TWILIO_HABILITADO) {
            $sidSimulado = 'SIMULADO_' . bin2hex(random_bytes(12));
            self::registrar('SIMULADO', $telefonoLimpio, $sidSimulado, $mensaje);
            return ['ok' => true, 'sid' => $sidSimulado, 'error' => null];
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/'
             . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json';

        $datos = http_build_query([
            'To'   => $telefonoLimpio,
            'From' => TWILIO_FROM_NUMBER,
            'Body' => $mensaje,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $datos,
            CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => TWILIO_TIMEOUT_SEGUNDOS,
            CURLOPT_CONNECTTIMEOUT => TWILIO_TIMEOUT_SEGUNDOS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl  = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $errorCurl !== '') {
            $detalle = 'cURL: ' . $errorCurl;
            self::registrar('FALLO_RED', $telefonoLimpio, $detalle, $mensaje);
            return ['ok' => false, 'sid' => null, 'error' => $detalle];
        }

        $payload = json_decode((string)$respuesta, true);

        if ($codigoHttp >= 200 && $codigoHttp < 300 && isset($payload['sid'])) {
            self::registrar('OK', $telefonoLimpio, $payload['sid'], $mensaje);
            return ['ok' => true, 'sid' => $payload['sid'], 'error' => null];
        }

        $detalle = 'HTTP ' . $codigoHttp . ' :: ' . ($payload['message'] ?? 'Respuesta inesperada de Twilio');
        self::registrar('FALLO', $telefonoLimpio, $detalle, $mensaje);
        return ['ok' => false, 'sid' => null, 'error' => $detalle];
    }

    /**
     * Normaliza un teléfono al formato E.164 (+52...) que exige Twilio.
     * Acepta: 9991234567 (10 dígitos México), +529991234567, 529991234567.
     */
    public static function normalizarTelefono(string $telefono): ?string
    {
        $telefono = preg_replace('/[\s\-\(\)]+/', '', trim($telefono));
        if ($telefono === '') return null;

        // Ya viene con +
        if (str_starts_with($telefono, '+')) {
            return preg_match('/^\+\d{10,15}$/', $telefono) ? $telefono : null;
        }

        // Solo dígitos
        if (!ctype_digit($telefono)) return null;

        // 10 dígitos → prefijar con +52 (México)
        if (strlen($telefono) === 10) {
            return TWILIO_PAIS_DEFAULT . $telefono;
        }

        // 11–15 dígitos → asumir que incluye país y solo falta el "+"
        if (strlen($telefono) >= 11 && strlen($telefono) <= 15) {
            return '+' . $telefono;
        }

        return null;
    }

    private static function registrar(string $resultado, string $destino, string $detalle, string $mensaje): void
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $linea = sprintf(
            "[%s] %s · %s · %s :: %s\n",
            date('Y-m-d H:i:s'),
            $resultado,
            $destino,
            $detalle,
            mb_substr($mensaje, 0, 120)
        );
        @file_put_contents($dir . '/sms_twilio.log', $linea, FILE_APPEND);
    }
}
