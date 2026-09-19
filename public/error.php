<?php
// Página de error amigable. Apache la usa para los errores 403, 404 y 500 (ver el .htaccess de la raíz).
// No usa sesión ni base de datos, para que funcione aunque eso sea justo lo que falla.

$mensajes = [
    403 => ['Acceso denegado', 'No tiene permiso para ver esta página.'],
    404 => ['Página no encontrada', 'La dirección que buscó no existe o fue movida.'],
    500 => ['Error del servidor', 'Ocurrió un problema inesperado. Inténtelo de nuevo en unos minutos.'],
];

// Apache indica el error original en REDIRECT_STATUS; si se abre directo se puede usar ?codigo=
$codigo = (int)($_SERVER['REDIRECT_STATUS'] ?? $_GET['codigo'] ?? 404);
if (!isset($mensajes[$codigo])) {
    $codigo = 404; // cualquier otro valor cae en "no encontrada"
}
http_response_code($codigo);

// Esta página se muestra bajo la URL que falló (por ejemplo /bancoAurea/public/a/b/c), así que los
// enlaces no pueden ser relativos: se arman desde la carpeta donde está este archivo.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
function h($texto) {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($codigo . ' - ' . $mensajes[$codigo][0]); ?> - Banco Áurea</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo h($base); ?>/assets/images/favicon.svg">
    <link href="<?php echo h($base); ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo h($base); ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
    <main class="form-02-main">
        <div class="form-03-main form-login text-center">
            <h1><?php echo (int)$codigo; ?></h1>
            <h2><?php echo h($mensajes[$codigo][0]); ?></h2>
            <p class="mb-4"><?php echo h($mensajes[$codigo][1]); ?></p>
            <a href="<?php echo h($base); ?>/index.php" class="btn_cliente">Volver al inicio</a>
        </div>
    </main>
</body>
</html>
