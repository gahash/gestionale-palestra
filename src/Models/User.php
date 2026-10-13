<?php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\AuditLogger;

final class User
{
    public static function all(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, nome, cognome, email, ruolo, attivo, ultimo_accesso, created_at
             FROM users WHERE tenant_id = ? ORDER BY cognome ASC'
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }

    public static function trainers(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, nome, cognome FROM users WHERE tenant_id = ? AND ruolo = 'trainer' AND attivo = 1 ORDER BY nome"
        );
        $stmt->execute([Database::tenantId()]);
        return $stmt->fetchAll();
    }

    public static function create(string $nome, string $cognome, string $email, string $password, string $ruolo): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (tenant_id, nome, cognome, email, password_hash, ruolo, attivo)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            Database::tenantId(), $nome, $cognome, $email,
            password_hash($password, PASSWORD_BCRYPT), $ruolo,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::log('create', 'user', $id, null, ['email' => $email, 'ruolo' => $ruolo]);
        return $id;
    }

    public static function setAttivo(int $id, bool $attivo): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET attivo = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$attivo ? 1 : 0, $id, Database::tenantId()]);
        AuditLogger::log('update', 'user', $id, null, ['attivo' => $attivo]);
    }

    public static function cambiaPassword(int $id, string $nuovaPassword): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([password_hash($nuovaPassword, PASSWORD_BCRYPT), $id, Database::tenantId()]);
        AuditLogger::log('cambio_password', 'user', $id);
    }
}
