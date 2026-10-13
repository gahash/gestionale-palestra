<?php
declare(strict_types=1);

ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/appuntamenti.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET['vista'] = 'settimana';
$_GET['data'] = date('Y-m-d');

require __DIR__ . '/../src/bootstrap.php';
\App\Auth::start();
$_SESSION['user'] = [
    'id' => 1,
    'nome' => 'Admin',
    'cognome' => 'Test',
    'email' => 'admin@example.com',
    'ruolo' => 'admin'
];

require __DIR__ . '/../admin/appuntamenti.php';
$html = ob_get_clean();

echo "Page rendered successfully! Length: " . strlen($html) . " bytes\n";
if (strpos($html, 'cliente-input-nome') !== false && strpos($html, 'btn-add-cliente') !== false) {
    echo "Modal multi-client elements verified in output HTML!\n";
} else {
    echo "ERROR: Modal multi-client elements missing in output HTML!\n";
    exit(1);
}
