<?php
// Configuración global de la base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); 
define('DB_NAME', 'banco');

function getConexion() {
    $conexion = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conexion->connect_error) {
        die("Error de conexión: " . $conexion->connect_error);
    }
    $conexion->set_charset("utf8");
    return $conexion;
}
?>