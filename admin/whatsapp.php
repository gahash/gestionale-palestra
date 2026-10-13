<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Database;
use App\Config;

$pageTitle = 'WhatsApp';
require __DIR__ . '/_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'salva_template' && Auth::hasRole('admin', 'staff')) {
    Csrf::requireValid();
    $stmt = Database::pdo()->prepare('UPDATE whatsapp_templates SET testo = ?, attivo = ? WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$_POST['testo'], !empty($_POST['attivo']) ? 1 : 0, (int) $_POST['id'], Database::tenantId()]);
    header('Location: whatsapp.php');
    exit;
}

$stmt = Database::pdo()->prepare('SELECT * FROM whatsapp_templates WHERE tenant_id = ? ORDER BY id');
$stmt->execute([Database::tenantId()]);
$templates = $stmt->fetchAll();

$stmtLog = Database::pdo()->prepare(
    'SELECT w.*, c.nome, c.cognome FROM whatsapp_log w
     LEFT JOIN clienti c ON c.id = w.cliente_id
     WHERE w.tenant_id = ? ORDER BY w.created_at DESC LIMIT 50'
);
$stmtLog->execute([Database::tenantId()]);
$log = $stmtLog->fetchAll();

$attivo = Config::bool('WHATSAPP_ENABLED', false);
?>

<?php if (!$attivo): ?>
  <div class="alert-box">
    ⚠️ L'invio reale via WhatsApp è <strong>disattivato</strong> (<code>WHATSAPP_ENABLED=false</code> nel file <code>.env</code>).
    I messaggi vengono registrati nel log ma non recapitati finché non configuri un provider (Meta Cloud API o Twilio) e le relative chiavi API nel <code>.env</code>.
  </div>
<?php endif; ?>

<section class="panel">
  <h2>Template messaggi</h2>
  <?php foreach ($templates as $t): ?>
    <form method="post" class="template-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="azione" value="salva_template">
      <input type="hidden" name="id" value="<?= $t['id'] ?>">
      <label><?= htmlspecialchars($t['nome']) ?> <code>[<?= htmlspecialchars($t['chiave']) ?>]</code></label>
      <textarea name="testo" rows="2"><?= htmlspecialchars($t['testo']) ?></textarea>
      <div class="template-actions">
        <label class="checkbox-label"><input type="checkbox" name="attivo" value="1" <?= $t['attivo'] ? 'checked' : '' ?>> Attivo</label>
        <button class="btn btn-outline btn-sm" type="submit">Salva</button>
      </div>
    </form>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2>Log invii recenti</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Cliente</th><th>Telefono</th><th>Stato</th><th>Messaggio</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr>
        <td><?= htmlspecialchars(substr($l['created_at'], 0, 16)) ?></td>
        <td><?= htmlspecialchars(trim(($l['nome'] ?? '') . ' ' . ($l['cognome'] ?? '')) ?: '-') ?></td>
        <td><?= htmlspecialchars($l['telefono']) ?></td>
        <td><span class="badge badge-<?= $l['stato'] === 'inviato' ? 'success' : ($l['stato'] === 'errore' ? 'danger' : 'muted') ?>"><?= htmlspecialchars($l['stato']) ?></span></td>
        <td><?= htmlspecialchars($l['messaggio']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$log): ?><tr><td colspan="5" class="empty-state">Nessun messaggio inviato.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
