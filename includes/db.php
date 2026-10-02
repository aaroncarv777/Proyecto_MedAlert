<?php

require_once __DIR__ . '/config.php';

class DB
{
    private static ?PDO $instancia = null;

    public static function obtener(): PDO
    {
        if (self::$instancia === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$instancia = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]);
            } catch (PDOException $e) {
                if (AMBIENTE === 'desarrollo') {
                    die('Error de conexión a la base de datos: ' . $e->getMessage());
                }
                die('Error de conexión a la base de datos.');
            }
        }
        return self::$instancia;
    }

    public static function consultar(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::obtener()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function uno(string $sql, array $params = []): ?array
    {
        $stmt = self::consultar($sql, $params);
        $fila = $stmt->fetch();
        return $fila === false ? null : $fila;
    }

    public static function todos(string $sql, array $params = []): array
    {
        return self::consultar($sql, $params)->fetchAll();
    }

    public static function ultimoId(): int
    {
        return (int)self::obtener()->lastInsertId();
    }
}
