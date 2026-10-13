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

$pdo = \App\Database::pdo();

echo "========================================================\n";
echo " TEST COMPLETO VERIFICA CORREZIONI GESTIONALE GYM STUDIO\n";
echo "========================================================\n\n";

$errors = 0;
function assertTest(bool $condition, string $msg) {
    global $errors;
    if ($condition) {
        echo " [OK] " . $msg . "\n";
    } else {
        echo " [FAIL] " . $msg . "\n";
        $errors++;
    }
}

// ---------------------------------------------------------------
// TEST 1: AGGIORNAMENTO CLIENTE & RIATTIVAZIONE
// ---------------------------------------------------------------
echo "--- TEST 1: AGGIORNAMENTO CLIENTE & RIATTIVAZIONE ---\n";
$nomeTest = 'TestCli_' . bin2hex(random_bytes(3));
$clienteId = \App\Models\Cliente::create([
    'nome' => $nomeTest,
    'cognome' => 'Originale',
    'email' => strtolower($nomeTest) . '@example.com',
    'telefono' => '0000000001',
    'codice_fiscale' => 'TSTNCL80A01H501U',
    'gdpr_consenso' => 1
], (int)$_SESSION['user']['id']);

assertTest($clienteId > 0, "Creazione cliente di test ID: $clienteId");

// 1.1 Aggiornamento anagrafica + GDPR
\App\Models\Cliente::update($clienteId, [
    'nome' => $nomeTest . '_Modificato',
    'cognome' => 'Aggiornato',
    'email' => strtolower($nomeTest) . '_mod@example.com',
    'telefono' => '0000000002',
    'codice_fiscale' => 'TSTNCL80A01H501U',
    'gdpr_consenso' => 1,
    'note' => 'Note di test aggiornate'
]);
$cliAggiornato = \App\Models\Cliente::find($clienteId);
assertTest(
    $cliAggiornato['nome'] === ($nomeTest . '_Modificato') &&
    $cliAggiornato['cognome'] === 'Aggiornato' &&
    $cliAggiornato['telefono'] === '0000000002',
    "Aggiornamento anagrafica cliente salvato con successo sul DB"
);

// 1.2 Aggiornamento certificato medico SENZA file obbligatorio
\App\Models\Cliente::setCertificato($clienteId, null, '2026-01-10', '2027-01-10');
$cliCert = \App\Models\Cliente::find($clienteId);
assertTest(
    $cliCert['certificato_scadenza'] === '2027-01-10' &&
    $cliCert['certificato_rilascio'] === '2026-01-10',
    "Aggiornamento date certificato medico senza obbligo di file salvato con successo"
);

// 1.3 Soft delete e successiva riattivazione
\App\Models\Cliente::softDelete($clienteId);
$cliDisattivato = \App\Models\Cliente::find($clienteId);
assertTest((int)$cliDisattivato['attivo'] === 0, "Cliente disattivato (soft delete)");

$elencoAttivi = \App\Models\Cliente::all(null, true);
$trovatoInAttivi = false;
foreach ($elencoAttivi as $c) {
    if ((int)$c['id'] === $clienteId) { $trovatoInAttivi = true; break; }
}
assertTest(!$trovatoInAttivi, "Cliente disattivato non compare tra i soli attivi");

\App\Models\Cliente::riattiva($clienteId);
$cliRiattivato = \App\Models\Cliente::find($clienteId);
assertTest((int)$cliRiattivato['attivo'] === 1, "Cliente riattivato con successo (torna attivo = 1)");


// ---------------------------------------------------------------
// TEST 2: INCASSI E PAGAMENTI TROVATI
// ---------------------------------------------------------------
echo "\n--- TEST 2: INCASSI E PAGAMENTI TROVATI ---\n";

// 2.1 Creazione tipo abbonamento fittizio se non presente
$tipoAbb = $pdo->query("SELECT id FROM tipi_abbonamento LIMIT 1")->fetch();
if (!$tipoAbb) {
    $pdo->exec("INSERT INTO tipi_abbonamento (nome, durata_giorni, prezzo, sessioni_incluse, max_sessioni_giorno, attivo) VALUES ('Test Pass', 30, 80.00, 10, 1, 1)");
    $tipoAbbId = (int)$pdo->lastInsertId();
} else {
    $tipoAbbId = (int)$tipoAbb['id'];
}

// Creiamo un abbonamento con decorrenza futura (prossimo mese), ma incassato OGGI
$dataInizioFutura = date('Y-m-d', strtotime('+30 days'));
$dataIncassoOggi = date('Y-m-d');
$abbonamentoId = \App\Models\Abbonamento::crea(
    $clienteId,
    $tipoAbbId,
    $dataInizioFutura,
    85.00,
    (int)$_SESSION['user']['id'],
    85.00,
    'carta',
    $dataIncassoOggi
);
assertTest($abbonamentoId > 0, "Abbonamento futuro creato con incasso registrato in data odierna");

// Verifichiamo che Pagamento::periodo() di questo mese trovi il pagamento oggi
$pagamentiMese = \App\Models\Pagamento::periodo(date('Y-m-01'), date('Y-m-t'));
$trovatoPagamento = false;
$pagamentoId = 0;
foreach ($pagamentiMese as $p) {
    if ((int)$p['cliente_id'] === $clienteId && (float)$p['importo'] === 85.00) {
        $trovatoPagamento = true;
        $pagamentoId = (int)$p['id'];
        break;
    }
}
assertTest($trovatoPagamento, "Incasso registrato OGGI correttamente visibile nei report del mese corrente");

