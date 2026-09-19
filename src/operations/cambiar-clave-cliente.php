<?php
require_once '../auth/auth.php';
requerir_cliente_api(); // solo POST, solo clientes y con token CSRF
require_once '../config/db.php';

// Guarda un mensaje y vuelve a la pantalla de cambio de contraseña
function volver($tipo, $mensaje) {
    flash_set($tipo, $mensaje);
    header('Location: ../../public/cambiar-clave.php');
    exit();
}

// Las contraseñas no se recortan: los espacios cuentan
$actual = is_string($_POST['actual'] ?? null) ? $_POST['actual'] : '';
$nueva = is_string($_POST['nueva'] ?? null) ? $_POST['nueva'] : '';
$confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';

$id = (int)$_SESSION['cliente']['id'];

// Mismo límite de intentos que el login: si alguien con la sesión abierta prueba contraseñas
// actuales una y otra vez, se bloquea 5 minutos.
$clave_limite = "cambio-clave-cliente-$id";
if (login_bloqueado($clave_limite)) {
    volver('danger', 'Demasiados intentos. Intente de nuevo en 5 minutos.');
}

// Primero se revisa lo que no necesita la base de datos
if (strlen($nueva) < 8 || strlen($nueva) > 72) {
    volver('danger', 'La contraseña nueva debe tener entre 8 y 72 caracteres.');
}
if ($nueva !== $confirmar) {
    volver('danger', 'La confirmación no coincide con la contraseña nueva.');
}
if ($nueva === $actual) {
    volver('danger', 'La contraseña nueva debe ser distinta de la actual.');
}

// La contraseña actual se verifica contra la BD (en la sesión no se guarda el hash)
$cn = getConexion();
$stmt = $cn->prepare("SELECT contraseña FROM registro WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$fila = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fila || !password_verify($actual, $fila['contraseña'])) {
    $cn->close();
    login_fallo($clave_limite);
    volver('danger', 'La contraseña actual no es correcta.');
}

$hash = password_hash($nueva, PASSWORD_DEFAULT);
$stmt = $cn->prepare("UPDATE registro SET contraseña = ? WHERE id = ?");
$stmt->bind_param("si", $hash, $id);
$stmt->execute();
$stmt->close();
$cn->close();

login_ok($clave_limite);
session_regenerate_id(true); // cambió una credencial: se renueva el identificador de sesión
volver('success', 'Contraseña actualizada.');
