<?php
require_once __DIR__ . '/../src/auth/auth.php';
// Seguridad: solo empleados
requerir_empleado_vista();
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Control - Banco Áurea</title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body style="background: #f8f9fa;">
    <div class="container mt-5">
        <div class="card p-5 shadow">
            <div class="text-center mb-4">
                <h1>Bienvenid@, <?php echo esc($_SESSION['nombre']); ?></h1>
                <p class="text-muted">¿Qué operación desea realizar hoy?</p>
                <p class="text-muted"><small>Rol: <?php echo es_admin() ? 'Administrador' : 'Empleado'; ?></small></p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['tipo'] === 'success' ? 'success' : 'danger'; ?>" role="alert">
                    <?php echo esc($flash['mensaje']); ?>
                </div>
            <?php endif; ?>
            
            <div class="row text-center">
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">👤➕</div>
                        <h3>Registrar Cliente</h3>
                        <p>Dar de alta nuevas cuentas en el sistema.</p>
                        <a href="registro-cliente-vista.php" class="btn btn-success btn-lg btn-block">Ir a Registro</a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">🔍</div>
                        <h3>Consultar Cuentas</h3>
                        <p>Ver saldos, movimientos y datos de clientes.</p>
                        <a href="buscar-cliente-vista.php" class="btn btn-primary btn-lg btn-block">Ir a Buscador</a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">💵</div>
                        <h3>Nómina</h3>
                        <p>Consultar el sueldo de un empleado.</p>
                        <a href="sueldos-vista.php" class="btn btn-info btn-lg btn-block">Ir a Nómina</a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">📋</div>
                        <h3>Clientes</h3>
                        <p>Listado de clientes con búsqueda.</p>
                        <a href="clientes-vista.php" class="btn btn-secondary btn-lg btn-block">Ver Clientes</a>
                    </div>
                </div>

                <?php if (es_admin()): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">🕵️</div>
                        <h3>Auditoría</h3>
                        <p>Ver qué operaciones se han realizado.</p>
                        <a href="auditoria-vista.php" class="btn btn-dark btn-lg btn-block">Ver Auditoría</a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">👥</div>
                        <h3>Empleados</h3>
                        <p>Crear empleados, cambiar contraseñas y darlos de baja.</p>
                        <a href="empleados-vista.php" class="btn btn-danger btn-lg btn-block">Ir a Empleados</a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="p-4 border rounded bg-white h-100">
                        <div style="font-size: 3rem;" aria-hidden="true">💰</div>
                        <h3>Movimientos</h3>
                        <p>Depósitos, retiros e historial de una cuenta.</p>
                        <a href="movimientos-vista.php" class="btn btn-warning btn-lg btn-block">Ir a Movimientos</a>
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