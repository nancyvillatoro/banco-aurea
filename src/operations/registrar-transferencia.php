<?php
require_once '../auth/auth.php';
requerir_empleado_api();
require_once '../config/db.php';
require_once '../auth/auditoria.php';
require_once '../lib/movimientos.php';

// Cuenta desde la que se hizo la transferencia (para volver a su pantalla)
$origen = is_string($_POST['cuenta_origen'] ?? null) ? trim($_POST['cuenta_origen']) : '';
$destino = is_string($_POST['cuenta_destino'] ?? null) ? trim($_POST['cuenta_destino']) : '';
$motivo = is_string($_POST['motivo'] ?? null) ? trim($_POST['motivo']) : '';

// Guarda un mensaje y vuelve a la pantalla de movimientos
function volver($tipo, $mensaje, $cuenta = '') {
    flash_set($tipo, $mensaje);
    $url = '../../public/movimientos-vista.php';
    if ($cuenta !== '' && preg_match('/^\d{10}$/', $cuenta)) {
        $url .= '?cuenta=' . urlencode($cuenta);
    }
    header('Location: ' . $url);
    exit();
}

// Token de un solo uso contra el doble envío
if (!usar_token_operacion($_POST['op_token'] ?? '')) {
    volver('danger', 'Esta operación ya se procesó o el formulario venció. Revise el historial antes de repetirla.', $origen);
}

if (!preg_match('/^\d{10}$/', $origen) || !preg_match('/^\d{10}$/', $destino)) {
    volver('danger', 'Las dos cuentas deben tener 10 dígitos.', $origen);
}
$monto = normalizar_monto($_POST['monto'] ?? null);
if ($monto === null) {
    volver('danger', 'El monto debe ser mayor que 0 y tener máximo 2 decimales.', $origen);
}
if (mb_strlen($motivo) > 255) {
    volver('danger', 'El motivo es demasiado largo (máximo 255 caracteres).', $origen);
}

$cn = getConexion();

// Buscamos las dos cuentas
$ids = [];
foreach (['origen' => $origen, 'destino' => $destino] as $nombre => $numero) {
    $stmt = $cn->prepare("SELECT id FROM registro WHERE numeroCuenta = ?");
    $stmt->bind_param("s", $numero);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fila) {
        volver('danger', "La cuenta de $nombre no existe.", $origen);
    }
    $ids[$nombre] = (int)$fila['id'];
}

$res = transferir($cn, $ids['origen'], $ids['destino'], $monto, $_SESSION['empleado_id'] ?? '', $motivo);

if ($res['ok']) {
    registrar_auditoria($cn, 'transferencia', "De $origen a $destino, monto $monto, movimiento #" . $res['movimiento_id']);
    $cn->close();
    volver('success', 'Transferencia de $' . number_format((float)$monto, 2) . " a la cuenta $destino realizada. Saldo actual: $" . number_format((float)$res['saldo'], 2), $origen);
}

registrar_auditoria($cn, 'transferencia_rechazada', "De $origen a $destino, monto $monto: " . $res['mensaje']);
$cn->close();
volver('danger', $res['mensaje'], $origen);
