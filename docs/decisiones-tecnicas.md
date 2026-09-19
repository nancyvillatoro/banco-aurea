# Decisiones técnicas

Este documento explica **por qué** el proyecto está hecho como está. Cada decisión tiene el problema que resuelve,
la alternativa que descarté y, cuando lo medí, la evidencia. Al final están los compromisos que asumí y lo que haría
distinto con más tiempo.

## 1. Alcance y stack

**PHP sin framework, mysqli con sentencias preparadas, Apache/XAMPP.** Es un proyecto académico y quería que cada
mecanismo de seguridad y de concurrencia estuviera escrito a la vista, en vez de delegado en un framework. El costo es
código más repetitivo (por ejemplo, las funciones `volver()` y `campo()` se repiten en varios endpoints) a cambio de
poder explicar línea por línea qué pasa.

**Estructura en tres capas simples.** `public/` son las páginas, `src/operations/` los endpoints que reciben formularios
y `src/lib/movimientos.php` la lógica del dinero. Las páginas nunca escriben saldos: solo la librería lo hace.

## 2. Dinero

### 2.1 Montos exactos: `DECIMAL` y texto, nunca `float`
Un `float` no puede representar 0.10 exactamente y, sumando, aparecen centavos fantasma. Los montos son
`DECIMAL(10,2)` en la base, se validan con una expresión regular (`^\d{1,8}(\.\d{1,2})?$`), viajan como **texto** y
las comparaciones (`¿alcanza el saldo?`, `¿cabe en el máximo?`) las hace MySQL con decimales exactos, no PHP.
Se rechazan de forma explícita `1e3`, `10,5`, `1.234`, negativos, cero y vacío.

### 2.2 Cada operación es una transacción con bloqueo de fila
Un retiro tiene cuatro pasos: leer el saldo, comprobar que alcanza, actualizarlo y guardar el movimiento. Si dos retiros
llegan a la vez, ambos podrían leer el mismo saldo y dejar la cuenta en negativo. La solución es
`SELECT … FOR UPDATE`: la fila de la cuenta queda bloqueada hasta el `COMMIT`, así que el segundo retiro espera y ve el
saldo ya actualizado. Todo va dentro de `con_transaccion()`, que hace `COMMIT` si la operación devuelve éxito y
`ROLLBACK` si no o si hay una excepción.

**Evidencia:** 8 procesos retirando 100 a la vez de una cuenta con 300 dieron exactamente 3 retiros exitosos, 5
rechazados y saldo final 0.00, nunca negativo. Si además se fuerza un fallo al guardar el movimiento (una clave foránea
inexistente), el saldo vuelve al valor anterior.

### 2.3 El historial no se edita: los errores se corrigen con un reverso
Un banco no borra ni modifica un movimiento: lo compensa con otro. Los movimientos son de solo inserción; un error se
corrige con un movimiento de tipo `reverso` que hace lo contrario y apunta al original (`reversa_de`). Así el historial
completo es auditable y el saldo siempre es la suma de los movimientos.

Reglas que hacen cumplir esto:
- Un movimiento solo se puede revertir **una vez**: `reversa_de` tiene una restricción `UNIQUE` en la base de datos, de
  modo que aunque dos administradores lo intentaran a la vez, el segundo falla.
- No se revierte un reverso ni una apertura.
- Si revertir dejaría un saldo negativo, se rechaza.
- Cada movimiento guarda `saldo_despues`, lo que permite comprobar en cualquier momento que el saldo de la cuenta
  coincide con el último movimiento y con la suma de todos (las pruebas lo verifican).

### 2.4 Transferencias: dos filas, una transacción y bloqueo ordenado
Una transferencia se guarda como **dos movimientos** (uno que resta y otro que suma) con la misma referencia, dentro de
una sola transacción: si el destino no puede recibir, el origen no pierde dinero.

