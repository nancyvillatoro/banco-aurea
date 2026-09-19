<?php
session_start();
require_once '../config/db.php';
$cn = getConexion();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Identificar quién intenta entrar
    $usuario = "";
    if (isset($_POST['ID'])) {
        $usuario = $_POST['ID']; // Es un Empleado
    } elseif (isset($_POST['email'])) {
        $usuario = $_POST['email']; // Es un Cliente
    }
    
    $pass = $_POST['password'] ?? '';

    // 2. BUSCAR EN LA TABLA DE EMPLEADOS (inicioE)
    $stmtE = $cn->prepare("SELECT * FROM inicioE WHERE id_e = ? AND contraseña = ?");
    $stmtE->bind_param("ss", $usuario, $pass);
    $stmtE->execute();
    $resE = $stmtE->get_result();

    if ($emp = $resE->fetch_assoc()) {
        // Si lo encuentra, creamos sesión de empleado
        $_SESSION['nombre'] = "Administrador"; 
        $_SESSION['rol'] = "empleado";
        header("Location: ../../public/regisObusc.php"); // Página de éxito
        exit();
    }

    // 3. BUSCAR EN LA TABLA DE CLIENTES (registro) si no fue empleado
    $stmtC = $cn->prepare("SELECT * FROM registro WHERE correo = ? AND contraseña = ?");
    $stmtC->bind_param("ss", $usuario, $pass);
    $stmtC->execute();
    $resC = $stmtC->get_result();

    if ($user = $resC->fetch_assoc()) {
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['cliente'] = $user;
        header("Location: ../../public/datos-cliente.php");
        exit();
    }

    // Si no coincide en ninguna tabla
    header("Location: ../../public/index.php?error=1");
    exit();

} else {
    die("Acceso denegado.");
}
?>