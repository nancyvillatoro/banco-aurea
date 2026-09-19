<?php
require_once '../config/db.php';
$cn = getConexion();

$consulta = $cn->query("SELECT id_sucursal, nom_sucursal FROM sucursal");
$sucursales = [];

while ($fila = $consulta->fetch_assoc()) {
    $sucursales[] = $fila;
}

// REFACTORIZACIÓN: Eliminamos el armado manual de cadenas y enviamos JSON puro
header('Content-Type: application/json');
echo json_encode($sucursales);

$cn->close();
?>