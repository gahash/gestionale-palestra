<?php
declare(strict_types=1);

use App\Csrf;
use App\Models\Lead;

$pageTitle = 'Richieste dal sito';
require __DIR__ . '/_header.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $leadId = (int) $_POST['lead_id'];
    if (($_POST['azione'] ?? '') === 'cambia_stato') {
        Lead::cambiaStato($leadId, $_POST['stato']);
        $flash = 'Stato aggiornato.';
    }
    if (($_POST['azione'] ?? '') === 'converti') {
        $clienteId = Lead::convertiInCliente($leadId, $user['id']);
        header('Location: cliente.php?id=' . $clienteId);
        exit;
    }
}

$statoFiltro = $_GET['stato'] ?? null;
$leads = Lead::all($statoFiltro);
$stati = ['nuovo' => 'Nuovo', 'contattato' => 'Contattato', 'convertito' => 'Convertito', 'archiviato' => 'Archiviato'];
?>

<section class="panel">
  <div class="panel-header">
    <div class="filter-tabs">
      <a class="filter-tab <?= !$statoFiltro ? 'active' : '' ?>" href="?">Tutte</a>
      <?php foreach ($stati as $val => $label): ?>
        <a class="filter-tab <?= $statoFiltro === $val ? 'active' : '' ?>" href="?stato=<?= $val ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($flash): ?><p class="flash-ok"><?= htmlspecialchars($flash) ?></p><?php endif; ?>

  <table class="data-table">
    <thead><tr><th>Ricevuta</th><th>Nome</th><th>Telefono</th><th>Servizio</th><th>Messaggio</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td><?= htmlspecialchars(substr($l['created_at'], 0, 16)) ?></td>
        <td><?= htmlspecialchars($l['nome']) ?></td>
        <td><a href="tel:<?= htmlspecialchars($l['telefono']) ?>"><?= htmlspecialchars($l['telefono']) ?></a></td>
        <td><?= htmlspecialchars($l['servizio_interesse'] ?? '-') ?></td>
        <td><?= htmlspecialchars($l['messaggio'] ?? '-') ?></td>
        <td>
          <form method="post" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="azione" value="cambia_stato">
            <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
            <select name="stato" onchange="this.form.submit()">
              <?php foreach ($stati as $val => $label): ?>
                <option value="<?= $val ?>" <?= $l['stato'] === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td>
          <?php if ($l['stato'] !== 'convertito'): ?>
            <form method="post" class="inline-form">
              <?= Csrf::field() ?>
              <input type="hidden" name="azione" value="converti">
              <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit">Trasforma in cliente</button>
            </form>
          <?php else: ?>
            <span class="badge badge-success">Convertito</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$leads): ?><tr><td colspan="7" class="empty-state">Nessuna richiesta.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
