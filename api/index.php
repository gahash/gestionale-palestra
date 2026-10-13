<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Database;
use App\Config;
use App\Models\Lead;
use App\Models\Appuntamento;
use App\Models\Abbonamento;
use App\Models\Cliente;
use App\Services\WhatsappService;

header('Content-Type: application/json; charset=utf-8');
// API pensata per essere consumata dallo stesso dominio (landing + gestionale).
// Nessun dominio hardcoded: se in futuro serve consumarla da un altro origin,
// aggiungere qui l'header Access-Control-Allow-Origin con il dominio autorizzato via .env.

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

/** Limite semplice anti-abuso per endpoint pubblici (basato su file, senza dipendenze). */
function rateLimit(string $key, int $maxRichieste, int $finestraSecondi): bool
{
    $dir = sys_get_temp_dir() . '/gymstudio_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $finestraSecondi));
    if (count($hits) >= $maxRichieste) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits));
    return true;
}

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Estrai in modo affidabile il percorso dopo /api/ o api/ (funziona sia in root sia in sottocartelle es. /gym-studio/api/...)
if (preg_match('#(?:^|/)api(?:/(.*))?$#i', $uriPath, $m)) {
    $path = trim($m[1] ?? '', '/');
} else {
    $path = trim(preg_replace('#^api/?#i', '', trim($uriPath, '/')), '/');
}
$segments = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

