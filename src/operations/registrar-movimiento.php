<?php
require_once '../auth/auth.php';
requerir_empleado_api();
require_once '../config/db.php';
require_once '../auth/auditoria.php';
require_once '../lib/movimientos.php';

// Guarda un mensaje y vuelve a la pantalla de movimientos
function volver($tipo, $mensaje, $cuenta = '') {
    flash_set($tipo, $mensaje);
    $url = '../../public/movimientos-vista.php';
    if ($cuenta !== '') {
        $url .= '?cuenta=' . urlencode($cuenta);
    }
    header('Location: ' . $url);
    exit();
}

$cuenta = is_string($_POST['cuenta'] ?? null) ? trim($_POST['cuenta']) : '';
$tipo   = is_string($_POST['tipo'] ?? null) ? $_POST['tipo'] : '';
$motivo = is_string($_POST['motivo'] ?? null) ? trim($_POST['motivo']) : '';

// Token de un solo uso: si ya se usó, la operación se está repitiendo
if (!usar_token_operacion($_POST['op_token'] ?? '')) {
    volver('danger', 'Esta operación ya se procesó o el formulario venció. Revise el historial antes de repetirla.', $cuenta);
}

if (!preg_match('/^\d{10}$/', $cuenta)) {
    volver('danger', 'El número de cuenta debe tener 10 dígitos.');
}
if (!in_array($tipo, ['deposito', 'retiro'], true)) {
    volver('danger', 'El tipo de operación no es válido.', $cuenta);
}
$monto = normalizar_monto($_POST['monto'] ?? null);
if ($monto === null) {
    volver('danger', 'El monto debe ser mayor que 0 y tener máximo 2 decimales.', $cuenta);
}
if (mb_strlen($motivo) > 255) {
    volver('danger', 'El motivo es demasiado largo (máximo 255 caracteres).', $cuenta);
}

$cn = getConexion();

// Buscamos la cuenta
$stmt = $cn->prepare("SELECT id FROM registro WHERE numeroCuenta = ?");
$stmt->bind_param("s", $cuenta);
$stmt->execute();
$fila = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$fila) {
    volver('danger', 'La cuenta no existe.');
}

$empleado = $_SESSION['empleado_id'] ?? '';
if ($tipo === 'deposito') {
    $res = depositar($cn, (int)$fila['id'], $monto, $empleado, $motivo);
} else {
    $res = retirar($cn, (int)$fila['id'], $monto, $empleado, $motivo);
}

if ($res['ok']) {
    registrar_auditoria($cn, $tipo, "Cuenta $cuenta, monto $monto, movimiento #" . $res['movimiento_id']);
    $cn->close();
    $nombre = $tipo === 'deposito' ? 'Depósito' : 'Retiro';
    volver('success', "$nombre de $" . number_format((float)$monto, 2) . " realizado. Saldo actual: $" . number_format((float)$res['saldo'], 2), $cuenta);
}

registrar_auditoria($cn, $tipo . '_rechazado', "Cuenta $cuenta, monto $monto: " . $res['mensaje']);
$cn->close();
volver('danger', $res['mensaje'], $cuenta);