El riesgo específico de las transferencias es el **deadlock**. Si una transferencia A→B bloquea primero A y luego B, y a
la vez otra B→A bloquea primero B y luego A, cada una espera a la otra para siempre y MySQL aborta una. La solución es
bloquear **siempre las dos cuentas en el mismo orden** (por `id`) antes de tocar nada, sin importar el sentido.

**Evidencia:** con el bloqueo ordenado, 80 transferencias cruzadas simultáneas terminaron sin ningún error y el dinero
total se conservó exacto. Al quitar ese bloqueo a propósito, **58 de las 80 fallaron por deadlock**.

Revertir una transferencia deshace **las dos filas a la vez**, y solo el administrador puede hacerlo.

### 2.5 Doble envío: token de operación de un solo uso
Recargar la página tras enviar un depósito, o pulsar el botón dos veces, repetiría el dinero. Cada formulario de
dinero incluye un token aleatorio guardado en la sesión que se **consume** al usarse; un segundo envío con el mismo
token se rechaza con un mensaje claro. (Además, el botón se deshabilita al enviar, pero eso es solo comodidad: la
garantía está en el servidor.)

**Evidencia:** enviar el mismo formulario 3 veces produce un único movimiento.

### 2.6 Límite por operación
Un tope por depósito, retiro o transferencia (`MAX_OPERACION`, en `src/config/limites.php`) evita errores de tecleo con
montos enormes y se cambia en un solo sitio. También se comprueba que el saldo resultante no supere lo que cabe en
`DECIMAL(10,2)`.

## 3. Seguridad

### 3.1 Los permisos se verifican en el servidor, en cada petición
La primera versión solo protegía las páginas: los endpoints (por ejemplo, el que crea cuentas con saldo) respondían a
cualquiera que conociera la URL, y un cliente podía abrir pantallas de empleado porque la sesión de ambos guardaba el
mismo campo. Ahora:
- Todos los endpoints exigen **POST, sesión del rol correcto y token CSRF**; si no, responden 403.
- El rol se guarda como `rol` (empleado o cliente) y, para los empleados, `nivel` (empleado o administrador).
- Para los empleados, el estado y el rol se **comprueban en la base de datos en cada petición** (`empleado_vigente()`).
  El costo es una consulta extra por página; el beneficio es que dar de baja a un empleado o cambiar su rol se aplica
  **al instante**, sin esperar a que cierre sesión. Está probado con la sesión abierta.

### 3.2 Sesión y contraseñas
`password_hash` (bcrypt); `session_regenerate_id` al iniciar sesión (evita fijación de sesión); cookie `HttpOnly` y
`SameSite=Lax`; expiración a los 30 minutos de inactividad; el hash de la contraseña **no se guarda en la sesión**.

### 3.3 Protección contra fuerza bruta
Tras 5 intentos fallidos para un mismo usuario e IP, el login se bloquea 5 minutos, **incluso con la contraseña
correcta**. Los mensajes de error son genéricos: no se distingue entre usuario inexistente, contraseña incorrecta o
cuenta desactivada (el detalle solo queda en la auditoría).

### 3.4 CSRF y XSS
Token CSRF en todos los formularios y endpoints, comparado con `hash_equals`. Toda salida a HTML pasa por `esc()`
(`htmlspecialchars`), y los datos que se insertan desde JavaScript se agregan con `.text()`, no como HTML. Las
pruebas insertan un cliente con `<script>` en el nombre y comprueban que sale escapado.

### 3.5 Menor exposición
`src/config`, `src/lib`, `database` y `tests` tienen un `.htaccess` que impide abrirlos desde el navegador; el hash de
la contraseña de un cliente no viaja a ninguna página (ni siquiera a la sesión); y en una transferencia el cliente solo
ve los **últimos 4 dígitos** de la otra cuenta.

## 4. Auditoría
Cada operación de un empleado (login y login fallido, logout, registro de clientes, consultas, depósitos, retiros,
transferencias, reversos y gestión de empleados) se guarda en la tabla `auditoria` con quién, qué, cuándo y desde qué
IP. Decisiones:
- **Nunca se guardan contraseñas** en el detalle (las pruebas lo comprueban).
- Una falla al auditar no rompe la operación: queda en el log de errores.
- Solo el administrador ve la auditoría.
- No se auditan los inicios de sesión de clientes: la auditoría es de las acciones de empleados.

