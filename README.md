# Banco Áurea

Aplicación web bancaria hecha en **PHP y MySQL** como proyecto académico: clientes que consultan su cuenta y
empleados que operan el dinero (depósitos, retiros y transferencias) con control de roles, auditoría y pruebas
automáticas de extremo a extremo.

El foco del proyecto no es el diseño visual sino hacer bien lo que en un sistema con dinero **no puede fallar**:
saldos que siempre cuadran, operaciones que se hacen completas o no se hacen, permisos que se verifican en el
servidor y un historial que nunca se reescribe. Las decisiones y sus razones están en
[docs/decisiones-tecnicas.md](docs/decisiones-tecnicas.md).

<!--
Capturas de pantalla (agregar en docs/capturas/ y quitar este comentario):
![Inicio de sesión](docs/capturas/login.png)
![Panel del administrador](docs/capturas/panel-admin.png)
![Movimientos de una cuenta](docs/capturas/movimientos.png)
![Mi cuenta (cliente)](docs/capturas/mi-cuenta.png)
-->

## Qué puede hacer cada rol

| Rol | Puede |
|---|---|
| **Cliente** | Ver su saldo actualizado y sus últimos 10 movimientos (de las transferencias solo ve los últimos 4 dígitos de la otra cuenta). |
| **Empleado** | Registrar clientes, buscar cuentas, listar clientes con búsqueda y paginación, consultar nómina, hacer depósitos, retiros y transferencias, y ver el historial de una cuenta. |
| **Administrador** | Todo lo del empleado, más: **revertir movimientos** (incluidas transferencias completas), ver la **auditoría** y **gestionar empleados** (crear, cambiar contraseña y rol, activar o desactivar). |

## Probarlo en 5 minutos

Requisitos: [XAMPP](https://www.apachefriends.org/) (Apache + MariaDB/MySQL, PHP 8.1 o superior).

1. Copiar el proyecto en `C:\xampp\htdocs\bancoAurea` y arrancar **Apache** y **MySQL** desde el panel de XAMPP.
2. Crear la base de datos y cargar los datos de demostración:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database\datos-demo.sql
   ```
   Esto crea (o usa) una base llamada `banco`. Los datos de demo son ficticios y solo deben cargarse una vez.
3. Abrir `http://localhost/bancoAurea/public/`.

### Credenciales de demostración

| Rol | Usuario | Contraseña |
|---|---|---|
| Administrador | ID `demo-admin` | `Demo-Admin-2026` |
| Empleado | ID `demo-emp` | `Demo-Emp-2026` |
| Cliente | `lucia.demo@example.com` (también `diego.demo@…` y `sol.demo@…`) | `Demo-Cliente-2026` |

Los empleados entran por **"Soy Empleado"** y los clientes por **"Soy Cliente"**. La demo incluye depósitos, retiros,
dos transferencias entre cuentas y un reverso, con saldos que cuadran con sus movimientos. Los saldos y montos son
ficticios. En **Nómina** se puede consultar el número de empleado `1` o `2`.

Si quieres usar tus propias credenciales de MySQL, copia `src/config/config.example.php` a
`src/config/config.local.php` (ese archivo no se versiona).

## Tecnologías

- **PHP 8.2** sin framework (mysqli con sentencias preparadas), **MariaDB/MySQL** (InnoDB), **Apache**.
- **Bootstrap 5** (versión `5.0.0-alpha1`) y jQuery para la interfaz.
- Sin dependencias externas de PHP: no hace falta Composer.

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
seguridad y bloqueo de acceso directo a `src/config`, `src/lib`, `database` y `tests` mediante `.htaccess`.

## Pruebas automáticas

```
C:\xampp\php\php.exe tests\run-tests.php
```

**271 comprobaciones de extremo a extremo.** El script crea una base temporal `banco_test` desde
`database/schema.sql`, levanta un servidor PHP propio en el puerto 8081, recorre la aplicación con peticiones HTTP
reales y al terminar borra la base y apaga el servidor. **No toca la base de datos real** (aborta si la base
configurada no es `banco_test`). Termina con código 0 si todo pasó.

Cubre: login y bloqueo por intentos, roles y permisos, registro con validaciones, búsquedas, depósitos, retiros y
transferencias (montos inválidos, límites, doble envío), reversos, retiros y transferencias simultáneas, rollback,
gestión de empleados con la sesión abierta, vista del cliente, logout, auditoría y los propios datos de demostración
(incluye que las credenciales documentadas funcionan).

Necesita MySQL encendido. Algunos antivirus bloquean estos archivos (levantan un servidor y terminan procesos):
agregue una excepción para la carpeta `tests/` si le pasa.

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

## Límites conocidos

Es un proyecto académico pensado para ejecutarse en local, **no está listo para producción**:

- Funciona por HTTP (sin HTTPS) y por defecto usa el usuario `root` de MySQL sin contraseña.
- La política de contenido (CSP) permite scripts dentro del HTML (`'unsafe-inline'`).
- Usa Bootstrap 5.0.0-alpha1 (una versión preliminar).
- Los clientes no pueden cambiar ni recuperar su contraseña, ni hacer operaciones por sí mismos: solo consultan.
- El historial muestra solo los últimos 20 movimientos, sin filtros ni exportación.
- No hay pantalla para cargar la nómina (solo consulta) ni para gestionar sucursales; la sucursal se guarda por nombre.
- No hay comisiones, intereses ni varias monedas.
- Cambiar la contraseña de un empleado no cierra sus sesiones ya abiertas (desactivarlo sí).
- Las pruebas no cubren la apariencia en el navegador ni la expiración de sesión.
