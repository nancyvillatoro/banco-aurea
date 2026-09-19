<?php
require_once '../auth/auth.php';
requerir_empleado_api();
require_once '../config/db.php';
require_once '../auth/auditoria.php';
$cn = getConexion();

if (isset($_POST['empleado_id']) && ctype_digit((string)$_POST['empleado_id'])) {
    $id = (int)$_POST['empleado_id'];

    try {
        $stmt = $cn->prepare("SELECT nombre, sueldo_base, bono FROM sueldo WHERE empleado_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log("calcular-sueldo: " . $e->getMessage());
        responder_error(500, "No se pudo consultar la nómina.");
    }

    registrar_auditoria($cn, 'consultar_nomina', "Empleado ID $id");

    if ($f) {
        $total = $f['sueldo_base'] + $f['bono'];

        echo json_encode([
            "status" => "success",
            "nombre" => $f['nombre'],
            "base" => number_format($f['sueldo_base'], 2),
            "bono" => number_format($f['bono'], 2),
            "total" => number_format($total, 2)
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "ID de empleado no encontrado en nómina."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "ID de empleado inválido."]);
}
$cn->close();
