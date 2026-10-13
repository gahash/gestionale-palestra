<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class Abbonamento
{
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, t.nome AS piano_nome, t.prezzo AS piano_prezzo, t.durata_giorni, t.tipo AS piano_tipo
             FROM abbonamenti a JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE a.id = ? AND a.tenant_id = ?'
        );
        $stmt->execute([$id, Database::tenantId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Logica centralizzata di calcolo contabile per un abbonamento:
     * calcola dinamicamente totale, versato, residuo e stato del pagamento.
     *
     * @return array{
     *     prezzo_totale: float,
     *     versato: float,
     *     saldo_residuo: float,
     *     stato_pagamento: string,
     *     is_saldato: bool,
     *     ha_acconto: bool,
     *     da_saldare: bool
     * }
     */
    public static function calcolaStatoPagamento(float $prezzo, float $versato): array
    {
        $prezzo = max(0.0, round($prezzo, 2));
        $versato = max(0.0, round($versato, 2));
        $residuo = max(0.0, round($prezzo - $versato, 2));

        if ($prezzo <= 0.0) {
            $stato = 'gratuito';
        } elseif ($residuo <= 0.005) {
            $stato = 'saldato';
        } elseif ($versato > 0.0) {
            $stato = 'acconto';
        } else {
            $stato = 'non_pagato';
        }

        return [
            'prezzo_totale' => $prezzo,
            'versato' => $versato,
            'saldo_residuo' => $residuo,
            'stato_pagamento' => $stato,
            'is_saldato' => $stato === 'saldato' || $stato === 'gratuito',
            'ha_acconto' => $stato === 'acconto',
            'da_saldare' => $residuo > 0.005 && $prezzo > 0.0,
        ];
    }

    public static function perCliente(int $clienteId, bool $includiCancellati = false): array
    {
        $sql = 'SELECT a.*, t.nome AS piano_nome,
                       COALESCE((SELECT SUM(p.importo) FROM pagamenti p WHERE p.abbonamento_id = a.id AND p.tenant_id = a.tenant_id), 0) AS versato
                FROM abbonamenti a
                JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
                WHERE a.cliente_id = ? AND a.tenant_id = ?';
        if (!$includiCancellati) {
            $sql .= " AND a.stato <> 'cancellato'";
        }
        $sql .= ' ORDER BY a.data_inizio DESC, a.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$clienteId, Database::tenantId()]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $calc = self::calcolaStatoPagamento((float) $r['prezzo_pagato'], (float) $r['versato']);
            $r['incassato'] = $calc['versato']; // retrocompatibilità
            $r['prezzo_totale'] = $calc['prezzo_totale'];
            $r['saldo_residuo'] = $calc['saldo_residuo'];
            $r['stato_pagamento'] = $calc['stato_pagamento'];
            $r['is_saldato'] = $calc['is_saldato'];
            $r['ha_acconto'] = $calc['ha_acconto'];
            $r['da_saldare'] = $calc['da_saldare'];
        }
        unset($r);

        return $rows;
    }

    /** Elenco scadenziario con filtro: 'attivi' | 'scaduti' | numero di giorni (7/15/30) e ricerca testuale opzionale. */
    public static function scadenziario($filtro = 30, ?string $search = null): array
    {
        $pdo = Database::pdo();
        $params = [Database::tenantId()];

        if ($filtro === 'scaduti') {
            $where = "(a.stato = 'scaduto' OR (a.stato <> 'cancellato' AND a.data_scadenza < CURDATE()))";
        } elseif ($filtro === 'attivi') {
            $where = "a.stato IN ('attivo', 'rinnovato') AND a.data_scadenza >= CURDATE()";
        } else {
            $giorni = (int) $filtro;
            $where = "a.stato IN ('attivo', 'rinnovato') AND a.data_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $params[] = $giorni;
        }

        $searchWhere = '';
        if ($search !== null && trim($search) !== '') {
            $searchWhere = " AND (c.nome LIKE ? OR c.cognome LIKE ? OR CONCAT(c.nome, ' ', c.cognome) LIKE ? OR CONCAT(c.cognome, ' ', c.nome) LIKE ? OR c.telefono LIKE ? OR c.email LIKE ? OR t.nome LIKE ?)";
            $term = '%' . trim($search) . '%';
            array_push($params, $term, $term, $term, $term, $term, $term, $term);
        }

        $sql = "SELECT a.*, c.nome, c.cognome, c.telefono, c.email, t.nome AS piano_nome
                FROM abbonamenti a
                JOIN clienti c ON c.id = a.cliente_id
                JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
                WHERE a.tenant_id = ? AND c.attivo = 1 AND {$where}{$searchWhere}
                ORDER BY a.data_scadenza ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Crea un abbonamento e, se richiesto, registra contestualmente il pagamento collegato
     * (intero o acconto). Tutto in un'unica transazione: o si salva tutto o niente.
     *
     * @param float|null $importoIncassato importo effettivamente incassato ora (null = nessun pagamento registrato)
     * @param string|null $dataPagamento data effettiva in cui è avvenuto il pagamento (default oggi, NON la data inizio futura)
     */
    public static function crea(
        int $clienteId,
        int $tipoAbbonamentoId,
        string $dataInizio,
        float $prezzoPagato,
        ?int $creatoDa,
        ?float $importoIncassato = null,
        string $metodo = 'contanti',
        ?string $dataPagamento = null
    ): int {
        $pdo = Database::pdo();
        $tipo = $pdo->prepare('SELECT nome, durata_giorni FROM tipi_abbonamento WHERE id = ? AND tenant_id = ?');
        $tipo->execute([$tipoAbbonamentoId, Database::tenantId()]);
        $t = $tipo->fetch();
        if (!$t) {
            throw new \RuntimeException('Piano abbonamento non trovato.');
        }
        $durata = (int) ($t['durata_giorni'] ?? 30);
        $scadenza = date('Y-m-d', strtotime($dataInizio . " +{$durata} days"));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO abbonamenti (tenant_id, cliente_id, tipo_abbonamento_id, data_inizio, data_scadenza, prezzo_pagato, stato, creato_da)
                 VALUES (?, ?, ?, ?, ?, ?, "attivo", ?)'
            );
            $stmt->execute([Database::tenantId(), $clienteId, $tipoAbbonamentoId, $dataInizio, $scadenza, $prezzoPagato, $creatoDa]);
            $id = (int) $pdo->lastInsertId();
            AuditLogger::log('create', 'abbonamento', $id, null, ['cliente_id' => $clienteId, 'tipo' => $tipoAbbonamentoId, 'prezzo' => $prezzoPagato]);

            if ($importoIncassato !== null && $importoIncassato > 0) {
                // Il pagamento va registrato alla data effettiva dell'incasso (oggi), non alla data futura di inizio abbonamento
                $dataEffettiva = !empty($dataPagamento) ? $dataPagamento : date('Y-m-d');
                self::registraIncasso($id, $clienteId, $prezzoPagato, $importoIncassato, $metodo, 'Abbonamento ' . $t['nome'], $creatoDa, $dataEffettiva);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Rinnovo: chiude il vecchio abbonamento e ne storicizza uno nuovo collegato,
     * registrando il pagamento del rinnovo (intero o acconto) nella stessa transazione.
     */
    public static function rinnova(
        int $abbonamentoPrecedenteId,
        ?int $creatoDa,
        ?float $importoIncassato = null,
        string $metodo = 'contanti',
        ?float $prezzo = null,
        ?string $dataPagamento = null,
        ?string $dataInizio = null
    ): int {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM abbonamenti WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$abbonamentoPrecedenteId, Database::tenantId()]);
        $vecchio = $stmt->fetch();
        if (!$vecchio) {
            throw new \RuntimeException('Abbonamento non trovato');
        }

        $tipo = $pdo->prepare('SELECT nome, durata_giorni, prezzo FROM tipi_abbonamento WHERE id = ?');
        $tipo->execute([$vecchio['tipo_abbonamento_id']]);
        $t = $tipo->fetch();
        $prezzoRinnovo = $prezzo !== null ? $prezzo : (float) $t['prezzo'];
        $durata = (int) ($t['durata_giorni'] ?? 30);
        $inizio = !empty($dataInizio) ? $dataInizio : max(date('Y-m-d'), $vecchio['data_scadenza']);
        $scadenza = date('Y-m-d', strtotime($inizio . ' +' . $durata . ' days'));

        $pdo->beginTransaction();
        try {
            $chiudi = $pdo->prepare("UPDATE abbonamenti SET stato = 'rinnovato' WHERE id = ?");
            $chiudi->execute([$abbonamentoPrecedenteId]);

            $ins = $pdo->prepare(
                'INSERT INTO abbonamenti (tenant_id, cliente_id, tipo_abbonamento_id, data_inizio, data_scadenza,
                 prezzo_pagato, stato, abbonamento_precedente_id, creato_da)
                 VALUES (?, ?, ?, ?, ?, ?, "attivo", ?, ?)'
            );
            $ins->execute([
                Database::tenantId(), $vecchio['cliente_id'], $vecchio['tipo_abbonamento_id'],
                $inizio, $scadenza, $prezzoRinnovo, $abbonamentoPrecedenteId, $creatoDa,
            ]);
            $nuovoId = (int) $pdo->lastInsertId();
            AuditLogger::log('rinnovo', 'abbonamento', $nuovoId, ['precedente' => $abbonamentoPrecedenteId], ['prezzo' => $prezzoRinnovo]);

            if ($importoIncassato !== null && $importoIncassato > 0) {
                $dataEffettiva = !empty($dataPagamento) ? $dataPagamento : date('Y-m-d');
                self::registraIncasso($nuovoId, (int) $vecchio['cliente_id'], $prezzoRinnovo, $importoIncassato, $metodo, 'Rinnovo ' . $t['nome'], $creatoDa, $dataEffettiva);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $nuovoId;
    }

    /** Registra il pagamento collegato a un abbonamento: intero se copre il prezzo, altrimenti acconto con saldo residuo. */
    private static function registraIncasso(int $abbonamentoId, int $clienteId, float $prezzo, float $importo, string $metodo, string $causale, ?int $operatoreId, ?string $data): void
    {
        $acconto = $importo + 0.005 < $prezzo;
        $residuo = $acconto ? round($prezzo - $importo, 2) : 0.0;
        Pagamento::registra($clienteId, $abbonamentoId, $importo, $metodo, $causale, $acconto, $residuo, $operatoreId, $data);
    }

    /**
     * Eliminazione completa e coerente di un abbonamento:
     * - rimuove a cascata tutti i pagamenti collegati (evitando che restino orfani o sommati nei totali)
     * - scollega eventuali rinnovi successivi che facevano riferimento a questo abbonamento
     * - rimuove definitivamente l'abbonamento
     */
    public static function elimina(int $id): void
    {
        $pdo = Database::pdo();
        $tenant = Database::tenantId();

        $before = self::find($id);
        if (!$before) {
            return;
        }

        $pdo->beginTransaction();
        try {
            // 1. Rimuove a cascata tutti i pagamenti collegati
            $delPag = $pdo->prepare('DELETE FROM pagamenti WHERE abbonamento_id = ? AND tenant_id = ?');
            $delPag->execute([$id, $tenant]);

            // 2. Scollega eventuali abbonamenti successivi che avevano questo come precedente
            $updPrec = $pdo->prepare('UPDATE abbonamenti SET abbonamento_precedente_id = NULL WHERE abbonamento_precedente_id = ? AND tenant_id = ?');
            $updPrec->execute([$id, $tenant]);

            // 3. Elimina fisicamente l'abbonamento
            $delAbb = $pdo->prepare('DELETE FROM abbonamenti WHERE id = ? AND tenant_id = ?');
            $delAbb->execute([$id, $tenant]);

            AuditLogger::log('delete', 'abbonamento', $id, $before, null);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function annulla(int $id): void
    {
        self::elimina($id);
    }

    /** Job manutentivo: marca come 'scaduto' gli abbonamenti attivi la cui data e' passata. Da eseguire via cron. */
    public static function aggiornaScaduti(): int
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE abbonamenti SET stato = 'scaduto'
             WHERE tenant_id = ? AND stato = 'attivo' AND data_scadenza < CURDATE()"
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->rowCount();
    }

    /**
     * Entrate ricorrenti attive: abbonamenti in corso (stato attivo, non scaduti) del tipo "abbonamento"
     * (i pacchetti a seduta sono esclusi). Il valore mensile e' normalizzato sulla durata del piano
     * (es. piano da 30 giorni = prezzo pieno, piano trimestrale = prezzo/3).
     */
    public static function ricorrentiAttivi(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) n,
                    COALESCE(SUM(a.prezzo_pagato), 0) valore_periodo,
                    COALESCE(SUM(a.prezzo_pagato * 30 / GREATEST(t.durata_giorni, 1)), 0) valore_mensile
             FROM abbonamenti a
             JOIN clienti c ON c.id = a.cliente_id
             JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE a.tenant_id = ? AND c.attivo = 1 
               AND a.stato IN ('attivo', 'rinnovato')
               AND a.data_scadenza >= CURDATE()
               AND (a.data_inizio <= CURDATE() OR NOT EXISTS (
                   SELECT 1 FROM abbonamenti a2 
                   WHERE a2.cliente_id = a.cliente_id AND a2.tenant_id = a.tenant_id 
                     AND a2.data_scadenza >= CURDATE() AND a2.data_inizio <= CURDATE() AND a2.stato IN ('attivo', 'rinnovato')
               ))
               AND (t.tipo = 'abbonamento' OR t.tipo IS NULL OR t.tipo = '')"
        );
        $stmt->execute([Database::tenantId()]);
        $r = $stmt->fetch();
        return ['n' => (int) $r['n'], 'valore_periodo' => (float) $r['valore_periodo'], 'valore_mensile' => (float) $r['valore_mensile']];
    }

    /** Abbonamenti attivi raggruppati per piano (per la tabella "ricorrenti" in Finanze). */
    public static function ricorrentiPerPiano(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.nome AS piano_nome, t.durata_giorni, COUNT(*) n,
                    COALESCE(SUM(a.prezzo_pagato), 0) valore_periodo,
                    COALESCE(SUM(a.prezzo_pagato * 30 / GREATEST(t.durata_giorni, 1)), 0) valore_mensile
             FROM abbonamenti a
             JOIN clienti c ON c.id = a.cliente_id
             JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE a.tenant_id = ? AND c.attivo = 1 
               AND a.stato IN ('attivo', 'rinnovato')
               AND a.data_scadenza >= CURDATE()
               AND (a.data_inizio <= CURDATE() OR NOT EXISTS (
                   SELECT 1 FROM abbonamenti a2 
                   WHERE a2.cliente_id = a.cliente_id AND a2.tenant_id = a.tenant_id 
                     AND a2.data_scadenza >= CURDATE() AND a2.data_inizio <= CURDATE() AND a2.stato IN ('attivo', 'rinnovato')
               ))
               AND (t.tipo = 'abbonamento' OR t.tipo IS NULL OR t.tipo = '')
             GROUP BY t.id, t.nome, t.durata_giorni ORDER BY valore_mensile DESC"
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }

    /**
     * Elenco completo degli abbonamenti attivi, rinnovati o che coprono il periodo selezionato.
     * Include il calcolo esatto dell'incassato collegato, saldo residuo e stato contabile.
     */
    public static function nelPeriodo(string $dataInizio, string $dataFine): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, c.nome, c.cognome, c.telefono, t.nome AS piano_nome,
                    COALESCE((SELECT SUM(p.importo) FROM pagamenti p WHERE p.abbonamento_id = a.id AND p.tenant_id = a.tenant_id), 0) AS versato
             FROM abbonamenti a
             JOIN clienti c ON c.id = a.cliente_id
             JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE a.tenant_id = ? AND a.stato <> 'cancellato'
               AND (
                 (a.data_inizio BETWEEN ? AND ?)
                 OR (a.data_scadenza BETWEEN ? AND ?)
                 OR (a.data_inizio <= ? AND a.data_scadenza >= ?)
               )
             ORDER BY a.data_inizio DESC, a.id DESC"
        );
        $stmt->execute([
            Database::tenantId(),
            $dataInizio, $dataFine,
            $dataInizio, $dataFine,
            $dataInizio, $dataFine
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $calc = self::calcolaStatoPagamento((float) $r['prezzo_pagato'], (float) $r['versato']);
            $r['versato'] = $calc['versato'];
            $r['saldo_residuo'] = $calc['saldo_residuo'];
            $r['stato_pagamento'] = $calc['stato_pagamento'];
            $r['is_saldato'] = $calc['is_saldato'];
            $r['ha_acconto'] = $calc['ha_acconto'];
            $r['da_saldare'] = $calc['da_saldare'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Controllo di coerenza: abbonamenti con prezzo > 0 per cui non risulta alcun pagamento collegato.
     * Sono incassi che non compaiono nei totali finanziari finche' non vengono registrati.
     */
    public static function senzaPagamento(): array
    {
        // Per ogni abbonamento scoperto si cerca anche un "pagamento candidato": un incasso libero
        // (non collegato a nessun abbonamento) dello stesso cliente, di pari importo, registrato
        // entro 7 giorni dall'inizio o dalla creazione dell'abbonamento. In quel caso conviene
        // collegarlo invece di registrare un secondo pagamento (evita i doppi conteggi).
        $stmt = Database::pdo()->prepare(
            "SELECT a.id, a.cliente_id, a.data_inizio, a.data_scadenza, a.prezzo_pagato, a.stato, a.created_at,
                    c.nome, c.cognome, t.nome AS piano_nome,
                    (SELECT p.id FROM pagamenti p
                      WHERE p.cliente_id = a.cliente_id AND p.abbonamento_id IS NULL
                        AND p.importo = a.prezzo_pagato
                        AND (ABS(DATEDIFF(p.created_at, a.data_inizio)) <= 7 OR ABS(DATEDIFF(p.created_at, a.created_at)) <= 7)
                      ORDER BY ABS(DATEDIFF(p.created_at, a.created_at)) ASC LIMIT 1) AS pagamento_candidato_id
             FROM abbonamenti a
             JOIN clienti c ON c.id = a.cliente_id
             JOIN tipi_abbonamento t ON t.id = a.tipo_abbonamento_id
             WHERE a.tenant_id = ? AND c.attivo = 1 AND a.prezzo_pagato > 0 AND a.stato <> 'cancellato'
               AND NOT EXISTS (SELECT 1 FROM pagamenti p WHERE p.abbonamento_id = a.id)
             ORDER BY a.data_inizio DESC"
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }

    /** Collega un pagamento libero esistente a un abbonamento (nessun nuovo incasso viene creato). */
    public static function collegaPagamento(int $abbonamentoId, int $pagamentoId): bool
    {
        $pdo = Database::pdo();
        $chk = $pdo->prepare(
            'SELECT p.id FROM pagamenti p JOIN abbonamenti a ON a.cliente_id = p.cliente_id
             WHERE p.id = ? AND a.id = ? AND p.abbonamento_id IS NULL AND p.tenant_id = ? AND a.tenant_id = ?'
        );
        $chk->execute([$pagamentoId, $abbonamentoId, Database::tenantId(), Database::tenantId()]);
        if (!$chk->fetch()) {
            return false;
        }
        $upd = $pdo->prepare('UPDATE pagamenti SET abbonamento_id = ? WHERE id = ?');
        $upd->execute([$abbonamentoId, $pagamentoId]);
        AuditLogger::log('collega', 'pagamento', $pagamentoId, null, ['abbonamento_id' => $abbonamentoId]);
        return true;
    }

    /**
     * Registra a posteriori il pagamento di un abbonamento che ne era privo
     * (data contabile = data di inizio abbonamento o oggi se futura). Usato dal pannello di verifica in Finanze.
     */
    public static function registraPagamentoMancante(int $abbonamentoId, string $metodo, ?int $operatoreId): ?int
    {
        $a = self::find($abbonamentoId);
        if (!$a || (float) $a['prezzo_pagato'] <= 0) {
            return null;
        }
        $check = Database::pdo()->prepare('SELECT COUNT(*) c FROM pagamenti WHERE abbonamento_id = ?');
        $check->execute([$abbonamentoId]);
        if ((int) $check->fetch()['c'] > 0) {
            return null;
        }
        // Se la data_inizio è nel futuro, registriamo con data odierna affinché l'incasso compaia subito in Finanze
        $dataContabile = ($a['data_inizio'] > date('Y-m-d')) ? date('Y-m-d') : $a['data_inizio'];
        return Pagamento::registra(
            (int) $a['cliente_id'], $abbonamentoId, (float) $a['prezzo_pagato'], $metodo,
            'Abbonamento ' . $a['piano_nome'] . ' (registrato a posteriori)', false, 0.0, $operatoreId, $dataContabile
        );
    }
}
