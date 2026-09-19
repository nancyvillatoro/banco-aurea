<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_cliente_vista();
$c = $_SESSION['cliente'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi Cuenta - Banco Áurea</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="container mt-5">
        <div class="form-03-main">
            <h2 class="mb-4">Detalles de la Cuenta</h2>
            <div class="datos-cuenta">
                <p><strong>Nombre:</strong> <?php echo esc($c['nombre']); ?></p>
                <p><strong>N° Cuenta:</strong> <?php echo esc($c['numeroCuenta']); ?></p>
                <p><strong>Saldo Actual:</strong> $<?php echo number_format((float)$c['saldo'], 2); ?></p>
                <p><strong>Sucursal:</strong> <?php echo esc($c['sucursal2']); ?></p>
            </div>
            <form action="../src/auth/logout.php" method="post" class="text-right mt-4">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn-salir">Cerrar Sesión</button>
            </form>
        </div>
    </main>
</body>
</html>
