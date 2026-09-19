-- Esquema de la base de datos de Banco Áurea
-- Uso:  mysql -u root -p < database/schema.sql
-- Es idempotente: se puede ejecutar sobre una BD existente sin borrar datos.

-- Necesario: sin esto, en consolas de Windows la columna `contraseña` se crea con el nombre corrupto.
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS banco CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE banco;

-- Clientes
CREATE TABLE IF NOT EXISTS registro (
  id           INT(11)       NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(100)  NOT NULL,
  correo       VARCHAR(100)  NOT NULL,
  `contraseña` VARCHAR(255)  NOT NULL,           -- hash de password_hash()
  nacimiento   DATE          DEFAULT NULL,
  numeroCuenta VARCHAR(10)   DEFAULT NULL,
  tipo_cuenta  VARCHAR(20)   DEFAULT NULL,       -- Ahorro | Nomina | Empresarial
  saldo        DECIMAL(10,2) DEFAULT NULL,
  fecha        DATE          DEFAULT NULL,       -- fecha de alta
  sucursal2    VARCHAR(50)   DEFAULT NULL,       -- nombre de la sucursal
  PRIMARY KEY (id),
  UNIQUE KEY correo (correo),
  UNIQUE KEY numeroCuenta (numeroCuenta)         -- evita cuentas duplicadas
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Empleados (acceso administrativo)
-- Nombre en minúsculas: en Linux MySQL distingue mayúsculas en nombres de tabla.
CREATE TABLE IF NOT EXISTS inicioe (
  id           INT(11)      NOT NULL AUTO_INCREMENT,
  id_e         VARCHAR(50)  NOT NULL,
  `contraseña` VARCHAR(255) NOT NULL,            -- hash de password_hash()
  nombre       VARCHAR(100) NOT NULL DEFAULT '',
  rol          ENUM('empleado','administrador') NOT NULL DEFAULT 'empleado',
  activo       TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = dado de baja: ya no puede entrar
  PRIMARY KEY (id),
  UNIQUE KEY id_e (id_e)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sucursales (las usa el formulario de registro)
CREATE TABLE IF NOT EXISTS sucursal (
  id_sucursal  INT(11)      NOT NULL AUTO_INCREMENT,
  nom_sucursal VARCHAR(50)  NOT NULL,
  PRIMARY KEY (id_sucursal),
  UNIQUE KEY nom_sucursal (nom_sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO sucursal (nom_sucursal) VALUES ('Matriz');

-- Nómina (la usa sueldos-vista.php)
CREATE TABLE IF NOT EXISTS sueldo (
  empleado_id INT(11)       NOT NULL,
  nombre      VARCHAR(100)  NOT NULL,
  sueldo_base DECIMAL(10,2) NOT NULL DEFAULT 0,
  bono        DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (empleado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Auditoría: registra qué empleado hizo cada operación
CREATE TABLE IF NOT EXISTS auditoria (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  empleado_id VARCHAR(50)  NOT NULL,             -- id_e del empleado (o el ID intentado en un login fallido)
  accion      VARCHAR(50)  NOT NULL,             -- login, logout, registrar_cliente, consultar_cuenta...
  detalle     VARCHAR(255) DEFAULT NULL,
  ip          VARCHAR(45)  DEFAULT NULL,
  fecha       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Movimientos de dinero de cada cuenta.
-- Nunca se editan ni se borran: un error se corrige con otro movimiento de tipo 'reverso'.
CREATE TABLE IF NOT EXISTS movimientos (
  id            INT(11)       NOT NULL AUTO_INCREMENT,
  cuenta_id     INT(11)       NOT NULL,                 -- registro.id
  tipo          ENUM('apertura','deposito','retiro','reverso','transferencia') NOT NULL,
  monto         DECIMAL(10,2) NOT NULL,                 -- siempre positivo
  es_credito    TINYINT(1)    NOT NULL,                 -- 1 = suma al saldo, 0 = resta
  saldo_despues DECIMAL(10,2) NOT NULL,                 -- saldo de la cuenta luego del movimiento
  empleado_id   VARCHAR(50)   NOT NULL,                 -- quién lo hizo ('sistema' en las aperturas migradas)
  motivo        VARCHAR(255)  DEFAULT NULL,
  reversa_de    INT(11)       DEFAULT NULL,             -- si es un reverso, id del movimiento que corrige
  transferencia_ref CHAR(16)  DEFAULT NULL,             -- misma referencia en las dos filas de una transferencia
  contraparte_id INT(11)      DEFAULT NULL,             -- la otra cuenta de la transferencia
  fecha         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reversa_de (reversa_de),                -- un movimiento solo se puede revertir una vez
  KEY idx_cuenta (cuenta_id, id),
  KEY idx_transferencia_ref (transferencia_ref),
  CONSTRAINT fk_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES registro (id),
  CONSTRAINT fk_mov_reversa FOREIGN KEY (reversa_de) REFERENCES movimientos (id),
  CONSTRAINT fk_mov_contraparte FOREIGN KEY (contraparte_id) REFERENCES registro (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Crear el PRIMER administrador (generar el hash con:  php -r "echo password_hash('TuClave', PASSWORD_DEFAULT);" )
-- INSERT INTO inicioe (id_e, `contraseña`, nombre, rol) VALUES ('admin', '<hash>', 'Administrador', 'administrador');
-- Los demás empleados se crean desde la pantalla "Empleados" del administrador.
