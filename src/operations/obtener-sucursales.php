<?php
require_once '../auth/auth.php';
header('Content-Type: application/json');
if (!empleado_vigente()) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}
require_once '../config/db.php';
$cn = getConexion();

$sucursales = [];
try {
    $consulta = $cn->query("SELECT id_sucursal, nom_sucursal FROM sucursal");
    while ($fila = $consulta->fetch_assoc()) {
        $sucursales[] = $fila;
    }
} catch (mysqli_sql_exception $e) {
    // Sin tabla `sucursal` se devuelve lista vacía y el formulario usa "Matriz"
    error_log("obtener-sucursales: " . $e->getMessage());
}

echo json_encode($sucursales);

$cn->close();
