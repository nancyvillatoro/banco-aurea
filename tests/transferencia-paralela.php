<?php
// Auxiliar de run-tests.php: hace N transferencias de 10 entre dos cuentas.
// Se lanzan varios a la vez, algunos en un sentido y otros en el contrario (A->B y B->A),
// para comprobar que no se traban entre ellos ni se pierde dinero.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

require __DIR__ . '/../src/config/db.php';
require __DIR__ . '/../src/lib/movimientos.php';

$cn = getConexion();
$ok = $rechazadas = $errores = 0;
for ($i = 0; $i < (int)$argv[3]; $i++) {
    $r = transferir($cn, (int)$argv[1], (int)$argv[2], '10.00', 'prueba', 'transferencia paralela');
    if ($r['ok']) {
        $ok++;
    } elseif (strpos($r['mensaje'], 'Error en el sistema') === 0) {
        $errores++; // por ejemplo, un deadlock
    } else {
        $rechazadas++;
    }
}
echo "ok=$ok rechazadas=$rechazadas errores=$errores";
