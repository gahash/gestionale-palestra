<?php
declare(strict_types=1);

namespace App;

/** Dati di branding/contatto del tenant attivo, usati sia dalla landing sia dal gestionale. */
final class Tenant
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([Database::tenantId()]);
        $row = $stmt->fetch();
        self::$data = $row ?: [];
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        $data = self::data();
        return $data[$key] ?? $default;
    }

    /** Piani abbonamento attivi, ordinati come configurati (usati dalla landing e dal gestionale). */
    public static function piani(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM tipi_abbonamento WHERE tenant_id = ? AND attivo = 1 ORDER BY ordine ASC, id ASC'
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }
}
