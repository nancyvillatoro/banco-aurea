<?php
require_once '../auth/auth.php';
requerir_empleado_api();
require_once '../config/db.php';
require_once '../auth/auditoria.php';
$cn = getConexion();

$cuenta = $_POST['numeroCuenta'] ?? '';

if (is_string($cuenta) && $cuenta !== '') {
    $stmt = $cn->prepare("SELECT nombre, correo, saldo, tipo_cuenta, sucursal2 FROM registro WHERE numeroCuenta = ?");
    $stmt->bind_param("s", $cuenta);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($cliente = $res->fetch_assoc()) {
        registrar_auditoria($cn, 'consultar_cuenta', "Cuenta $cuenta");
        echo json_encode([
            "status" => "success",
            "cliente" => $cliente
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "La cuenta indicada no existe en el sistema."
        ]);
        registrar_auditoria($cn, 'consultar_cuenta', "Cuenta $cuenta (no existe)");
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Ingrese un número de cuenta."]);
}
$cn->close();
