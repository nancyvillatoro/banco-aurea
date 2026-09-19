<?php
// Auxiliar de run-tests.php: hace UN retiro de 100 sobre la cuenta indicada.
// Se lanzan varios a la vez para comprobar que el saldo nunca queda negativo.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

require __DIR__ . '/../src/config/db.php';
require __DIR__ . '/../src/lib/movimientos.php';

$cn = getConexion();
$r = retirar($cn, (int)$argv[1], '100.00', 'prueba', 'retiro paralelo');
echo $r['ok'] ? "OK" : "RECHAZADO";
