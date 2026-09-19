-- Para BD creadas ANTES de existir los roles: agrega nombre, rol y activo a los empleados.
-- Usa "IF NOT EXISTS" (MariaDB, el que trae XAMPP), así que se puede ejecutar más de una vez.
-- El empleado 'admin' que ya existía pasa a ser administrador; los demás quedan como empleados.
SET NAMES utf8mb4;
USE banco;

ALTER TABLE inicioe ADD COLUMN IF NOT EXISTS nombre VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE inicioe ADD COLUMN IF NOT EXISTS rol ENUM('empleado','administrador') NOT NULL DEFAULT 'empleado';
ALTER TABLE inicioe ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1;

UPDATE inicioe SET rol = 'administrador', nombre = 'Administrador' WHERE id_e = 'admin' AND nombre = '';
