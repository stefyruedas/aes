<?php
/**
 * Conexion a la base de datos mediante PDO.
 * Devuelve una unica instancia (patron singleton simple).
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            if (MODO_DESARROLLO) {
                die('Error de conexion a la base de datos: ' . $e->getMessage()
                    . '<br>Verifica que XAMPP (MySQL) este encendido y que hayas importado database/aes_db.sql.');
            }
            die('No se pudo conectar a la base de datos.');
        }
    }

    return $pdo;
}
