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

// Últimos 10 movimientos de SU cuenta (el id sale de la sesión, no de la URL)
$movimientos = [];
if ($c) {
    try {
        // De las transferencias solo se muestran los últimos 4 dígitos de la otra cuenta
        $stmt = $cn->prepare("SELECT m.tipo, m.monto, m.es_credito, m.saldo_despues, m.motivo, m.fecha,
                                     CONCAT('******', RIGHT(c.numeroCuenta, 4)) AS contraparte
                              FROM movimientos m
                              LEFT JOIN registro c ON c.id = m.contraparte_id
                              WHERE m.cuenta_id = ? ORDER BY m.id DESC LIMIT 10");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log("datos-cliente: " . $e->getMessage());
    }
}
$cn->close();
$nombres_tipo = ['apertura' => 'Apertura', 'deposito' => 'Depósito', 'retiro' => 'Retiro', 'reverso' => 'Corrección', 'transferencia' => 'Transferencia'];

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

            <h3 class="mt-4">Últimos movimientos</h3>
            <?php if (count($movimientos) === 0): ?>
                <p class="text-muted">Todavía no tiene movimientos.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Saldo</th><th>Detalle</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movimientos as $m): ?>
                                <tr>
                                    <td><?php echo esc($m['fecha']); ?></td>
                                    <td><?php echo esc($nombres_tipo[$m['tipo']] ?? $m['tipo']); ?></td>
                                    <td class="<?php echo $m['es_credito'] ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $m['es_credito'] ? '+' : '-'; ?>$<?php echo number_format((float)$m['monto'], 2); ?>
                                    </td>
                                    <td>$<?php echo number_format((float)$m['saldo_despues'], 2); ?></td>
                                    <td>
                                        <?php if ($m['contraparte']): ?>
                                            <?php echo $m['es_credito'] ? 'De' : 'A'; ?> cuenta <?php echo esc($m['contraparte']); ?>.
                                        <?php endif; ?>
                                        <?php echo esc($m['motivo']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <form action="../src/auth/logout.php" method="post" class="text-right mt-4">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn-salir">Cerrar Sesión</button>
            </form>
        </div>
    </main>
</body>
</html>
