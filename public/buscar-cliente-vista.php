<?php
session_start();
// Seguridad: Si no es empleado, fuera.
if (!isset($_SESSION['nombre'])) {
    header("Location: login-empleado.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Banco Áurea - Consulta de Cuentas</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="form-03-main card p-4">
            <div class="logo text-center mb-4">
                <img src="assets/images/user.png" width="60">
                <h2>Consulta de Clientes</h2>
            </div>

            <div class="row justify-content-center mb-4">
                <div class="col-md-8">
                    <div class="input-group">
                        <input type="text" id="num_cuenta" class="form-control" placeholder="Ingrese el Número de Cuenta (10 dígitos)">
                        <div class="input-group-append">
                            <button class="btn btn-primary" type="button" id="btnBuscar">Buscar Cliente</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="resultado-busqueda" style="display:none;">
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Nombre:</strong> <span id="res-nombre"></span></p>
                        <p><strong>Correo:</strong> <span id="res-correo"></span></p>
                        <p><strong>Teléfono:</strong> <span id="res-tel"></span></p>
                    </div>
                    <div class="col-md-6 text-right">
                        <h3 class="text-success">Saldo: $<span id="res-saldo"></span></h3>
                        <p><strong>Tipo:</strong> <span id="res-tipo"></span></p>
                        <p><strong>Sucursal:</strong> <span id="res-suc"></span></p>
                    </div>
                </div>
            </div>

            <div id="error-busqueda" class="alert alert-danger mt-3" style="display:none;"></div>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#btnBuscar').click(function() {
                const cuenta = $('#num_cuenta').val();
                
                $.ajax({
                    url: '../src/operations/buscar-cliente.php', // El código que te pasé anteriormente
                    type: 'POST',
                    data: { numeroCuenta: cuenta },
                    success: function(response) {
                        try {
                            const data = JSON.parse(response);
                            if(data.status === "success") {
                                $('#res-nombre').text(data.cliente.nombre);
                                $('#res-correo').text(data.cliente.correo);
                                $('#res-tel').text(data.cliente.telefono);
                                $('#res-saldo').text(parseFloat(data.cliente.saldo).toLocaleString());
                                $('#res-tipo').text(data.cliente.tipo_cuenta);
                                $('#res-suc').text(data.cliente.sucursal2);
                                
                                $('#resultado-busqueda').fadeIn();
                                $('#error-busqueda').hide();
                            } else {
                                $('#error-busqueda').text(data.message).fadeIn();
                                $('#resultado-busqueda').hide();
                            }
                        } catch(e) {
                            console.error("Error en JSON", e);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>