<?php
require_once '../auth/auth.php';
requerir_admin_api(); // solo el administrador corrige movimientos
require_once '../config/db.php';
require_once '../auth/auditoria.php';
require_once '../lib/movimientos.php';

// Cuenta a la que se vuelve después (solo para redirigir)
$cuenta = is_string($_POST['cuenta'] ?? null) && preg_match('/^\d{10}$/', $_POST['cuenta']) ? $_POST['cuenta'] : '';

function volver($tipo, $mensaje, $cuenta = '') {
    flash_set($tipo, $mensaje);
    $url = '../../public/movimientos-vista.php';
    if ($cuenta !== '') {
        $url .= '?cuenta=' . urlencode($cuenta);
    }
    header('Location: ' . $url);
    exit();
}

if (!usar_token_operacion($_POST['op_token'] ?? '')) {
    volver('danger', 'Esta operación ya se procesó o el formulario venció. Revise el historial antes de repetirla.', $cuenta);
}

$id = $_POST['movimiento_id'] ?? '';
if (!is_string($id) || !ctype_digit($id)) {
    volver('danger', 'Movimiento no válido.', $cuenta);
}
$motivo = is_string($_POST['motivo'] ?? null) ? trim($_POST['motivo']) : '';
if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 255) {
    volver('danger', 'Escriba el motivo del reverso (entre 5 y 255 caracteres).', $cuenta);
}

$cn = getConexion();
$res = reversar($cn, (int)$id, $_SESSION['empleado_id'] ?? '', $motivo);

if ($res['ok']) {
    registrar_auditoria($cn, 'reverso', "Movimiento #$id revertido (nuevo movimiento #" . $res['movimiento_id'] . "). Motivo: $motivo");
    $cn->close();
    $texto = "Movimiento #$id revertido.";
    if (isset($res['saldo'])) {
        $texto .= " Saldo actual: $" . number_format((float)$res['saldo'], 2);
    } else {
        $texto = "Transferencia del movimiento #$id revertida: se deshicieron las dos partes.";
    }
    volver('success', $texto, $cuenta);
}

registrar_auditoria($cn, 'reverso_rechazado', "Movimiento #$id: " . $res['mensaje']);
$cn->close();
volver('danger', $res['mensaje'], $cuenta);
