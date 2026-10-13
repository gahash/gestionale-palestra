<?php
declare(strict_types=1);

/**
 * Backup automatico del database (requisito enterprise/GDPR).
 * Da pianificare quotidianamente via cron job del pannello hosting:
 *   php /percorso/progetto/cron/backup_database.php
 *
 * Non usa mysqldump via shell (spesso non disponibile su hosting condiviso):
 * esporta lo schema riga per riga tramite PDO, cosi' funziona ovunque PHP giri.
 * Mantiene solo gli ultimi 14 backup per non riempire lo spazio disco.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Eseguibile solo da riga di comando (cron).');
}

$pdo = Database::pdo();
$dbName = Config::get('DB_NAME');
$dir = __DIR__ . '/../storage/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$file = $dir . '/backup_' . date('Ymd_His') . '.sql';
$fh = fopen($file, 'w');

fwrite($fh, "-- Backup automatico {$dbName} - " . date('Y-m-d H:i:s') . "\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n");

    $rows = $pdo->query("SELECT * FROM `{$table}`");
    foreach ($rows as $row) {
        $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
        $vals = array_map(function ($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote((string) $v);
        }, array_values($row));
        fwrite($fh, "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
    }
    fwrite($fh, "\n");
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);
@chmod($file, 0600);

// Ruota i backup: tiene solo gli ultimi 14
$backups = glob($dir . '/backup_*.sql');
rsort($backups);
foreach (array_slice($backups, 14) as $old) {
    @unlink($old);
}

echo date('Y-m-d H:i:s') . " - Backup creato: " . basename($file) . PHP_EOL;