try {
    // -----------------------------------------------------------------
    // PUBBLICO: invio form contatto/prenotazione dalla landing page
    // -----------------------------------------------------------------
    if ($segments === ['leads'] && $method === 'POST') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!rateLimit('lead_' . $ip, 5, 300)) {
            respond(['success' => false, 'error' => 'Troppe richieste, riprova tra qualche minuto.'], 429);
        }

        $d = input();

        // Honeypot anti-spam: campo nascosto che un utente reale non compila mai.
        if (!empty($d['sito_web'])) {
            respond(['success' => true]); // finge successo, scarta silenziosamente
        }

        $nome = trim((string) ($d['nome'] ?? ''));
        $telefono = trim((string) ($d['telefono'] ?? ''));
        if ($nome === '' || $telefono === '') {
            respond(['success' => false, 'error' => 'Nome e telefono sono obbligatori.'], 422);
        }
        if (empty($d['gdpr_consenso'])) {
            respond(['success' => false, 'error' => 'E\' necessario accettare il trattamento dei dati (privacy).'], 422);
        }
        if (isset($d['email']) && $d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'error' => 'Email non valida.'], 422);
        }

        $id = Lead::creaDaLanding($d, $ip);

        if (Config::bool('MAIL_ENABLED', false)) {
            $to = Config::get('MAIL_TO_STAFF');
            if ($to) {
                $subject = '[' . Config::get('APP_NAME', 'Gym Studio') . '] Nuova richiesta dalla landing page';
                $body = "Nome: {$nome}\nTelefono: {$telefono}\nEmail: " . ($d['email'] ?? '-') .
                    "\nServizio: " . ($d['servizio_interesse'] ?? '-') .
                    "\nGiorno preferito: " . ($d['giorno_preferito'] ?? '-') .
                    "\nFascia oraria: " . ($d['fascia_oraria'] ?? '-') .
                    "\nMessaggio: " . ($d['messaggio'] ?? '-');
                @mail($to, $subject, $body, 'From: ' . Config::get('MAIL_FROM', 'noreply@example.com'));
            }
        }

        respond(['success' => true, 'id' => $id]);
    }

    // -----------------------------------------------------------------
    // Da qui in poi: endpoint riservati al gestionale (richiedono login)
    // -----------------------------------------------------------------
    Auth::start();
    if (!Auth::check()) {
        respond(['success' => false, 'error' => 'Non autenticato'], 401);
    }
    $user = Auth::user();

    if (($segments[0] ?? '') === 'appuntamenti' && $method === 'GET') {
        $data = $_GET['data'] ?? date('Y-m-d');
        respond(['success' => true, 'data' => Appuntamento::delGiorno($data)]);
    }

    if (($segments[0] ?? '') === 'appuntamenti' && $method === 'POST' && !isset($segments[1])) {
        $d = input();
        try {
            if (!empty($d['clienti_nomi']) && is_array($d['clienti_nomi'])) {
                $cids = [];
                foreach ($d['clienti_nomi'] as $cn) {
                    $cn = trim((string) $cn);
                    if ($cn !== '') {
                        $cids[] = Cliente::trovaOCreaPerNome($cn, $user['id'] ?? null);
                    }
                }
                $ids = Appuntamento::creaMultiplo(
                    $cids,
                    !empty($d['trainer_id']) ? (int) $d['trainer_id'] : null,
                    $d['data'],
                    $d['ora_inizio'],
                    $d['ora_fine'],
                    (int) ($d['capienza_max'] ?? 4),
                    $d['note'] ?? null
                );
                respond(['success' => true, 'ids' => $ids]);
            } else {
                $id = Appuntamento::crea(
                    (int) $d['cliente_id'],
                    !empty($d['trainer_id']) ? (int) $d['trainer_id'] : null,
                    $d['data'],
                    $d['ora_inizio'],
                    $d['ora_fine'],
                    (int) ($d['capienza_max'] ?? 4),
                    $d['note'] ?? null
                );
                respond(['success' => true, 'id' => $id]);
            }
        } catch (\RuntimeException $e) {
            respond(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    if (($segments[0] ?? '') === 'appuntamenti' && isset($segments[1]) && ($segments[2] ?? '') === 'stato' && $method === 'POST') {
        $d = input();
        Appuntamento::cambiaStato((int) $segments[1], $d['stato']);
        respond(['success' => true]);
    }

    if (($segments[0] ?? '') === 'appuntamenti' && ($segments[1] ?? '') === 'cancella-multiplo' && $method === 'POST') {
        $d = input();
        $ids = (array) ($d['ids'] ?? []);
        $count = Appuntamento::cancellaMultiplo($ids);
        respond(['success' => true, 'cancellati' => $count]);
    }

    if (($segments[0] ?? '') === 'appuntamenti' && ($segments[1] ?? '') === 'cancella-sessione' && $method === 'POST') {
        $d = input();
        $dataApp = (string) ($d['data'] ?? '');
        $oraInizio = (string) ($d['ora_inizio'] ?? '');
        $oraFine = (string) ($d['ora_fine'] ?? '');
        $count = Appuntamento::cancellaSessione($dataApp, $oraInizio, $oraFine);
        respond(['success' => true, 'cancellati' => $count]);
    }

    if (($segments[0] ?? '') === 'scadenziario' && $method === 'GET') {
        $filtro = $_GET['filtro'] ?? 30;
        $q = trim((string) ($_GET['q'] ?? ''));
        respond(['success' => true, 'data' => Abbonamento::scadenziario($filtro, $q ?: null)]);
    }

    if (($segments[0] ?? '') === 'whatsapp' && ($segments[1] ?? '') === 'invia' && $method === 'POST') {
        if (!Auth::hasRole('admin', 'staff')) {
            respond(['success' => false, 'error' => 'Permessi insufficienti'], 403);
        }
        $d = input();
        $cliente = Cliente::find((int) $d['cliente_id']);
        if (!$cliente) {
            respond(['success' => false, 'error' => 'Cliente non trovato'], 404);
        }
        $result = WhatsappService::invia(
            (int) $cliente['id'],
            $d['telefono'] ?? $cliente['telefono'],
            $d['template'],
            $d['placeholders'] ?? [],
            $user['id']
        );
        respond(['success' => true] + $result);
    }

    if (($segments[0] ?? '') === 'clienti' && $method === 'GET') {
        respond(['success' => true, 'data' => Cliente::all($_GET['q'] ?? null)]);
    }

    respond(['success' => false, 'error' => 'Endpoint non trovato'], 404);
} catch (\Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    respond(['success' => false, 'error' => Config::bool('APP_DEBUG', false) ? $e->getMessage() : 'Errore interno'], 500);
}