## 5. Cómo se prueba
Hay un script (`tests/run-tests.php`) que hace **pruebas de extremo a extremo**: crea una base temporal, levanta un
servidor PHP propio y usa peticiones HTTP reales con cookies, como lo haría un navegador. Así se prueba lo que de
verdad importa (sesiones, redirecciones, permisos entre roles y formularios), no solo funciones aisladas.

- **Nunca toca la base real:** aborta si la base configurada no es `banco_test`.
- **Prueba concurrencia de verdad:** lanza procesos PHP en paralelo para retiros y transferencias cruzadas.
- **Comprueba invariantes, no solo mensajes:** el dinero total se conserva, cada saldo coincide con su último
  movimiento y con la suma de movimientos, y una operación fallida no deja filas a medias.
- **Verifica los datos de demostración:** que las credenciales documentadas funcionan y que los saldos cuadran.

**¿Las pruebas detectan errores de verdad?** Para no fiarme de pruebas que siempre pasan, rompí el código a propósito
en 6 ocasiones y comprobé que las pruebas fallaban: quitar el permiso de administrador en un endpoint, desactivar la
verificación CSRF, dejar de comprobar el saldo en los retiros, quitar el bloqueo de cuentas en las transferencias,
confirmar la transacción aunque fallara y hacer que el destino recibiera un monto distinto al que salía. Las 6 se
detectaron (por ejemplo, la falta de atomicidad dejó al origen $20 más pobre sin que llegaran al destino). Esto lo
hice de forma manual, no está automatizado.

## 6. Base de datos y migraciones
- `schema.sql` crea una instalación nueva; `migracion-001…004` actualizan bases anteriores. Las migraciones 002 a 004
  se pueden ejecutar más de una vez sin duplicar nada (la 001 añade un índice y no es repetible), y la 003 y la 004 se
  probaron primero sobre una copia de la base.
- El saldo inicial de un cliente se guarda también como movimiento de **apertura**, y la migración 002 hizo lo mismo con
  los clientes que ya existían (solo inserta filas, no modifica ningún saldo).
- `datos-demo.sql` carga datos ficticios coherentes y se detiene si se ejecuta dos veces, para no duplicar.
- Un problema real que encontré probando: al importar `schema.sql` desde la consola de Windows, la columna
  `contraseña` (con "ñ") se creaba con el nombre corrupto. Se corrigió con `SET NAMES utf8mb4` al inicio del archivo.

## 7. Compromisos y lo que haría distinto
Cosas que decidí dejar fuera por ser un proyecto académico local (están también en el README):
- **Sin HTTPS** y con el usuario `root` de MySQL por defecto. En producción habría un usuario con permisos mínimos y
  HTTPS con cookie `secure`.
- **La política de contenido usa `'unsafe-inline'`** porque hay scripts dentro del HTML; lo correcto sería moverlos a
  archivos.
- **Bootstrap 5.0.0-alpha1**, una versión preliminar; habría que pasar a una estable.
- **Cambiar la contraseña de un empleado no cierra sus sesiones abiertas**; desactivarlo sí.
- **El bloqueo de login puede usarse para molestar a un usuario** (5 intentos fallidos lo bloquean 5 minutos).
- **Las pruebas no cubren la apariencia en navegador ni la expiración de sesión.**
- **El cliente solo consulta**: no cambia su contraseña ni opera. Que un cliente transfiera por sí mismo exigiría
  confirmar la operación, límites diarios y otra capa de seguridad.

Con más tiempo: paginación y filtros en el historial y exportación de estados de cuenta, recuperación de contraseña,
un rol de administrador con verificación en dos pasos, integración continua para ejecutar las pruebas en cada cambio,
y mover la lógica repetida de los endpoints a un solo lugar.
