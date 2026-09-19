-- Para BD que ya tenían la tabla movimientos ANTES de las transferencias.
-- Una transferencia son DOS filas (salida y entrada) con la misma transferencia_ref.
-- Usa "IF NOT EXISTS" (MariaDB, el que trae XAMPP), así que se puede ejecutar más de una vez.
SET NAMES utf8mb4;
USE banco;

ALTER TABLE movimientos MODIFY tipo ENUM('apertura','deposito','retiro','reverso','transferencia') NOT NULL;
ALTER TABLE movimientos ADD COLUMN IF NOT EXISTS transferencia_ref CHAR(16) DEFAULT NULL;
ALTER TABLE movimientos ADD COLUMN IF NOT EXISTS contraparte_id INT(11) DEFAULT NULL;
ALTER TABLE movimientos ADD KEY IF NOT EXISTS idx_transferencia_ref (transferencia_ref);
ALTER TABLE movimientos ADD FOREIGN KEY IF NOT EXISTS fk_mov_contraparte (contraparte_id) REFERENCES registro (id);
