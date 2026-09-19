<?php
require_once '../config/db.php';
$cn = getConexion();

// Recibimos el número de cuenta por POST
$cuenta = $_POST['numeroCuenta'] ?? '';

if (!empty($cuenta)) {
    // Usamos Sentencia Preparada (Seguridad Fase 2)
    $stmt = $cn->prepare("SELECT nombre, correo, telefono, saldo, tipo_cuenta, sucursal2 FROM registro WHERE numeroCuenta = ?");
    $stmt->bind_param("s", $cuenta);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($cliente = $res->fetch_assoc()) {
        // Si lo encuentra, enviamos éxito y los datos
        echo json_encode([
            "status" => "success",
            "cliente" => $cliente
        ]);
    } else {
        // Si no, enviamos error
        echo json_encode([
            "status" => "error", 
            "message" => "La cuenta $cuenta no existe en el sistema."
        ]);
    }
    $stmt->close();
}
$cn->close();
?>