<?php
/**
 * Configuracion global de Gestion AES
 * Hostal Casa Zen
 */

// --- Zona horaria ---
date_default_timezone_set('America/Bogota');

// --- Parametros de conexion a la base de datos (XAMPP por defecto) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'aes_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // En XAMPP la contrasena de root suele ir vacia
define('DB_CHARSET', 'utf8mb4');

// --- Rutas base ---
// BASE_URL: ajustala segun la carpeta donde coloques el proyecto dentro de htdocs.
// Si el proyecto esta en  htdocs/aes/  entonces BASE_URL = '/aes'
define('BASE_URL', '/aes');
define('APP_NAME', 'Gestion AES');
define('HOSTAL_NOMBRE', 'Hostal Casa Zen');

// --- Correo simulado ---
// Los codigos de verificacion no se envian por SMTP real: se guardan en este
// archivo de log y (en modo desarrollo) se muestran en pantalla.
define('CORREO_SIMULADO', true);
define('CORREO_LOG', __DIR__ . '/../storage/correos.log');

// --- Vigencia de codigos de verificacion (minutos) ---
define('CODIGO_VIGENCIA_MIN', 10);

// --- Modo desarrollo: muestra codigos en pantalla y errores detallados ---
define('MODO_DESARROLLO', true);

if (MODO_DESARROLLO) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
