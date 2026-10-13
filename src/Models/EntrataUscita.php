<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class EntrataUscita
{
    public static function periodo(string $dataInizio, string $dataFine): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM entrate_uscite WHERE tenant_id = ? AND data BETWEEN ? AND ? ORDER BY data DESC, id DESC'
        );
        $stmt->execute([Database::tenantId(), $dataInizio, $dataFine]);
        return $stmt->fetchAll();
    }

    public static function totali(string $dataInizio, string $dataFine): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT tipo, COALESCE(SUM(importo),0) tot FROM entrate_uscite
             WHERE tenant_id = ? AND data BETWEEN ? AND ? GROUP BY tipo"
        );
        $stmt->execute([Database::tenantId(), $dataInizio, $dataFine]);
        $result = ['entrata' => 0.0, 'uscita' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['tipo']] = (float) $row['tot'];
        }
        return $result;
    }

    public static function registra(string $tipo, string $categoria, float $importo, ?string $descrizione, string $data, ?int $operatoreId): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO entrate_uscite (tenant_id, tipo, categoria, importo, descrizione, data, operatore_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([Database::tenantId(), $tipo, $categoria, $importo, $descrizione, $data, $operatoreId]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'entrata_uscita', $id, null, compact('tipo', 'categoria', 'importo'));
        return $id;
    }

    public static function elimina(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM entrate_uscite WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, Database::tenantId()]);
        AuditLogger::log('delete', 'entrata_uscita', $id);
    }

    /** Nuovi iscritti (primo abbonamento) vs disdette (abbonamenti cancellati) nel periodo. */
    public static function nuoviIscrittiVsDisdette(string $dataInizio, string $dataFine): array
    {
        $pdo = Database::pdo();
        $nuovi = $pdo->prepare(
            "SELECT COUNT(*) c FROM clienti WHERE tenant_id = ? AND created_at BETWEEN ? AND ?"
        );
        $nuovi->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);

        // Le disdette si contano alla data dell'annullamento (audit log), non alla data di creazione dell'abbonamento.
        $disdette = $pdo->prepare(
            "SELECT COUNT(DISTINCT entita_id) c FROM audit_log
             WHERE tenant_id = ? AND entita = 'abbonamento' AND azione = 'cancella'
             AND created_at BETWEEN ? AND ?"
        );
        $disdette->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);

        return ['nuovi' => (int) $nuovi->fetch()['c'], 'disdette' => (int) $disdette->fetch()['c']];
    }
}
