<?php
// Pruebas automáticas de Banco Áurea.
//
// Uso:  C:\xampp\php\php.exe tests\run-tests.php
//
// Qué hace:
//   1. Crea una base de datos temporal (banco_test) con database/schema.sql
//   2. Levanta un servidor PHP propio en el puerto 8081 apuntando a esa BD
//   3. Recorre la aplicación con peticiones HTTP reales (login, roles, dinero...)
//   4. Borra la BD temporal y apaga el servidor
// NUNCA toca la base de datos real (banco).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

const RAIZ = __DIR__ . '/..';
const BD_PRUEBA = 'banco_test';
const PUERTO = 8081;
const BASE = 'http://localhost:8081/';

// Todo el proceso (y el servidor que lanzamos) usa la BD de prueba
putenv('DB_NAME=' . BD_PRUEBA);
require RAIZ . '/src/config/db.php';
if (DB_NAME !== BD_PRUEBA) {
    exit("ABORTADO: la BD configurada es '" . DB_NAME . "', no '" . BD_PRUEBA . "'. No se ejecuta nada para no tocar datos reales.\n");
}
require RAIZ . '/src/lib/movimientos.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ------------------------------------------------------------------ mini framework
$total = 0;
$fallos = [];

function seccion($titulo) {
    echo "\n== $titulo\n";
}

function comprobar($nombre, $condicion, $detalle = '') {
    global $total, $fallos;
    $total++;
    if ($condicion) {
        echo "  ok    $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  FALLO $nombre" . ($detalle !== '' ? "  -> $detalle" : '') . "\n";
    }
}

function igual($nombre, $esperado, $real) {
    comprobar($nombre, (string)$esperado === (string)$real, "esperado '$esperado', obtenido '$real'");
}

// ------------------------------------------------------------------ acceso a la BD de prueba
function bd() {
    static $cn = null;
    if ($cn === null) {
        $cn = new mysqli(DB_HOST, DB_USER, DB_PASS, BD_PRUEBA);
        $cn->set_charset('utf8mb4');
    }
    return $cn;
}

function fila($sql, $params = []) {
    $stmt = bd()->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $r = $stmt->get_result();
    $f = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    return $f;
}

function valor($sql, $params = []) {
    $f = fila($sql, $params);
    return $f ? array_values($f)[0] : null;
}

function saldo($cuenta) {
    return valor("SELECT saldo FROM registro WHERE numeroCuenta = ?", [$cuenta]);
}

function num_movimientos($cuenta) {
    return (int)valor("SELECT COUNT(*) FROM movimientos m JOIN registro r ON r.id = m.cuenta_id WHERE r.numeroCuenta = ?", [$cuenta]);
}

// ------------------------------------------------------------------ navegador simulado (con cookies)
class Nav {
    private $jar;
    private $csrf = null;

    public function __construct() {
        $this->jar = tempnam(sys_get_temp_dir(), 'aurea_nav_');
    }

    public function __destruct() {
        @unlink($this->jar);
    }

    // Devuelve ['codigo', 'cuerpo', 'ubicacion']
    public function pedir($metodo, $ruta, $datos = []) {
        $ubicacion = '';
        $ch = curl_init(BASE . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$ubicacion) {
                if (stripos($linea, 'Location:') === 0) {
                    $ubicacion = trim(substr($linea, 9));
                }
                return strlen($linea);
            },
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
        }
        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['codigo' => $codigo, 'cuerpo' => (string)$cuerpo, 'ubicacion' => $ubicacion];
    }

    public function get($ruta) {
        return $this->pedir('GET', $ruta);
    }

    // POST agregando automáticamente el token CSRF de la sesión
    public function post($ruta, $datos = []) {
        if (!array_key_exists('csrf_token', $datos)) {
            $datos['csrf_token'] = $this->csrf();
        }
        return $this->pedir('POST', $ruta, $datos);
    }

    // Token CSRF de la sesión actual (se saca de un formulario de la propia página)
    public function csrf($ruta = null) {
        if ($this->csrf === null || $ruta !== null) {
            foreach ($ruta ? [$ruta] : ['public/regisObusc.php', 'public/datos-cliente.php', 'public/login-empleado.php'] as $r) {
                if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $this->get($r)['cuerpo'], $m)) {
                    $this->csrf = $m[1];
                    break;
                }
            }
        }
        return $this->csrf;
    }

    public function loginEmpleado($id, $clave) {
        $this->csrf = null;
        $t = $this->csrf('public/login-empleado.php');
        $r = $this->pedir('POST', 'src/auth/login-proceso.php', ['csrf_token' => $t, 'ID' => $id, 'password' => $clave]);
        $this->csrf = null;
        return $r;
    }

    public function loginCliente($correo, $clave) {
        $this->csrf = null;
        $t = $this->csrf('public/login-cliente.php');
        $r = $this->pedir('POST', 'src/auth/login-proceso.php', ['csrf_token' => $t, 'email' => $correo, 'password' => $clave]);
        $this->csrf = null;
        return $r;
    }

    public function logout() {
        $r = $this->post('src/auth/logout.php');
        $this->csrf = null;
        return $r;
    }
}

// Texto del primer aviso (alert) de una página
function aviso($html) {
    if (preg_match('/class="alert alert-(?:success|danger|warning)[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m)) {
        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    return '';
}

function tarjetas($html) {
    return substr_count($html, 'col-md-6 col-lg-4');
}

// ------------------------------------------------------------------ preparación
function preparar() {
    echo "Preparando BD temporal '" . BD_PRUEBA . "' y servidor de prueba...\n";

    $cn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $cn->query("DROP DATABASE IF EXISTS " . BD_PRUEBA);
    $sql = file_get_contents(RAIZ . '/database/schema.sql');
    $sql = preg_replace('/\bbanco\b/', BD_PRUEBA, $sql);
    $cn->multi_query($sql);
    do {
        if ($r = $cn->store_result()) {
            $r->free();
        }
    } while ($cn->more_results() && $cn->next_result());
    $cn->close();

    // Administrador inicial
    $hash = password_hash('Admin-Clave-1', PASSWORD_DEFAULT);
    $stmt = bd()->prepare("INSERT INTO inicioe (id_e, contraseña, nombre, rol) VALUES ('adm_test', ?, 'Ana Admin', 'administrador')");
    $stmt->bind_param('s', $hash);
    $stmt->execute();

    // Limpia bloqueos de login que hayan quedado de otras ejecuciones
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aurea_login_*') as $f) {
        @unlink($f);
    }
}

function arrancar_servidor() {
    $proceso = proc_open(
        [PHP_BINARY, '-S', 'localhost:' . PUERTO, '-t', realpath(RAIZ)],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/aurea_server.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/aurea_server.log', 'a']],
        $pipes
    );
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $ch = curl_init(BASE . 'public/index.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codigo === 200) {
            return $proceso;
        }
    }
    exit("No se pudo iniciar el servidor de prueba en el puerto " . PUERTO . " (¿está ocupado?).\n");
}

function limpiar($proceso) {
    if ($proceso) {
        $estado = proc_get_status($proceso);
        proc_terminate($proceso);
        if (!empty($estado['pid']) && stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /F /T /PID ' . (int)$estado['pid'] . ' 2>NUL');
        }
        @proc_close($proceso);
    }
    $cn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $cn->query("DROP DATABASE IF EXISTS " . BD_PRUEBA);
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aurea_login_*') as $f) {
        @unlink($f);
    }
    @unlink(sys_get_temp_dir() . '/aurea_server.log');
}

preparar();
$servidor = arrancar_servidor();
register_shutdown_function('limpiar', $servidor);

$anon = new Nav();     // sin sesión
$admin = new Nav();    // administrador
$emp = new Nav();      // empleado normal
$cli = new Nav();      // cliente

// ================================================================== 1. SIN SESIÓN
seccion('1. Sin sesion: nada protegido es accesible');
igual('pagina de inicio', 200, $anon->get('public/index.php')['codigo']);
foreach (['regisObusc', 'registro-cliente-vista', 'buscar-cliente-vista', 'sueldos-vista', 'clientes-vista', 'movimientos-vista', 'auditoria-vista', 'empleados-vista'] as $v) {
    igual("vista $v redirige", 302, $anon->get("public/$v.php")['codigo']);
}
igual('datos-cliente redirige', 302, $anon->get('public/datos-cliente.php')['codigo']);
foreach (['buscar-cliente', 'calcular-sueldo', 'guardar-cliente', 'registrar-movimiento', 'registrar-transferencia', 'reversar-movimiento', 'gestionar-empleado'] as $e) {
    igual("endpoint $e -> 403", 403, $anon->pedir('POST', "src/operations/$e.php", ['x' => 1])['codigo']);
}
igual('endpoint por GET -> 405', 405, $anon->get('src/operations/buscar-cliente.php')['codigo']);
igual('sucursales sin sesion -> 403', 403, $anon->get('src/operations/obtener-sucursales.php')['codigo']);

