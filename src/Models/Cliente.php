<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class Cliente
{
    public static function all(?string $search = null, bool $soloAttivi = true): array
    {
        $pdo = Database::pdo();
        $sql = 'SELECT * FROM clienti WHERE tenant_id = ?';
        if ($soloAttivi) {
            $sql .= ' AND attivo = 1';
        }
        $params = [Database::tenantId()];
        if ($search) {
            $sql .= ' AND (nome LIKE ? OR cognome LIKE ? OR telefono LIKE ? OR email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY cognome ASC, nome ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM clienti WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, Database::tenantId()]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, ?int $creatoDa): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO clienti
             (tenant_id, nome, cognome, data_nascita, codice_fiscale, telefono, email, indirizzo, note,
              gdpr_consenso, gdpr_consenso_data, creato_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Database::tenantId(),
            trim((string)($data['nome'] ?? '')),
            trim((string)($data['cognome'] ?? '')),
            !empty($data['data_nascita']) ? $data['data_nascita'] : null,
            !empty($data['codice_fiscale']) ? strtoupper(trim((string)$data['codice_fiscale'])) : null,
            !empty($data['telefono']) ? trim((string)$data['telefono']) : null,
            !empty($data['email']) ? trim((string)$data['email']) : null,
            !empty($data['indirizzo']) ? trim((string)$data['indirizzo']) : null,
            !empty($data['note']) ? trim((string)$data['note']) : null,
            !empty($data['gdpr_consenso']) ? 1 : 0,
            !empty($data['gdpr_consenso']) ? date('Y-m-d H:i:s') : null,
            $creatoDa,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'cliente', $id, null, $data);
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $before = self::find($id);
        $stmt = Database::pdo()->prepare(
            'UPDATE clienti SET nome=?, cognome=?, data_nascita=?, codice_fiscale=?, telefono=?, email=?,
             indirizzo=?, note=?, gdpr_consenso=? WHERE id=? AND tenant_id=?'
        );
        $stmt->execute([
            trim((string)($data['nome'] ?? '')),
            trim((string)($data['cognome'] ?? '')),
            !empty($data['data_nascita']) ? $data['data_nascita'] : null,
            !empty($data['codice_fiscale']) ? strtoupper(trim((string)$data['codice_fiscale'])) : null,
            !empty($data['telefono']) ? trim((string)$data['telefono']) : null,
            !empty($data['email']) ? trim((string)$data['email']) : null,
            !empty($data['indirizzo']) ? trim((string)$data['indirizzo']) : null,
            !empty($data['note']) ? trim((string)$data['note']) : null,
            !empty($data['gdpr_consenso']) ? 1 : 0,
            $id, Database::tenantId(),
        ]);
        AuditLogger::log('update', 'cliente', $id, $before, $data);
    }

    public static function riattiva(int $id): void
    {
        $before = self::find($id);
        $stmt = Database::pdo()->prepare('UPDATE clienti SET attivo=1 WHERE id=? AND tenant_id=?');
        $stmt->execute([$id, Database::tenantId()]);
        AuditLogger::log('riattiva', 'cliente', $id, $before, ['attivo' => 1]);
    }

    public static function setCertificato(int $id, ?string $path, ?string $rilascio, ?string $scadenza): void
    {
        if ($path !== null) {
            $stmt = Database::pdo()->prepare(
                'UPDATE clienti SET certificato_medico_path=?, certificato_rilascio=?, certificato_scadenza=?
                 WHERE id=? AND tenant_id=?'
            );
            $stmt->execute([$path, $rilascio ?: null, $scadenza ?: null, $id, Database::tenantId()]);
        } else {
            $stmt = Database::pdo()->prepare(
                'UPDATE clienti SET certificato_rilascio=?, certificato_scadenza=?
                 WHERE id=? AND tenant_id=?'
            );
            $stmt->execute([$rilascio ?: null, $scadenza ?: null, $id, Database::tenantId()]);
        }
        AuditLogger::log('update_certificato', 'cliente', $id, null, ['scadenza' => $scadenza, 'rilascio' => $rilascio]);
    }

    public static function setFoto(int $id, string $path): void
    {
        $stmt = Database::pdo()->prepare('UPDATE clienti SET foto_path=? WHERE id=? AND tenant_id=?');
        $stmt->execute([$path, $id, Database::tenantId()]);
    }

    public static function softDelete(int $id): void
    {
        $before = self::find($id);
        $pdo = Database::pdo();
        $tenantId = Database::tenantId();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE clienti SET attivo=0 WHERE id=? AND tenant_id=?');
            $stmt->execute([$id, $tenantId]);

            // Cancella gli appuntamenti futuri del cliente per liberare i posti nel calendario
            $stmtApp = $pdo->prepare(
                "UPDATE appuntamenti SET stato = 'cancellato'
                 WHERE tenant_id = ? AND cliente_id = ? AND data >= CURDATE() AND stato != 'cancellato'"
            );
            $stmtApp->execute([$tenantId, $id]);

            // Annulla eventuali abbonamenti attivi per non generare falsi rinnovi o notifiche fantasma
            $stmtAbb = $pdo->prepare(
                "UPDATE abbonamenti SET stato = 'cancellato'
                 WHERE tenant_id = ? AND cliente_id = ? AND stato = 'attivo'"
            );
            $stmtAbb->execute([$tenantId, $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLogger::log('delete', 'cliente', $id, $before, null);
    }

    public static function softDeleteMultiplo(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (empty($ids)) {
            return 0;
        }
        $pdo = Database::pdo();
        $tenantId = Database::tenantId();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE clienti SET attivo = 0 WHERE tenant_id = ? AND id IN ($in)");
            $params = array_merge([$tenantId], $ids);
            $stmt->execute($params);
            $count = $stmt->rowCount();

            // Cancella gli appuntamenti futuri di tutti i clienti disattivati
            $stmtApp = $pdo->prepare(
                "UPDATE appuntamenti SET stato = 'cancellato'
                 WHERE tenant_id = ? AND cliente_id IN ($in) AND data >= CURDATE() AND stato != 'cancellato'"
            );
            $stmtApp->execute($params);

            // Annulla eventuali abbonamenti attivi di tutti i clienti disattivati
            $stmtAbb = $pdo->prepare(
                "UPDATE abbonamenti SET stato = 'cancellato'
                 WHERE tenant_id = ? AND cliente_id IN ($in) AND stato = 'attivo'"
            );
            $stmtAbb->execute($params);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        foreach ($ids as $id) {
            AuditLogger::log('delete_multiplo', 'cliente', $id, null, null);
        }
        return $count;
    }

    /** Certificati medici in scadenza entro N giorni (o gia' scaduti). */
    public static function certificatiInScadenza(int $giorni = 30): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, nome, cognome, telefono, certificato_scadenza FROM clienti
             WHERE tenant_id = ? AND attivo = 1 AND certificato_scadenza IS NOT NULL
             AND certificato_scadenza <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY certificato_scadenza ASC'
        );
        $stmt->execute([Database::tenantId(), $giorni]);
        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) c FROM clienti WHERE tenant_id = ? AND attivo = 1');
        $stmt->execute([Database::tenantId()]);
        return (int) $stmt->fetch()['c'];
    }

    /**
     * Cerca un cliente esistente per nome/cognome. Se non esiste, lo crea automaticamente.
     */
    public static function trovaOCreaPerNome(string $nomeCompleto, ?int $creatoDa = null): int
    {
        $nomeCompleto = trim($nomeCompleto);
        if ($nomeCompleto === '') {
            throw new \InvalidArgumentException('Il nome del cliente non può essere vuoto.');
        }

        $tenantId = Database::tenantId();
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT id FROM clienti
             WHERE tenant_id = ? AND attivo = 1
               AND (
                 LOWER(CONCAT(TRIM(nome), " ", TRIM(cognome))) = LOWER(?)
                 OR LOWER(CONCAT(TRIM(cognome), " ", TRIM(nome))) = LOWER(?)
                 OR LOWER(TRIM(nome)) = LOWER(?)
                 OR LOWER(TRIM(cognome)) = LOWER(?)
               )
             LIMIT 1'
        );
        $stmt->execute([$tenantId, $nomeCompleto, $nomeCompleto, $nomeCompleto, $nomeCompleto]);
        $trovato = $stmt->fetch();
        if ($trovato) {
            return (int) $trovato['id'];
        }

        $parti = preg_split('/\s+/', $nomeCompleto, 2);
        $nome = $parti[0] ?? '';
        $cognome = !empty($parti[1]) ? $parti[1] : '-';

        return self::create([
            'nome' => $nome,
            'cognome' => $cognome,
            'data_nascita' => null,
            'codice_fiscale' => null,
            'telefono' => null,
            'email' => null,
            'indirizzo' => null,
            'note' => 'Inserito da prenotazione',
            'gdpr_consenso' => 0,
        ], $creatoDa);
    }
}

