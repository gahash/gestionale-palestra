<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class TipoAbbonamento
{
    public static function all(bool $soloAttivi = false): array
    {
        $sql = 'SELECT * FROM tipi_abbonamento WHERE tenant_id = ?';
        if ($soloAttivi) {
            $sql .= ' AND attivo = 1';
        }
        $sql .= ' ORDER BY ordine ASC, id ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM tipi_abbonamento WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, Database::tenantId()]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO tipi_abbonamento (tenant_id, nome, descrizione, prezzo, frequenza_settimanale, durata_giorni, tipo, in_evidenza, ordine, attivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            Database::tenantId(), $d['nome'], $d['descrizione'] ?? null, $d['prezzo'],
            $d['frequenza_settimanale'] ?: null, $d['durata_giorni'] ?: 30, $d['tipo'] ?: 'abbonamento',
            !empty($d['in_evidenza']) ? 1 : 0, (int) ($d['ordine'] ?? 0),
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'tipo_abbonamento', $id, null, $d);
        return $id;
    }

    public static function update(int $id, array $d): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE tipi_abbonamento SET nome=?, descrizione=?, prezzo=?, frequenza_settimanale=?, durata_giorni=?,
             tipo=?, in_evidenza=?, ordine=?, attivo=? WHERE id=? AND tenant_id=?'
        );
        $stmt->execute([
            $d['nome'], $d['descrizione'] ?? null, $d['prezzo'], $d['frequenza_settimanale'] ?: null,
            $d['durata_giorni'] ?: 30, $d['tipo'] ?: 'abbonamento', !empty($d['in_evidenza']) ? 1 : 0,
            (int) ($d['ordine'] ?? 0), !empty($d['attivo']) ? 1 : 0, $id, Database::tenantId(),
        ]);
        AuditLogger::log('update', 'tipo_abbonamento', $id, null, $d);
    }
}
