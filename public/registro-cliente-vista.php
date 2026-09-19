<?php
session_start();
// Seguridad: Solo empleados pueden registrar clientes
if (!isset($_SESSION['cliente']) && !isset($_SESSION['nombre'])) {
    header("Location: login-empleado.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Banco Áurea - Registro de Cliente</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="form-03-main card p-4">
            <div class="logo text-center mb-4">
                <img src="assets/images/user.png" width="80">
                <h2 class="d-block"></h2>
            </div>

            <form action="../src/operations/guardar-cliente.php" method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <label>Nombre Completo:</label>
                        <input type="text" name="full_name" class="form-control mb-3" required>
                        
                        <label>Correo Electrónico:</label>
                        <input type="email" name="email" class="form-control mb-3" required>
                        
                        <label>Contraseña para el Cliente:</label>
                        <input type="password" name="password" class="form-control mb-3" required>
                        
                        <label>Fecha de Nacimiento:</label>
                        <input type="date" name="fechaNac" class="form-control mb-3" required>
                    </div>

                    <div class="col-md-6">
                        <label>Número de Cuenta (10 dígitos):</label>
                        <input type="text" name="numeroCuenta" class="form-control mb-3" maxlength="10" required>
                        
                        <label>Tipo de Cuenta:</label>
                        <select name="tipo_cuenta" class="form-control mb-3">
                            <option value="Ahorro">Cuenta de Ahorro</option>
                            <option value="Nomina">Cuenta de Nómina</option>
                            <option value="Empresarial">Cuenta Empresarial</option>
                        </select>

                        <label>Saldo Inicial ($):</label>
                        <input type="number" name="saldo" step="0.01" class="form-control mb-3" required>

                        <label>Asignar Sucursal:</label>
                        <select name="sucursal" id="sucursales-ajax" class="form-control mb-3">
                            <option value="Matriz">Sucursal Matriz</option>
                        </select>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg">Finalizar Registro</button>
                    <a href="regisObusc.php" class="btn btn-danger btn-lg">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Ejemplo de llamada AJAX para cargar sucursales desde la BD
            $.ajax({
                url: '../src/operations/obtener-sucursales.php',
                type: 'GET',
                success: function(response) {
                    let select = $('#sucursales-ajax');
                    // Si el archivo PHP devuelve JSON, aquí lo procesamos
                    if(response.length > 0) {
                        select.empty();
                        response.forEach(suc => {
                            select.append(`<option value="${suc.nom_sucursal}">${suc.nom_sucursal}</option>`);
                        });
                    }
                }
            });
        });
    </script>
</body>
</html>