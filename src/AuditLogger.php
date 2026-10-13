<?php
declare(strict_types=1);

namespace App;

/** Traccia le modifiche critiche (pagamenti, cancellazioni, login) per requisito GDPR/enterprise. */
final class AuditLogger
{
    public static function log(
        string $azione,
        string $entita,
        ?int $entitaId = null,
        ?array $datiPrecedenti = null,
        ?array $datiNuovi = null
    ): void {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log (tenant_id, utente_id, azione, entita, entita_id, dati_precedenti, dati_nuovi, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $user = Auth::user();
            $stmt->execute([
                Database::tenantId(),
                $user['id'] ?? null,
                $azione,
                $entita,
                $entitaId,
                $datiPrecedenti ? json_encode($datiPrecedenti, JSON_UNESCAPED_UNICODE) : null,
                $datiNuovi ? json_encode($datiNuovi, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // L'audit log non deve mai bloccare l'operazione principale.
            error_log('AuditLogger: ' . $e->getMessage());
        }
    }
}
