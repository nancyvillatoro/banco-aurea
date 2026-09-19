<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_cliente_vista();
require_once __DIR__ . '/../src/config/db.php';

// Buscamos los datos en la BD cada vez que se abre la página,
// así el saldo siempre está actualizado (antes se leía de la sesión).
$cn = getConexion();
$id = (int)$_SESSION['cliente']['id'];
$stmt = $cn->prepare("SELECT nombre, numeroCuenta, saldo, sucursal2 FROM registro WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();
$cn->close();

// Si la cuenta ya no existe, se manda al login
if (!$c) {
    header("Location: login-cliente.php");
    exit();
}
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
