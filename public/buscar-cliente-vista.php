<?php
require_once __DIR__ . '/../src/auth/auth.php';
// Seguridad: solo empleados
requerir_empleado_vista();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Consulta de Cuentas</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="form-03-main card p-4">
            <div class="text-center mb-4">
                <img src="assets/images/user.png" class="logo" alt="Logo de Banco Áurea" style="width:60px; height:60px; margin: 0 auto 10px;">
                <h2>Consulta de Clientes</h2>
            </div>

            <div class="row justify-content-center mb-4">
                <div class="col-md-8">
                    <div class="input-group">
                        <label for="num_cuenta" class="sr-only">Número de cuenta</label>
                        <input type="text" id="num_cuenta" class="form-control" inputmode="numeric" maxlength="10" placeholder="Ingrese el Número de Cuenta (10 dígitos)">
                        <button class="btn btn-primary" type="button" id="btnBuscar">Buscar Cliente</button>
                    </div>
                </div>
            </div>

            <div id="resultado-busqueda" style="display:none;" aria-live="polite">
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Nombre:</strong> <span id="res-nombre"></span></p>
                        <p><strong>Correo:</strong> <span id="res-correo"></span></p>
                       
                    </div>
                    <div class="col-md-6 text-right">
                        <h3 class="text-success">Saldo: $<span id="res-saldo"></span></h3>
                        <p><strong>Tipo:</strong> <span id="res-tipo"></span></p>
                        <p><strong>Sucursal:</strong> <span id="res-suc"></span></p>
                    </div>
                </div>
            </div>

            <div id="error-busqueda" class="alert alert-danger mt-3" role="alert" style="display:none;"></div>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#btnBuscar').click(function() {
                const cuenta = $('#num_cuenta').val().trim();

                if (!/^\d{10}$/.test(cuenta)) {
                    $('#error-busqueda').text('Ingrese un número de cuenta de 10 dígitos.').fadeIn();
                    $('#resultado-busqueda').hide();
                    return;
                }

                $.ajax({
                    url: '../src/operations/buscar-cliente.php',
                    type: 'POST',
                    data: { numeroCuenta: cuenta, csrf_token: <?php echo json_encode(csrf_token()); ?> },
                    success: function(response) {
                        try {
                            const data = JSON.parse(response);
                            if(data.status === "success") {
                                $('#res-nombre').text(data.cliente.nombre);
                                $('#res-correo').text(data.cliente.correo);
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
                            $('#error-busqueda').text('Respuesta inesperada del servidor.').fadeIn();
                        }
                    },
                    error: function(xhr) {
                        let msg = 'No se pudo completar la búsqueda.';
                        try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                        $('#error-busqueda').text(msg).fadeIn();
                        $('#resultado-busqueda').hide();
                    }
                });
            });
        });
    </script>
</body>
</html>