// ================================================================== 2. LOGIN
seccion('2. Login de empleado');
$r = $anon->loginEmpleado('adm_test', 'mala');
comprobar('clave incorrecta vuelve al login con error', strpos($r['ubicacion'], 'login-empleado.php?error=1') !== false);
$r = $anon->pedir('POST', 'src/auth/login-proceso.php', ['ID' => 'adm_test', 'password' => 'Admin-Clave-1']);
comprobar('sin token CSRF se rechaza', strpos($r['ubicacion'], 'index.php?error=1') !== false);
comprobar('mensaje de error visible', strpos($anon->get('public/login-empleado.php?error=1')['cuerpo'], 'incorrectos') !== false);
$r = $admin->loginEmpleado('adm_test', 'Admin-Clave-1');
comprobar('clave correcta entra al panel', strpos($r['ubicacion'], 'regisObusc.php') !== false);

seccion('2b. Bloqueo por intentos');
$b = new Nav();
$ultimo = '';
for ($i = 1; $i <= 6; $i++) {
    $ultimo = $b->loginEmpleado('intruso', 'x')['ubicacion'];
}
comprobar('al 6o intento queda bloqueado', strpos($ultimo, 'error=bloqueado') !== false);
$b2 = new Nav();
$r = $b2->loginEmpleado('intruso', 'cualquiera');
comprobar('sigue bloqueado con otra sesion', strpos($r['ubicacion'], 'error=bloqueado') !== false);

// ================================================================== 3. GESTIÓN DE EMPLEADOS
seccion('3. Administrador: gestion de empleados');
$panel = $admin->get('public/regisObusc.php')['cuerpo'];
igual('admin ve 7 tarjetas', 7, tarjetas($panel));
comprobar('panel muestra el rol', strpos($panel, 'Rol: Administrador') !== false);
comprobar('panel saluda por nombre', strpos($panel, 'Ana Admin') !== false);
igual('admin abre auditoria', 200, $admin->get('public/auditoria-vista.php')['codigo']);
igual('admin abre empleados', 200, $admin->get('public/empleados-vista.php')['codigo']);

function gest($nav, $datos) {
    $nav->post('src/operations/gestionar-empleado.php', $datos);
    return aviso($nav->get('public/empleados-vista.php')['cuerpo']);
}
$ok = ['accion' => 'crear', 'id_e' => 'pedro', 'nombre' => 'Pedro Perez', 'password' => 'Clave-1234', 'rol' => 'empleado'];
comprobar('ID con espacios rechazado', strpos(gest($admin, ['id_e' => 'pe dro'] + $ok), 'ID debe') !== false);
comprobar('ID muy corto rechazado', strpos(gest($admin, ['id_e' => 'ab'] + $ok), 'ID debe') !== false);
comprobar('nombre corto rechazado', strpos(gest($admin, ['nombre' => 'P'] + $ok), 'nombre debe') !== false);
comprobar('clave corta rechazada', strpos(gest($admin, ['password' => '123'] + $ok), 'contrase') !== false);
comprobar('rol inventado rechazado', strpos(gest($admin, ['rol' => 'superjefe'] + $ok), 'rol no es') !== false);
comprobar('crear empleado valido', strpos(gest($admin, $ok), 'creado') !== false);
comprobar('ID repetido (sin distinguir mayusculas)', strpos(gest($admin, ['id_e' => 'PEDRO'] + $ok), 'Ya existe') !== false);
igual('la clave se guarda como hash', '$2y$', substr(valor("SELECT contraseña FROM inicioe WHERE id_e='pedro'"), 0, 4));
gest($admin, ['accion' => 'crear', 'id_e' => 'xss_t', 'nombre' => '<b>Negrita</b>', 'password' => 'Clave-1234', 'rol' => 'empleado']);
$lista = $admin->get('public/empleados-vista.php')['cuerpo'];
comprobar('nombre con HTML sale escapado', strpos($lista, '&lt;b&gt;Negrita') !== false && strpos($lista, '<b>Negrita') === false);

seccion('3b. Restricciones sobre uno mismo');
comprobar('no puede desactivarse', strpos(gest($admin, ['accion' => 'estado', 'id_e' => 'adm_test', 'valor' => 'desactivar']), 'a s') !== false);
comprobar('tampoco con otras mayusculas', strpos(gest($admin, ['accion' => 'estado', 'id_e' => 'ADM_TEST', 'valor' => 'desactivar']), 'a s') !== false);
comprobar('no puede quitarse su rol', strpos(gest($admin, ['accion' => 'rol', 'id_e' => 'adm_test', 'rol' => 'empleado']), 'propio rol') !== false);
igual('sigue siendo administrador activo', 'administrador/1', valor("SELECT CONCAT(rol,'/',activo) FROM inicioe WHERE id_e='adm_test'"));
comprobar('accion invalida rechazada', strpos(gest($admin, ['accion' => 'inventada', 'id_e' => 'pedro']), 'no v') !== false);
comprobar('empleado inexistente', strpos(gest($admin, ['accion' => 'clave', 'id_e' => 'fantasma', 'password' => 'Clave-1234']), 'no existe') !== false);

// ================================================================== 4. EMPLEADO NORMAL
seccion('4. Empleado normal: sin poderes de administrador');
$emp->loginEmpleado('pedro', 'Clave-1234');
$panel = $emp->get('public/regisObusc.php')['cuerpo'];
igual('empleado ve 5 tarjetas', 5, tarjetas($panel));
comprobar('no ve auditoria ni empleados', strpos($panel, 'auditoria-vista') === false && strpos($panel, 'empleados-vista') === false);
igual('auditoria-vista redirige al panel', 302, $emp->get('public/auditoria-vista.php')['codigo']);
comprobar('redirige con aviso de permiso', strpos(aviso($emp->get('public/regisObusc.php')['cuerpo']), 'permiso') !== false);
igual('empleados-vista redirige', 302, $emp->get('public/empleados-vista.php')['codigo']);
$r = $emp->post('src/operations/gestionar-empleado.php', ['accion' => 'crear', 'id_e' => 'hack', 'nombre' => 'Hacker', 'password' => 'Clave-1234', 'rol' => 'administrador']);
igual('POST gestionar-empleado -> 403', 403, $r['codigo']);
igual('el empleado "hack" no se creo', 0, valor("SELECT COUNT(*) FROM inicioe WHERE id_e='hack'"));
igual('POST reversar-movimiento -> 403', 403, $emp->post('src/operations/reversar-movimiento.php', ['movimiento_id' => 1, 'motivo' => 'intento', 'op_token' => 'x'])['codigo']);
igual('si puede abrir clientes', 200, $emp->get('public/clientes-vista.php')['codigo']);
igual('si puede abrir movimientos', 200, $emp->get('public/movimientos-vista.php')['codigo']);

