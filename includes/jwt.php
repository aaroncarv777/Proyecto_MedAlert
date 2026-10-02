<?php

class JWT
{

    private static function b64Encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64Decode(string $data): string
    {

        $rem = strlen($data) % 4;
        if ($rem) $data .= str_repeat('=', 4 - $rem);
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function generar(array $payload, string $secreto): string
    {
        $header  = self::b64Encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $cuerpo  = self::b64Encode(json_encode($payload));
        $firma   = self::b64Encode(hash_hmac('sha256', "$header.$cuerpo", $secreto, true));
        return "$header.$cuerpo.$firma";
    }

    public static function verificar(string $token, string $secreto): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) return null;

        [$header, $cuerpo, $firma] = $partes;

        $firmaEsperada = self::b64Encode(
            hash_hmac('sha256', "$header.$cuerpo", $secreto, true)
        );
        if (!hash_equals($firmaEsperada, $firma)) return null;

        $datos = json_decode(self::b64Decode($cuerpo), true);
        if (!is_array($datos)) return null;

        if (isset($datos['exp']) && $datos['exp'] < time()) return null;

        return $datos;
    }
}
