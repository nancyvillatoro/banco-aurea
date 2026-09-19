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
function aplicar_movimiento($cn, $cuenta_id, $tipo, $es_credito, $monto, $empleado, $motivo, $reversa_de = null, $ref = null, $contraparte = null) {
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
                          (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, reversa_de,
                           transferencia_ref, contraparte_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // tipos: cuenta_id(i) tipo(s) monto(s) es_credito(i) saldo(s) empleado(s) motivo(s) reversa_de(i) ref(s) contraparte(i)
    $stmt->bind_param("ississsisi", $cuenta_id, $tipo, $monto, $credito, $saldo, $empleado, $motivo, $reversa_de, $ref, $contraparte);
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
        $stmt = $cn->prepare("SELECT cuenta_id, tipo, monto, es_credito, transferencia_ref FROM movimientos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $movimiento_id);
        $stmt->execute();
        $orig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orig) {
            return resultado(false, 'El movimiento no existe.');
        }
        // Solo se revierten depósitos, retiros y transferencias (no aperturas ni otros reversos)
        if (!in_array($orig['tipo'], ['deposito', 'retiro', 'transferencia'], true)) {
            return resultado(false, 'Ese tipo de movimiento no se puede revertir.');
        }

        // Una transferencia tiene dos filas: se revierten las dos juntas
        if ($orig['tipo'] === 'transferencia') {
            return reversar_transferencia($cn, $orig['transferencia_ref'], $empleado, $motivo);
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

// Bloquea dos cuentas SIEMPRE en el mismo orden (por id). Así, si dos transferencias
// cruzadas ocurren a la vez (A->B y B->A), no se traban entre ellas (deadlock).
function bloquear_cuentas($cn, $id1, $id2) {
    $menor = min($id1, $id2);
    $mayor = max($id1, $id2);
    $stmt = $cn->prepare("SELECT id FROM registro WHERE id IN (?, ?) ORDER BY id FOR UPDATE");
    $stmt->bind_param("ii", $menor, $mayor);
    $stmt->execute();
    $cuantas = $stmt->get_result()->num_rows;
    $stmt->close();
    return $cuantas === 2;
}

// Mueve dinero de una cuenta a otra. Se guardan DOS movimientos con la misma referencia.
function transferir($cn, $origen_id, $destino_id, $monto, $empleado, $motivo) {
    if ($origen_id === $destino_id) {
        return resultado(false, 'La cuenta de origen y la de destino deben ser distintas.');
    }
    if ((float)$monto > MAX_OPERACION) {
        return resultado(false, 'El monto máximo por operación es $' . number_format(MAX_OPERACION, 2) . '.');
    }
    return con_transaccion($cn, function () use ($cn, $origen_id, $destino_id, $monto, $empleado, $motivo) {
        if (!bloquear_cuentas($cn, $origen_id, $destino_id)) {
            return resultado(false, 'Alguna de las cuentas no existe.');
        }
        $ref = bin2hex(random_bytes(8));

        // Sale de la cuenta de origen (aquí se valida que alcance el saldo)...
        $salida = aplicar_movimiento($cn, $origen_id, 'transferencia', false, $monto, $empleado, $motivo, null, $ref, $destino_id);
        if (!$salida['ok']) {
            return $salida;
        }
        // ...y entra a la de destino. Si esto falla, con_transaccion deshace también la salida.
        $entrada = aplicar_movimiento($cn, $destino_id, 'transferencia', true, $monto, $empleado, $motivo, null, $ref, $origen_id);
        if (!$entrada['ok']) {
            return resultado(false, 'La cuenta de destino no puede recibir ese monto: ' . $entrada['mensaje']);
        }
        return resultado(true, 'Transferencia realizada.', [
            'saldo' => $salida['saldo'],
            'movimiento_id' => $salida['movimiento_id'],
        ]);
    });
}

// Revierte una transferencia completa (las dos filas). Debe llamarse DENTRO de una transacción.
function reversar_transferencia($cn, $ref, $empleado, $motivo) {
    $stmt = $cn->prepare("SELECT id, cuenta_id, monto, es_credito, contraparte_id FROM movimientos
                          WHERE transferencia_ref = ? AND tipo = 'transferencia' ORDER BY id FOR UPDATE");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($filas) !== 2) {
        return resultado(false, 'La transferencia está incompleta y no se puede revertir.');
    }

    // ¿Alguna de las dos filas ya fue revertida?
    $stmt = $cn->prepare("SELECT COUNT(*) AS n FROM movimientos WHERE reversa_de IN (?, ?)");
    $stmt->bind_param("ii", $filas[0]['id'], $filas[1]['id']);
    $stmt->execute();
    $yaRevertida = (int)$stmt->get_result()->fetch_assoc()['n'] > 0;
    $stmt->close();
    if ($yaRevertida) {
        return resultado(false, 'Esa transferencia ya fue revertida.');
    }

    if (!bloquear_cuentas($cn, (int)$filas[0]['cuenta_id'], (int)$filas[1]['cuenta_id'])) {
        return resultado(false, 'Alguna de las cuentas no existe.');
    }

    // Primero la cuenta que en el reverso RESTA (la que había recibido el dinero):
    // ahí es donde puede faltar saldo y todo se cancela.
    usort($filas, function ($a, $b) {
        return (int)$b['es_credito'] <=> (int)$a['es_credito'];
    });

    $nueva_ref = bin2hex(random_bytes(8));
    $ultimo = null;
    foreach ($filas as $f) {
        $ultimo = aplicar_movimiento($cn, (int)$f['cuenta_id'], 'reverso', !$f['es_credito'], $f['monto'],
                                     $empleado, $motivo, (int)$f['id'], $nueva_ref, (int)$f['contraparte_id']);
        if (!$ultimo['ok']) {
            return $ultimo;
        }
    }
    // No se devuelve un saldo: son dos cuentas distintas
    return resultado(true, 'Transferencia revertida.', ['movimiento_id' => $ultimo['movimiento_id']]);
}
