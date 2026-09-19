<?php
require_once __DIR__ . '/../src/auth/auth.php';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Login Cliente</title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="form-02-main">
        <div class="form-03-main form-login">
            <div class="text-center">
                <img src="assets/images/user.png" class="logo" alt="Logo de Banco Áurea">
                <span class="logo-text">Banco Áurea</span>
            </div>
            <form action="../src/auth/login-proceso.php" method="post">
                <?php echo csrf_field(); ?>
                <?php if ($error === 'bloqueado'): ?>
                    <div class="alert alert-danger" role="alert">Demasiados intentos. Intente de nuevo en 5 minutos.</div>
                <?php elseif ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">Correo o contraseña incorrectos.</div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="email" class="sr-only">Correo electrónico</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Correo Electrónico" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="password" class="sr-only">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Contraseña" autocomplete="current-password" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn_sesionC">INICIAR SESIÓN</button>
                </div>
                <div class="form-group text-center">
                    <a href="index.php" class="link_register">Regresar</a>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
