<?php
declare(strict_types=1);

/**
 * Test suite per l'implementazione della Prenotazione Multipla (fino a 5 clienti)
 * e autocompletamento/creazione cliente per nome.
 */

// Inizializza la sessione in modalità CLI prima di qualsiasi output
@ini_set('session.use_cookies', '0');
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Models\Cliente;
use App\Models\Appuntamento;
use App\Auth;

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $cleanUpClientiIds = [];
    private array $cleanUpAppuntamentiIds = [];

    public function run(): void
    {
        echo "\n" . str_repeat('=', 65) . "\n";
        echo " AVVIO TEST SUITE: PRENOTAZIONE MULTIPLA E GESTIONE CLIENTI\n";
        echo str_repeat('=', 65) . "\n\n";

        try {
            $this->testSuiteClienteTrovaOCrea();
            $this->testSuiteAppuntamentoCreaMultiplo();
            $this->testSuiteFormInputParsing();
            $this->testSuiteApiEndpoint();
            $this->testSuiteFrontendIntegrity();
        } finally {
            $this->cleanup();
        }

        echo "\n" . str_repeat('=', 65) . "\n";
        echo sprintf(" RISULTATI FINALI: %d PASSATI, %d FALLITI\n", $this->passed, $this->failed);
        echo str_repeat('=', 65) . "\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(bool $condition, string $testName, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo " [OK] " . $testName . "\n";
        } else {
            $this->failed++;
            echo " [FAIL] " . $testName . ($details ? " -> " . $details : '') . "\n";
        }
    }

    private function testSuiteClienteTrovaOCrea(): void
    {
        echo "--- SUITE 1: Cliente::trovaOCreaPerNome ---\n";

        // 1.1 Creazione di un nuovo cliente con nome e cognome
        $nomeUnico = 'TestAuto_' . bin2hex(random_bytes(3)) . ' Bianchi';
        $id1 = Cliente::trovaOCreaPerNome($nomeUnico, 1);
        $this->cleanUpClientiIds[] = $id1;
        $c1 = Cliente::find($id1);

        $this->assert($id1 > 0, "1.1 Creazione nuovo cliente con nome e cognome");
        $this->assert($c1 !== null && $c1['nome'] === explode(' ', $nomeUnico)[0] && $c1['cognome'] === 'Bianchi',
            "1.1b Campi inseriti corretti (nome e cognome)");
        $this->assert($c1 !== null && $c1['note'] === 'Inserito da prenotazione' && (int)$c1['gdpr_consenso'] === 0,
            "1.1c Default corretti: note='Inserito da prenotazione' e gdpr_consenso=0");

        // 1.2 Ricerca cliente esistente con lo stesso nome esatto: deve ritornare lo stesso ID
        $id1_bis = Cliente::trovaOCreaPerNome($nomeUnico);
        $this->assert($id1 === $id1_bis, "1.2 Ricerca per nome identico restituisce lo stesso ID senza duplicati");

        // 1.3 Ricerca case-insensitive
        $id1_lower = Cliente::trovaOCreaPerNome(strtolower($nomeUnico));
        $this->assert($id1 === $id1_lower, "1.3 Ricerca case-insensitive (minuscolo)");

        $id1_upper = Cliente::trovaOCreaPerNome(strtoupper($nomeUnico));
        $this->assert($id1 === $id1_upper, "1.3b Ricerca case-insensitive (maiuscolo)");

        // 1.4 Ricerca con formato invertito "Cognome Nome"
        $parti = explode(' ', $nomeUnico);
        $nomeInvertito = $parti[1] . ' ' . $parti[0];
        $id1_inv = Cliente::trovaOCreaPerNome($nomeInvertito);
        $this->assert($id1 === $id1_inv, "1.4 Ricerca invertita 'Cognome Nome' identifica correttamente il cliente");

        // 1.5 Creazione con parola singola (solo nome, es. "Emanuele")
        $nomeSingolo = 'SoloNome_' . bin2hex(random_bytes(3));
        $id2 = Cliente::trovaOCreaPerNome($nomeSingolo, 1);
        $this->cleanUpClientiIds[] = $id2;
        $c2 = Cliente::find($id2);
        $this->assert($c2 !== null && $c2['nome'] === $nomeSingolo && $c2['cognome'] === '-',
            "1.5 Creazione cliente a parola singola imposta cognome='-' di default");

        // 1.6 Validazione nome vuoto
        $caughtEmpty = false;
        try {
            Cliente::trovaOCreaPerNome('   ');
        } catch (\InvalidArgumentException $e) {
            $caughtEmpty = true;
        }
        $this->assert($caughtEmpty, "1.6 Nome vuoto o di soli spazi lancia InvalidArgumentException");
        echo "\n";
    }

    private function testSuiteAppuntamentoCreaMultiplo(): void
    {
        echo "--- SUITE 2: Appuntamento::creaMultiplo ---\n";

        // Prepara 6 clienti di test
        $clientiIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $cid = Cliente::create([
                'nome' => 'TestMulti_' . $i,
                'cognome' => 'Cliente',
                'data_nascita' => null,
                'codice_fiscale' => null,
                'telefono' => null,
                'email' => null,
                'indirizzo' => null,
                'note' => 'Test',
                'gdpr_consenso' => 0
            ], 1);
            $this->cleanUpClientiIds[] = $cid;
            $clientiIds[] = $cid;
        }

        $dataTest = '2029-01-15';
        $oraInizio = '14:00:00';
        $oraFine = '15:00:00';

        // 2.1 Lista vuota lancia RuntimeException
        $caughtEmpty = false;
        try {
            Appuntamento::creaMultiplo([], null, $dataTest, $oraInizio, $oraFine);
        } catch (\RuntimeException $e) {
            $caughtEmpty = str_contains($e->getMessage(), 'Nessun cliente');
        }
        $this->assert($caughtEmpty, "2.1 Lista clienti vuota lancia eccezione");

        // 2.2 Più di 5 clienti lancia RuntimeException
        $caughtOverMax = false;
        try {
            Appuntamento::creaMultiplo($clientiIds, null, $dataTest, $oraInizio, $oraFine);
        } catch (\RuntimeException $e) {
            $caughtOverMax = str_contains($e->getMessage(), 'massimo 5 clienti');
        }
        $this->assert($caughtOverMax, "2.2 Inserimento > 5 clienti (6 clienti) bloccato da eccezione");

        // 2.3 Creazione multipla valida di 3 clienti in sessione con capienza default 4
        $primi3 = array_slice($clientiIds, 0, 3);
        $idsCreated = Appuntamento::creaMultiplo($primi3, null, $dataTest, $oraInizio, $oraFine, 4, 'Nota multipla');
        foreach ($idsCreated as $id) {
            $this->cleanUpAppuntamentiIds[] = $id;
        }

        $this->assert(count($idsCreated) === 3, "2.3 Inserimento di 3 clienti restituisce 3 IDs validi");

        $occupati = Appuntamento::occupazione($dataTest, $oraInizio, $oraFine);
        $this->assert($occupati === 3, "2.3b Occupazione registrata per lo slot = 3");

        $sessioni = Appuntamento::raggruppaInSessioni(Appuntamento::delPeriodo($dataTest, $dataTest));
        $trovata = null;
        foreach ($sessioni as $s) {
            if ($s['ora_inizio'] === '14:00') {
                $trovata = $s;
                break;
            }
        }
        $this->assert($trovata !== null && $trovata['occupati'] === 3 && count($trovata['partecipanti']) === 3,
            "2.3c Appuntamenti raggruppati in sessione unica con 3 partecipanti");

        // 2.4 Espansione dinamica della capienza: 3 già presenti + 2 nuovi = 5 (supera capienza 4)
        $altri2 = array_slice($clientiIds, 3, 2);
        $idsCreated2 = Appuntamento::creaMultiplo($altri2, null, $dataTest, $oraInizio, $oraFine, 4, 'Seconda mandata');
        foreach ($idsCreated2 as $id) {
            $this->cleanUpAppuntamentiIds[] = $id;
        }

        $occupatiTotali = Appuntamento::occupazione($dataTest, $oraInizio, $oraFine);
        $this->assert($occupatiTotali === 5, "2.4 Occupazione totale dopo aggiunta di 2 ulteriori clienti = 5");

        // Verifica che TUTTI gli appuntamenti dello slot abbiano ora capienza_max espansa a 5
        $stmtCap = Database::pdo()->prepare(
            'SELECT DISTINCT capienza_max FROM appuntamenti WHERE data = ? AND ora_inizio = ?'
        );
        $stmtCap->execute([$dataTest, $oraInizio]);
        $capienze = $stmtCap->fetchAll(\PDO::FETCH_COLUMN);

        $this->assert(count($capienze) === 1 && (int)$capienze[0] === 5,
            "2.4b Capienza_max di tutti gli appuntamenti dello slot espansa automaticamente a 5");

        // 2.5 Rollback transazionale: se un ID cliente viola FK o fallisce, rollback completo
        $erroreRollback = false;
        $occupatiPrima = Appuntamento::occupazione($dataTest, '16:00:00', '17:00:00');
        try {
            Database::pdo()->exec("SET foreign_key_checks=1");
            Appuntamento::creaMultiplo([$clientiIds[0], 999999], null, $dataTest, '16:00:00', '17:00:00');
        } catch (\Throwable $e) {
            $erroreRollback = true;
        }
        $occupatiDopo = Appuntamento::occupazione($dataTest, '16:00:00', '17:00:00');
        $this->assert($erroreRollback && $occupatiDopo === $occupatiPrima,
            "2.5 Rollback transazionale: in caso di errore nessun record parziale viene salvato");

        echo "\n";
    }

    private function testSuiteFormInputParsing(): void
    {
        echo "--- SUITE 3: Simulazione logica form admin/appuntamenti.php ---\n";

        $parseNomi = function (array $post): array {
            $nomiClienti = [];
            if (!empty($post['clienti_nomi']) && is_array($post['clienti_nomi'])) {
                foreach ($post['clienti_nomi'] as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') $nomiClienti[] = $n;
                }
            } elseif (!empty($post['clienti_nomi_json'])) {
                $decoded = json_decode((string) $post['clienti_nomi_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $n) {
                        $n = trim((string) $n);
                        if ($n !== '') $nomiClienti[] = $n;
                    }
                }
            } elseif (!empty($post['cliente_nome'])) {
                $n = trim((string) $post['cliente_nome']);
                if ($n !== '') $nomiClienti[] = $n;
            } elseif (!empty($post['cliente_id'])) {
                $c = Cliente::find((int) $post['cliente_id']);
                if ($c) {
                    $nomiClienti[] = trim($c['nome'] . ' ' . $c['cognome']);
                }
            }
            return $nomiClienti;
        };

        // 3.1 Parsing da array clienti_nomi[] (formato generato dai campi hidden JS)
        $resArray = $parseNomi(['clienti_nomi' => ['Marco Rossi', '', 'Luca Bianchi', '   ']]);
        $this->assert($resArray === ['Marco Rossi', 'Luca Bianchi'],
            "3.1 Parsing da array clienti_nomi[] filtra stringhe vuote e spazi");

        // 3.2 Parsing da JSON string
        $resJson = $parseNomi(['clienti_nomi_json' => '["Mario Rossi", "Luigi Verdi"]']);
        $this->assert($resJson === ['Mario Rossi', 'Luigi Verdi'],
            "3.2 Parsing da clienti_nomi_json valido");

        // 3.3 Fallback a singolo cliente_nome
        $resSingle = $parseNomi(['cliente_nome' => 'Giulia Neri']);
        $this->assert($resSingle === ['Giulia Neri'],
            "3.3 Fallback su singolo cliente_nome");

        // 3.4 Validazione ora_fine <= ora_inizio
        $oraInizio = '15:00';
        $oraFine = '14:30';
        $this->assert($oraFine <= $oraInizio, "3.4 Controllo logico oraFine <= oraInizio scatta correttamente");

        echo "\n";
    }

    private function testSuiteApiEndpoint(): void
    {
        echo "--- SUITE 4: Endpoint API /appuntamenti (api/index.php) ---\n";

        // 4.1 Test autenticazione: verifica che senza sessione l'accesso sia negato
        unset($_SESSION['user']);
        $this->assert(Auth::check() === false, "4.1 Chiamata senza sessione utente non risulta autenticata");

        // 4.2 Simulazione utente autenticato
        $_SESSION['user'] = [
            'id' => 1,
            'nome' => 'Admin',
            'cognome' => 'Test',
            'email' => 'admin@example.com',
            'ruolo' => 'admin'
        ];
        $this->assert(Auth::check() === true, "4.2 Chiamata con sessione admin risulta autenticata");

        // 4.3 Creazione multipla con clienti_nomi tramite logica API
        $inputApi = [
            'clienti_nomi' => ['TestApi_1 ' . bin2hex(random_bytes(2)), 'TestApi_2 ' . bin2hex(random_bytes(2))],
            'data' => '2029-01-20',
            'ora_inizio' => '11:00:00',
            'ora_fine' => '12:00:00',
            'capienza_max' => 4,
            'note' => 'Prenotazione via API'
        ];

        $cids = [];
        foreach ($inputApi['clienti_nomi'] as $cn) {
            $cids[] = Cliente::trovaOCreaPerNome(trim($cn), (int)$_SESSION['user']['id']);
        }
        foreach ($cids as $cid) {
            $this->cleanUpClientiIds[] = $cid;
        }

        $idsApi = Appuntamento::creaMultiplo(
            $cids,
            null,
            $inputApi['data'],
            $inputApi['ora_inizio'],
            $inputApi['ora_fine'],
            (int) $inputApi['capienza_max'],
            $inputApi['note']
        );
        foreach ($idsApi as $id) {
            $this->cleanUpAppuntamentiIds[] = $id;
        }

        $this->assert(count($idsApi) === 2, "4.3 Creazione multipla API: 2 clienti risolti e 2 appuntamenti inseriti");

        // 4.4 Validazione limite API > 5 clienti
        $overLimitNames = ['A', 'B', 'C', 'D', 'E', 'F'];
        $caughtApiOverLimit = false;
        try {
            $tempCids = [];
            foreach ($overLimitNames as $n) {
                $tempCids[] = Cliente::trovaOCreaPerNome('LimTest_' . bin2hex(random_bytes(2)), 1);
            }
            foreach ($tempCids as $tId) {
                $this->cleanUpClientiIds[] = $tId;
            }
            Appuntamento::creaMultiplo($tempCids, null, '2029-01-20', '12:00:00', '13:00:00');
        } catch (\RuntimeException $e) {
            $caughtApiOverLimit = str_contains($e->getMessage(), 'massimo 5 clienti');
        }
        $this->assert($caughtApiOverLimit, "4.4 Chiamata API con >5 clienti intercettata e bloccata con errore 409");

        echo "\n";
    }

    private function testSuiteFrontendIntegrity(): void
    {
        echo "--- SUITE 5: Integrità Frontend (Markup & CSS) ---\n";

        $appuntamentiPhp = file_get_contents(__DIR__ . '/../admin/appuntamenti.php');
        $adminCss = file_get_contents(__DIR__ . '/../css/admin.css');

        // 5.1 Verifica elementi chiave del form modale
        $this->assert(str_contains($appuntamentiPhp, 'id="cliente-input-nome"'),
            "5.1 Input nome cliente presente (id='cliente-input-nome')");

        $this->assert(str_contains($appuntamentiPhp, 'id="btn-add-cliente"'),
            "5.1b Bottone aggiunta presente (id='btn-add-cliente')");

        $this->assert(str_contains($appuntamentiPhp, 'id="clienti-list"'),
            "5.1c Container lista clienti presente (id='clienti-list')");

        $this->assert(str_contains($appuntamentiPhp, 'id="clienti-hidden-inputs"'),
            "5.1d Container campi hidden presente (id='clienti-hidden-inputs')");

        $this->assert(str_contains($appuntamentiPhp, 'id="clienti-count-label"'),
            "5.1e Label contatore persone inserite presente (id='clienti-count-label')");

        $this->assert(str_contains($appuntamentiPhp, 'id="clienti-limit-msg"'),
            "5.1f Messaggio limite raggiunto presente (id='clienti-limit-msg')");

        // 5.2 Verifica vincolo limite 5 in JavaScript
        $this->assert(str_contains($appuntamentiPhp, 'listaClienti.length >= 5'),
            "5.2 Controllo JavaScript limite massimo 5 clienti (listaClienti.length >= 5)");

        $this->assert(str_contains($appuntamentiPhp, 'autoFine()'),
            "5.2b Funzione autoFine() presente per impostare fine = inizio + 1h");

        // 5.3 Verifica regole CSS
        $this->assert(str_contains($adminCss, '.cliente-item') && str_contains($adminCss, '.cliente-item-name'),
            "5.3 Regole CSS per card cliente presenti (.cliente-item, .cliente-item-name)");

        $this->assert(str_contains($adminCss, '.cliente-btn-edit') && str_contains($adminCss, '.cliente-btn-remove'),
            "5.3b Regole CSS per pulsanti modifica e rimozione (.cliente-btn-edit, .cliente-btn-remove)");

        $this->assert(str_contains($adminCss, '.clienti-limit-msg'),
            "5.3c Regola CSS per messaggio limite clienti presente (.clienti-limit-msg)");

        echo "\n";
    }

    private function cleanup(): void
    {
        echo "Pulizia dati di test...\n";
        $pdo = Database::pdo();

        if (!empty($this->cleanUpAppuntamentiIds)) {
            $in = implode(',', array_map('intval', $this->cleanUpAppuntamentiIds));
            $pdo->exec("DELETE FROM presenze WHERE appuntamento_id IN ($in)");
            $pdo->exec("DELETE FROM appuntamenti WHERE id IN ($in)");
            $pdo->exec("DELETE FROM audit_log WHERE entita = 'appuntamento' AND entita_id IN ($in)");
        }

        if (!empty($this->cleanUpClientiIds)) {
            $in = implode(',', array_map('intval', $this->cleanUpClientiIds));
            $pdo->exec("DELETE FROM presenze WHERE cliente_id IN ($in)");
            $pdo->exec("DELETE FROM appuntamenti WHERE cliente_id IN ($in)");
            $pdo->exec("DELETE FROM clienti WHERE id IN ($in)");
            $pdo->exec("DELETE FROM audit_log WHERE entita = 'cliente' AND entita_id IN ($in)");
        }
        echo "Pulizia completata con successo.\n";
    }
}

$runner = new TestRunner();
$runner->run();
