<?php
require_once __DIR__ . '/../src/auth/auth.php';
// Seguridad: solo empleados pueden registrar clientes
requerir_empleado_vista();
$flash = flash_get();
$old = $flash['datos'] ?? [];
$v = fn($campo) => esc($old[$campo] ?? '');
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

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['tipo'] === 'success' ? 'success' : 'danger'; ?>" role="alert">
                    <?php echo esc($flash['mensaje']); ?>
                </div>
            <?php endif; ?>

            <form action="../src/operations/guardar-cliente.php" method="POST">
                <?php echo csrf_field(); ?>
                <div class="row">
                    <div class="col-md-6">
                        <label>Nombre Completo:</label>
                        <input type="text" name="full_name" class="form-control mb-3" minlength="3" maxlength="100" value="<?php echo $v('full_name'); ?>" required>

                        <label>Correo Electrónico:</label>
                        <input type="email" name="email" class="form-control mb-3" maxlength="100" value="<?php echo $v('email'); ?>" required>

                        <label>Contraseña para el Cliente (mín. 8 caracteres):</label>
                        <input type="password" name="password" class="form-control mb-3" minlength="8" maxlength="72" autocomplete="new-password" required>

                        <label>Fecha de Nacimiento (mayor de 18):</label>
                        <input type="date" name="fechaNac" class="form-control mb-3" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" value="<?php echo $v('fechaNac'); ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label>Número de Cuenta (10 dígitos):</label>
                        <input type="text" name="numeroCuenta" class="form-control mb-3" inputmode="numeric" pattern="\d{10}" minlength="10" maxlength="10" title="Exactamente 10 dígitos" value="<?php echo $v('numeroCuenta'); ?>" required>
                        
                        <label>Tipo de Cuenta:</label>
                        <select name="tipo_cuenta" class="form-control mb-3">
                            <?php foreach (['Ahorro' => 'Cuenta de Ahorro', 'Nomina' => 'Cuenta de Nómina', 'Empresarial' => 'Cuenta Empresarial'] as $val => $txt): ?>
                                <option value="<?php echo $val; ?>"<?php echo ($old['tipo_cuenta'] ?? '') === $val ? ' selected' : ''; ?>><?php echo $txt; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Saldo Inicial ($):</label>
                        <input type="number" name="saldo" step="0.01" min="0" max="99999999.99" class="form-control mb-3" value="<?php echo $v('saldo'); ?>" required>

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
                            select.append($('<option>').val(suc.nom_sucursal).text(suc.nom_sucursal));
                        });
                        const previa = <?php echo json_encode($old['sucursal'] ?? ''); ?>;
                        if (previa) select.val(previa);
                    }
                }
            });
        });
    </script>
</body>
</html>