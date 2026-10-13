<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class Appuntamento
{
    public static function delGiorno(string $data): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, c.nome, c.cognome, u.nome AS trainer_nome
             FROM appuntamenti a
             JOIN clienti c ON c.id = a.cliente_id
             LEFT JOIN users u ON u.id = a.trainer_id
             WHERE a.tenant_id = ? AND a.data = ? AND a.stato != "cancellato" AND c.attivo = 1
             ORDER BY a.ora_inizio ASC'
        );
        $stmt->execute([Database::tenantId(), $data]);
        return $stmt->fetchAll();
    }

    public static function delPeriodo(string $dataInizio, string $dataFine): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, c.nome, c.cognome, c.telefono, u.nome AS trainer_nome
             FROM appuntamenti a
             JOIN clienti c ON c.id = a.cliente_id
             LEFT JOIN users u ON u.id = a.trainer_id
             WHERE a.tenant_id = ? AND a.data BETWEEN ? AND ? AND a.stato != "cancellato" AND c.attivo = 1
             ORDER BY a.data ASC, a.ora_inizio ASC, c.cognome ASC'
        );
        $stmt->execute([Database::tenantId(), $dataInizio, $dataFine]);
        return $stmt->fetchAll();
    }

    /**
     * Raggruppa gli appuntamenti in "sessioni" (stessa data + stessa fascia oraria), come vengono
     * mostrate nel calendario settimanale: una cella = una sessione con i suoi partecipanti.
     */
    public static function raggruppaInSessioni(array $appuntamenti): array
    {
        $sessioni = [];
        foreach ($appuntamenti as $a) {
            $key = $a['data'] . '|' . $a['ora_inizio'] . '|' . $a['ora_fine'];
            if (!isset($sessioni[$key])) {
                $sessioni[$key] = [
                    'key' => $key,
                    'data' => $a['data'],
                    'ora_inizio' => substr($a['ora_inizio'], 0, 5),
                    'ora_fine' => substr($a['ora_fine'], 0, 5),
                    'capienza_max' => (int) $a['capienza_max'],
                    'trainer' => $a['trainer_nome'],
                    'partecipanti' => [],
                    'occupati' => 0,
                ];
            }
            $sessioni[$key]['capienza_max'] = max($sessioni[$key]['capienza_max'], (int) $a['capienza_max']);
            if (!$sessioni[$key]['trainer'] && $a['trainer_nome']) {
                $sessioni[$key]['trainer'] = $a['trainer_nome'];
            }
            $sessioni[$key]['partecipanti'][] = [
                'id' => (int) $a['id'],
                'cliente_id' => (int) $a['cliente_id'],
                'nome' => $a['nome'] . ' ' . $a['cognome'],
                'telefono' => $a['telefono'] ?? null,
                'stato' => $a['stato'],
                'trainer' => $a['trainer_nome'],
                'note' => $a['note'],
            ];
            if ($a['stato'] !== 'assente') {
                $sessioni[$key]['occupati']++;
            }
        }
        return array_values($sessioni);
    }

    /** Numero di persone gia' prenotate nella stessa fascia oraria (per il limite di capienza). */
    public static function occupazione(string $data, string $oraInizio, string $oraFine, ?int $escludiId = null): int
    {
        $sql = 'SELECT COUNT(*) c FROM appuntamenti
                WHERE tenant_id = ? AND data = ? AND stato NOT IN ("cancellato","assente")
                AND ora_inizio = ? AND ora_fine = ?';
        $params = [Database::tenantId(), $data, $oraInizio, $oraFine];
        if ($escludiId) {
            $sql .= ' AND id != ?';
            $params[] = $escludiId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['c'];
    }

    /**
     * Crea un appuntamento rispettando la capienza massima per sessione (default 4, come da locandina).
     * Lancia eccezione se la sessione e' gia' piena.
     */
    public static function crea(
        int $clienteId,
        ?int $trainerId,
        string $data,
        string $oraInizio,
        string $oraFine,
        int $capienzaMax = 4,
        ?string $note = null,
        ?int $ricorrenzaId = null
    ): int {
        $occupati = self::occupazione($data, $oraInizio, $oraFine);
        if ($occupati >= $capienzaMax) {
            throw new \RuntimeException("Sessione al completo ({$occupati}/{$capienzaMax}) per {$data} {$oraInizio}");
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO appuntamenti (tenant_id, cliente_id, trainer_id, data, ora_inizio, ora_fine, capienza_max, stato, ricorrenza_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, "prenotato", ?, ?)'
        );
        $stmt->execute([Database::tenantId(), $clienteId, $trainerId, $data, $oraInizio, $oraFine, $capienzaMax, $ricorrenzaId, $note]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'appuntamento', $id, null, ['cliente_id' => $clienteId, 'data' => $data]);
        return $id;
    }

    /**
     * Crea prenotazioni per uno o più clienti (fino a 5) nella stessa sessione.
     * @param int[] $clientiIds
     * @return int[] ID degli appuntamenti creati
     */
    public static function creaMultiplo(
        array $clientiIds,
        ?int $trainerId,
        string $data,
        string $oraInizio,
        string $oraFine,
        int $capienzaMax = 4,
        ?string $note = null
    ): array {
        if (empty($clientiIds)) {
            throw new \RuntimeException('Nessun cliente specificato.');
        }
        if (count($clientiIds) > 5) {
            throw new \RuntimeException('È possibile inserire al massimo 5 clienti per prenotazione.');
        }

        $occupati = self::occupazione($data, $oraInizio, $oraFine);
        $nuovaCapienza = max($capienzaMax, $occupati + count($clientiIds));

        $ids = [];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($nuovaCapienza > $capienzaMax) {
                $upd = $pdo->prepare(
                    'UPDATE appuntamenti SET capienza_max = ?
                     WHERE tenant_id = ? AND data = ? AND ora_inizio = ? AND ora_fine = ?'
                );
                $upd->execute([$nuovaCapienza, Database::tenantId(), $data, $oraInizio, $oraFine]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO appuntamenti (tenant_id, cliente_id, trainer_id, data, ora_inizio, ora_fine, capienza_max, stato, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "prenotato", ?)'
            );

            foreach ($clientiIds as $cid) {
                $stmt->execute([Database::tenantId(), (int) $cid, $trainerId, $data, $oraInizio, $oraFine, $nuovaCapienza, $note]);
                $id = (int) $pdo->lastInsertId();
                $ids[] = $id;
                AuditLogger::log('create', 'appuntamento', $id, null, ['cliente_id' => $cid, 'data' => $data]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $ids;
    }


    public static function cambiaStato(int $id, string $stato): void
    {
        $valori = ['prenotato', 'confermato', 'presente', 'assente', 'cancellato'];
        if (!in_array($stato, $valori, true)) {
            throw new \InvalidArgumentException('Stato non valido');
        }
        $stmt = Database::pdo()->prepare('UPDATE appuntamenti SET stato = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$stato, $id, Database::tenantId()]);
        AuditLogger::log('cambio_stato', 'appuntamento', $id, null, ['stato' => $stato]);

        if ($stato === 'presente') {
            $app = Database::pdo()->prepare('SELECT cliente_id FROM appuntamenti WHERE id = ?');
            $app->execute([$id]);
            $clienteId = $app->fetch()['cliente_id'] ?? null;
            if ($clienteId) {
                $ins = Database::pdo()->prepare(
                    'INSERT INTO presenze (tenant_id, cliente_id, appuntamento_id) VALUES (?, ?, ?)'
                );
                $ins->execute([Database::tenantId(), $clienteId, $id]);
            }
        }
    }

    /**
     * Cancella appuntamenti multipli impostando lo stato su 'cancellato' e liberando la capienza.
     * @param int[] $ids
     * @return int Numero di appuntamenti cancellati
     */
    public static function cancellaMultiplo(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (empty($ids)) {
            return 0;
        }
        $pdo = Database::pdo();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE appuntamenti SET stato = 'cancellato' WHERE tenant_id = ? AND id IN ($in)");
        $params = array_merge([Database::tenantId()], $ids);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        foreach ($ids as $id) {
            AuditLogger::log('cambio_stato', 'appuntamento', $id, null, ['stato' => 'cancellato', 'azione' => 'cancella_multiplo']);
        }
        return $count;
    }

    /**
     * Cancella tutti gli appuntamenti di una sessione oraria (data, ora inizio ed eventuale ora fine).
     */
    public static function cancellaSessione(string $data, string $oraInizio, string $oraFine = ''): int
    {
        $pInizio = explode(':', trim($oraInizio));
        $oraInizioClean = sprintf('%02d:%02d:00', (int)($pInizio[0] ?? 0), (int)($pInizio[1] ?? 0));

        $pdo = Database::pdo();
        $sql = "UPDATE appuntamenti SET stato = 'cancellato'
                WHERE tenant_id = ? AND data = ? AND ora_inizio = ? AND stato != 'cancellato'";
        $params = [Database::tenantId(), $data, $oraInizioClean];

        if (trim($oraFine) !== '') {
            $pFine = explode(':', trim($oraFine));
            $oraFineClean = sprintf('%02d:%02d:00', (int)($pFine[0] ?? 0), (int)($pFine[1] ?? 0));
            $sql .= " AND (ora_fine = ? OR ora_fine IS NULL OR ora_fine = '00:00:00')";
            $params[] = $oraFineClean;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();

        AuditLogger::log('cancella_sessione', 'appuntamento', 0, null, [
            'data' => $data,
            'ora_inizio' => $oraInizioClean,
            'cancellati' => $count
        ]);
        return $count;
    }
}
