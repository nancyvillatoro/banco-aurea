# Banco Áurea

Aplicación web bancaria hecha en **PHP y MySQL** como proyecto académico: clientes que consultan su cuenta y
empleados que operan el dinero (depósitos, retiros y transferencias) con control de roles, auditoría y pruebas
automáticas de extremo a extremo.

## Qué puede hacer cada rol

| Rol | Puede |
|---|---|
| **Cliente** | Ver su saldo actualizado y todos sus movimientos, paginados (de las transferencias solo ve los últimos 4 dígitos de la otra cuenta), y **cambiar su contraseña**. |
| **Empleado** | Registrar clientes, buscar cuentas, listar clientes con búsqueda y paginación, consultar nómina, hacer depósitos, retiros y transferencias, y ver el historial paginado de una cuenta. |
| **Administrador** | Todo lo del empleado, más: **revertir movimientos** (incluidas transferencias completas), ver la **auditoría** y **gestionar empleados** (crear, cambiar contraseña y rol, activar o desactivar). |

## Tecnologías

- **PHP 8.2** sin framework (mysqli con sentencias preparadas), **MariaDB/MySQL** (InnoDB), **Apache**.
- **Bootstrap 5** (versión `5.0.0-alpha1`) y jQuery para la interfaz.
- Sin dependencias externas de PHP

## Lo más importante que resuelve

- **Dinero exacto:** los montos son `DECIMAL(10,2)` y se manejan como texto, nunca como `float`.
- **Operaciones atómicas:** cada depósito, retiro, transferencia o reverso es una transacción con bloqueo de fila
  (`SELECT … FOR UPDATE`). Si algo falla, no queda ningún cambio a medias.
- **Sin saldos negativos ni con retiros simultáneos:** probado con 8 retiros a la vez sobre un saldo que solo alcanza
  para 3.
- **Sin deadlocks en transferencias cruzadas:** las dos cuentas se bloquean siempre en el mismo orden. Probado con
  80 transferencias simultáneas en sentidos opuestos.
- **Historial inmutable:** un error se corrige con un movimiento de **reverso**; nada se edita ni se borra, y un
  movimiento solo puede revertirse una vez (restricción única en la base de datos).
- **Doble envío:** cada formulario de dinero lleva un token de un solo uso; pulsar dos veces o recargar la página no
  repite la operación.
- **Permisos verificados en el servidor:** el rol y el estado del empleado se comprueban contra la base de datos en
  cada petición, así una baja o un cambio de rol se aplica al instante aunque tenga la sesión abierta.
- **Auditoría:** cada operación de un empleado queda registrada con quién, qué, cuándo y desde qué IP (sin guardar
  contraseñas).

### Seguridad

Sentencias preparadas en todas las consultas con datos del usuario · contraseñas con `password_hash` (bcrypt) ·
token CSRF en todos los formularios y endpoints · sesión regenerada al iniciar sesión, cookie `HttpOnly` y
`SameSite`, expiración a los 30 minutos de inactividad · bloqueo temporal tras 5 intentos fallidos de login ·
escape de toda salida HTML · endpoints que solo aceptan POST y responden 403 sin sesión o sin permiso · cabeceras de
seguridad, páginas de error propias (403, 404 y 500) y bloqueo de acceso directo a `src/config`, `src/lib`,
`database` y `tests` mediante `.htaccess`.


## Estructura

```
public/               Páginas (vistas) y assets
src/auth/             Login, logout, sesión, roles, CSRF y auditoría
src/operations/       Endpoints (solo POST, con sesión y token CSRF)
src/lib/movimientos.php   Depósitos, retiros, transferencias y reversos (transacciones)
src/config/           Conexión a la BD y límites del negocio (src/config/limites.php)
database/             schema.sql, datos-demo.sql y migraciones para bases anteriores
tests/                Pruebas automáticas
docs/                 Decisiones técnicas
```

## Origen y alcance

Banco Áurea empezó como un proyecto en equipo. La **base** (inicio de sesión de clientes y empleados, registro y consulta
de clientes, consulta de nómina y la primera versión de la interfaz) la desarrolló el equipo original, y es el primer
commit de este repositorio.

Todo lo que aparece después en el historial se agregó sobre esa base:

- Seguridad: endpoints protegidos, roles, CSRF, sesiones seguras y bloqueo por intentos.
- Dinero: depósitos, retiros, transferencias y reversos con transacciones y control de concurrencia.
- Auditoría, gestión de empleados con rol de administrador, listado y búsqueda de clientes.
- Historial paginado, cambio de contraseña del cliente, páginas de error y datos de demostración.
- 327 pruebas automáticas de extremo a extremo y la documentación de decisiones técnicas.

## Límites conocidos

Es un proyecto académico pensado para ejecutarse en local, **no está listo para producción**:

- Funciona por HTTP (sin HTTPS) y por defecto usa el usuario `root` de MySQL sin contraseña.
- La política de contenido (CSP) permite scripts dentro del HTML (`'unsafe-inline'`).
- Usa Bootstrap 5.0.0-alpha1 (una versión preliminar).
- Los clientes pueden cambiar su contraseña pero no recuperarla si la olvidan, ni hacer operaciones por sí mismos: solo consultan.
- El historial está paginado pero no tiene filtros por fecha ni exportación.
- No hay pantalla para cargar la nómina (solo consulta) ni para gestionar sucursales; la sucursal se guarda por nombre.
- No hay comisiones, intereses ni varias monedas.
- Cambiar la contraseña de un empleado no cierra sus sesiones ya abiertas (desactivarlo sí).
- Las páginas de error usan la ruta `/bancoAurea/public/error.php` en el `.htaccess`: si el proyecto está en otra carpeta, hay que ajustarla.
- Las pruebas no cubren la apariencia en el navegador ni la expiración de sesión.
