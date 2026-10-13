<?php
declare(strict_types=1);

/**
 * Job da pianificare quotidianamente (cron job del pannello hosting, orario consigliato: 03:00).
 * Comando CLI: php /percorso/progetto/cron/aggiorna_scaduti.php
 * Segna come "scaduto" gli abbonamenti attivi la cui data e' passata.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Models\Abbonamento;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Eseguibile solo da riga di comando (cron).');
}

$n = Abbonamento::aggiornaScaduti();
echo date('Y-m-d H:i:s') . " - Abbonamenti marcati come scaduti: {$n}" . PHP_EOL;
