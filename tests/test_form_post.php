<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
\App\Auth::start();
$_SESSION['user'] = [
    'id' => 1,
    'nome' => 'Admin',
    'cognome' => 'Test',
    'email' => 'admin@example.com',
    'ruolo' => 'admin'
];

$csrfToken = \App\Csrf::token();

// Simula una richiesta POST come inviata dal browser con 3 clienti
$nome1 = 'Mario Test ' . bin2hex(random_bytes(2));
$nome2 = 'Luca Test ' . bin2hex(random_bytes(2));
$nome3 = 'Chiara Test ' . bin2hex(random_bytes(2));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/admin/appuntamenti.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = [
    'csrf_token' => $csrfToken,
    'azione' => 'prenota',
    'data' => '2029-02-10',
    'ora_inizio' => '09:00',
    'ora_fine' => '10:00',
    'capienza_max' => '4',
    'trainer_id' => '',
    'note' => 'Prenotazione di test multipla',
    'clienti_nomi' => [$nome1, $nome2, $nome3]
];

// Eseguiamo la logica esatta presente in admin/appuntamenti.php
$nomiClienti = [];
if (!empty($_POST['clienti_nomi']) && is_array($_POST['clienti_nomi'])) {
    foreach ($_POST['clienti_nomi'] as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $nomiClienti[] = $n;
        }
    }
}

$clientiIds = [];
foreach ($nomiClienti as $nomeC) {
    $clientiIds[] = \App\Models\Cliente::trovaOCreaPerNome($nomeC, (int)$_SESSION['user']['id']);
}

$appuntamentiIds = \App\Models\Appuntamento::creaMultiplo(
    $clientiIds,
    null,
    $_POST['data'],
    $_POST['ora_inizio'] . ':00',
    $_POST['ora_fine'] . ':00',
    (int)$_POST['capienza_max'],
    $_POST['note']
);

echo "POST processing simulated successfully!\n";
echo "Created " . count($clientiIds) . " clients: " . implode(', ', $clientiIds) . "\n";
echo "Created " . count($appuntamentiIds) . " appointments: " . implode(', ', $appuntamentiIds) . "\n";

// Cleanup
$pdo = \App\Database::pdo();
$inApp = implode(',', array_map('intval', $appuntamentiIds));
$inCli = implode(',', array_map('intval', $clientiIds));

$pdo->exec("DELETE FROM presenze WHERE appuntamento_id IN ($inApp)");
$pdo->exec("DELETE FROM appuntamenti WHERE id IN ($inApp)");
$pdo->exec("DELETE FROM audit_log WHERE entita = 'appuntamento' AND entita_id IN ($inApp)");

$pdo->exec("DELETE FROM presenze WHERE cliente_id IN ($inCli)");
$pdo->exec("DELETE FROM appuntamenti WHERE cliente_id IN ($inCli)");
$pdo->exec("DELETE FROM clienti WHERE id IN ($inCli)");
$pdo->exec("DELETE FROM audit_log WHERE entita = 'cliente' AND entita_id IN ($inCli)");

echo "Cleanup completed successfully!\n";
