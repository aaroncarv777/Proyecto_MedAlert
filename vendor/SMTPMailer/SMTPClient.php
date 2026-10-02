<?php
/**
 * MedAlert SMTP Mailer
 * ─────────────────────────────────────────────────────────────────────────────
 * Cliente SMTP compatible con Gmail (STARTTLS + AUTH LOGIN).
 *
 * IMPLEMENTACIÓN VENDOR INTEGRADA — esta clase es 100% funcional para envío
 * de correos por Gmail. Si en producción prefieres usar PHPMailer oficial
 * (más probado, con soporte OAuth2, etc.) puedes reemplazarlo:
 *
 *   composer require phpmailer/phpmailer
 *
 * y cambiar el `use` en includes/mailer.php de:
 *   use MedAlert\Vendor\SMTPMailer\SMTPClient;  ← actual
 * a:
 *   use PHPMailer\PHPMailer\PHPMailer;          ← futuro
 *
 * La API pública es idéntica.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Uso:
 *   $smtp = new SMTPClient();
 *   $smtp->Host = 'smtp.gmail.com';
 *   $smtp->Port = 587;
 *   $smtp->Username = 'tucuenta@gmail.com';
 *   $smtp->Password = 'app-password-de-google';
 *   $smtp->SMTPSecure = 'tls';   // tls (587) o ssl (465)
 *   $smtp->setFrom('tucuenta@gmail.com', 'MedAlert');
 *   $smtp->addAddress('destino@gmail.com', 'Nombre');
 *   $smtp->Subject = 'Asunto';
 *   $smtp->isHTML(true);
 *   $smtp->Body = '<p>Hola</p>';
 *   $smtp->send();
 */

namespace MedAlert\Vendor\SMTPMailer;

class SMTPException extends \Exception {}

class SMTPClient
{
    // Configuración (públicas para igualar API de PHPMailer)
    public string $Host = 'smtp.gmail.com';
    public int $Port = 587;
    public string $SMTPSecure = 'tls';   // 'tls' | 'ssl' | ''
    public bool $SMTPAuth = true;
    public string $Username = '';
    public string $Password = '';
    public string $CharSet = 'UTF-8';
    public string $Subject = '';
    public string $Body = '';
    public string $AltBody = '';
    public int $Timeout = 30;
    public bool $SMTPDebug = false;
    public string $Helo = '';

    private array $from = ['', ''];
    private array $to = [];
    private array $replyTo = [];
    private bool $isHtml = true;

    /** @var resource|null */
    private $socket = null;
    private array $debugLog = [];

    public function setFrom(string $email, string $nombre = ''): void
    {
        $this->from = [$email, $nombre];
    }

    public function addAddress(string $email, string $nombre = ''): void
    {
        $this->to[] = [$email, $nombre];
    }

    public function addReplyTo(string $email, string $nombre = ''): void
    {
        $this->replyTo[] = [$email, $nombre];
    }

    public function isHTML(bool $html): void
    {
        $this->isHtml = $html;
    }

