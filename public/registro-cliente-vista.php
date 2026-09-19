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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Registro de Cliente</title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="form-03-main card p-4">
            <div class="text-center mb-4">
                <img src="assets/images/user.png" width="80" alt="Logo de Banco Áurea">
                <h2 class="mt-2">Registro de Cliente</h2>
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
                        <label for="full_name" class="form-label">Nombre Completo:</label>
                        <input id="full_name" type="text" name="full_name" class="form-control mb-3" minlength="3" maxlength="100" value="<?php echo $v('full_name'); ?>" required>

                        <label for="email" class="form-label">Correo Electrónico:</label>
                        <input id="email" type="email" name="email" class="form-control mb-3" maxlength="100" value="<?php echo $v('email'); ?>" required>

                        <label for="password" class="form-label">Contraseña para el Cliente (mín. 8 caracteres):</label>
                        <input id="password" type="password" name="password" class="form-control mb-3" minlength="8" maxlength="72" autocomplete="new-password" required>

                        <label for="fechaNac" class="form-label">Fecha de Nacimiento (mayor de 18):</label>
                        <input id="fechaNac" type="date" name="fechaNac" class="form-control mb-3" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" value="<?php echo $v('fechaNac'); ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label for="numeroCuenta" class="form-label">Número de Cuenta (10 dígitos):</label>
                        <input id="numeroCuenta" type="text" name="numeroCuenta" class="form-control mb-3" inputmode="numeric" pattern="\d{10}" minlength="10" maxlength="10" title="Exactamente 10 dígitos" value="<?php echo $v('numeroCuenta'); ?>" required>
                        
                        <label for="tipo_cuenta" class="form-label">Tipo de Cuenta:</label>
                        <select id="tipo_cuenta" name="tipo_cuenta" class="form-select mb-3">
                            <?php foreach (['Ahorro' => 'Cuenta de Ahorro', 'Nomina' => 'Cuenta de Nómina', 'Empresarial' => 'Cuenta Empresarial'] as $val => $txt): ?>
                                <option value="<?php echo $val; ?>"<?php echo ($old['tipo_cuenta'] ?? '') === $val ? ' selected' : ''; ?>><?php echo $txt; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="saldo" class="form-label">Saldo Inicial ($):</label>
                        <input id="saldo" type="number" name="saldo" step="0.01" min="0" max="99999999.99" class="form-control mb-3" value="<?php echo $v('saldo'); ?>" required>

                        <label for="sucursales-ajax" class="form-label">Asignar Sucursal:</label>
                        <select id="sucursales-ajax" name="sucursal" class="form-select mb-3">
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