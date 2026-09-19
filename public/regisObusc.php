<?php
require_once __DIR__ . '/../src/auth/auth.php';
// Seguridad: solo empleados
requerir_empleado_vista();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Control - Banco Áurea</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body style="background: #f8f9fa;">
    <div class="container mt-5">
        <div class="card p-5 shadow">
            <div class="text-center mb-4">
                <h1>Bienvenid@, <?php echo esc($_SESSION['nombre']); ?></h1>
                <p class="text-muted">¿Qué operación desea realizar hoy?</p>
            </div>
            
            <div class="row text-center">
                <div class="col-md-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">👤➕</div>
                        <h3>Registrar Cliente</h3>
                        <p>Dar de alta nuevas cuentas en el sistema.</p>
                        <a href="registro-cliente-vista.php" class="btn btn-success btn-lg btn-block">Ir a Registro</a>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">🔍</div>
                        <h3>Consultar Cuentas</h3>
                        <p>Ver saldos, movimientos y datos de clientes.</p>
                        <a href="buscar-cliente-vista.php" class="btn btn-primary btn-lg btn-block">Ir a Buscador</a>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">💵</div>
                        <h3>Nómina</h3>
                        <p>Consultar el sueldo de un empleado.</p>
                        <a href="sueldos-vista.php" class="btn btn-info btn-lg btn-block">Ir a Nómina</a>
                    </div>
                </div>
            </div>

            <div class="text-center mt-4">
                <form action="../src/auth/logout.php" method="post" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-outline-danger">Cerrar Sesión Segura</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>