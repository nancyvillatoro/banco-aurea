<!DOCTYPE HTML>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Banco Áurea - Login Cliente</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <section class="form-02-main">
        <div class="container">
            <div class="row">
                <div class="col-md-12">
                    <div class="_lk_de">
                        <div class="form-03-main">
                            <div class="logo">
                                <img src="assets/images/user.png">
                                <span>Banco Áurea</span>
                            </div>
                            <form action="../src/auth/login-proceso.php" method="post">
                                <div class="form-group">
                                    <input type="email" name="email" class="form-control _ge_de_ol" placeholder="Correo Electrónico" required>
                                </div>
                                <div class="form-group">
                                    <input type="password" name="password" class="form-control _ge_de_ol" placeholder="Contraseña" required>
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="btn_sesion">INICIAR SESIÓN</button>
                                </div>
                                <div class="form-group">
                                    <a href="index.php" class="btn-regresar">Regresar</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</body>
</html>