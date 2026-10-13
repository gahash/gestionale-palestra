<?php
declare(strict_types=1);

namespace App;

/**
 * Carica .env (o .env.example come fallback in dev) senza dipendenze esterne.
 * Nessun valore e' hardcoded: dominio, DB e branding arrivano tutti da qui,
 * cosi' lo stesso codice funziona identico su qualsiasi hosting/dominio.
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        $root = dirname(__DIR__);
        $envFile = file_exists($root . '/.env') ? $root . '/.env' : null;

        if ($envFile !== null) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }

        self::$loaded = true;

        date_default_timezone_set(self::get('APP_TIMEZONE', 'UTC'));
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Percorso base assoluto (senza dominio) della root del progetto, es. "" se installato
     * nella root del dominio, oppure "/palestra" se installato in una sottocartella.
     * Calcolato confrontando la cartella reale del progetto con la document root del server:
     * funziona identico da qualunque script (root, admin/, api/), a differenza di una
     * deduzione basata su SCRIPT_NAME (che varia da pagina a pagina).
     */
    public static function baseUrl(): string
    {
        $configured = self::get('APP_URL');
        if ($configured) {
            return rtrim($configured, '/');
        }

        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        $documentRoot = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));

        if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
            return rtrim(substr($projectRoot, strlen($documentRoot)), '/');
        }

        return '';
    }
}
