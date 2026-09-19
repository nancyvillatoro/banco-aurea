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
foreach (['buscar-cliente', 'calcular-sueldo', 'guardar-cliente', 'registrar-movimiento', 'reversar-movimiento', 'gestionar-empleado'] as $e) {
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
    $nav->post('src/operations/reversar-movimiento.php', ['op_token' => $m[1][1] ?? '', 'cuenta' => $cuenta, 'movimiento_id' => $id, 'motivo' => $motivo]);
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
comprobar('ve sus movimientos', strpos($h, 'Ultimos movimientos') !== false || strpos($h, 'movimientos') !== false);
comprobar('ve "Correccion" en los reversos', strpos($h, 'Corrección') !== false);
comprobar('no ve empleados ni botones de reverso', stripos($h, 'adm_test') === false && strpos($h, 'Reversar') === false);
comprobar('el hash de la clave no aparece', strpos($h, '$2y$') === false);
$saldo_antes = saldo($c);
foreach (['regisObusc', 'movimientos-vista', 'clientes-vista', 'auditoria-vista', 'empleados-vista', 'buscar-cliente-vista', 'registro-cliente-vista'] as $v) {
    igual("cliente no entra a $v", 302, $cli->get("public/$v.php")['codigo']);
}
foreach (['registrar-movimiento', 'reversar-movimiento', 'gestionar-empleado', 'guardar-cliente', 'buscar-cliente'] as $e) {
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
foreach (['login', 'login_fallido', 'logout', 'registrar_cliente', 'consultar_cuenta', 'listar_clientes', 'consultar_movimientos', 'deposito', 'retiro', 'reverso', 'crear_empleado', 'cambiar_clave_empleado', 'desactivar_empleado', 'activar_empleado', 'cambiar_rol_empleado'] as $a) {
    comprobar("se registra '$a'", (int)valor("SELECT COUNT(*) FROM auditoria WHERE accion = ?", [$a]) >= 1);
}
igual('ninguna contrasena en la auditoria', 0, valor("SELECT COUNT(*) FROM auditoria WHERE detalle LIKE '%Clave-%' OR detalle LIKE '%Cliente-123%' OR detalle LIKE '%Admin-Clave%'"));
$h = $admin->get('public/auditoria-vista.php')['cuerpo'];
comprobar('el administrador ve la auditoria (ultimas 100 filas)', strpos($h, 'adm_test') !== false && substr_count($h, '<tr>') > 10);
comprobar('acciones con comillas salen escapadas', strpos($h, "' OR '1'='1") === false);

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
