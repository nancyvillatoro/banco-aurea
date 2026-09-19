<?php
require_once __DIR__ . '/../src/auth/auth.php';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE HTML>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Banco Áurea - Login Cliente</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <section class="form-02-main d-flex" style="align-items:center; justify-content:center;">
        <div class="form-03-main" style="max-width:450px; width:100%;">
            <div class="logo" style="text-align:center;">
                <img src="assets/images/user.png" class="logo">
                <span class="logo-text">Banco Áurea</span>
            </div>
            <form action="../src/auth/login-proceso.php" method="post">
                <?php echo csrf_field(); ?>
                <?php if ($error === 'bloqueado'): ?>
                    <p style="color:#b00020; text-align:center;">Demasiados intentos. Intente de nuevo en 5 minutos.</p>
                <?php elseif ($error !== ''): ?>
                    <p style="color:#b00020; text-align:center;">Correo o contraseña incorrectos.</p>
                <?php endif; ?>
                <div class="form-group">
                    <input type="email" name="email" class="form-control _ge_de_ol" placeholder="Correo Electrónico" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" class="form-control _ge_de_ol" placeholder="Contraseña" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn_sesionC">INICIAR SESIÓN</button>
                </div>
                <div class="form-group" style="text-align:center;">
                    <a href="index.php" class="link_register">Regresar</a>
                </div>
            </form>
        </div>
    </section>
</body>
</html>