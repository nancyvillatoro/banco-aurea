-- Para BD creadas ANTES de schema.sql: añade el índice único a numeroCuenta.
-- (schema.sql ya lo incluye en instalaciones nuevas.)
-- Antes, comprobar que no hay duplicados:
--   SELECT numeroCuenta, COUNT(*) FROM registro
--   WHERE numeroCuenta IS NOT NULL GROUP BY numeroCuenta HAVING COUNT(*) > 1;
USE banco;
ALTER TABLE registro ADD UNIQUE KEY numeroCuenta (numeroCuenta);
