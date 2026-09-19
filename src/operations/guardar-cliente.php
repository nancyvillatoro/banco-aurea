<?php
require_once '../auth/auth.php';
requerir_empleado_api();
require_once '../config/db.php';

const TIPOS_CUENTA = ['Ahorro', 'Nomina', 'Empresarial'];
const VISTA_REGISTRO = '../../public/registro-cliente-vista.php';

function volver_con_error(string $mensaje, array $datos = []): void {
    // Nunca se devuelve la contraseña al formulario
    unset($datos['password']);
    flash_set('danger', $mensaje, $datos);
    header('Location: ' . VISTA_REGISTRO);
    exit();
}

// Lee un campo como texto (o '' si no existe / no es texto)
function campo(string $nombre): string {
    $v = $_POST[$nombre] ?? '';
    return is_string($v) ? trim($v) : '';
}

$nombre   = campo('full_name');
$correo   = campo('email');
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$fechaNac = campo('fechaNac');
$cuenta   = campo('numeroCuenta');
$tipo     = campo('tipo_cuenta');
$saldoStr = campo('saldo');
$sucursal = campo('sucursal') !== '' ? campo('sucursal') : 'Matriz';

$old = $_POST;

// ---- Validaciones ----
if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 100) {
    volver_con_error('El nombre debe tener entre 3 y 100 caracteres.', $old);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 100) {
    volver_con_error('El correo electrónico no es válido.', $old);
}
if (strlen($password) < 8 || strlen($password) > 72) {
    volver_con_error('La contraseña debe tener entre 8 y 72 caracteres.', $old);
}
$fecha = DateTime::createFromFormat('!Y-m-d', $fechaNac);
if (!$fecha || $fecha->format('Y-m-d') !== $fechaNac) {
    volver_con_error('La fecha de nacimiento no es válida.', $old);
}
if ($fecha->diff(new DateTime('today'))->invert === 1 || $fecha->diff(new DateTime('today'))->y < 18) {
    volver_con_error('El cliente debe ser mayor de 18 años.', $old);
}
if (!preg_match('/^\d{10}$/', $cuenta)) {
    volver_con_error('El número de cuenta debe tener exactamente 10 dígitos.', $old);
}
if (!in_array($tipo, TIPOS_CUENTA, true)) {
    volver_con_error('El tipo de cuenta no es válido.', $old);
}
if (!is_numeric($saldoStr) || (float)$saldoStr < 0 || (float)$saldoStr > 99999999.99) {
    volver_con_error('El saldo debe ser un número entre 0 y 99,999,999.99.', $old);
}
if (mb_strlen($sucursal) > 50) {
    volver_con_error('La sucursal no es válida.', $old);
}
$saldo = round((float)$saldoStr, 2);

$cn = getConexion();

// La sucursal debe existir en la tabla (si la tabla existe); si no, solo se acepta "Matriz"
try {
    $r = $cn->query("SELECT nom_sucursal FROM sucursal");
    $validas = array_column($r->fetch_all(MYSQLI_ASSOC), 'nom_sucursal');
} catch (mysqli_sql_exception $e) {
    $validas = [];
}
if (!in_array($sucursal, $validas ?: ['Matriz'], true)) {
    volver_con_error('La sucursal seleccionada no existe.', $old);
}

// Comprobamos si el correo o el número de cuenta ya existen
$check = $cn->prepare("SELECT id FROM registro WHERE correo = ? OR numeroCuenta = ?");
$check->bind_param("ss", $correo, $cuenta);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    volver_con_error('El correo o el número de cuenta ya está registrado.', $old);
}
$check->close();

// Inserción (saldo = d, resto = s)
$hash  = password_hash($password, PASSWORD_DEFAULT);
$hoy   = date('Y-m-d');
$sql = "INSERT INTO registro (nombre, correo, contraseña, nacimiento, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $cn->prepare($sql);
$stmt->bind_param("ssssssdss", $nombre, $correo, $hash, $fechaNac, $cuenta, $tipo, $saldo, $hoy, $sucursal);

try {
    $stmt->execute();
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) { // clave duplicada (carrera entre el SELECT y el INSERT)
        volver_con_error('El correo o el número de cuenta ya está registrado.', $old);
    }
    error_log("guardar-cliente: " . $e->getMessage());
    volver_con_error('Error en el sistema. Inténtelo de nuevo.', $old);
}
$stmt->close();
$cn->close();

flash_set('success', "Cliente registrado correctamente (cuenta $cuenta).");
header('Location: ' . VISTA_REGISTRO);
exit();