    public function clearAddresses(): void
    {
        $this->to = [];
    }

    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * Envía el correo. Lanza SMTPException en caso de error.
     */
    public function send(): bool
    {
        if (empty($this->to))    throw new SMTPException('No hay destinatarios.');
        if (empty($this->from[0])) throw new SMTPException('Falta el remitente.');
        if (empty($this->Username) || empty($this->Password)) {
            throw new SMTPException('Faltan credenciales SMTP.');
        }

        try {
            $this->conectar();
            $this->saludar();
            if ($this->SMTPSecure === 'tls' && $this->Port !== 465) {
                $this->iniciarTLS();
                $this->saludar();   // EHLO de nuevo tras STARTTLS (RFC 3207)
            }
            if ($this->SMTPAuth) $this->autenticar();
            $this->enviarSobre();
            $this->enviarDatos();
            $this->cerrar();
            return true;
        } catch (\Throwable $e) {
            if ($this->socket) @fclose($this->socket);
            throw new SMTPException('SMTP: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function conectar(): void
    {
        $protocolo = ($this->SMTPSecure === 'ssl' || $this->Port === 465) ? 'ssl://' : '';
        $url = $protocolo . $this->Host . ':' . $this->Port;
        $contexto = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($url, $errno, $errstr, $this->Timeout,
                                      STREAM_CLIENT_CONNECT, $contexto);
        if (!$sock) {
            throw new SMTPException("No se pudo conectar a {$url}: {$errstr} (#{$errno})");
        }
        stream_set_timeout($sock, $this->Timeout);
        $this->socket = $sock;
        $this->leer(220);
    }

    private function saludar(): void
    {
        $hostLocal = $this->Helo !== '' ? $this->Helo
                                         : ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $this->escribir('EHLO ' . $hostLocal);
        $this->leer(250);
    }

    private function iniciarTLS(): void
    {
        $this->escribir('STARTTLS');
        $this->leer(220);
        $cripto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if (!@stream_socket_enable_crypto($this->socket, true, $cripto)) {
            throw new SMTPException('No se pudo iniciar TLS.');
        }
    }

    private function autenticar(): void
    {
        $this->escribir('AUTH LOGIN');
        $this->leer(334);
        $this->escribir(base64_encode($this->Username));
        $this->leer(334);
        $this->escribir(base64_encode($this->Password));
        $this->leer(235);
    }

    private function enviarSobre(): void
    {
        $this->escribir('MAIL FROM:<' . $this->from[0] . '>');
        $this->leer(250);
        foreach ($this->to as [$correo, $_]) {
            $this->escribir('RCPT TO:<' . $correo . '>');
            $this->leer([250, 251]);
        }
    }

    private function enviarDatos(): void
    {
        $this->escribir('DATA');
        $this->leer(354);
        $this->escribirSinEsperar($this->construirMime() . "\r\n.\r\n");
        $this->leer(250);
    }

    private function cerrar(): void
    {
        @$this->escribir('QUIT');
        @$this->leer(221);
        @fclose($this->socket);
        $this->socket = null;
    }

    private function construirMime(): string
    {
        $boundary = '=_MedAlert_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->codificarDireccion($this->from[1], $this->from[0]);
        $listaTo = array_map(fn($a) => $this->codificarDireccion($a[1], $a[0]), $this->to);
        $headers[] = 'To: ' . implode(', ', $listaTo);
        if (!empty($this->replyTo)) {
            $listaRt = array_map(fn($a) => $this->codificarDireccion($a[1], $a[0]), $this->replyTo);
            $headers[] = 'Reply-To: ' . implode(', ', $listaRt);
        }
        $headers[] = 'Subject: ' . $this->codificarHeader($this->Subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . ($this->Helo ?: 'medalert') . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: MedAlert SMTPClient v1.0';

        $altBody = $this->AltBody !== ''
            ? $this->AltBody
            : ($this->isHtml ? trim(html_entity_decode(strip_tags($this->Body))) : $this->Body);

        if ($this->isHtml && $altBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $cuerpo  = "\r\n--{$boundary}\r\n";
            $cuerpo .= "Content-Type: text/plain; charset={$this->CharSet}\r\n";
            $cuerpo .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $cuerpo .= $this->aplanarLineas($altBody) . "\r\n";
            $cuerpo .= "--{$boundary}\r\n";
            $cuerpo .= "Content-Type: text/html; charset={$this->CharSet}\r\n";
            $cuerpo .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $cuerpo .= $this->aplanarLineas($this->Body) . "\r\n";
            $cuerpo .= "--{$boundary}--\r\n";
        } elseif ($this->isHtml) {
            $headers[] = "Content-Type: text/html; charset={$this->CharSet}";
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $cuerpo = "\r\n" . $this->aplanarLineas($this->Body);
        } else {
            $headers[] = "Content-Type: text/plain; charset={$this->CharSet}";
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $cuerpo = "\r\n" . $this->aplanarLineas($this->Body);
        }

        return implode("\r\n", $headers) . "\r\n" . $cuerpo;
    }

    private function aplanarLineas(string $texto): string
    {
        // Convertir saltos de línea y aplicar dot-stuffing (RFC 5321)
        $texto = preg_replace("/\r\n|\r|\n/", "\r\n", $texto);
        $lineas = explode("\r\n", $texto);
        foreach ($lineas as &$l) {
            if (isset($l[0]) && $l[0] === '.') $l = '.' . $l;
        }
        return implode("\r\n", $lineas);
    }

    private function codificarDireccion(string $nombre, string $correo): string
    {
        if ($nombre === '') return '<' . $correo . '>';
        return $this->codificarHeader($nombre) . ' <' . $correo . '>';
    }

    private function codificarHeader(string $valor): string
    {
        if (preg_match('/[\x80-\xFF]/', $valor)) {
            return '=?UTF-8?B?' . base64_encode($valor) . '?=';
        }
        return $valor;
    }

    private function escribir(string $comando): void
    {
        if ($this->SMTPDebug) $this->debugLog[] = '> ' . $comando;
        fwrite($this->socket, $comando . "\r\n");
    }

    private function escribirSinEsperar(string $datos): void
    {
        if ($this->SMTPDebug) $this->debugLog[] = '> [DATOS ' . strlen($datos) . ' bytes]';
        fwrite($this->socket, $datos);
    }

    /**
     * Lee respuesta multilínea y verifica el código.
     *
     * @param int|int[] $codigosEsperados
     */
    private function leer($codigosEsperados): string
    {
        $codigos = is_array($codigosEsperados) ? $codigosEsperados : [$codigosEsperados];
        $respuesta = '';
        $codigo = 0;
        while (($linea = fgets($this->socket, 515)) !== false) {
            $respuesta .= $linea;
            if ($this->SMTPDebug) $this->debugLog[] = '< ' . trim($linea);
            $codigo = (int)substr($linea, 0, 3);
            // Si el 4to carácter es '-' hay continuación; si es ' ' es la última
            if (strlen($linea) < 4 || $linea[3] !== '-') break;
            $info = stream_get_meta_data($this->socket);
            if ($info['timed_out']) break;
        }
        if (!in_array($codigo, $codigos, true)) {
            throw new SMTPException("Código SMTP inesperado: {$codigo} (esperado " . implode('|', $codigos) . "). Respuesta: " . trim($respuesta));
        }
        return $respuesta;
    }
}
