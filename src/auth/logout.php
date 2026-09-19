<?php
require_once __DIR__ . '/auth.php';

// Solo por POST con token, para que un enlace externo no pueda cerrar la sesión
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
}
header("Location: ../../public/index.php");
exit();
