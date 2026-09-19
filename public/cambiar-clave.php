<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_cliente_vista();
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cambiar contraseña - Banco Áurea</title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="form-02-main">
        <div class="form-03-main form-login">
            <h2 class="text-center mb-4">Cambiar contraseña</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['tipo'] === 'success' ? 'success' : 'danger'; ?>" role="alert">
                    <?php echo esc($flash['mensaje']); ?>
                </div>
            <?php endif; ?>

            <form action="../src/operations/cambiar-clave-cliente.php" method="post">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="actual" class="form-label">Contraseña actual</label>
                    <input type="password" id="actual" name="actual" class="form-control"
                           autocomplete="current-password" required>
                </div>
                <div class="form-group">
                    <label for="nueva" class="form-label">Contraseña nueva (mínimo 8 caracteres)</label>
                    <input type="password" id="nueva" name="nueva" class="form-control"
                           minlength="8" maxlength="72" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="confirmar" class="form-label">Repita la contraseña nueva</label>
                    <input type="password" id="confirmar" name="confirmar" class="form-control"
                           minlength="8" maxlength="72" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn_sesionC">GUARDAR CONTRASEÑA</button>
                </div>
                <div class="form-group text-center">
                    <a href="datos-cliente.php" class="link_register">Volver a mi cuenta</a>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
