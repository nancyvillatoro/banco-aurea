<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_admin_vista(); // solo el administrador ve la auditoría
require_once __DIR__ . '/../src/config/db.php';

$cn = getConexion();
$registros = [];
$error = '';

// Se muestran los últimos 100 movimientos
try {
    $res = $cn->query("SELECT fecha, empleado_id, accion, detalle, ip FROM auditoria ORDER BY id DESC LIMIT 100");
    $registros = $res->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log("auditoria-vista: " . $e->getMessage());
    $error = 'No se pudo leer la auditoría. Verifique que exista la tabla (database/schema.sql).';
}
$cn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Auditoría</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="container mt-5 mb-5">
        <div class="form-03-main">
            <h2 class="text-center mb-4">Registro de Auditoría</h2>
            <p class="text-muted">Últimas 100 operaciones realizadas por los empleados.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?php echo esc($error); ?></div>
            <?php elseif (count($registros) === 0): ?>
                <div class="alert alert-warning text-center" role="alert">Todavía no hay registros.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Empleado</th>
                                <th>Acción</th>
                                <th>Detalle</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $r): ?>
                                <tr>
                                    <td><?php echo esc($r['fecha']); ?></td>
                                    <td><?php echo esc($r['empleado_id']); ?></td>
                                    <td><?php echo esc($r['accion']); ?></td>
                                    <td><?php echo esc($r['detalle']); ?></td>
                                    <td><?php echo esc($r['ip']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </main>
</body>
</html>
