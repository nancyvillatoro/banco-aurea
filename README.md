# Banco Áurea

Aplicación web en PHP + MySQL (XAMPP) con acceso para clientes y empleados.

## Puesta en marcha

1. Copiar el proyecto en `htdocs/bancoAurea` y arrancar Apache y MySQL.
2. Crear la base de datos: importar `database/schema.sql` (phpMyAdmin o `mysql -u root < database/schema.sql`).
3. Crear el primer administrador en `inicioe` con la contraseña hasheada (instrucciones al final de `schema.sql`).
   Los demás empleados los crea el administrador desde la pantalla "Empleados".
4. (Recomendado) Copiar `src/config/config.example.php` a `src/config/config.local.php` y poner
   credenciales propias de MySQL. Ese archivo no se versiona. Sin él se usa `root` sin contraseña.
5. Abrir `http://localhost/bancoAurea/public/`.

## Pruebas automáticas

```
C:\xampp\php\php.exe tests\run-tests.php
```

Necesita MySQL encendido. Crea una BD temporal `banco_test` con `database/schema.sql`, levanta un servidor PHP
en el puerto 8081, recorre la aplicación con peticiones reales (login, roles, registro, dinero, empleados...) y al
terminar borra la BD y apaga el servidor. **No toca la base de datos real.** Termina con código 0 si todo pasó.

Algunos antivirus bloquean estos archivos (levantan un servidor y terminan procesos): agregue una excepción
para la carpeta `tests/` si le pasa.

## Estructura

- `public/` — páginas (vistas) y assets.
- `src/auth/` — login, logout y `auth.php` (sesión, roles, CSRF, límite de intentos).
- `src/operations/` — endpoints; todos exigen sesión de empleado, POST y token CSRF.
- `src/auth/auditoria.php` — guarda en la tabla `auditoria` qué empleado hizo cada operación (se consulta en `public/auditoria-vista.php`).
- Roles: **empleado** (registra clientes, consulta, nómina, depósitos, retiros y transferencias) y **administrador** (además: auditoría, reversos —incluidas las transferencias— y gestión de empleados).
- `src/lib/movimientos.php` — depósitos, retiros, transferencias y reversos (con transacciones). El límite por operación está en `src/config/limites.php`.
- `src/config/` — conexión a la BD (`.htaccess` impide el acceso web directo).
- `database/schema.sql` — esquema de la BD (y `migracion-*.sql` para BD anteriores).
- `tests/` — pruebas automáticas.
