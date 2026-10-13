<?php
declare(strict_types=1);

use App\Auth;
use App\Database;

$pageTitle = 'Audit log';
require __DIR__ . '/_header.php';

Auth::requireRole(['admin']);

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$perPagina = 40;
$offset = ($pagina - 1) * $perPagina;

$stmt = Database::pdo()->prepare(
    'SELECT a.*, u.nome, u.cognome FROM audit_log a
     LEFT JOIN users u ON u.id = a.utente_id
     WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, \App\Database::tenantId(), PDO::PARAM_INT);
$stmt->bindValue(2, $perPagina, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$righe = $stmt->fetchAll();
?>

<section class="panel">
  <h2>Registro modifiche critiche</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Utente</th><th>Azione</th><th>Entità</th><th>ID</th><th>Dettagli</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($righe as $r): ?>
      <tr>
        <td><?= htmlspecialchars(substr($r['created_at'], 0, 19)) ?></td>
        <td><?= htmlspecialchars(trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: 'sistema') ?></td>
        <td><?= htmlspecialchars($r['azione']) ?></td>
        <td><?= htmlspecialchars($r['entita']) ?></td>
        <td><?= htmlspecialchars((string) ($r['entita_id'] ?? '-')) ?></td>
        <td><code class="audit-json"><?= htmlspecialchars(mb_substr((string) ($r['dati_nuovi'] ?? $r['dati_precedenti'] ?? ''), 0, 120)) ?></code></td>
        <td><?= htmlspecialchars($r['ip'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$righe): ?><tr><td colspan="7" class="empty-state">Nessuna voce registrata.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="pagination">
    <?php if ($pagina > 1): ?><a class="btn btn-outline btn-sm" href="?pagina=<?= $pagina - 1 ?>">&larr; Precedente</a><?php endif; ?>
    <?php if (count($righe) === $perPagina): ?><a class="btn btn-outline btn-sm" href="?pagina=<?= $pagina + 1 ?>">Successiva &rarr;</a><?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
