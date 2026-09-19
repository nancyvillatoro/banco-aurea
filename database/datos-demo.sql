-- Datos de DEMOSTRACIÓN de Banco Áurea (todo es ficticio).
--
-- Uso (después de schema.sql):   mysql -u root < database/datos-demo.sql
--
-- Credenciales de demo:
--   Administrador:  ID  demo-admin                Clave  Demo-Admin-2026
--   Empleado:       ID  demo-emp                  Clave  Demo-Emp-2026
--   Clientes:       lucia.demo@example.com
--                   diego.demo@example.com        Clave  Demo-Cliente-2026
--                   sol.demo@example.com
--
-- Incluye depósitos, retiros, dos transferencias entre cuentas y un reverso, con saldos
-- que cuadran con los movimientos. Ejecútelo UNA sola vez: si se repite, el INSERT de
-- clientes falla (correo repetido) y el script se detiene sin duplicar nada.
SET NAMES utf8mb4;
USE banco;

INSERT IGNORE INTO sucursal (nom_sucursal) VALUES ('Sucursal Norte'), ('Sucursal Sur');

INSERT IGNORE INTO inicioe (id_e, `contraseña`, nombre, rol, activo) VALUES
('demo-admin', '$2y$10$IUmcUIKhJvPZZOsRDiW4ZOonqb/Y14TvqEYxWrbUqCw7CwGJl/EMC', 'Ana Demo (Administradora)', 'administrador', 1),
('demo-emp',   '$2y$10$2fNAZtNWb.PI9sMj/VSbqOBXV8SLdGKdGoaU0tcPwTuswGldE2beC', 'Carlos Demo (Cajero)',      'empleado',      1);

-- Nómina de demo (en "Nómina" se consulta por el número de empleado: 1 o 2)
INSERT IGNORE INTO sueldo (empleado_id, nombre, sueldo_base, bono) VALUES
(1, 'Ana Demo (Administradora)', 18000.00, 2500.00),
(2, 'Carlos Demo (Cajero)',      12000.00, 1000.00);

-- Clientes (el saldo es el resultado final de los movimientos de más abajo)
INSERT INTO registro (nombre, correo, `contraseña`, nacimiento, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES
('Lucía Ramírez',    'lucia.demo@example.com', '$2y$10$dgSrysF0ffu9xxNNKFJABOQfUZAjD3DfP6uCo.PqtyYNRw.j/G97q', '1992-03-14', '7100000001', 'Ahorro',      7700.00,  '2026-09-01', 'Matriz'),
('Diego Herrera',    'diego.demo@example.com', '$2y$10$dgSrysF0ffu9xxNNKFJABOQfUZAjD3DfP6uCo.PqtyYNRw.j/G97q', '1988-11-02', '7100000002', 'Nomina',      2749.50,  '2026-09-01', 'Sucursal Norte'),
('Empresa Sol S.A.', 'sol.demo@example.com',   '$2y$10$dgSrysF0ffu9xxNNKFJABOQfUZAjD3DfP6uCo.PqtyYNRw.j/G97q', '2015-06-20', '7100000003', 'Empresarial', 22000.00, '2026-09-01', 'Sucursal Sur');

SET @a = (SELECT id FROM registro WHERE numeroCuenta = '7100000001');  -- Lucía
SET @d = (SELECT id FROM registro WHERE numeroCuenta = '7100000002');  -- Diego
SET @e = (SELECT id FROM registro WHERE numeroCuenta = '7100000003');  -- Empresa Sol

-- Movimientos en orden cronológico. Columnas:
--   cuenta, tipo, monto, es_credito (1 suma / 0 resta), saldo_despues, empleado, motivo, fecha
INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, fecha) VALUES
(@a, 'apertura', 5000.00,  1,  5000.00, 'demo-emp', 'Saldo inicial',                '2026-09-01 09:00:00'),
(@d, 'apertura', 2000.00,  1,  2000.00, 'demo-emp', 'Saldo inicial',                '2026-09-01 09:05:00'),
(@e, 'apertura', 20000.00, 1, 20000.00, 'demo-emp', 'Saldo inicial',                '2026-09-01 09:10:00'),
(@a, 'deposito', 1500.00,  1,  6500.00, 'demo-emp', 'Depósito de nómina',           '2026-09-02 10:00:00'),
(@a, 'retiro',    800.00,  0,  5700.00, 'demo-emp', 'Retiro en ventanilla',         '2026-09-02 11:30:00'),
(@e, 'deposito', 5000.00,  1, 25000.00, 'demo-emp', 'Cobro a cliente',              '2026-09-03 09:15:00');

