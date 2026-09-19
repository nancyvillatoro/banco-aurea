<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_empleado_vista();
require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/auth/auditoria.php';

$por_pagina = 10;

// Texto a buscar (nombre, correo o número de cuenta) y página actual
$buscar = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$pagina = max(1, (int)($_GET['p'] ?? 1));

$cn = getConexion();

// Escapamos % y _ para que se busquen como texto normal y no como comodines
$like = '%' . addcslashes($buscar, '%_\\') . '%';
$where = "WHERE nombre LIKE ? OR correo LIKE ? OR numeroCuenta LIKE ?";

// 1) Cuántos clientes hay en total con ese filtro
$stmt = $cn->prepare("SELECT COUNT(*) AS total FROM registro $where");
$stmt->bind_param("sss", $like, $like, $like);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$paginas = max(1, (int)ceil($total / $por_pagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $por_pagina;

// 2) Los clientes de la página actual
$stmt = $cn->prepare("SELECT nombre, correo, numeroCuenta, tipo_cuenta, saldo, sucursal2, fecha
                      FROM registro $where ORDER BY nombre LIMIT ? OFFSET ?");
$stmt->bind_param("sssii", $like, $like, $like, $por_pagina, $offset);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

registrar_auditoria($cn, 'listar_clientes', ($buscar !== '' ? "Búsqueda: $buscar, " : '') . "página $pagina");
$cn->close();

// Arma el enlace de una página conservando la búsqueda
function enlace_pagina($p, $buscar) {
    return '?' . http_build_query(['q' => $buscar, 'p' => $p]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Clientes</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="container mt-5 mb-5">
        <div class="form-03-main">
            <h2 class="text-center mb-4">Listado de Clientes</h2>

            <form method="get" class="row justify-content-center mb-4">
                <div class="col-md-8">
                    <div class="input-group">
                        <label for="q" class="sr-only">Buscar cliente</label>
                        <input type="text" id="q" name="q" class="form-control" maxlength="100"
                               placeholder="Buscar por nombre, correo o número de cuenta"
                               value="<?php echo esc($buscar); ?>">
                        <button class="btn btn-primary" type="submit">Buscar</button>
                    </div>
                </div>
            </form>

            <p class="text-muted"><?php echo $total; ?> cliente(s) encontrado(s)</p>

            <?php if ($total === 0): ?>
                <div class="alert alert-warning text-center" role="alert">No se encontraron clientes.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>N° Cuenta</th>
                                <th>Tipo</th>
                                <th>Saldo</th>
                                <th>Sucursal</th>
                                <th>Alta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $c): ?>
                                <tr>
                                    <td><?php echo esc($c['nombre']); ?></td>
                                    <td><?php echo esc($c['correo']); ?></td>
                                    <td><?php echo esc($c['numeroCuenta']); ?></td>
                                    <td><?php echo esc($c['tipo_cuenta']); ?></td>
                                    <td>$<?php echo number_format((float)$c['saldo'], 2); ?></td>
                                    <td><?php echo esc($c['sucursal2']); ?></td>
                                    <td><?php echo esc($c['fecha']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <?php if ($pagina > 1): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo esc(enlace_pagina($pagina - 1, $buscar)); ?>">&laquo; Anterior</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>

                    <span>Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span>

                    <?php if ($pagina < $paginas): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo esc(enlace_pagina($pagina + 1, $buscar)); ?>">Siguiente &raquo;</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </main>
</body>
</html>
