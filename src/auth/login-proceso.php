<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auditoria.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Acceso denegado.");
}

if (!csrf_valido()) {
    header("Location: ../../public/index.php?error=1");
    exit();
}

$cn = getConexion();
$pass = $_POST['password'] ?? '';
if (!is_string($pass)) $pass = '';

if (isset($_POST['ID'])) {
    // Login de EMPLEADO — solo busca en inicioE
    $usuario = (string)$_POST['ID'];

    if (login_bloqueado($usuario)) {
        header("Location: ../../public/login-empleado.php?error=bloqueado");
        exit();
    }

    $stmtE = $cn->prepare("SELECT id_e, contraseña, nombre, rol, activo FROM inicioe WHERE id_e = ?");
    $stmtE->bind_param("s", $usuario);
    $stmtE->execute();
    $emp = $stmtE->get_result()->fetch_assoc();

    // Un empleado dado de baja (activo = 0) no puede entrar aunque la contraseña sea correcta
    if ($emp && $emp['activo'] && password_verify($pass, $emp['contraseña'])) {
        login_ok($usuario);
        session_regenerate_id(true);
        $_SESSION['nombre'] = $emp['nombre'] !== '' ? $emp['nombre'] : $emp['id_e'];
        $_SESSION['rol'] = "empleado";          // tipo de usuario
        $_SESSION['nivel'] = $emp['rol'];       // empleado o administrador
        $_SESSION['empleado_id'] = $emp['id_e'];
        registrar_auditoria($cn, 'login');
        header("Location: ../../public/regisObusc.php");
        exit();
    }
    login_fallo($usuario);
    $detalle = 'ID intentado: ' . $usuario . ($emp && !$emp['activo'] ? ' (cuenta desactivada)' : '');
    registrar_auditoria($cn, 'login_fallido', $detalle, mb_substr($usuario, 0, 50));
    header("Location: ../../public/login-empleado.php?error=1");
    exit();

} elseif (isset($_POST['email'])) {
    // Login de CLIENTE — solo busca en registro
    $usuario = (string)$_POST['email'];

    if (login_bloqueado($usuario)) {
        header("Location: ../../public/login-cliente.php?error=bloqueado");
        exit();
    }

    $stmtC = $cn->prepare("SELECT * FROM registro WHERE correo = ?");
    $stmtC->bind_param("s", $usuario);
    $stmtC->execute();
    $user = $stmtC->get_result()->fetch_assoc();

    if ($user && password_verify($pass, $user['contraseña'])) {
        login_ok($usuario);
        session_regenerate_id(true);
        unset($user['contraseña']); // el hash no debe vivir en la sesión
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol'] = "cliente";
        $_SESSION['cliente'] = $user;
        header("Location: ../../public/datos-cliente.php");
        exit();
    }
    login_fallo($usuario);
    header("Location: ../../public/login-cliente.php?error=1");
    exit();
}

header("Location: ../../public/index.php?error=1");
exit();