-- Un retiro capturado con un monto equivocado...
INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, fecha)
VALUES (@e, 'retiro', 1200.00, 0, 23800.00, 'demo-emp', 'Pago a proveedor', '2026-09-03 12:00:00');
SET @retiro_error = LAST_INSERT_ID();

-- ...y su reverso, hecho por el administrador (el retiro original no se toca)
INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, reversa_de, fecha)
VALUES (@e, 'reverso', 1200.00, 1, 25000.00, 'demo-admin', 'Monto capturado por error', @retiro_error, '2026-09-03 12:20:00');

-- Transferencias: cada una son DOS filas con la misma referencia (16 caracteres)
INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, transferencia_ref, contraparte_id, fecha) VALUES
(@a, 'transferencia', 1000.00, 0,  4700.00, 'demo-emp', 'Pago de renta',       'DEMOTRANSF000001', @d, '2026-09-04 10:00:00'),
(@d, 'transferencia', 1000.00, 1,  3000.00, 'demo-emp', 'Pago de renta',       'DEMOTRANSF000001', @a, '2026-09-04 10:00:00');

INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, fecha)
VALUES (@d, 'retiro', 250.50, 0, 2749.50, 'demo-emp', 'Retiro en ventanilla', '2026-09-05 16:00:00');

INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id, motivo, transferencia_ref, contraparte_id, fecha) VALUES
(@e, 'transferencia', 3000.00, 0, 22000.00, 'demo-emp', 'Pago de servicios',   'DEMOTRANSF000002', @a, '2026-09-06 09:30:00'),
(@a, 'transferencia', 3000.00, 1,  7700.00, 'demo-emp', 'Pago de servicios',   'DEMOTRANSF000002', @e, '2026-09-06 09:30:00');

-- Un poco de auditoría para que la pantalla no aparezca vacía
INSERT INTO auditoria (empleado_id, accion, detalle, ip, fecha) VALUES
('demo-emp',   'login',             '',                                                           '127.0.0.1', '2026-09-01 08:55:00'),
('demo-emp',   'registrar_cliente', 'Cuenta 7100000001, correo lucia.demo@example.com',           '127.0.0.1', '2026-09-01 09:00:00'),
('demo-emp',   'registrar_cliente', 'Cuenta 7100000002, correo diego.demo@example.com',           '127.0.0.1', '2026-09-01 09:05:00'),
('demo-emp',   'registrar_cliente', 'Cuenta 7100000003, correo sol.demo@example.com',             '127.0.0.1', '2026-09-01 09:10:00'),
('demo-emp',   'deposito',          'Cuenta 7100000001, monto 1500.00',                           '127.0.0.1', '2026-09-02 10:00:00'),
('demo-emp',   'retiro',            'Cuenta 7100000003, monto 1200.00',                           '127.0.0.1', '2026-09-03 12:00:00'),
('demo-admin', 'login',             '',                                                           '127.0.0.1', '2026-09-03 12:15:00'),
('demo-admin', 'reverso',           'Retiro de 1200.00 en la cuenta 7100000003. Motivo: Monto capturado por error', '127.0.0.1', '2026-09-03 12:20:00'),
('demo-emp',   'transferencia',     'De 7100000001 a 7100000002, monto 1000.00',                  '127.0.0.1', '2026-09-04 10:00:00'),
('demo-emp',   'transferencia',     'De 7100000003 a 7100000001, monto 3000.00',                  '127.0.0.1', '2026-09-06 09:30:00');
