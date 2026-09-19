# Banco Áurea

Aplicación web en PHP + MySQL (XAMPP) con acceso para clientes y empleados.

## Puesta en marcha

1. Copiar el proyecto en `htdocs/bancoAurea` y arrancar Apache y MySQL.
2. Crear la base de datos: importar `database/schema.sql` (phpMyAdmin o `mysql -u root < database/schema.sql`).
3. Crear un empleado en `inicioe` con la contraseña hasheada (instrucciones al final de `schema.sql`).
4. (Recomendado) Copiar `src/config/config.example.php` a `src/config/config.local.php` y poner
   credenciales propias de MySQL. Ese archivo no se versiona. Sin él se usa `root` sin contraseña.
5. Abrir `http://localhost/bancoAurea/public/`.

## Estructura

- `public/` — páginas (vistas) y assets.
- `src/auth/` — login, logout y `auth.php` (sesión, roles, CSRF, límite de intentos).
- `src/operations/` — endpoints; todos exigen sesión de empleado, POST y token CSRF.
- `src/auth/auditoria.php` — guarda en la tabla `auditoria` qué empleado hizo cada operación (se consulta en `public/auditoria-vista.php`).
- `src/config/` — conexión a la BD (`.htaccess` impide el acceso web directo).
- `database/schema.sql` — esquema de la BD.
