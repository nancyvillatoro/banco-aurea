<?php
// Configuración de la base de datos.
// Para credenciales propias crear src/config/config.local.php (ver config.example.php);
// ese archivo NO se versiona. También se aceptan variables de entorno DB_*.
$__local = __DIR__ . '/config.local.php';
$__cfg = is_file($__local) ? require $__local : [];

define('DB_HOST', $__cfg['host'] ?? (getenv('DB_HOST') ?: 'localhost'));
define('DB_USER', $__cfg['user'] ?? (getenv('DB_USER') ?: 'root'));
define('DB_PASS', $__cfg['pass'] ?? (getenv('DB_PASS') ?: ''));
define('DB_NAME', $__cfg['name'] ?? (getenv('DB_NAME') ?: 'banco'));

function getConexion() {
    try {
        $conexion = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (mysqli_sql_exception $e) {
        error_log("Error de conexión a la BD: " . $e->getMessage());
        http_response_code(500);
        die("Error de conexión con la base de datos.");
    }
    if ($conexion->connect_error) {
        error_log("Error de conexión a la BD: " . $conexion->connect_error);
        http_response_code(500);
        die("Error de conexión con la base de datos.");
    }
    $conexion->set_charset("utf8");
    return $conexion;
}
