-- Para BD que ya tenían clientes ANTES de existir la tabla movimientos.
-- Crea un movimiento de "apertura" con el saldo actual de cada cliente, así el historial
-- cuadra con el saldo. Solo INSERTA filas; no modifica ningún saldo.
-- Es seguro ejecutarlo más de una vez (no duplica aperturas).
-- Requiere haber ejecutado antes schema.sql (crea la tabla movimientos).
SET NAMES utf8mb4;
USE banco;

INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, fecha)
SELECT r.id, 'apertura', r.saldo, 1, r.saldo, 'sistema', 'Saldo inicial existente antes de los movimientos',
       COALESCE(r.fecha, CURDATE())
FROM registro r
WHERE r.saldo IS NOT NULL AND r.saldo > 0
  AND NOT EXISTS (SELECT 1 FROM movimientos m WHERE m.cuenta_id = r.id AND m.tipo = 'apertura');