// ================================================================== 5. REGISTRO DE CLIENTES
seccion('5. Registro de clientes');
function registrar($nav, $extra = []) {
    $base = ['full_name' => 'Ana Uno', 'email' => 'ana@test.com', 'password' => 'Cliente-123', 'fechaNac' => '1990-01-15',
             'numeroCuenta' => '1111111111', 'tipo_cuenta' => 'Ahorro', 'saldo' => '1000', 'sucursal' => 'Matriz'];
    $nav->post('src/operations/guardar-cliente.php', $extra + $base);
    return aviso($nav->get('public/registro-cliente-vista.php')['cuerpo']);
}
comprobar('sin token CSRF -> 403', $emp->pedir('POST', 'src/operations/guardar-cliente.php', ['full_name' => 'x'])['codigo'] === 403);
comprobar('nombre corto', strpos(registrar($emp, ['full_name' => 'A']), 'nombre') !== false);
comprobar('correo invalido', strpos(registrar($emp, ['email' => 'nope']), 'correo') !== false);
comprobar('clave corta', strpos(registrar($emp, ['password' => '123']), 'contrase') !== false);
comprobar('menor de edad', strpos(registrar($emp, ['fechaNac' => '2015-05-01']), '18') !== false);
comprobar('fecha futura', strpos(registrar($emp, ['fechaNac' => '2999-01-01']), '18') !== false);
comprobar('cuenta de 9 digitos', strpos(registrar($emp, ['numeroCuenta' => '123456789']), '10 d') !== false);
comprobar('tipo de cuenta invalido', strpos(registrar($emp, ['tipo_cuenta' => 'VIP']), 'tipo') !== false);
comprobar('saldo negativo', strpos(registrar($emp, ['saldo' => '-5']), 'saldo') !== false);
comprobar('sucursal inexistente', strpos(registrar($emp, ['sucursal' => 'Narnia']), 'sucursal') !== false);
igual('nada se guardo con datos invalidos', 0, valor("SELECT COUNT(*) FROM registro"));
comprobar('registro valido', strpos(registrar($emp), 'registrado correctamente') !== false);
comprobar('correo/cuenta duplicados', strpos(registrar($emp, ['full_name' => 'Otro Nombre']), 'ya est') !== false);
igual('saldo inicial', '1000.00', saldo('1111111111'));
igual('la apertura queda como movimiento', 1, num_movimientos('1111111111'));
registrar($emp, ['full_name' => 'Beto Dos', 'email' => 'beto@test.com', 'numeroCuenta' => '2222222222', 'saldo' => '0']);
igual('saldo 0 no crea movimiento de apertura', 0, num_movimientos('2222222222'));
igual('la clave del cliente es un hash', '$2y$', substr(valor("SELECT contraseña FROM registro WHERE correo='ana@test.com'"), 0, 4));

// ================================================================== 6. BÚSQUEDAS
seccion('6. Buscar cliente por cuenta');
$r = $emp->post('src/operations/buscar-cliente.php', ['numeroCuenta' => '1111111111']);
$j = json_decode($r['cuerpo'], true);
comprobar('cuenta existente', ($j['status'] ?? '') === 'success' && $j['cliente']['nombre'] === 'Ana Uno');
$j = json_decode($emp->post('src/operations/buscar-cliente.php', ['numeroCuenta' => '0000000000'])['cuerpo'], true);
comprobar('cuenta inexistente', ($j['status'] ?? '') === 'error');
$j = json_decode($emp->post('src/operations/buscar-cliente.php', ['numeroCuenta' => '1111111111', 'csrf_token' => 'falso'])['cuerpo'], true);
comprobar('token CSRF falso rechazado', ($j['status'] ?? '') === 'error');

seccion('6b. Listado de clientes con busqueda y paginacion');
$hashDummy = password_hash('x', PASSWORD_DEFAULT);
$ins = bd()->prepare("INSERT INTO registro (nombre, correo, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES (?, ?, ?, ?, 'Ahorro', 1, CURDATE(), 'Matriz')");
foreach (array_merge(range(1, 23), ['x']) as $n) {
    $nombre = $n === 'x' ? '<script>alert(1)</script>' : sprintf('Cliente %02d', $n);
    $correo = $n === 'x' ? 'xss@test.com' : sprintf('c%02d@test.com', $n);
    $cta = $n === 'x' ? '9999999991' : sprintf('10000000%02d', $n);
    $ins->bind_param('ssss', $nombre, $correo, $hashDummy, $cta);
    $ins->execute();
}
$ins2 = bd()->prepare("INSERT INTO registro (nombre, correo, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES ('100% Real', 'pct@test.com', ?, '9999999992', 'Ahorro', 1, CURDATE(), 'Matriz')");
$ins2->bind_param('s', $hashDummy);
$ins2->execute();
function total_lista($nav, $q = '', $p = 1) {
    $h = $nav->get('public/clientes-vista.php?' . http_build_query(['q' => $q, 'p' => $p]))['cuerpo'];
    return [preg_match('/(\d+) cliente\(s\)/', $h, $m) ? (int)$m[1] : -1, substr_count($h, '<td>' ) / 7, $h];
}
[$t, $f] = total_lista($emp);
igual('total de clientes', 27, $t);
igual('10 filas en la pagina 1', 10, $f);
igual('7 filas en la pagina 3', 7, total_lista($emp, '', 3)[1]);
comprobar('pagina fuera de rango cae en la ultima', strpos(total_lista($emp, '', 999)[2], 'de 3') !== false);
igual('buscar por nombre', 1, total_lista($emp, 'Cliente 07')[0]);
igual('buscar por correo', 1, total_lista($emp, 'c15@')[0]);
igual('buscar "%" como texto', 1, total_lista($emp, '%')[0]);
igual('buscar "_" como texto', 0, total_lista($emp, '_')[0]);
igual('inyeccion SQL no devuelve todo', 0, total_lista($emp, "' OR '1'='1")[0]);
$h = total_lista($emp, 'script')[2];
comprobar('HTML del nombre sale escapado', strpos($h, '&lt;script&gt;') !== false && strpos($h, '<script>alert') === false);
igual('q como arreglo no rompe la pagina', 200, $emp->get('public/clientes-vista.php?q[]=x')['codigo']);

// ================================================================== 7. DINERO
seccion('7. Depositos y retiros');
function mover($nav, $cuenta, $tipo, $monto, $motivo = '', $token = null) {
    if ($token === null) {
        preg_match('/name="op_token" value="([0-9a-f]+)"/', $nav->get("public/movimientos-vista.php?cuenta=$cuenta")['cuerpo'], $m);
        $token = $m[1] ?? '';
    }
    $nav->post('src/operations/registrar-movimiento.php', ['op_token' => $token, 'cuenta' => $cuenta, 'tipo' => $tipo, 'monto' => $monto, 'motivo' => $motivo]);
    return aviso($nav->get("public/movimientos-vista.php?cuenta=$cuenta")['cuerpo']);
}
$c = '1111111111';
mover($emp, $c, 'deposito', '250.50', 'pago de prueba');
igual('deposito de 250.50', '1250.50', saldo($c));
mover($emp, $c, 'retiro', '100');
igual('retiro de 100', '1150.50', saldo($c));
mover($emp, $c, 'deposito', '10.5');
igual('deposito con un decimal', '1161.00', saldo($c));

$antes = num_movimientos($c);
$rechazos = [
    ['retiro', '5000', 'mas que el saldo'], ['retiro', '1161.01', 'un centavo de mas'], ['deposito', '50000.01', 'sobre el limite'],
    ['deposito', '-5', 'negativo'], ['deposito', '0', 'cero'], ['deposito', 'abc', 'texto'], ['deposito', '1.234', '3 decimales'],
    ['deposito', '1e3', 'notacion cientifica'], ['deposito', '10,5', 'coma decimal'], ['deposito', '', 'vacio'], ['deposito', '99999999999', 'enorme'],
];
foreach ($rechazos as [$tp, $monto, $desc]) {
    $msg = mover($emp, $c, $tp, $monto);
    comprobar("rechazado: $desc", $msg !== '' && strpos($msg, 'realizado') === false, $msg);
}
igual('el saldo no cambio con los rechazos', '1161.00', saldo($c));
igual('no se guardo ningun movimiento de mas', $antes, num_movimientos($c));
mover($emp, $c, 'retiro', '50000');
igual('un retiro sin saldo suficiente aunque este en el limite', '1161.00', saldo($c));
mover($emp, $c, 'retiro', '1161.00');
igual('se puede retirar exactamente todo el saldo', '0.00', saldo($c));
mover($emp, $c, 'deposito', '50000');
igual('deposito exactamente en el limite', '50000.00', saldo($c));
mover($emp, $c, 'retiro', '50000');
mover($emp, $c, 'deposito', '1000');

bd()->query("UPDATE registro SET saldo = 99999990.00 WHERE numeroCuenta = '2222222222'");
comprobar('no se pasa del saldo maximo', strpos(mover($emp, '2222222222', 'deposito', '20'), 'superar') !== false);
igual('saldo intacto tras el desbordamiento', '99999990.00', saldo('2222222222'));
bd()->query("UPDATE registro SET saldo = 0 WHERE numeroCuenta = '2222222222'");

