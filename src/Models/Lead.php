<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class Lead
{
    public static function all(?string $stato = null): array
    {
        $sql = 'SELECT * FROM leads WHERE tenant_id = ?';
        $params = [Database::tenantId()];
        if ($stato) {
            $sql .= ' AND stato = ?';
            $params[] = $stato;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(string $stato = 'nuovo'): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) c FROM leads WHERE tenant_id = ? AND stato = ?');
        $stmt->execute([Database::tenantId(), $stato]);
        return (int) $stmt->fetch()['c'];
    }

    /** Crea un lead dal form pubblico della landing page. Ritorna l'id creato. */
    public static function creaDaLanding(array $d, string $ip): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO leads (tenant_id, nome, telefono, email, servizio_interesse, giorno_preferito, fascia_oraria, messaggio, gdpr_consenso, ip_richiesta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Database::tenantId(),
            $d['nome'], $d['telefono'], $d['email'] ?: null, $d['servizio_interesse'] ?: null,
            $d['giorno_preferito'] ?: null, $d['fascia_oraria'] ?: null, $d['messaggio'] ?: null,
            !empty($d['gdpr_consenso']) ? 1 : 0, $ip,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function cambiaStato(int $id, string $stato): void
    {
        $stmt = Database::pdo()->prepare('UPDATE leads SET stato = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$stato, $id, Database::tenantId()]);
        AuditLogger::log('cambio_stato', 'lead', $id, null, ['stato' => $stato]);
    }

    /** Trasforma un lead in scheda cliente (self-service reception -> gestionale). */
    public static function convertiInCliente(int $leadId, ?int $operatoreId): int
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM leads WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$leadId, Database::tenantId()]);
        $lead = $stmt->fetch();
        if (!$lead) {
            throw new \RuntimeException('Lead non trovato');
        }
        [$nome, $cognome] = array_pad(explode(' ', $lead['nome'], 2), 2, '');
        $clienteId = Cliente::create([
            'nome' => $nome ?: $lead['nome'],
            'cognome' => $cognome ?: '-',
            'telefono' => $lead['telefono'],
            'email' => $lead['email'],
            'note' => 'Convertito da richiesta landing page: ' . ($lead['messaggio'] ?? ''),
            'gdpr_consenso' => $lead['gdpr_consenso'],
        ], $operatoreId);

        $upd = Database::pdo()->prepare('UPDATE leads SET stato = "convertito", cliente_id = ? WHERE id = ?');
        $upd->execute([$clienteId, $leadId]);
        AuditLogger::log('converti', 'lead', $leadId, null, ['cliente_id' => $clienteId]);
        return $clienteId;
    }
}
