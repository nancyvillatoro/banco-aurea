<?php
require_once '../config/db.php';
$cn = getConexion();

if (isset($_POST['empleado_id'])) {
    $id = $_POST['empleado_id'];
    
    $stmt = $cn->prepare("SELECT * FROM sueldo WHERE empleado_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($f = $res->fetch_assoc()) {
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
    $stmt->close();
}
$cn->close();
?>