seccion('7b. Doble envio y tokens');
preg_match('/name="op_token" value="([0-9a-f]+)"/', $emp->get("public/movimientos-vista.php?cuenta=$c")['cuerpo'], $m);
$tok = $m[1];
$antes = num_movimientos($c);
$s0 = saldo($c);
foreach ([1, 2, 3] as $i) {
    $msg_repetido = mover($emp, $c, 'deposito', '5', '', $tok);
}
igual('3 envios del mismo formulario = 1 deposito', $antes + 1, num_movimientos($c));
igual('saldo sumo solo una vez', number_format($s0 + 5, 2, '.', ''), saldo($c));
comprobar('mensaje de operacion repetida', strpos($msg_repetido, 'ya se proces') !== false, $msg_repetido);
$msg = mover($emp, $c, 'deposito', '5', '', '00000000000000000000000000000000');
comprobar('token inventado rechazado', strpos($msg, 'ya se proces') !== false);
$msg = mover($emp, $c, 'deposito', '5', '', '');
comprobar('sin token rechazado', strpos($msg, 'ya se proces') !== false);

// ================================================================== 8. REVERSOS
seccion('8. Reversos');
function reversar_web($nav, $cuenta, $id, $motivo) {
    preg_match_all('/name="op_token" value="([0-9a-f]+)"/', $nav->get("public/movimientos-vista.php?cuenta=$cuenta")['cuerpo'], $m);
    $nav->post('src/operations/reversar-movimiento.php', ['op_token' => end($m[1]) ?: '', 'cuenta' => $cuenta, 'movimiento_id' => $id, 'motivo' => $motivo]);
    return aviso($nav->get("public/movimientos-vista.php?cuenta=$cuenta")['cuerpo']);
}
$idDep = (int)valor("SELECT id FROM movimientos WHERE tipo='deposito' AND monto=250.50 LIMIT 1");
$idRet = (int)valor("SELECT id FROM movimientos WHERE tipo='retiro' AND monto=100.00 LIMIT 1");
$idApe = (int)valor("SELECT id FROM movimientos WHERE tipo='apertura' LIMIT 1");
$vista_admin = $admin->get("public/movimientos-vista.php?cuenta=$c")['cuerpo'];
$vista_emp = $emp->get("public/movimientos-vista.php?cuenta=$c")['cuerpo'];
comprobar('el administrador ve botones "Reversar"', strpos($vista_admin, '>Reversar</button>') !== false);
comprobar('el empleado NO ve botones "Reversar"', strpos($vista_emp, 'Reversar') === false);
$s0 = saldo($c);
comprobar('revertir un deposito', strpos(reversar_web($admin, $c, $idDep, 'error de captura'), 'revertido') !== false);
igual('el saldo baja lo depositado', number_format($s0 - 250.50, 2, '.', ''), saldo($c));
comprobar('no se revierte dos veces', strpos(reversar_web($admin, $c, $idDep, 'otra vez lo mismo'), 'ya fue revertido') !== false);
$idRev = (int)valor("SELECT id FROM movimientos WHERE tipo='reverso' LIMIT 1");
comprobar('no se revierte un reverso', strpos(reversar_web($admin, $c, $idRev, 'revertir el reverso'), 'no se puede') !== false);
comprobar('no se revierte una apertura', strpos(reversar_web($admin, $c, $idApe, 'revertir apertura'), 'no se puede') !== false);
comprobar('motivo demasiado corto', strpos(reversar_web($admin, $c, $idRet, 'ab'), 'motivo') !== false);
comprobar('movimiento inexistente', strpos(reversar_web($admin, $c, 99999, 'no existe el mov'), 'no existe') !== false);
$s0 = saldo($c);
comprobar('revertir un retiro devuelve el dinero', strpos(reversar_web($admin, $c, $idRet, 'retiro equivocado'), 'revertido') !== false);
igual('el saldo sube lo retirado', number_format($s0 + 100, 2, '.', ''), saldo($c));
igual('el movimiento original no se modifico', '250.50/1', valor("SELECT CONCAT(monto,'/',es_credito) FROM movimientos WHERE id=?", [$idDep]));
bd()->query("UPDATE registro SET saldo = 10 WHERE numeroCuenta = '2222222222'");
bd()->query("INSERT INTO movimientos (cuenta_id, tipo, monto, es_credito, saldo_despues, empleado_id) SELECT id, 'deposito', 500, 1, 510, 'prueba' FROM registro WHERE numeroCuenta='2222222222'");
$idGrande = (int)bd()->insert_id;
comprobar('no se revierte si el saldo ya no alcanza', strpos(reversar_web($admin, '2222222222', $idGrande, 'saldo insuficiente'), 'insuficiente') !== false);
igual('saldo intacto', '10.00', saldo('2222222222'));

