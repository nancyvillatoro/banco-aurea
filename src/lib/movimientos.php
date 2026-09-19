<?php
// Funciones para depósitos, retiros y reversos.
// Cada operación se hace dentro de una transacción: o se guarda todo o no se guarda nada.

require_once __DIR__ . '/../config/limites.php';

const SALDO_MAXIMO = '99999999.99'; // lo máximo que cabe en DECIMAL(10,2)

// Arma la respuesta que devuelven todas las funciones
function resultado($ok, $mensaje, $extra = []) {
    return array_merge(['ok' => $ok, 'mensaje' => $mensaje], $extra);
}

// Revisa el monto y lo devuelve como texto con 2 decimales (ej. "100.50").
// Devuelve null si no es válido. Se usa texto (no float) para no perder centavos.
function normalizar_monto($valor) {
    if (!is_string($valor)) {
        return null;
    }
    $valor = trim($valor);
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valor) || (float)$valor <= 0) {
        return null;
    }
    return number_format((float)$valor, 2, '.', '');
}

// Ejecuta $accion dentro de una transacción.
// Si devuelve ok se hace commit; si no, o si hay un error, rollback.
function con_transaccion($cn, $accion) {
    $cn->begin_transaction();
    try {
        $res = $accion();
        if ($res['ok']) {
            $cn->commit();
        } else {
            $cn->rollback();
        }
        return $res;
    } catch (Throwable $e) {
        $cn->rollback();
        // 1062 = clave duplicada: el movimiento ya tenía un reverso
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            return resultado(false, 'Ese movimiento ya fue revertido.');
        }
        error_log('movimientos: ' . $e->getMessage());
        return resultado(false, 'Error en el sistema. No se hizo ningún cambio.');
    }
}

// Cambia el saldo y guarda el movimiento. Debe llamarse DENTRO de una transacción.
function aplicar_movimiento($cn, $cuenta_id, $tipo, $es_credito, $monto, $empleado, $motivo, $reversa_de = null) {
    // 1) Leemos el saldo y bloqueamos la fila (FOR UPDATE) para que nadie más la cambie a la vez.
    //    Las comparaciones las hace MySQL con decimales exactos.
    $stmt = $cn->prepare("SELECT COALESCE(saldo, 0) >= ? AS alcanza,
                                 COALESCE(saldo, 0) + ? <= " . SALDO_MAXIMO . " AS cabe
                          FROM registro WHERE id = ? FOR UPDATE");
    $stmt->bind_param("ssi", $monto, $monto, $cuenta_id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        return resultado(false, 'La cuenta no existe.');
    }
    if ($es_credito && !$fila['cabe']) {
        return resultado(false, 'El saldo resultante superaría el máximo permitido.');
    }
    if (!$es_credito && !$fila['alcanza']) {
        return resultado(false, 'Saldo insuficiente.');
    }

    // 2) Actualizamos el saldo
    $signo = $es_credito ? '+' : '-';
    $stmt = $cn->prepare("UPDATE registro SET saldo = COALESCE(saldo, 0) $signo ? WHERE id = ?");
    $stmt->bind_param("si", $monto, $cuenta_id);
    $stmt->execute();
    $stmt->close();

    // 3) Leemos el saldo nuevo
    $stmt = $cn->prepare("SELECT saldo FROM registro WHERE id = ?");
    $stmt->bind_param("i", $cuenta_id);
    $stmt->execute();
    $saldo = $stmt->get_result()->fetch_assoc()['saldo'];
    $stmt->close();

    // 4) Guardamos el movimiento
    $credito = $es_credito ? 1 : 0;
    $stmt = $cn->prepare("INSERT INTO movimientos
                          (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, reversa_de)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    // tipos: cuenta_id(i) tipo(s) monto(s) es_credito(i) saldo(s) empleado(s) motivo(s) reversa_de(i)
    $stmt->bind_param("ississsi", $cuenta_id, $tipo, $monto, $credito, $saldo, $empleado, $motivo, $reversa_de);
    $stmt->execute();
    $movimiento_id = $cn->insert_id;
    $stmt->close();

    return resultado(true, 'Operación realizada.', ['saldo' => $saldo, 'movimiento_id' => $movimiento_id]);
}

function depositar($cn, $cuenta_id, $monto, $empleado, $motivo) {
    if ((float)$monto > MAX_OPERACION) {
        return resultado(false, 'El monto máximo por operación es $' . number_format(MAX_OPERACION, 2) . '.');
    }
    return con_transaccion($cn, function () use ($cn, $cuenta_id, $monto, $empleado, $motivo) {
        return aplicar_movimiento($cn, $cuenta_id, 'deposito', true, $monto, $empleado, $motivo);
    });
}

function retirar($cn, $cuenta_id, $monto, $empleado, $motivo) {
    if ((float)$monto > MAX_OPERACION) {
        return resultado(false, 'El monto máximo por operación es $' . number_format(MAX_OPERACION, 2) . '.');
    }
    return con_transaccion($cn, function () use ($cn, $cuenta_id, $monto, $empleado, $motivo) {
        return aplicar_movimiento($cn, $cuenta_id, 'retiro', false, $monto, $empleado, $motivo);
    });
}

// Corrige un movimiento creando otro que hace lo contrario (el original no se toca)
function reversar($cn, $movimiento_id, $empleado, $motivo) {
    return con_transaccion($cn, function () use ($cn, $movimiento_id, $empleado, $motivo) {
        // Buscamos el movimiento original y lo bloqueamos
        $stmt = $cn->prepare("SELECT cuenta_id, tipo, monto, es_credito FROM movimientos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $movimiento_id);
        $stmt->execute();
        $orig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orig) {
            return resultado(false, 'El movimiento no existe.');
        }
        // Solo se revierten depósitos y retiros (no aperturas ni otros reversos)
        if (!in_array($orig['tipo'], ['deposito', 'retiro'], true)) {
            return resultado(false, 'Ese tipo de movimiento no se puede revertir.');
        }

        // ¿Ya tiene un reverso?
        $stmt = $cn->prepare("SELECT id FROM movimientos WHERE reversa_de = ?");
        $stmt->bind_param("i", $movimiento_id);
        $stmt->execute();
        $yaRevertido = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($yaRevertido) {
            return resultado(false, 'Ese movimiento ya fue revertido.');
        }

        // El reverso hace lo contrario: si el original sumó, el reverso resta (y al revés)
        $es_credito = !$orig['es_credito'];
        return aplicar_movimiento($cn, (int)$orig['cuenta_id'], 'reverso', $es_credito,
                                  $orig['monto'], $empleado, $motivo, $movimiento_id);
    });
}
