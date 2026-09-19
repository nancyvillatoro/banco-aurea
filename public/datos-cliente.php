<?php
session_start();
if (!isset($_SESSION['cliente'])) {
    header("Location: login-cliente.php");
    exit();
}
$c = $_SESSION['cliente'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Cuenta - Banco Áurea</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Detalles de la Cuenta</h2>
        <div class="card">
            <p><strong>Nombre:</strong> <?php echo htmlspecialchars($c['nombre']); ?></p>
            <p><strong>N° Cuenta:</strong> <?php echo htmlspecialchars($c['numeroCuenta']); ?></p>
            <p><strong>Saldo Actual:</strong> $<?php echo number_format($c['saldo'], 2); ?></p>
            <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($c['sucursal2']); ?></p>
        </div>
        <a href="../src/auth/logout.php" class="btn-salir">Cerrar Sesión</a>
    </div>
</body>
</html>