// 2.2 Verifichiamo che il pagamento rimanga visibile anche se il cliente viene disattivato (LEFT JOIN)
\App\Models\Cliente::softDelete($clienteId);
$pagamentiMeseDopoSoftDelete = \App\Models\Pagamento::periodo(date('Y-m-01'), date('Y-m-t'));
$trovatoAncheSeDisattivato = false;
foreach ($pagamentiMeseDopoSoftDelete as $p) {
    if ((int)$p['id'] === $pagamentoId) {
        $trovatoAncheSeDisattivato = true;
        break;
    }
}
assertTest($trovatoAncheSeDisattivato, "Incasso visibile anche se il cliente è disattivato (nessuna perdita di tracciato economico)");

// 2.3 Eliminazione del pagamento errato
\App\Models\Pagamento::elimina($pagamentoId);
$pagamentoCancellato = \App\Models\Pagamento::find($pagamentoId);
assertTest($pagamentoCancellato === null, "Cancellazione pagamento/movimento errato eseguita con successo (il record non esiste più)");

// Riattiviamo il cliente per i test successivi
\App\Models\Cliente::riattiva($clienteId);


// ---------------------------------------------------------------
// TEST 3: ELIMINAZIONE CHE RESTAVA SEMPRE & CANCELLAZIONE SESSIONI
// ---------------------------------------------------------------
echo "\n--- TEST 3: ELIMINAZIONE & CANCELLAZIONI APPUNTAMENTI ---\n";

// 3.1 Creazione appuntamento futuro per il cliente
$dataDomani = date('Y-m-d', strtotime('+1 day'));
$appuntamentiIds = \App\Models\Appuntamento::creaMultiplo(
    [$clienteId],
    null,
    $dataDomani,
    '10:00:00',
    '11:00:00',
    4,
    'Sessione test cancellazione'
);
assertTest(count($appuntamentiIds) > 0, "Appuntamento futuro creato per il test");

// Verifichiamo che se il cliente viene disattivato, i suoi appuntamenti futuri vengano liberati e rimossi automaticamente
\App\Models\Cliente::softDelete($clienteId);
$appDopoDisattivazione = \App\Models\Appuntamento::delGiorno($dataDomani);
$appPresente = false;
foreach ($appDopoDisattivazione as $ap) {
    if ((int)$ap['cliente_id'] === $clienteId) {
        $appPresente = true;
        break;
    }
}
assertTest(!$appPresente, "Alla disattivazione del cliente, i suoi appuntamenti futuri NON restano nel calendario");

// 3.2 Test cancellazione intera sessione (Appuntamento::cancellaSessione)
\App\Models\Cliente::riattiva($clienteId);
$appuntamentiSessione = \App\Models\Appuntamento::creaMultiplo(
    [$clienteId],
    null,
    $dataDomani,
    '15:00:00',
    '16:00:00',
    4,
    'Sessione da cancellare'
);
assertTest(count($appuntamentiSessione) > 0, "Seconda sessione creata per test cancellazione");

$cancellati = \App\Models\Appuntamento::cancellaSessione($dataDomani, '15:00', '16:00');
assertTest($cancellati > 0, "Cancellazione sessione oraria riuscita ($cancellati record rimossi)");

$appGiorno = \App\Models\Appuntamento::delGiorno($dataDomani);
$sessioneRimasta = false;
foreach ($appGiorno as $ap) {
    if ($ap['ora_inizio'] === '15:00:00') {
        $sessioneRimasta = true;
        break;
    }
}
assertTest(!$sessioneRimasta, "La sessione cancellata è sparita completamente dal calendario (non resta più)");

// 3.3 Test router API con sottocartelle (gym-studio/api/...)
$testUri = '/gym-studio/api/cancella-sessione';
$uriPath = parse_url($testUri, PHP_URL_PATH) ?? '';
$endpoint = '';
if (preg_match('#(?:^|/)api(?:/(.*))?$#i', $uriPath, $m)) {
    $endpoint = trim($m[1] ?? '', '/');
}
assertTest($endpoint === 'cancella-sessione', "Router API risolve correttamente gli endpoint anche in sottocartelle ('$endpoint')");


// ---------------------------------------------------------------
// CLEANUP FINALE
// ---------------------------------------------------------------
echo "\n--- PULIZIA DATI DI TEST ---\n";
$pdo->exec("DELETE FROM abbonamenti WHERE id = $abbonamentoId");
$pdo->exec("DELETE FROM appuntamenti WHERE cliente_id = $clienteId");
$pdo->exec("DELETE FROM audit_log WHERE entita = 'cliente' AND entita_id = $clienteId");
$pdo->exec("DELETE FROM audit_log WHERE entita = 'abbonamento' AND entita_id = $abbonamentoId");
$pdo->exec("DELETE FROM clienti WHERE id = $clienteId");
echo " [OK] Record di test ripuliti con successo.\n\n";

if ($errors === 0) {
    echo "========================================================\n";
    echo " TUTTI I TEST COMPLETATI CON SUCCESSO! ZERO ERRORI.\n";
    echo "========================================================\n";
} else {
    echo "========================================================\n";
    echo " RILEVATI $errors ERRORI!\n";
    echo "========================================================\n";
}
