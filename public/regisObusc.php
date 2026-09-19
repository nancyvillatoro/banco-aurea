<?php
session_start();
// Seguridad: Si alguien intenta entrar sin loguearse, lo mandamos al inicio
if (!isset($_SESSION['nombre'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Panel de Control - Banco Áurea</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body style="background: #f8f9fa;">
    <div class="container mt-5">
        <div class="card p-5 shadow">
            <div class="text-center mb-4">
                <h1>Bienvenid@, <?php echo $_SESSION['nombre']; ?></h1>
                <p class="text-muted">¿Qué operación desea realizar hoy?</p>
            </div>
            
            <div class="row text-center">
                <div class="col-md-6 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <img src="assets/images/add-user.png" width="80" class="mb-3">
                        <h3>Registrar Cliente</h3>
                        <p>Dar de alta nuevas cuentas en el sistema.</p>
                        <a href="registro-cliente-vista.php" class="btn btn-success btn-lg btn-block">Ir a Registro</a>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <img src="assets/images/search.png" width="80" class="mb-3">
                        <h3>Consultar Cuentas</h3>
                        <p>Ver saldos, movimientos y datos de clientes.</p>
                        <a href="buscar-cliente-vista.php" class="btn btn-primary btn-lg btn-block">Ir a Buscador</a>
                    </div>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="../src/auth/logout.php" class="btn btn-outline-danger">Cerrar Sesión Segura</a>
            </div>
        </div>
    </div>
</body>
</html>