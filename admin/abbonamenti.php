<?php
declare(strict_types=1);

use App\Models\Abbonamento;

$pageTitle = 'Scadenziario abbonamenti';
require __DIR__ . '/_header.php';

$filtro = $_GET['filtro'] ?? '30';
$q = trim((string) ($_GET['q'] ?? ''));

if (($_GET['export'] ?? '') === 'csv') {
    $rows = Abbonamento::scadenziario($filtro, $q ?: null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="scadenziario.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Cliente', 'Telefono', 'Email', 'Piano', 'Scadenza', 'Stato']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['nome'] . ' ' . $r['cognome'], $r['telefono'], $r['email'], $r['piano_nome'], $r['data_scadenza'], $r['stato']]);
    }
    fclose($out);
    exit;
}

$rows = Abbonamento::scadenziario($filtro, $q ?: null);
$filtri = ['7' => '7 giorni', '15' => '15 giorni', '30' => '30 giorni', 'scaduti' => 'Scaduti', 'attivi' => 'Tutti gli attivi'];
?>

<section class="panel">
  <div class="panel-header" style="flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
      <div class="filter-tabs">
        <?php foreach ($filtri as $val => $label): ?>
          <a class="filter-tab <?= $filtro === $val ? 'active' : '' ?>" href="?filtro=<?= $val ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </div>
      <form method="get" class="inline-search">
        <?php if ($filtro !== '30'): ?>
          <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
        <?php endif; ?>
        <input type="search" name="q" id="search-scadenziario" value="<?= htmlspecialchars($q) ?>" placeholder="Cerca cliente, telefono, piano...">
        <button class="btn btn-outline" type="submit">Cerca</button>
        <?php if ($q !== ''): ?>
          <a class="btn btn-outline" href="?filtro=<?= htmlspecialchars($filtro) ?>" title="Azzera ricerca">✕</a>
        <?php endif; ?>
      </form>
    </div>
    <a class="btn btn-outline btn-sm" href="?filtro=<?= htmlspecialchars($filtro) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>&export=csv">⬇ Esporta CSV</a>
  </div>

  <table class="data-table">
    <thead><tr><th>Cliente</th><th>Piano</th><th>Scadenza</th><th>Telefono</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $giorni = (int) floor((strtotime($r['data_scadenza']) - strtotime(date('Y-m-d'))) / 86400);
        $badge = $giorni < 0 ? 'badge-danger' : ($giorni <= 7 ? 'badge-warn' : 'badge-success');
      ?>
      <tr>
        <td><a href="cliente.php?id=<?= $r['cliente_id'] ?>"><?= htmlspecialchars($r['nome'] . ' ' . $r['cognome']) ?></a></td>
        <td><?= htmlspecialchars($r['piano_nome']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['data_scadenza']) ?> (<?= $giorni ?>gg)</span></td>
        <td><?= htmlspecialchars($r['telefono'] ?? '-') ?></td>
        <td><a class="btn btn-outline btn-sm" href="cliente.php?id=<?= $r['cliente_id'] ?>">Apri scheda</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr>
        <td colspan="5" class="empty-state">
          <?= $q !== '' ? 'Nessun risultato trovato per "' . htmlspecialchars($q) . '".' : 'Nessun risultato per questo filtro.' ?>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('search-scadenziario');
  const tbody = document.querySelector('.data-table tbody');
  if (!searchInput || !tbody) return;

  const dataRows = Array.from(tbody.querySelectorAll('tr')).filter(function (r) {
    return !r.querySelector('.empty-state');
  });
  if (dataRows.length === 0) return;

  let liveEmpty = null;

  searchInput.addEventListener('input', function () {
    const term = this.value.trim().toLowerCase();
    let matches = 0;

    dataRows.forEach(function (row) {
      const text = row.textContent.toLowerCase();
      const match = !term || text.indexOf(term) !== -1;
      row.style.display = match ? '' : 'none';
      if (match) matches++;
    });

    if (matches === 0) {
      if (!liveEmpty) {
        liveEmpty = document.createElement('tr');
        liveEmpty.id = 'scadenziario-empty-live';
        liveEmpty.innerHTML = '<td colspan="5" class="empty-state"></td>';
        tbody.appendChild(liveEmpty);
      }
      liveEmpty.style.display = '';
      liveEmpty.querySelector('.empty-state').textContent = 'Nessun abbonamento corrispondente alla ricerca.';
    } else if (liveEmpty) {
      liveEmpty.style.display = 'none';
    }
  });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>
