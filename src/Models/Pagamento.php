<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class Pagamento
{
    public static function perCliente(int $clienteId): array
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT p.*, u.nome AS operatore_nome, t.nome AS piano_nome
             FROM pagamenti p
             LEFT JOIN users u ON u.id = p.operatore_id
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             LEFT JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE p.cliente_id = ? AND p.tenant_id = ? AND {$cond}
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([$clienteId, Database::tenantId()]);
        return $stmt->fetchAll();
    }

    public static function registra(
        int $clienteId,
        ?int $abbonamentoId,
        float $importo,
        string $metodo,
        ?string $causale,
        bool $acconto,
        float $saldoResiduo,
        ?int $operatoreId,
        ?string $dataPagamento = null
    ): int {
        $metodiValidi = ['contanti', 'carta', 'bonifico', 'altro'];
        if (!in_array($metodo, $metodiValidi, true)) {
            $metodo = 'altro';
        }
        if ($importo <= 0) {
            throw new \InvalidArgumentException("L'importo del pagamento deve essere maggiore di zero.");
        }
        // Data contabile: se viene passata solo la data (YYYY-MM-DD) si aggiunge l'ora corrente.
        $createdAt = date('Y-m-d H:i:s');
        if ($dataPagamento) {
            $createdAt = strlen($dataPagamento) === 10 ? $dataPagamento . ' ' . date('H:i:s') : $dataPagamento;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO pagamenti (tenant_id, cliente_id, abbonamento_id, importo, metodo, causale, acconto, saldo_residuo, operatore_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Database::tenantId(), $clienteId, $abbonamentoId, $importo, $metodo, $causale,
            $acconto ? 1 : 0, $acconto ? $saldoResiduo : 0, $operatoreId, $createdAt,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'pagamento', $id, null, [
            'cliente_id' => $clienteId, 'abbonamento_id' => $abbonamentoId, 'importo' => $importo, 'acconto' => $acconto,
        ]);
        return $id;
    }

    /** Condizione SQL riutilizzabile per escludere incassi cancellati o pagamenti orfani di abbonamenti eliminati. */
    public static function sqlCondizioneValidi(string $p = 'p', string $a = 'a'): string
    {
        return "(({$p}.abbonamento_id IS NOT NULL AND {$a}.id IS NOT NULL AND {$a}.stato <> 'cancellato') " .
               "OR ({$p}.abbonamento_id IS NULL))";
    }

    /**
     * Elenco completo e dinamico di tutti i pagamenti ancora da riscuotere:
     * - abbonamenti attivi/in corso o scaduti ma con saldo residuo (acconto parziale o nessun pagamento)
     * - eventuali acconti liberi aperti con saldo residuo
     * Esclude clienti disattivati e abbonamenti cancellati.
     *
     * @return array<int, array{
     *     cliente_id: int,
     *     cliente_nome: string,
     *     telefono: string,
     *     abbonamento_id: ?int,
     *     abbonamento_nome: string,
     *     servizio: string,
     *     data: string,
     *     importo_totale: float,
     *     prezzo_totale: float,
     *     versato: float,
     *     saldo_residuo: float,
     *     tipo: string
     * }>
     */
    public static function elencoDaRiscuotere(): array
    {
        $pdo = Database::pdo();
        $tenant = Database::tenantId();
        $risultati = [];

        // 1. Abbonamenti con saldo aperto (acconto parziale o nessun pagamento versato)
        $stmtAbb = $pdo->prepare(
            "SELECT 
                c.id AS cliente_id, c.nome, c.cognome, c.telefono,
                a.id AS abbonamento_id,
                t.nome AS piano_nome,
                a.data_inizio AS data,
                a.prezzo_pagato AS importo_totale,
                COALESCE(SUM(p.importo), 0) AS versato
             FROM abbonamenti a
             JOIN clienti c ON c.id = a.cliente_id
             JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             LEFT JOIN pagamenti p ON p.abbonamento_id = a.id AND p.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.stato <> 'cancellato' AND c.attivo = 1 AND a.prezzo_pagato > 0
             GROUP BY a.id, c.id, c.nome, c.cognome, c.telefono, t.nome, a.data_inizio, a.prezzo_pagato
             ORDER BY a.data_inizio DESC"
        );
        $stmtAbb->execute([$tenant]);
        foreach ($stmtAbb->fetchAll() as $r) {
            $calc = Abbonamento::calcolaStatoPagamento((float) $r['importo_totale'], (float) $r['versato']);
            if ($calc['da_saldare']) {
                $risultati[] = [
                    'cliente_id' => (int) $r['cliente_id'],
                    'cliente_nome' => trim($r['nome'] . ' ' . $r['cognome']),
                    'telefono' => $r['telefono'] ?? '',
                    'abbonamento_id' => (int) $r['abbonamento_id'],
                    'abbonamento_nome' => $r['piano_nome'],
                    'servizio' => $r['piano_nome'],
                    'data' => $r['data'],
                    'importo_totale' => $calc['prezzo_totale'],
                    'prezzo_totale' => $calc['prezzo_totale'],
                    'versato' => $calc['versato'],
                    'saldo_residuo' => $calc['saldo_residuo'],
                    'tipo' => 'abbonamento',
                ];
            }
        }

        // 2. Eventuali pagamenti liberi registrati come acconto con saldo_residuo aperto
        $stmtLib = $pdo->prepare(
            "SELECT 
                c.id AS cliente_id, c.nome, c.cognome, c.telefono,
                p.id AS pagamento_id,
                COALESCE(p.causale, 'Pagamento libero') AS causale,
                DATE(p.created_at) AS data,
                ROUND(p.importo + p.saldo_residuo, 2) AS importo_totale,
                p.importo AS versato,
                p.saldo_residuo AS saldo_residuo
             FROM pagamenti p
             JOIN clienti c ON c.id = p.cliente_id
             WHERE p.tenant_id = ? AND p.abbonamento_id IS NULL AND p.acconto = 1 AND p.saldo_residuo > 0.009 AND c.attivo = 1
             ORDER BY p.created_at DESC"
        );
        $stmtLib->execute([$tenant]);
        foreach ($stmtLib->fetchAll() as $r) {
            $risultati[] = [
                'cliente_id' => (int) $r['cliente_id'],
                'cliente_nome' => trim($r['nome'] . ' ' . $r['cognome']),
                'telefono' => $r['telefono'] ?? '',
                'abbonamento_id' => null,
                'abbonamento_nome' => $r['causale'] . ' (acconto libero)',
                'servizio' => $r['causale'] . ' (acconto libero)',
                'data' => $r['data'],
                'importo_totale' => (float) $r['importo_totale'],
                'prezzo_totale' => (float) $r['importo_totale'],
                'versato' => (float) $r['versato'],
                'saldo_residuo' => (float) $r['saldo_residuo'],
                'tipo' => 'libero',
            ];
        }

        return $risultati;
    }

    /** Saldo residuo aperto per cliente calcolato dinamicamente. */
    public static function saldoResiduoCliente(int $clienteId): float
    {
        $tot = 0.0;
        foreach (self::elencoDaRiscuotere() as $s) {
            if ((int) $s['cliente_id'] === $clienteId) {
                $tot += (float) $s['saldo_residuo'];
            }
        }
        return round($tot, 2);
    }

    /** Elenco completo di tutti i clienti con saldo residuo aperto (acconti non saldati). */
    public static function elencoAccontiAperti(): array
    {
        return self::elencoDaRiscuotere();
    }

    /** Clienti con saldo residuo aperto oltre la soglia di giorni indicata (alert). */
    public static function saldiApertiOltreGiorni(int $giorni): array
    {
        $elenco = self::elencoDaRiscuotere();
        if ($giorni <= 0) {
            return $elenco;
        }
        $limite = date('Y-m-d', strtotime("-{$giorni} days"));
        return array_values(array_filter($elenco, fn($r) => $r['data'] <= $limite));
    }

    /** Totale crediti ancora da incassare (somma dei saldi residui dinamici). */
    public static function totaleSaldiAperti(): float
    {
        return round(array_sum(array_column(self::elencoDaRiscuotere(), 'saldo_residuo')), 2);
    }

    public static function totaleIncassatoPeriodo(string $dataInizio, string $dataFine): float
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(p.importo), 0) tot FROM pagamenti p
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ?
               AND {$cond}
               AND p.created_at BETWEEN ? AND ?"
        );
        $stmt->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);
        return (float) $stmt->fetch()['tot'];
    }

    /**
     * Incassi del periodo suddivisi: abbonamenti (pagamenti collegati a un abbonamento valido non cancellato)
     * e altri incassi clienti (pagamenti liberi effettivi, es. sedute singole, quote varie).
     */
    public static function incassiPeriodoSuddivisi(string $dataInizio, string $dataFine): array
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN (p.abbonamento_id IS NOT NULL OR LOWER(p.causale) LIKE '%abbonamento%' OR LOWER(p.causale) LIKE '%rinnovo%' OR LOWER(p.causale) LIKE '%saldo%') THEN p.importo ELSE 0 END), 0) abbonamenti,
                COALESCE(SUM(CASE WHEN (p.abbonamento_id IS NULL AND (p.causale IS NULL OR (LOWER(p.causale) NOT LIKE '%abbonamento%' AND LOWER(p.causale) NOT LIKE '%rinnovo%' AND LOWER(p.causale) NOT LIKE '%saldo%'))) THEN p.importo ELSE 0 END), 0) altri,
                COUNT(*) n
             FROM pagamenti p
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ?
               AND {$cond}
               AND p.created_at BETWEEN ? AND ?"
        );
        $stmt->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);
        $r = $stmt->fetch();
        return ['abbonamenti' => (float) $r['abbonamenti'], 'altri' => (float) $r['altri'], 'n' => (int) $r['n']];
    }

    /** Storico completo degli incassi da abbonamento valido non cancellato. */
    public static function storicoAbbonamenti(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(p.importo), 0) tot, COUNT(*) n, MIN(p.created_at) primo, MAX(p.created_at) ultimo
             FROM pagamenti p
             JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ? AND a.stato <> "cancellato"'
        );
        $stmt->execute([Database::tenantId()]);
        $r = $stmt->fetch();
        return ['tot' => (float) $r['tot'], 'n' => (int) $r['n'], 'primo' => $r['primo'], 'ultimo' => $r['ultimo']];
    }

    /** Incassi per metodo di pagamento nel periodo. */
    public static function perMetodoPeriodo(string $dataInizio, string $dataFine): array
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT p.metodo, COALESCE(SUM(p.importo), 0) tot, COUNT(*) n FROM pagamenti p
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ?
               AND {$cond}
               AND p.created_at BETWEEN ? AND ? GROUP BY p.metodo ORDER BY tot DESC"
        );
        $stmt->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);
        return $stmt->fetchAll();
    }

    /**
     * Riepilogo mensile degli ultimi N mesi: incassi abbonamenti, altri incassi,
     * entrate manuali, uscite. I mesi senza movimenti compaiono con zero.
     */
    public static function riepilogoMensile(int $mesi = 12): array
    {
        $pdo = Database::pdo();
        $tenant = Database::tenantId();
        $inizio = date('Y-m-01', strtotime(date('Y-m-01') . ' -' . ($mesi - 1) . ' months'));

        $righe = [];
        for ($i = 0; $i < $mesi; $i++) {
            $m = date('Y-m', strtotime($inizio . " +{$i} months"));
            $righe[$m] = ['mese' => $m, 'abbonamenti' => 0.0, 'altri' => 0.0, 'entrate_manuali' => 0.0, 'uscite' => 0.0];
        }

        $cond = self::sqlCondizioneValidi('p', 'a');
        $p = $pdo->prepare(
            "SELECT DATE_FORMAT(p.created_at, '%Y-%m') mese,
                    COALESCE(SUM(CASE WHEN (p.abbonamento_id IS NOT NULL OR LOWER(p.causale) LIKE '%abbonamento%' OR LOWER(p.causale) LIKE '%rinnovo%' OR LOWER(p.causale) LIKE '%saldo%') THEN p.importo ELSE 0 END), 0) abbonamenti,
                    COALESCE(SUM(CASE WHEN (p.abbonamento_id IS NULL AND (p.causale IS NULL OR (LOWER(p.causale) NOT LIKE '%abbonamento%' AND LOWER(p.causale) NOT LIKE '%rinnovo%' AND LOWER(p.causale) NOT LIKE '%saldo%'))) THEN p.importo ELSE 0 END), 0) altri
             FROM pagamenti p
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ? 
               AND {$cond}
               AND p.created_at >= ? GROUP BY mese"
        );
        $p->execute([$tenant, $inizio . ' 00:00:00']);
        foreach ($p->fetchAll() as $r) {
            if (isset($righe[$r['mese']])) {
                $righe[$r['mese']]['abbonamenti'] = (float) $r['abbonamenti'];
                $righe[$r['mese']]['altri'] = (float) $r['altri'];
            }
        }

        $e = $pdo->prepare(
            "SELECT DATE_FORMAT(data, '%Y-%m') mese, tipo, COALESCE(SUM(importo), 0) tot
             FROM entrate_uscite WHERE tenant_id = ? AND data >= ? GROUP BY mese, tipo"
        );
        $e->execute([$tenant, $inizio]);
        foreach ($e->fetchAll() as $r) {
            if (!isset($righe[$r['mese']])) {
                continue;
            }
            if ($r['tipo'] === 'entrata') {
                $righe[$r['mese']]['entrate_manuali'] = (float) $r['tot'];
            } else {
                $righe[$r['mese']]['uscite'] = (float) $r['tot'];
            }
        }

        foreach ($righe as &$r) {
            $r['entrate'] = $r['abbonamenti'] + $r['altri'] + $r['entrate_manuali'];
            $r['netto'] = $r['entrate'] - $r['uscite'];
        }
        unset($r);
        return array_values($righe);
    }

    public static function serieGiornaliera(string $dataInizio, string $dataFine): array
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT DATE(p.created_at) giorno, SUM(p.importo) tot FROM pagamenti p
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             WHERE p.tenant_id = ? 
               AND {$cond}
               AND p.created_at BETWEEN ? AND ?
             GROUP BY DATE(p.created_at) ORDER BY giorno ASC"
        );
        $stmt->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);
        return $stmt->fetchAll();
    }

    /** Elenco pagamenti del periodo con cliente e piano (registro movimenti unificato ed export CSV). */
    public static function periodo(string $dataInizio, string $dataFine): array
    {
        $cond = self::sqlCondizioneValidi('p', 'a');
        $stmt = Database::pdo()->prepare(
            "SELECT p.*, COALESCE(c.nome, 'Cliente eliminato') AS nome, COALESCE(c.cognome, '') AS cognome,
                    t.nome AS piano_nome, u.nome AS operatore_nome
             FROM pagamenti p
             LEFT JOIN clienti c ON c.id = p.cliente_id
             LEFT JOIN abbonamenti a ON a.id = p.abbonamento_id
             LEFT JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             LEFT JOIN users u ON u.id = p.operatore_id
             WHERE p.tenant_id = ? 
               AND {$cond}
               AND p.created_at BETWEEN ? AND ?
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([Database::tenantId(), $dataInizio . ' 00:00:00', $dataFine . ' 23:59:59']);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM pagamenti WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, Database::tenantId()]);
        return $stmt->fetch() ?: null;
    }

    public static function elimina(int $id): void
    {
        $before = self::find($id);
        if (!$before) {
            return;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM pagamenti WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, Database::tenantId()]);
        AuditLogger::log('delete', 'pagamento', $id, $before, null);
    }
}
