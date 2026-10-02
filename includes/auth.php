<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

class Auth
{

    public static function crearSesion(int $idUsuario, string $dispositivo = '', string $ip = ''): string
    {

        DB::consultar(
            "UPDATE sesiones_activas
                SET activa = FALSE,
                    cerrada_por_otra_sesion = TRUE
              WHERE id_usuario = :id AND activa = TRUE",
            ['id' => $idUsuario]
        );

        $token = bin2hex(random_bytes(32));
        $expira = (new DateTime())
            ->modify('+' . DURACION_SESION_HORAS . ' hours')
            ->format('Y-m-d H:i:s');

        DB::consultar(
            "INSERT INTO sesiones_activas
              (id_usuario, token_sesion, dispositivo, ip_origen, fecha_expiracion)
              VALUES (:id, :token, :dispositivo, :ip, :expira)",
            [
                'id' => $idUsuario,
                'token' => $token,
                'dispositivo' => substr($dispositivo, 0, 150),
                'ip' => $ip,
                'expira' => $expira
            ]
        );

        DB::consultar(
            "UPDATE usuarios SET fecha_ultima_sesion = NOW() WHERE id_usuario = :id",
            ['id' => $idUsuario]
        );

        $_SESSION['token'] = $token;
        $_SESSION['id_usuario'] = $idUsuario;

        $sesionCompleta = self::consultarSesionPorToken($token);
        if ($sesionCompleta) {
            self::emitirCookiesJWT($sesionCompleta, true);
        }

        return $token;
    }

    public static function usuarioActual(): ?array
    {

        $accessCookie = $_COOKIE[JWT_ACCESS_COOKIE] ?? null;
        if ($accessCookie) {
            $payload = JWT::verificar($accessCookie, JWT_SECRETO);
            if ($payload) {

                return $payload;
            }
        }

        $refreshCookie = $_COOKIE[JWT_REFRESH_COOKIE] ?? null;
        if ($refreshCookie) {
            $refreshPayload = JWT::verificar($refreshCookie, JWT_SECRETO);
            if (!$refreshPayload) {

                self::limpiarCookiesJWT();
                return null;
            }

            $sesion = self::consultarSesionPorToken($refreshPayload['token_sesion'] ?? '');

            if (!$sesion) {
                self::limpiarCookiesJWT();
                return null;
            }

            if (!$sesion['activa']) {

                self::limpiarCookiesJWT();
                self::destruirSesionLocal();
                $_SESSION['mensaje_sesion_cerrada'] = !empty($sesion['cerrada_por_otra_sesion']);
                return null;
            }

            self::emitirCookiesJWT($sesion, false);
            return $sesion;
        }

        if (empty($_SESSION['token'])) return null;

        $sesion = self::consultarSesionPorToken($_SESSION['token']);

        if (!$sesion) return null;

        if (!$sesion['activa']) {
            self::destruirSesionLocal();
            $_SESSION['mensaje_sesion_cerrada'] = !empty($sesion['cerrada_por_otra_sesion']);
            return null;
        }

        return $sesion;
    }

    public static function cerrarSesion(): void
    {

        $tokenSesion = null;

        $accessCookie = $_COOKIE[JWT_ACCESS_COOKIE] ?? null;
        if ($accessCookie) {
            $payload = JWT::verificar($accessCookie, JWT_SECRETO);
            $tokenSesion = $payload['token_sesion'] ?? null;
        }
        if (!$tokenSesion && !empty($_SESSION['token'])) {
            $tokenSesion = $_SESSION['token'];
        }

        if ($tokenSesion) {
            DB::consultar(
                "UPDATE sesiones_activas SET activa = FALSE WHERE token_sesion = :token",
                ['token' => $tokenSesion]
            );
        }

        self::limpiarCookiesJWT();
        self::destruirSesionLocal();
    }

    private static function destruirSesionLocal(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
    }

    public static function requerirSesion(): array
    {
        $usuario = self::usuarioActual();
        if (!$usuario) {
            header('Location: ' . APP_URL . '/login.php?expirada=1');
            exit;
        }
        return $usuario;
    }

    public static function requerirTipo(array $tiposPermitidos): array
    {
        $usuario = self::requerirSesion();
        if (!in_array($usuario['tipo_nombre'], $tiposPermitidos, true)) {
            http_response_code(403);
            require __DIR__ . '/../public/403.php';
            exit;
        }
        return $usuario;
    }

    public static function hashearContrasena(string $contrasena): string
    {
        return password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    public static function verificarContrasena(string $contrasena, string $hash): bool
    {
        return password_verify($contrasena, $hash);
    }

    public static function generarCodigoVerificacion(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function escapar(?string $texto): string
    {
        return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function ipCliente(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public static function dispositivoCliente(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
    }

    private static function consultarSesionPorToken(string $token): ?array
    {
        if (!$token) return null;
        return DB::uno(
            "SELECT s.*, u.nombre_completo, u.correo, u.id_tipo, u.estado_cuenta,
                    u.confirmar_marcar_toma, t.nombre AS tipo_nombre, t.permisos
               FROM sesiones_activas s
               INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
               INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
              WHERE s.token_sesion = :token
                AND s.fecha_expiracion > NOW()",
            ['token' => $token]
        );
    }

    private static function emitirCookiesJWT(array $sesion, bool $conRefresh): void
    {
        $ahora   = time();
        $seguro  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        $accessPayload = [
            'id_usuario'            => (int)$sesion['id_usuario'],
            'nombre_completo'       => $sesion['nombre_completo'],
            'correo'                => $sesion['correo'],
            'id_tipo'               => (int)$sesion['id_tipo'],
            'tipo_nombre'           => $sesion['tipo_nombre'],
            'estado_cuenta'         => $sesion['estado_cuenta'],
            'confirmar_marcar_toma' => (int)($sesion['confirmar_marcar_toma'] ?? 0),
            'permisos'              => $sesion['permisos'] ?? null,
            'token_sesion'          => $sesion['token_sesion'],
            'iat'                   => $ahora,
            'exp'                   => $ahora + (JWT_ACCESS_MINUTOS * 60),
        ];

        setcookie(JWT_ACCESS_COOKIE, JWT::generar($accessPayload, JWT_SECRETO), [
            'expires'  => 0,          
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $seguro,
        ]);

        if ($conRefresh) {

            $refreshPayload = [
                'token_sesion' => $sesion['token_sesion'],
                'id_usuario'   => (int)$sesion['id_usuario'],
                'iat'          => $ahora,
                'exp'          => $ahora + (JWT_REFRESH_DIAS * 86400),
            ];

            setcookie(JWT_REFRESH_COOKIE, JWT::generar($refreshPayload, JWT_SECRETO), [
                'expires'  => $ahora + (JWT_REFRESH_DIAS * 86400),
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $seguro,
            ]);
        }
    }

    private static function limpiarCookiesJWT(): void
    {
        $seguro = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        foreach ([JWT_ACCESS_COOKIE, JWT_REFRESH_COOKIE] as $cookie) {
            setcookie($cookie, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $seguro,
            ]);
            unset($_COOKIE[$cookie]);
        }
    }
}
