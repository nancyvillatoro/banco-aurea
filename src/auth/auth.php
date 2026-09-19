<?php
// Utilidades compartidas de sesión, roles y CSRF.
// Incluir con: require_once __DIR__ . '/auth.php';  (ya llama a session_start)

const SESION_TIMEOUT = 1800; // 30 min de inactividad

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// Expira la sesión por inactividad
if (isset($_SESSION['ultima_actividad']) && time() - $_SESSION['ultima_actividad'] > SESION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['ultima_actividad'] = time();

function esc($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function es_empleado(): bool {
    return ($_SESSION['rol'] ?? '') === 'empleado';
}

function es_admin(): bool {
    return es_empleado() && ($_SESSION['nivel'] ?? '') === 'administrador';
}

// Confirma en la BD que el empleado sigue activo y toma su rol ACTUAL.
// Así, si un administrador lo da de baja o le cambia el rol, se nota en la siguiente página
// (sin esperar a que cierre sesión).
function empleado_vigente(): bool {
    if (!es_empleado()) {
        return false;
    }
    static $resultado = null; // se consulta una sola vez por petición
    if ($resultado !== null) {
        return $resultado;
    }

    require_once __DIR__ . '/../config/db.php';
    try {
        $cn = getConexion();
        $id = (string)($_SESSION['empleado_id'] ?? '');
        $stmt = $cn->prepare("SELECT rol, activo, nombre FROM inicioe WHERE id_e = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $cn->close();
    } catch (Throwable $e) {
        error_log("empleado_vigente: " . $e->getMessage());
        return $resultado = false; // ante la duda, no se deja pasar
    }

    if (!$fila || !$fila['activo']) {
        session_unset(); // ya no es un empleado válido: se cierra su sesión
        return $resultado = false;
    }
    $_SESSION['nivel'] = $fila['rol'];
    if ($fila['nombre'] !== '') {
        $_SESSION['nombre'] = $fila['nombre'];
    }
    return $resultado = true;
}

function es_cliente(): bool {
    return ($_SESSION['rol'] ?? '') === 'cliente' && isset($_SESSION['cliente']);
}

// Para vistas (páginas): redirige al login si no hay sesión del rol adecuado
function requerir_empleado_vista(): void {
    if (!empleado_vigente()) {
        header('Location: login-empleado.php');
        exit();
    }
}

// Páginas solo para el administrador; un empleado normal vuelve al panel con un aviso
function requerir_admin_vista(): void {
    requerir_empleado_vista();
    if (!es_admin()) {
        flash_set('danger', 'No tiene permiso para entrar a esa sección.');
        header('Location: regisObusc.php');
        exit();
    }
}

function requerir_cliente_vista(): void {
    if (!es_cliente()) {
        header('Location: login-cliente.php');
        exit();
    }
}

// Para endpoints: solo POST, solo empleados y con token CSRF válido
function requerir_empleado_api(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder_error(405, 'Método no permitido.');
    }
    if (!empleado_vigente()) {
        responder_error(403, 'No autorizado.');
    }
    if (!csrf_valido()) {
        responder_error(403, 'Token de seguridad inválido. Recargue la página.');
    }
}

// Endpoints solo para clientes: solo POST, sesión de cliente y token CSRF
function requerir_cliente_api(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder_error(405, 'Método no permitido.');
    }
    if (!es_cliente()) {
        responder_error(403, 'No autorizado.');
    }
    if (!csrf_valido()) {
        responder_error(403, 'Token de seguridad inválido. Recargue la página.');
    }
}

// Endpoints solo para el administrador
function requerir_admin_api(): void {
    requerir_empleado_api();
    if (!es_admin()) {
        responder_error(403, 'Solo el administrador puede hacer esto.');
    }
}

function responder_error(int $codigo, string $mensaje): void {
    http_response_code($codigo);
    echo json_encode(["status" => "error", "message" => $mensaje]);
    exit();
}

// ---- Mensajes flash (un solo uso, sobreviven a una redirección) ----
function flash_set(string $tipo, string $mensaje, array $datos = []): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje, 'datos' => $datos];
}

function flash_get(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}

function csrf_valido(): bool {
    $enviado = $_POST['csrf_token'] ?? '';
    return is_string($enviado) && $enviado !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $enviado);
}

// ---- Token de operación (un solo uso) ----
// Evita que un formulario de dinero se procese dos veces (doble clic, recargar la página).
function nuevo_token_operacion(): string {
    $t = bin2hex(random_bytes(16));
    $_SESSION['op_tokens'][$t] = time();
    // Guardamos solo los últimos 20 para que la sesión no crezca
    if (count($_SESSION['op_tokens']) > 20) {
        array_shift($_SESSION['op_tokens']);
    }
    return $t;
}

// Devuelve true solo la primera vez que se usa un token válido
function usar_token_operacion($token): bool {
    if (is_string($token) && isset($_SESSION['op_tokens'][$token])) {
        unset($_SESSION['op_tokens'][$token]);
        return true;
    }
    return false;
}

// ---- Límite de intentos de login (por IP + usuario) ----
const LOGIN_MAX_INTENTOS = 5;
const LOGIN_BLOQUEO_SEG  = 300;

function _throttle_archivo(string $usuario): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aurea_login_'
        . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower($usuario));
}

function login_bloqueado(string $usuario): bool {
    $f = _throttle_archivo($usuario);
    if (!is_file($f)) return false;
    $d = json_decode((string)file_get_contents($f), true) ?: [];
    if (time() - ($d['desde'] ?? 0) > LOGIN_BLOQUEO_SEG) {
        @unlink($f);
        return false;
    }
    return ($d['intentos'] ?? 0) >= LOGIN_MAX_INTENTOS;
}

function login_fallo(string $usuario): void {
    $f = _throttle_archivo($usuario);
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (time() - ($d['desde'] ?? 0) > LOGIN_BLOQUEO_SEG) {
        $d = ['intentos' => 0, 'desde' => time()];
    }
    $d['intentos'] = ($d['intentos'] ?? 0) + 1;
    file_put_contents($f, json_encode($d), LOCK_EX);
}

function login_ok(string $usuario): void {
    @unlink(_throttle_archivo($usuario));
}
