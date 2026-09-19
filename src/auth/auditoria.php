<?php
// Guarda en la tabla `auditoria` qué empleado hizo qué operación.
// Uso: registrar_auditoria($cn, 'consultar_cuenta', 'Cuenta 1234567890');

function registrar_auditoria($cn, $accion, $detalle = '', $empleado = null) {
    // Si no se indica el empleado, se toma el de la sesión
    if ($empleado === null) {
        $empleado = $_SESSION['empleado_id'] ?? '';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $detalle = mb_substr($detalle, 0, 255);

    try {
        $stmt = $cn->prepare("INSERT INTO auditoria (empleado_id, accion, detalle, ip) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $empleado, $accion, $detalle, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        // Si falla la auditoría no se rompe la operación, solo se deja en el log
        error_log("auditoria: " . $e->getMessage());
    }
}