seccion('8b. Integridad de saldos');
$f = fila("SELECT r.saldo AS saldo, SUM(IF(m.es_credito, m.monto, -m.monto)) AS suma,
                  (SELECT saldo_despues FROM movimientos WHERE cuenta_id = r.id ORDER BY id DESC LIMIT 1) AS ultimo
           FROM registro r JOIN movimientos m ON m.cuenta_id = r.id WHERE r.numeroCuenta = ? GROUP BY r.id", [$c]);
igual('saldo = suma de movimientos', $f['saldo'], $f['suma']);
igual('saldo = ultimo saldo registrado', $f['saldo'], $f['ultimo']);


// ================================================================== 8c. TRANSFERENCIAS
seccion('8c. Transferencias entre cuentas');
function transferir_web($nav, $origen, $destino, $monto, $motivo = '', $token = null, $pagina = null) {
    if ($token === null) {
        preg_match_all('/name="op_token" value="([0-9a-f]+)"/', $nav->get("public/movimientos-vista.php?cuenta=" . ($pagina ?? $origen))['cuerpo'], $m);
        $token = $m[1][1] ?? ''; // el 1o es el de deposito/retiro, el 2o el de transferencias
    }
    $nav->post('src/operations/registrar-transferencia.php', ['op_token' => $token, 'cuenta_origen' => $origen, 'cuenta_destino' => $destino, 'monto' => $monto, 'motivo' => $motivo]);
    return aviso($nav->get("public/movimientos-vista.php?cuenta=$origen")['cuerpo']);
}
$hCli = password_hash('Cliente-123', PASSWORD_DEFAULT);
$insT = bd()->prepare("INSERT INTO registro (nombre, correo, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES (?, ?, ?, ?, 'Ahorro', ?, CURDATE(), 'Matriz')");
foreach ([['Dora Cuatro', 'dora@test.com', '4444444444', '1000'], ['Elio Cinco', 'elio@test.com', '5555555555', '500']] as [$n, $co, $ct, $sl]) {
    $insT->bind_param('sssss', $n, $co, $hCli, $ct, $sl);
    $insT->execute();
}
$D = '4444444444';
$E = '5555555555';
function total_DE() {
    return valor("SELECT SUM(saldo) FROM registro WHERE numeroCuenta IN ('4444444444', '5555555555')");
}
igual('dinero total al empezar', '1500.00', total_DE());

$msg = transferir_web($emp, $D, $E, '200', 'pago de renta');
comprobar('transferencia valida', strpos($msg, 'realizada') !== false, $msg);
igual('sale de la cuenta de origen', '800.00', saldo($D));
igual('entra a la cuenta de destino', '700.00', saldo($E));
igual('el dinero total se conserva', '1500.00', total_DE());
igual('se guardan 2 movimientos con la misma referencia', '2/1', valor("SELECT CONCAT(COUNT(*), '/', COUNT(DISTINCT transferencia_ref)) FROM movimientos WHERE tipo='transferencia'"));
igual('una fila resta y la otra suma', '0,1', valor("SELECT GROUP_CONCAT(es_credito ORDER BY id) FROM movimientos WHERE tipo='transferencia'"));
igual('la fila de salida apunta a la cuenta de destino', $E, valor("SELECT r.numeroCuenta FROM movimientos m JOIN registro r ON r.id = m.contraparte_id WHERE m.tipo='transferencia' AND m.es_credito = 0"));
comprobar('el historial de origen dice "A cuenta"', strpos($emp->get("public/movimientos-vista.php?cuenta=$D")['cuerpo'], 'A cuenta 5555555555') !== false);
comprobar('el historial de destino dice "De cuenta"', strpos($emp->get("public/movimientos-vista.php?cuenta=$E")['cuerpo'], 'De cuenta 4444444444') !== false);

$antes = (int)valor("SELECT COUNT(*) FROM movimientos");
$casos = [
    [$D, $D, '10', 'distintas', 'misma cuenta de origen y destino'],
    [$D, '9090909090', '10', 'no existe', 'destino inexistente'],
    ['9090909090', $E, '10', 'no existe', 'origen inexistente'],
    [$D, 'abc', '10', '10 d', 'destino con formato invalido'],
    [$D, $E, '5000', 'insuficiente', 'mas que el saldo'],
    [$D, $E, '800.01', 'insuficiente', 'un centavo de mas'],
    [$D, $E, '50000.01', 'ximo', 'sobre el limite'],
    [$D, $E, '-5', 'monto debe', 'monto negativo'],
    [$D, $E, '0', 'monto debe', 'monto cero'],
    [$D, $E, 'abc', 'monto debe', 'monto texto'],
    [$D, $E, '1.234', 'monto debe', 'tres decimales'],
];
foreach ($casos as [$o, $d, $m, $esperado, $desc]) {
    // si el origen no existe, el token se saca de la pagina de una cuenta valida
    $msg = transferir_web($emp, $o, $d, $m, '', null, $o === '9090909090' ? $D : null);
    comprobar("rechazada: $desc", strpos($msg, $esperado) !== false, $msg);
}
igual('los saldos no cambiaron con los rechazos', '800.00/700.00', saldo($D) . '/' . saldo($E));
igual('no se guardo ningun movimiento', $antes, (int)valor("SELECT COUNT(*) FROM movimientos"));

preg_match_all('/name="op_token" value="([0-9a-f]+)"/', $emp->get("public/movimientos-vista.php?cuenta=$D")['cuerpo'], $m);
$tokT = $m[1][1];
$antes = (int)valor("SELECT COUNT(*) FROM movimientos");
foreach ([1, 2, 3] as $i) {
    $msg_rep = transferir_web($emp, $D, $E, '10', '', $tokT);
}
igual('3 envios del mismo formulario = 1 transferencia (2 filas)', $antes + 2, (int)valor("SELECT COUNT(*) FROM movimientos"));
comprobar('mensaje de operacion repetida', strpos($msg_rep, 'ya se proces') !== false, $msg_rep);
igual('saldos tras la transferencia de 10', '790.00/710.00', saldo($D) . '/' . saldo($E));

bd()->query("UPDATE registro SET saldo = 99999990.00 WHERE numeroCuenta = '$E'");
$antes = (int)valor("SELECT COUNT(*) FROM movimientos");
$msg = transferir_web($emp, $D, $E, '20');
comprobar('si el destino no puede recibir, se rechaza', strpos($msg, 'destino') !== false, $msg);
igual('atomicidad: el origen NO perdio dinero', '790.00', saldo($D));
igual('atomicidad: el destino no cambio', '99999990.00', saldo($E));
igual('atomicidad: no quedo ninguna fila a medias', $antes, (int)valor("SELECT COUNT(*) FROM movimientos"));
bd()->query("UPDATE registro SET saldo = 710.00 WHERE numeroCuenta = '$E'");

$dora = new Nav();
$dora->loginCliente('dora@test.com', 'Cliente-123');
$h = $dora->get('public/datos-cliente.php')['cuerpo'];
comprobar('el cliente ve la transferencia', strpos($h, 'Transferencia') !== false);
comprobar('ve la otra cuenta enmascarada (******5555)', strpos($h, '******5555') !== false);
comprobar('NO ve el numero completo de la otra cuenta', strpos($h, '5555555555') === false);

seccion('8d. Reverso de transferencias');
$idT = (int)valor("SELECT MIN(id) FROM movimientos WHERE tipo='transferencia'");
$refT = valor("SELECT transferencia_ref FROM movimientos WHERE id = ?", [$idT]);
$idT2 = (int)valor("SELECT id FROM movimientos WHERE transferencia_ref = ? AND id <> ?", [$refT, $idT]);
$vista = $admin->get("public/movimientos-vista.php?cuenta=$D")['cuerpo'];
comprobar('el administrador ve el boton para revertir la transferencia', strpos($vista, 'la transferencia completa') !== false);
igual('un empleado normal no puede revertirla (403)', 403, $emp->post('src/operations/reversar-movimiento.php', ['movimiento_id' => $idT, 'motivo' => 'intento de empleado', 'op_token' => 'x'])['codigo']);
$msg = reversar_web($admin, $D, $idT, 'transferencia por error');
comprobar('el administrador revierte la transferencia', strpos($msg, 'revertida') !== false, $msg);
igual('vuelve el dinero a cada cuenta', '990.00/510.00', saldo($D) . '/' . saldo($E));
igual('el dinero total se conserva tras el reverso', '1500.00', total_DE());
igual('se crean 2 reversos ligados a la transferencia original', 2, (int)valor("SELECT COUNT(*) FROM movimientos WHERE tipo='reverso' AND transferencia_ref IS NOT NULL"));
comprobar('no se revierte dos veces (por la misma fila)', strpos(reversar_web($admin, $D, $idT, 'otra vez lo mismo'), 'ya fue revertida') !== false);
comprobar('ni por la otra fila de la transferencia', strpos(reversar_web($admin, $E, $idT2, 'por la otra fila'), 'ya fue revertida') !== false);
igual('sigue todo igual tras los intentos repetidos', '990.00/510.00', saldo($D) . '/' . saldo($E));

transferir_web($emp, $D, $E, '300');
igual('nueva transferencia de 300', '690.00/810.00', saldo($D) . '/' . saldo($E));
mover($emp, $E, 'retiro', '810');
igual('el destino gasta todo su saldo', '0.00', saldo($E));
$idT3 = (int)valor("SELECT MAX(id) FROM movimientos WHERE tipo='transferencia' AND es_credito = 0");
$reversos_antes = (int)valor("SELECT COUNT(*) FROM movimientos WHERE tipo='reverso'");
$msg = reversar_web($admin, $D, $idT3, 'el destino ya no tiene fondos');
comprobar('no se puede revertir si el destino ya no tiene el dinero', strpos($msg, 'insuficiente') !== false, $msg);
igual('reverso fallido: ninguna cuenta cambio', '690.00/0.00', saldo($D) . '/' . saldo($E));
igual('reverso fallido: no quedaron reversos a medias', $reversos_antes, (int)valor("SELECT COUNT(*) FROM movimientos WHERE tipo='reverso'"));

seccion('8e. Transferencias cruzadas simultaneas');
bd()->query("UPDATE registro SET saldo = 1000 WHERE numeroCuenta = '$D'");
bd()->query("UPDATE registro SET saldo = 500 WHERE numeroCuenta = '$E'");
$idD = (int)valor("SELECT id FROM registro WHERE numeroCuenta = ?", [$D]);
$idE = (int)valor("SELECT id FROM registro WHERE numeroCuenta = ?", [$E]);
$procs = [];
$tuberias = [];
for ($i = 0; $i < 8; $i++) {
    [$o, $d] = $i % 2 === 0 ? [$idD, $idE] : [$idE, $idD]; // la mitad D->E y la otra mitad E->D
    $procs[] = proc_open([PHP_BINARY, __DIR__ . '/transferencia-paralela.php', (string)$o, (string)$d, '10'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $tuberias[] = $pipes;
}
$oks = $rech = $errs = 0;
foreach ($procs as $i => $p) {
    $out = trim(stream_get_contents($tuberias[$i][1]));
    proc_close($p);
    if (preg_match('/ok=(\d+) rechazadas=(\d+) errores=(\d+)/', $out, $mm)) {
        $oks += (int)$mm[1];
        $rech += (int)$mm[2];
        $errs += (int)$mm[3];
    }
}
igual('80 transferencias cruzadas: ninguna termino en error (sin deadlocks)', 0, $errs);
igual('...todas se procesaron (ok + rechazadas)', 80, $oks + $rech);
igual('el dinero total se conserva', '1500.00', total_DE());
igual('cada cuenta cuadra con su ultimo saldo registrado (D)', saldo($D), valor("SELECT saldo_despues FROM movimientos WHERE cuenta_id = ? ORDER BY id DESC LIMIT 1", [$idD]));
igual('cada cuenta cuadra con su ultimo saldo registrado (E)', saldo($E), valor("SELECT saldo_despues FROM movimientos WHERE cuenta_id = ? ORDER BY id DESC LIMIT 1", [$idE]));
igual('cada transferencia exitosa dejo 2 filas', $oks * 2, (int)valor("SELECT COUNT(*) FROM movimientos WHERE tipo='transferencia' AND id > ?", [$idT3 + 1]));

// ================================================================== 9. CONCURRENCIA Y ROLLBACK
seccion('9. Retiros simultaneos y rollback');
$hashDummy2 = password_hash('x', PASSWORD_DEFAULT);
bd()->query("INSERT INTO registro (nombre, correo, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES ('Carla Tres', 'carla@test.com', '" . bd()->real_escape_string($hashDummy2) . "', '3333333333', 'Ahorro', 300, CURDATE(), 'Matriz')");
$cid = (int)valor("SELECT id FROM registro WHERE numeroCuenta='3333333333'");
$procesos = [];
for ($i = 0; $i < 8; $i++) {
    $procesos[$i] = proc_open([PHP_BINARY, __DIR__ . '/retiro-paralelo.php', (string)$cid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procesos[$i] = [$procesos[$i], $pipes];
}
$salidas = [];
foreach ($procesos as [$p, $pipes]) {
    $salidas[] = trim(stream_get_contents($pipes[1]));
    proc_close($p);
}
$conteo = array_count_values($salidas);
igual('8 retiros de 100 sobre 300: exactamente 3 exitosos', 3, $conteo['OK'] ?? 0);
igual('...y 5 rechazados', 5, $conteo['RECHAZADO'] ?? 0);
igual('saldo final 0, nunca negativo', '0.00', saldo('3333333333'));
igual('quedan 3 retiros guardados', 3, valor("SELECT COUNT(*) FROM movimientos WHERE cuenta_id=? AND tipo='retiro'", [$cid]));

bd()->query("UPDATE registro SET saldo = 500 WHERE id = $cid");
$cn = getConexion();
$antes_log = ini_set('error_log', sys_get_temp_dir() . '/aurea_test_error.log');
$res = con_transaccion($cn, function () use ($cn, $cid) {
    // reversa_de = 999999 no existe: el UPDATE del saldo se hace y el INSERT falla por la clave foranea
    return aplicar_movimiento($cn, $cid, 'reverso', false, '100.00', 'prueba', 'forzado', 999999);
});
ini_set('error_log', $antes_log);
@unlink(sys_get_temp_dir() . '/aurea_test_error.log');
comprobar('la operacion fallida se informa', $res['ok'] === false);
igual('rollback: el saldo no cambio', '500.00', saldo('3333333333'));

// ================================================================== 10. EMPLEADOS EN CALIENTE
seccion('10. Cambios de empleados con la sesion abierta');
$emp2 = new Nav();
comprobar('pedro cambia de clave (admin)', strpos(gest($admin, ['accion' => 'clave', 'id_e' => 'pedro', 'password' => 'Nueva-Clave-99']), 'actualizada') !== false);
comprobar('clave vieja ya no entra', strpos($emp2->loginEmpleado('pedro', 'Clave-1234')['ubicacion'], 'error=1') !== false);
comprobar('clave nueva si entra', strpos($emp2->loginEmpleado('pedro', 'Nueva-Clave-99')['ubicacion'], 'regisObusc') !== false);
igual('antes de la baja: panel', 200, $emp2->get('public/regisObusc.php')['codigo']);
comprobar('desactivar empleado', strpos(gest($admin, ['accion' => 'estado', 'id_e' => 'pedro', 'valor' => 'desactivar']), 'desactivado') !== false);
$r = $emp2->get('public/regisObusc.php');
comprobar('su sesion abierta deja de funcionar al instante', $r['codigo'] === 302 && strpos($r['ubicacion'], 'login-empleado') !== false);
$r = $emp2->post('src/operations/buscar-cliente.php', ['numeroCuenta' => '1111111111']);
igual('y los endpoints tambien lo rechazan', 403, $r['codigo']);
$emp3 = new Nav();
comprobar('desactivado no puede entrar con clave correcta', strpos($emp3->loginEmpleado('pedro', 'Nueva-Clave-99')['ubicacion'], 'error=1') !== false);
comprobar('la auditoria anota la cuenta desactivada', (int)valor("SELECT COUNT(*) FROM auditoria WHERE accion='login_fallido' AND detalle LIKE '%desactivada%'") >= 1);
gest($admin, ['accion' => 'estado', 'id_e' => 'pedro', 'valor' => 'activar']);
comprobar('reactivado puede volver a entrar', strpos($emp3->loginEmpleado('pedro', 'Nueva-Clave-99')['ubicacion'], 'regisObusc') !== false);

igual('antes: no ve auditoria', 302, $emp3->get('public/auditoria-vista.php')['codigo']);
gest($admin, ['accion' => 'rol', 'id_e' => 'pedro', 'rol' => 'administrador']);
igual('promovido: ve auditoria sin volver a entrar', 200, $emp3->get('public/auditoria-vista.php')['codigo']);
igual('promovido: ve 7 tarjetas', 7, tarjetas($emp3->get('public/regisObusc.php')['cuerpo']));
gest($admin, ['accion' => 'rol', 'id_e' => 'pedro', 'rol' => 'empleado']);
igual('degradado: pierde la auditoria de inmediato', 302, $emp3->get('public/auditoria-vista.php')['codigo']);

// ================================================================== 11. CLIENTE
seccion('11. Cliente');
comprobar('clave incorrecta', strpos($cli->loginCliente('ana@test.com', 'mala')['ubicacion'], 'error=1') !== false);
comprobar('clave correcta entra a "Mi cuenta"', strpos($cli->loginCliente('ana@test.com', 'Cliente-123')['ubicacion'], 'datos-cliente') !== false);
$h = $cli->get('public/datos-cliente.php')['cuerpo'];
comprobar('ve su saldo actualizado', strpos($h, number_format((float)saldo($c), 2)) !== false);
comprobar('ve sus movimientos', strpos($h, '>Movimientos</h3>') !== false && strpos($h, '<td>Depósito</td>') !== false);
comprobar('ve "Correccion" en los reversos', strpos($h, 'Corrección') !== false);
comprobar('no ve empleados ni botones de reverso', stripos($h, 'adm_test') === false && strpos($h, 'Reversar') === false);
comprobar('el hash de la clave no aparece', strpos($h, '$2y$') === false);
$saldo_antes = saldo($c);
foreach (['regisObusc', 'movimientos-vista', 'clientes-vista', 'auditoria-vista', 'empleados-vista', 'buscar-cliente-vista', 'registro-cliente-vista'] as $v) {
    igual("cliente no entra a $v", 302, $cli->get("public/$v.php")['codigo']);
}
foreach (['registrar-movimiento', 'registrar-transferencia', 'reversar-movimiento', 'gestionar-empleado', 'guardar-cliente', 'buscar-cliente'] as $e) {
    igual("cliente no puede usar $e", 403, $cli->post("src/operations/$e.php", ['cuenta' => $c, 'tipo' => 'deposito', 'monto' => '999'])['codigo']);
}
igual('el saldo de Ana no cambio por los intentos del cliente', $saldo_antes, saldo($c));

seccion('11b. Logout');
$cli->get('src/auth/logout.php');
igual('sigue con sesion tras GET', 200, $cli->get('public/datos-cliente.php')['codigo']);
$cli->pedir('POST', 'src/auth/logout.php');
igual('sigue con sesion tras POST sin token', 200, $cli->get('public/datos-cliente.php')['codigo']);
$cli->logout();
igual('POST con token cierra la sesion', 302, $cli->get('public/datos-cliente.php')['codigo']);
$emp->logout();
igual('el empleado tambien cierra sesion', 302, $emp->get('public/regisObusc.php')['codigo']);

// ================================================================== 12. AUDITORÍA
seccion('12. Auditoria');
foreach (['login', 'login_fallido', 'logout', 'registrar_cliente', 'consultar_cuenta', 'listar_clientes', 'consultar_movimientos', 'deposito', 'retiro', 'reverso', 'transferencia', 'transferencia_rechazada', 'crear_empleado', 'cambiar_clave_empleado', 'desactivar_empleado', 'activar_empleado', 'cambiar_rol_empleado'] as $a) {
    comprobar("se registra '$a'", (int)valor("SELECT COUNT(*) FROM auditoria WHERE accion = ?", [$a]) >= 1);
}
igual('ninguna contrasena en la auditoria', 0, valor("SELECT COUNT(*) FROM auditoria WHERE detalle LIKE '%Clave-%' OR detalle LIKE '%Cliente-123%' OR detalle LIKE '%Admin-Clave%'"));
$h = $admin->get('public/auditoria-vista.php')['cuerpo'];
comprobar('el administrador ve la auditoria (ultimas 100 filas)', strpos($h, 'adm_test') !== false && substr_count($h, '<tr>') > 10);
comprobar('acciones con comillas salen escapadas', strpos($h, "' OR '1'='1") === false);

// ================================================================== 13. DATOS DE DEMOSTRACIÓN
seccion('13. Datos de demostracion (database/datos-demo.sql)');
function cargar_sql($ruta) {
    $sql = preg_replace('/\bbanco\b/', BD_PRUEBA, file_get_contents($ruta));
    $cn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    try {
        $cn->multi_query($sql);
        do {
            if ($r = $cn->store_result()) {
                $r->free();
            }
        } while ($cn->more_results() && $cn->next_result());
    } finally {
        $cn->close();
    }
}
cargar_sql(RAIZ . '/database/datos-demo.sql');
igual('se cargaron 3 clientes de demo', 3, valor("SELECT COUNT(*) FROM registro WHERE correo LIKE '%.demo@example.com'"));
igual('saldo total de las cuentas de demo', '32449.50', valor("SELECT SUM(saldo) FROM registro WHERE correo LIKE '%.demo@example.com'"));

foreach (['7100000001', '7100000002', '7100000003'] as $cta) {
    $filas = [];
    $r = bd()->prepare("SELECT m.monto, m.es_credito, m.saldo_despues FROM movimientos m JOIN registro g ON g.id = m.cuenta_id WHERE g.numeroCuenta = ? ORDER BY m.id");
    $r->bind_param('s', $cta);
    $r->execute();
    $filas = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
    $corriente = 0.0;
    $coherente = true;
    foreach ($filas as $f) {
        $corriente += $f['es_credito'] ? (float)$f['monto'] : -(float)$f['monto'];
        if (abs($corriente - (float)$f['saldo_despues']) > 0.001) {
            $coherente = false;
        }
    }
    comprobar("cuenta $cta: cada saldo_despues sigue al anterior", $coherente && count($filas) > 0);
    igual("cuenta $cta: el saldo coincide con el ultimo movimiento", number_format($corriente, 2, '.', ''), saldo($cta));
}
igual('cada transferencia son 2 filas: una suma y otra resta, mismo monto', 0, valor("SELECT COUNT(*) FROM (SELECT transferencia_ref FROM movimientos WHERE tipo='transferencia' AND transferencia_ref LIKE 'DEMOTRANSF%' GROUP BY transferencia_ref HAVING COUNT(*) <> 2 OR SUM(es_credito) <> 1 OR COUNT(DISTINCT monto) <> 1) x"));
igual('el reverso apunta a un retiro de la misma cuenta y monto', 1, valor("SELECT COUNT(*) FROM movimientos r JOIN movimientos o ON o.id = r.reversa_de WHERE r.tipo='reverso' AND o.tipo='retiro' AND o.cuenta_id = r.cuenta_id AND o.monto = r.monto AND r.empleado_id = 'demo-admin' AND r.motivo = 'Monto capturado por error'"));

$da = new Nav();
comprobar('demo-admin / Demo-Admin-2026 entra', strpos($da->loginEmpleado('demo-admin', 'Demo-Admin-2026')['ubicacion'], 'regisObusc') !== false);
$h = $da->get('public/regisObusc.php')['cuerpo'];
igual('el administrador de demo ve 7 tarjetas', 7, tarjetas($h));
comprobar('el panel lo saluda por su nombre', strpos($h, 'Ana Demo (Administradora)') !== false);
comprobar('la auditoria de demo se ve', strpos($da->get('public/auditoria-vista.php')['cuerpo'], '7100000003') !== false);
$vista = $da->get('public/movimientos-vista.php?cuenta=7100000003')['cuerpo'];
comprobar('la cuenta de demo muestra un reverso y una transferencia', strpos($vista, 'Reverso') !== false && strpos($vista, 'Transferencia') !== false);

$de = new Nav();
comprobar('demo-emp / Demo-Emp-2026 entra', strpos($de->loginEmpleado('demo-emp', 'Demo-Emp-2026')['ubicacion'], 'regisObusc') !== false);
igual('el empleado de demo ve 5 tarjetas', 5, tarjetas($de->get('public/regisObusc.php')['cuerpo']));
$nom = json_decode($de->post('src/operations/calcular-sueldo.php', ['empleado_id' => 1])['cuerpo'], true);
comprobar('la nomina de demo funciona (empleado 1: 18,000 + 2,500)', ($nom['status'] ?? '') === 'success' && ($nom['total'] ?? '') === '20,500.00', json_encode($nom));

foreach (['lucia.demo@example.com', 'diego.demo@example.com', 'sol.demo@example.com'] as $correo) {
    $dc = new Nav();
    comprobar("cliente $correo / Demo-Cliente-2026 entra", strpos($dc->loginCliente($correo, 'Demo-Cliente-2026')['ubicacion'], 'datos-cliente') !== false);
}
$dc = new Nav();
$dc->loginCliente('lucia.demo@example.com', 'Demo-Cliente-2026');
$h = $dc->get('public/datos-cliente.php')['cuerpo'];
comprobar('Lucia ve su saldo de 7,700.00', strpos($h, '7,700.00') !== false);
comprobar('Lucia ve transferencias con la otra cuenta enmascarada', strpos($h, 'Transferencia') !== false && strpos($h, '******') !== false);

$antes = (int)valor("SELECT COUNT(*) FROM movimientos");
$fallo_esperado = false;
try {
    cargar_sql(RAIZ . '/database/datos-demo.sql');
} catch (mysqli_sql_exception $e) {
    $fallo_esperado = true; // correo repetido: el script se detiene
}
comprobar('cargar la demo por segunda vez falla en vez de duplicar', $fallo_esperado);
igual('...y no se duplico ningun movimiento', $antes, (int)valor("SELECT COUNT(*) FROM movimientos"));

// ================================================================== 14. PAGINACIÓN DEL HISTORIAL
seccion('14. Paginacion del historial');
$hPablo = password_hash('Cliente-123', PASSWORD_DEFAULT);
$insP = bd()->prepare("INSERT INTO registro (nombre, correo, contraseña, numeroCuenta, tipo_cuenta, saldo, fecha, sucursal2) VALUES ('Pablo Seis', 'pablo@test.com', ?, '6666666666', 'Ahorro', 0, CURDATE(), 'Matriz')");
$insP->bind_param('s', $hPablo);
$insP->execute();
$idPablo = (int)valor("SELECT id FROM registro WHERE numeroCuenta = '6666666666'");
$cnP = getConexion();
for ($i = 1; $i <= 45; $i++) {
    depositar($cnP, $idPablo, '1.00', 'prueba', "deposito $i");
}
$cnP->close();
igual('la cuenta de prueba tiene 45 movimientos y saldo 45.00', '45/45.00', num_movimientos('6666666666') . '/' . saldo('6666666666'));

function filas_tabla($html) {
    return substr_count($html, '<tr>') - 1; // menos la fila del encabezado
}
$pg = function ($p) use ($admin) {
    return $admin->get('public/movimientos-vista.php?cuenta=6666666666' . ($p === null ? '' : '&p=' . $p))['cuerpo'];
};
$h1 = $pg(null);
igual('pagina 1: 20 filas', 20, filas_tabla($h1));
comprobar('pagina 1 dice "Pagina 1 de 3 (45 movimientos)"', strpos($h1, 'Página 1 de 3 (45 movimientos)') !== false);
comprobar('pagina 1 muestra lo mas reciente y no lo mas antiguo', preg_match('/deposito 45\s*<\/td>/', $h1) === 1 && preg_match('/deposito 1\s*<\/td>/', $h1) === 0);
comprobar('pagina 1 enlaza a la 2 conservando la cuenta', strpos($h1, 'cuenta=6666666666&amp;p=2') !== false);
comprobar('pagina 1 no tiene enlace a una pagina anterior', strpos($h1, 'Más recientes') === false);
$h2 = $pg(2);
igual('pagina 2: 20 filas', 20, filas_tabla($h2));
comprobar('pagina 2 tiene enlaces a ambos lados', strpos($h2, 'Más recientes') !== false && strpos($h2, 'Más antiguos') !== false);
$h3 = $pg(3);
igual('pagina 3: las 5 filas restantes', 5, filas_tabla($h3));
comprobar('la ultima pagina llega hasta el movimiento mas antiguo', preg_match('/deposito 1\s*<\/td>/', $h3) === 1);
comprobar('la ultima pagina no tiene enlace a una siguiente', strpos($h3, 'Más antiguos') === false);
comprobar('pagina fuera de rango cae en la ultima', strpos($pg(999), 'Página 3 de 3') !== false);
foreach (['abc', '0', '-4'] as $raro) {
    comprobar("p='$raro' cae en la primera", strpos($pg($raro), 'Página 1 de 3') !== false);
}
igual('p como arreglo no rompe la pagina', 200, $admin->get('public/movimientos-vista.php?cuenta=6666666666&p[]=x')['codigo']);
igual('una cuenta con pocos movimientos no muestra paginacion', 0, substr_count($admin->get('public/movimientos-vista.php?cuenta=2222222222')['cuerpo'], 'Página 1 de'));

$pablo = new Nav();
$pablo->loginCliente('pablo@test.com', 'Cliente-123');
$c1 = $pablo->get('public/datos-cliente.php')['cuerpo'];
igual('cliente pagina 1: 10 filas', 10, filas_tabla($c1));
comprobar('cliente: "Pagina 1 de 5"', strpos($c1, 'Página 1 de 5') !== false);
igual('cliente pagina 5: 5 filas', 5, filas_tabla($pablo->get('public/datos-cliente.php?p=5')['cuerpo']));
comprobar('cliente: pagina fuera de rango cae en la ultima', strpos($pablo->get('public/datos-cliente.php?p=99')['cuerpo'], 'Página 5 de 5') !== false);
comprobar('cliente: p invalido cae en la primera', strpos($pablo->get('public/datos-cliente.php?p=abc')['cuerpo'], 'Página 1 de 5') !== false);
comprobar('cliente: "Mas antiguos" lleva a la pagina 2', strpos($c1, 'href="?p=2"') !== false);

// ================================================================== 15. CAMBIO DE CONTRASEÑA DEL CLIENTE
seccion('15. El cliente cambia su contrasena');
igual('sin sesion: la pantalla redirige', 302, $anon->get('public/cambiar-clave.php')['codigo']);
igual('un empleado no entra a la pantalla', 302, $admin->get('public/cambiar-clave.php')['codigo']);
igual('sin sesion: el endpoint da 403', 403, $anon->pedir('POST', 'src/operations/cambiar-clave-cliente.php', ['actual' => 'x'])['codigo']);
igual('un empleado no puede usar el endpoint (403)', 403, $admin->post('src/operations/cambiar-clave-cliente.php', ['actual' => 'x', 'nueva' => 'Otra-Clave-1', 'confirmar' => 'Otra-Clave-1'])['codigo']);
igual('el cliente sin token CSRF da 403', 403, $pablo->pedir('POST', 'src/operations/cambiar-clave-cliente.php', ['actual' => 'Cliente-123', 'nueva' => 'Otra-Clave-1', 'confirmar' => 'Otra-Clave-1'])['codigo']);
igual('el cliente ve la pantalla', 200, $pablo->get('public/cambiar-clave.php')['codigo']);
comprobar('"Mi cuenta" enlaza a cambiar la contrasena', strpos($c1, 'cambiar-clave.php') !== false);

function cambiar_clave($nav, $actual, $nueva, $confirmar) {
    $nav->post('src/operations/cambiar-clave-cliente.php', ['actual' => $actual, 'nueva' => $nueva, 'confirmar' => $confirmar]);
    return aviso($nav->get('public/cambiar-clave.php')['cuerpo']);
}
$hash0 = valor("SELECT contraseña FROM registro WHERE correo = 'pablo@test.com'");
comprobar('la confirmacion no coincide', strpos(cambiar_clave($pablo, 'Cliente-123', 'Nueva-Clave-2026', 'Otra-Clave-2026'), 'no coincide') !== false);
comprobar('contrasena nueva muy corta', strpos(cambiar_clave($pablo, 'Cliente-123', 'corta', 'corta'), 'entre 8 y 72') !== false);
comprobar('contrasena nueva igual a la actual', strpos(cambiar_clave($pablo, 'Cliente-123', 'Cliente-123', 'Cliente-123'), 'distinta') !== false);
comprobar('contrasena actual incorrecta', strpos(cambiar_clave($pablo, 'incorrecta-1', 'Nueva-Clave-2026', 'Nueva-Clave-2026'), 'no es correcta') !== false);
igual('con todos esos rechazos la contrasena no cambio', 1, valor("SELECT contraseña = ? FROM registro WHERE correo = 'pablo@test.com'", [$hash0]));

comprobar('cambio correcto', strpos(cambiar_clave($pablo, 'Cliente-123', 'Nueva-Clave-2026', 'Nueva-Clave-2026'), 'actualizada') !== false);
igual('la sesion sigue abierta tras el cambio', 200, $pablo->get('public/datos-cliente.php')['codigo']);
igual('el hash guardado es distinto', 0, valor("SELECT contraseña = ? FROM registro WHERE correo = 'pablo@test.com'", [$hash0]));
comprobar('la nueva contrasena verifica contra el hash guardado', password_verify('Nueva-Clave-2026', valor("SELECT contraseña FROM registro WHERE correo = 'pablo@test.com'")));
$otro = new Nav();
comprobar('la contrasena vieja ya no entra', strpos($otro->loginCliente('pablo@test.com', 'Cliente-123')['ubicacion'], 'error=1') !== false);
comprobar('la contrasena nueva si entra', strpos($otro->loginCliente('pablo@test.com', 'Nueva-Clave-2026')['ubicacion'], 'datos-cliente') !== false);

for ($i = 1; $i <= 5; $i++) {
    cambiar_clave($pablo, 'incorrecta-' . $i, 'Tercera-Clave-2026', 'Tercera-Clave-2026');
}
$msg = cambiar_clave($pablo, 'Nueva-Clave-2026', 'Tercera-Clave-2026', 'Tercera-Clave-2026');
comprobar('tras 5 intentos fallidos se bloquea, incluso con la actual correcta', strpos($msg, 'Demasiados intentos') !== false, $msg);
comprobar('...y la contrasena no cambio', password_verify('Nueva-Clave-2026', valor("SELECT contraseña FROM registro WHERE correo = 'pablo@test.com'")));

// ================================================================== 16. PÁGINAS DE ERROR Y FAVICON
seccion('16. Paginas de error y favicon');
foreach ([[403, 'Acceso denegado'], [404, 'Página no encontrada'], [500, 'Error del servidor']] as [$cod, $titulo]) {
    $r = $anon->get("public/error.php?codigo=$cod");
    igual("error.php?codigo=$cod responde con ese estado", $cod, $r['codigo']);
    comprobar("...y muestra '$titulo'", strpos($r['cuerpo'], $titulo) !== false);
}
igual('sin codigo: 404', 404, $anon->get('public/error.php')['codigo']);
igual('codigo desconocido: 404', 404, $anon->get('public/error.php?codigo=418')['codigo']);
$r = $anon->get('public/error.php?codigo=' . urlencode('<script>alert(1)</script>'));
igual('codigo con HTML: 404', 404, $r['codigo']);
comprobar('...y no se refleja en la pagina', strpos($r['cuerpo'], '<script>alert') === false);
$r = $anon->get('public/error.php?codigo=404');
comprobar('los enlaces son absolutos (funcionan bajo cualquier URL)', strpos($r['cuerpo'], 'href="/public/assets/css/style.css"') !== false && strpos($r['cuerpo'], 'href="/public/index.php"') !== false);
$favicon = $anon->get('public/assets/images/favicon.svg');
comprobar('el favicon existe y es un SVG', $favicon['codigo'] === 200 && strpos($favicon['cuerpo'], '<svg') !== false);
$sin_icono = [];
foreach (['public/index.php' => $anon, 'public/login-cliente.php' => $anon, 'public/login-empleado.php' => $anon, 'public/error.php' => $anon,
          'public/regisObusc.php' => $admin, 'public/registro-cliente-vista.php' => $admin, 'public/buscar-cliente-vista.php' => $admin,
          'public/clientes-vista.php' => $admin, 'public/movimientos-vista.php' => $admin, 'public/auditoria-vista.php' => $admin,
          'public/empleados-vista.php' => $admin, 'public/sueldos-vista.php' => $admin, 'public/datos-cliente.php' => $pablo,
          'public/cambiar-clave.php' => $pablo] as $ruta => $nav) {
    if (strpos($nav->get($ruta)['cuerpo'], 'favicon.svg') === false) {
        $sin_icono[] = $ruta;
    }
}
comprobar('las 14 paginas enlazan el favicon', count($sin_icono) === 0, implode(', ', $sin_icono));

// ================================================================== resultado
echo "\n" . str_repeat('=', 60) . "\n";
if ($fallos) {
    echo count($fallos) . " de $total comprobaciones FALLARON:\n";
    foreach ($fallos as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "TODO BIEN: $total comprobaciones correctas.\n";
exit(0);
