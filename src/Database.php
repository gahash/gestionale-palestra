<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        Config::load();
        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS', '');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');

        if (!$name || !$user) {
            http_response_code(500);
            die('Configurazione database mancante: compila il file .env (vedi .env.example).');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            $debug = Config::bool('APP_DEBUG', false);
            die('Connessione al database non riuscita.' . ($debug ? ' Dettagli: ' . htmlspecialchars($e->getMessage()) : ''));
        }

        return self::$instance;
    }

    /** ID del tenant attivo per questa installazione (letto da TENANT_SLUG in .env). */
    public static function tenantId(): int
    {
        static $tenantId = null;
        if ($tenantId !== null) {
            return $tenantId;
        }
        $slug = Config::get('TENANT_SLUG', 'gym-studio');
        $stmt = self::pdo()->prepare('SELECT id FROM tenants WHERE slug = ? AND attivo = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(500);
            die("Tenant '{$slug}' non trovato o non attivo. Verifica la tabella tenants e la variabile TENANT_SLUG nel file .env.");
        }
        $tenantId = (int) $row['id'];
        return $tenantId;
    }
}
