<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_cliente_vista();
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
        <form action="../src/auth/logout.php" method="post">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn-salir">Cerrar Sesión</button>
        </form>
    </div>
</body>
</html>