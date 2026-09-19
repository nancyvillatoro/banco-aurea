<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_empleado_vista();
require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/config/limites.php';
require_once __DIR__ . '/../src/auth/auditoria.php';

$flash = flash_get();
$cuenta = is_string($_GET['cuenta'] ?? null) ? trim($_GET['cuenta']) : '';
$datos = null;       // datos de la cuenta encontrada
$movimientos = [];
$error = '';

if ($cuenta !== '') {
    if (!preg_match('/^\d{10}$/', $cuenta)) {
        $error = 'El número de cuenta debe tener 10 dígitos.';
    } else {
        $cn = getConexion();

        $stmt = $cn->prepare("SELECT id, nombre, numeroCuenta, tipo_cuenta, saldo FROM registro WHERE numeroCuenta = ?");
        $stmt->bind_param("s", $cuenta);
        $stmt->execute();
        $datos = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$datos) {
            $error = 'La cuenta no existe.';
        } else {
            // Últimos 20 movimientos; revertido_por dice si ya tiene un reverso
            $stmt = $cn->prepare("SELECT m.id, m.tipo, m.monto, m.es_credito, m.saldo_despues, m.empleado_id,
                                         m.motivo, m.reversa_de, m.fecha, r.id AS revertido_por
                                  FROM movimientos m
                                  LEFT JOIN movimientos r ON r.reversa_de = m.id
                                  WHERE m.cuenta_id = ?
                                  ORDER BY m.id DESC LIMIT 20");
            $stmt->bind_param("i", $datos['id']);
            $stmt->execute();
            $movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            registrar_auditoria($cn, 'consultar_movimientos', "Cuenta $cuenta");
        }
        $cn->close();
    }
}

// Tokens de un solo uso: uno para el formulario de depósito/retiro y otro para los reversos
$token_operacion = $datos ? nuevo_token_operacion() : '';
$token_reverso = $datos ? nuevo_token_operacion() : '';

$nombres_tipo = ['apertura' => 'Apertura', 'deposito' => 'Depósito', 'retiro' => 'Retiro', 'reverso' => 'Reverso'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Movimientos</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="container mt-5 mb-5">
        <div class="form-03-main">
            <h2 class="text-center mb-4">Movimientos de Cuenta</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['tipo'] === 'success' ? 'success' : 'danger'; ?>" role="alert">
                    <?php echo esc($flash['mensaje']); ?>
                </div>
            <?php endif; ?>

            <form method="get" class="row justify-content-center mb-4">
                <div class="col-md-8">
                    <div class="input-group">
                        <label for="cuenta" class="sr-only">Número de cuenta</label>
                        <input type="text" id="cuenta" name="cuenta" class="form-control" inputmode="numeric"
                               pattern="\d{10}" maxlength="10" placeholder="Número de cuenta (10 dígitos)"
                               value="<?php echo esc($cuenta); ?>" required>
                        <button class="btn btn-primary" type="submit">Buscar Cuenta</button>
                    </div>
                </div>
            </form>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?php echo esc($error); ?></div>
            <?php endif; ?>

            <?php if ($datos): ?>
                <hr>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Cliente:</strong> <?php echo esc($datos['nombre']); ?></p>
                        <p><strong>Cuenta:</strong> <?php echo esc($datos['numeroCuenta']); ?> (<?php echo esc($datos['tipo_cuenta']); ?>)</p>
                    </div>
                    <div class="col-md-6 text-right">
                        <h3 class="text-success">Saldo: $<?php echo number_format((float)$datos['saldo'], 2); ?></h3>
                    </div>
                </div>

                <h4>Nueva operación</h4>
                <form action="../src/operations/registrar-movimiento.php" method="post" class="row mb-4"
                      onsubmit="this.querySelector('button').disabled = true;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="op_token" value="<?php echo esc($token_operacion); ?>">
                    <input type="hidden" name="cuenta" value="<?php echo esc($datos['numeroCuenta']); ?>">

                    <div class="col-md-3 mb-2">
                        <label for="tipo" class="form-label">Operación</label>
                        <select id="tipo" name="tipo" class="form-select">
                            <option value="deposito">Depósito</option>
                            <option value="retiro">Retiro</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="monto" class="form-label">Monto ($)</label>
                        <input type="number" id="monto" name="monto" class="form-control" step="0.01" min="0.01"
                               max="<?php echo MAX_OPERACION; ?>" required>
                        <small class="text-muted">Máximo $<?php echo number_format(MAX_OPERACION, 2); ?> por operación</small>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label for="motivo" class="form-label">Descripción (opcional)</label>
                        <input type="text" id="motivo" name="motivo" class="form-control" maxlength="255">
                    </div>
                    <div class="col-md-2 mb-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100">Aplicar</button>
                    </div>
                </form>

                <h4>Últimos movimientos</h4>
                <?php if (count($movimientos) === 0): ?>
                    <div class="alert alert-warning text-center" role="alert">Esta cuenta todavía no tiene movimientos.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Monto</th>
                                    <th>Saldo</th>
                                    <th>Empleado</th>
                                    <th>Detalle</th>
                                    <th>Corrección</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movimientos as $m): ?>
                                    <tr>
                                        <td><?php echo (int)$m['id']; ?></td>
                                        <td><?php echo esc($m['fecha']); ?></td>
                                        <td><?php echo esc($nombres_tipo[$m['tipo']] ?? $m['tipo']); ?></td>
                                        <td class="<?php echo $m['es_credito'] ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $m['es_credito'] ? '+' : '-'; ?>$<?php echo number_format((float)$m['monto'], 2); ?>
                                        </td>
                                        <td>$<?php echo number_format((float)$m['saldo_despues'], 2); ?></td>
                                        <td><?php echo esc($m['empleado_id']); ?></td>
                                        <td>
                                            <?php if ($m['reversa_de']): ?>
                                                Reverso del #<?php echo (int)$m['reversa_de']; ?>.
                                            <?php endif; ?>
                                            <?php echo esc($m['motivo']); ?>
                                        </td>
                                        <td>
                                            <?php if ($m['revertido_por']): ?>
                                                Revertido (#<?php echo (int)$m['revertido_por']; ?>)
                                            <?php elseif ($m['tipo'] === 'deposito' || $m['tipo'] === 'retiro'): ?>
                                                <form action="../src/operations/reversar-movimiento.php" method="post"
                                                      onsubmit="if (!confirm('¿Reversar el movimiento #<?php echo (int)$m['id']; ?>?')) { return false; } this.querySelector('button').disabled = true;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="op_token" value="<?php echo esc($token_reverso); ?>">
                                                    <input type="hidden" name="cuenta" value="<?php echo esc($datos['numeroCuenta']); ?>">
                                                    <input type="hidden" name="movimiento_id" value="<?php echo (int)$m['id']; ?>">
                                                    <input type="text" name="motivo" class="form-control form-control-sm mb-1"
                                                           placeholder="Motivo (mín. 5 letras)" minlength="5" maxlength="255" required>
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Reversar</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </main>
</body>
</html>
