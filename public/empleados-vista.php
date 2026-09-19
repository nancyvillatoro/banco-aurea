<?php
require_once __DIR__ . '/../src/auth/auth.php';
requerir_admin_vista(); // solo el administrador
require_once __DIR__ . '/../src/config/db.php';

$flash = flash_get();

$cn = getConexion();
$res = $cn->query("SELECT id_e, nombre, rol, activo FROM inicioe ORDER BY id_e");
$empleados = $res->fetch_all(MYSQLI_ASSOC);
$cn->close();

$yo = $_SESSION['empleado_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Banco Áurea - Empleados</title>
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="container mt-5 mb-5">
        <div class="form-03-main">
            <h2 class="text-center mb-4">Gestión de Empleados</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['tipo'] === 'success' ? 'success' : 'danger'; ?>" role="alert">
                    <?php echo esc($flash['mensaje']); ?>
                </div>
            <?php endif; ?>

            <h4>Nuevo empleado</h4>
            <form action="../src/operations/gestionar-empleado.php" method="post" class="row mb-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" value="crear">

                <div class="col-md-3 mb-2">
                    <label for="n_id" class="form-label">ID de acceso</label>
                    <input type="text" id="n_id" name="id_e" class="form-control" pattern="[A-Za-z0-9_.\-]{3,50}"
                           minlength="3" maxlength="50" autocomplete="off" required>
                </div>
                <div class="col-md-3 mb-2">
                    <label for="n_nombre" class="form-label">Nombre</label>
                    <input type="text" id="n_nombre" name="nombre" class="form-control" minlength="3" maxlength="100" required>
                </div>
                <div class="col-md-3 mb-2">
                    <label for="n_pass" class="form-label">Contraseña (mín. 8)</label>
                    <input type="password" id="n_pass" name="password" class="form-control" minlength="8" maxlength="72"
                           autocomplete="new-password" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label for="n_rol" class="form-label">Rol</label>
                    <select id="n_rol" name="rol" class="form-select">
                        <option value="empleado">Empleado</option>
                        <option value="administrador">Administrador</option>
                    </select>
                </div>
                <div class="col-md-1 mb-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Crear</button>
                </div>
            </form>

            <h4>Empleados registrados</h4>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Cambiar contraseña</th>
                            <th>Rol / Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empleados as $e): ?>
                            <?php $esUno = ($e['id_e'] === $yo); ?>
                            <tr>
                                <td><?php echo esc($e['id_e']); ?><?php echo $esUno ? ' <small class="text-muted">(usted)</small>' : ''; ?></td>
                                <td><?php echo esc($e['nombre']); ?></td>
                                <td><?php echo $e['rol'] === 'administrador' ? 'Administrador' : 'Empleado'; ?></td>
                                <td><?php echo $e['activo'] ? '<span class="text-success">Activo</span>' : '<span class="text-danger">Desactivado</span>'; ?></td>
                                <td>
                                    <form action="../src/operations/gestionar-empleado.php" method="post" class="d-flex">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="accion" value="clave">
                                        <input type="hidden" name="id_e" value="<?php echo esc($e['id_e']); ?>">
                                        <label class="sr-only" for="p_<?php echo esc($e['id_e']); ?>">Nueva contraseña de <?php echo esc($e['id_e']); ?></label>
                                        <input type="password" id="p_<?php echo esc($e['id_e']); ?>" name="password"
                                               class="form-control form-control-sm" placeholder="Nueva contraseña"
                                               minlength="8" maxlength="72" autocomplete="new-password" required>
                                        <button type="submit" class="btn btn-outline-primary btn-sm ml-1">Cambiar</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($esUno): ?>
                                        <small class="text-muted">No puede cambiar su propio rol ni desactivarse.</small>
                                    <?php else: ?>
                                        <form action="../src/operations/gestionar-empleado.php" method="post" class="d-flex mb-1">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="accion" value="rol">
                                            <input type="hidden" name="id_e" value="<?php echo esc($e['id_e']); ?>">
                                            <label class="sr-only" for="r_<?php echo esc($e['id_e']); ?>">Rol de <?php echo esc($e['id_e']); ?></label>
                                            <select id="r_<?php echo esc($e['id_e']); ?>" name="rol" class="form-select form-select-sm">
                                                <option value="empleado"<?php echo $e['rol'] === 'empleado' ? ' selected' : ''; ?>>Empleado</option>
                                                <option value="administrador"<?php echo $e['rol'] === 'administrador' ? ' selected' : ''; ?>>Administrador</option>
                                            </select>
                                            <button type="submit" class="btn btn-outline-secondary btn-sm ml-1">Guardar</button>
                                        </form>
                                        <form action="../src/operations/gestionar-empleado.php" method="post"
                                              onsubmit="return confirm('¿Seguro?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="accion" value="estado">
                                            <input type="hidden" name="id_e" value="<?php echo esc($e['id_e']); ?>">
                                            <input type="hidden" name="valor" value="<?php echo $e['activo'] ? 'desactivar' : 'activar'; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $e['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                <?php echo $e['activo'] ? 'Desactivar' : 'Activar'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-center mt-4">
                <a href="regisObusc.php" class="btn btn-secondary">Volver al Panel</a>
            </div>
        </div>
    </main>
</body>
</html>
