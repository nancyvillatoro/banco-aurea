<?php
require_once '../config/db.php';
$cn = getConexion();

// Recibimos todos los datos del formulario
$datos = [
    $_POST['full_name'], $_POST['address'], $_POST['city'], $_POST['state'],
    $_POST['postal_code'], $_POST['email'], $_POST['phone'], $_POST['fechaNac'],
    $_POST['password'], $_POST['numeroCuenta'], $_POST['tipo_cuenta'], 
    $_POST['saldo'], $_POST['fecha'], $_POST['sucursal']
];

// Comprobamos si la contraseña ya existe (Seguridad)
$check = $cn->prepare("SELECT id FROM registro WHERE contraseña = ?");
$check->bind_param("s", $datos[8]);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    die("Error: La contraseña ya existe.");
}

// Inserción profesional
$sql = "INSERT INTO registro (nombre, direccion, ciudad, estado, codigo, correo, telefono, nacimiento, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $cn->prepare($sql);
// "ssssssssssssss" significa que los 14 datos son tratados como strings seguros
$stmt->bind_param("ssssssssssssss", ...$datos);

if ($stmt->execute()) {
    echo "Registro exitoso";
} else {
    echo "Error en el sistema.";
}
$stmt->close();
?>