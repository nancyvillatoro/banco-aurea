<?php
session_start();
// Seguridad: Solo empleados autenticados
if (!isset($_SESSION['nombre'])) {
    header("Location: login-empleado.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Banco Áurea - Cálculo de Nómina</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="form-03-main card p-4 shadow">
            <div class="logo text-center mb-4">
                <img src="assets/images/user.png" width="60">
                <h2>Consulta de Nómina</h2>
                <p class="text-muted">Ingrese su ID para calcular el sueldo del periodo</p>
            </div>

            <div class="row justify-content-center mb-4">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="number" id="emp_id" class="form-control" placeholder="ID de Empleado (ej: 1)">
                        <div class="input-group-append">
                            <button class="btn btn-info" type="button" id="btnCalcular">Calcular Sueldo</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="detalle-sueldo" class="alert alert-light border" style="display:none;">
                <h4 class="text-center mb-3">Recibo de Pago Digital</h4>
                <table class="table table-sm">
                    <tr>
                        <th>Empleado:</th>
                        <td id="nom-emp"></td>
                    </tr>
                    <tr>
                        <th>Sueldo Base:</th>
                        <td id="base-emp"></td>
                    </tr>
                    <tr>
                        <th>Bonos/Comisiones:</th>
                        <td id="bono-emp"></td>
                    </tr>
                    <tr class="table-success">
                        <th><span class="h5">Total Neto:</span></th>
                        <td><span class="h5 text-dark" id="total-emp"></span></td>
                    </tr>
                </table>
            </div>

            <div id="error-sueldo" class="alert alert-warning text-center" style="display:none;"></div>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#btnCalcular').click(function() {
                const id = $('#emp_id').val();
                
                if(!id) {
                    alert("Por favor ingrese un ID");
                    return;
                }

                $.ajax({
                    url: '../src/operations/calcular-sueldo.php',
                    type: 'POST',
                    data: { empleado_id: id },
                    success: function(response) {
                        try {
                            const data = JSON.parse(response);
                            if(data.status === "success") {
                                $('#nom-emp').text(data.nombre);
                                $('#base-emp').text("$" + data.base);
                                $('#bono-emp').text("$" + data.bono);
                                $('#total-emp').text("$" + data.total);
                                
                                $('#detalle-sueldo').slideDown();
                                $('#error-sueldo').hide();
                            } else {
                                $('#error-sueldo').text(data.message).fadeIn();
                                $('#detalle-sueldo').hide();
                            }
                        } catch(e) {
                            console.error("Error en respuesta:", response);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>