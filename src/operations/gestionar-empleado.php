<?php
require_once '../auth/auth.php';
requerir_admin_api(); // solo el administrador
require_once '../config/db.php';
require_once '../auth/auditoria.php';

const ROLES = ['empleado', 'administrador'];

function volver($tipo, $mensaje) {
    flash_set($tipo, $mensaje);
    header('Location: ../../public/empleados-vista.php');
    exit();
}

// Lee un campo de texto (o '' si no existe / no es texto)
function campo($nombre) {
    $v = $_POST[$nombre] ?? '';
    return is_string($v) ? trim($v) : '';
}

// La contraseña no se recorta: los espacios cuentan
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$accion = campo('accion');
$id = campo('id_e');

$cn = getConexion();

// ---------- Crear empleado ----------
if ($accion === 'crear') {
    $nombre = campo('nombre');
    $rol = campo('rol');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $id)) {
        volver('danger', 'El ID debe tener de 3 a 50 caracteres (letras, números, punto, guion o guion bajo).');
    }
    if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 100) {
        volver('danger', 'El nombre debe tener entre 3 y 100 caracteres.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        volver('danger', 'La contraseña debe tener entre 8 y 72 caracteres.');
    }
    if (!in_array($rol, ROLES, true)) {
        volver('danger', 'El rol no es válido.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $cn->prepare("INSERT INTO inicioe (id_e, contraseña, nombre, rol) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $id, $hash, $nombre, $rol);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) { // ID repetido
            volver('danger', 'Ya existe un empleado con ese ID.');
        }
        error_log('gestionar-empleado: ' . $e->getMessage());
        volver('danger', 'Error en el sistema. Inténtelo de nuevo.');
    }

    registrar_auditoria($cn, 'crear_empleado', "ID $id, rol $rol");
    volver('success', "Empleado $id creado como $rol.");
}

// ---------- Las demás acciones necesitan un empleado que ya exista ----------
$stmt = $cn->prepare("SELECT id_e, rol, activo FROM inicioe WHERE id_e = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$emp) {
    volver('danger', 'El empleado no existe.');
}
$id = $emp['id_e'];
$esUno = ($id === ($_SESSION['empleado_id'] ?? ''));

if ($accion === 'clave') {
    if (strlen($password) < 8 || strlen($password) > 72) {
        volver('danger', 'La contraseña debe tener entre 8 y 72 caracteres.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $cn->prepare("UPDATE inicioe SET contraseña = ? WHERE id_e = ?");
    $stmt->bind_param("ss", $hash, $id);
    $stmt->execute();
    $stmt->close();

    // Nunca se guarda la contraseña en la auditoría
    registrar_auditoria($cn, 'cambiar_clave_empleado', "ID $id");
    volver('success', "Contraseña de $id actualizada.");
}

if ($accion === 'estado') {
    $valor = campo('valor'); // activar o desactivar
    if (!in_array($valor, ['activar', 'desactivar'], true)) {
        volver('danger', 'Acción no válida.');
    }
    // Así siempre queda al menos un administrador activo: el que está haciendo el cambio
    if ($valor === 'desactivar' && $esUno) {
        volver('danger', 'No puede desactivarse a sí mismo.');
    }
    $activo = $valor === 'activar' ? 1 : 0;
    $stmt = $cn->prepare("UPDATE inicioe SET activo = ? WHERE id_e = ?");
    $stmt->bind_param("is", $activo, $id);
    $stmt->execute();
    $stmt->close();

    registrar_auditoria($cn, $valor . '_empleado', "ID $id");
    volver('success', $activo ? "Empleado $id activado." : "Empleado $id desactivado: ya no puede entrar.");
}

if ($accion === 'rol') {
    $rol = campo('rol');
    if (!in_array($rol, ROLES, true)) {
        volver('danger', 'El rol no es válido.');
    }
    if ($esUno) {
        volver('danger', 'No puede cambiar su propio rol.');
    }
    $stmt = $cn->prepare("UPDATE inicioe SET rol = ? WHERE id_e = ?");
    $stmt->bind_param("ss", $rol, $id);
    $stmt->execute();
    $stmt->close();

    registrar_auditoria($cn, 'cambiar_rol_empleado', "ID $id: de {$emp['rol']} a $rol");
    volver('success', "El rol de $id ahora es $rol.");
}

volver('danger', 'Acción no válida.');
