<?php
declare(strict_types=1);

use App\Models\Cliente;
use App\Models\Abbonamento;
use App\Models\Appuntamento;
use App\Models\Lead;
use App\Models\Pagamento;

$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';

$totClienti = Cliente::count();
$scadenzeImminenti = Abbonamento::scadenziario(7);
$scadenzeMese = Abbonamento::scadenziario(30);
$scaduti = Abbonamento::scadenziario('scaduti');
$leadNuovi = Lead::all('nuovo');
$certificati = Cliente::certificatiInScadenza(30);

$primoGiornoMese = date('Y-m-01');
$oggi = date('Y-m-d');
$fineMese = date('Y-m-t');
$incassiMese = Pagamento::incassiPeriodoSuddivisi($primoGiornoMese, $fineMese);
$ricorrenti = Abbonamento::ricorrentiAttivi();
$sessioniOggi = Appuntamento::raggruppaInSessioni(Appuntamento::delGiorno($oggi));
$daRiscuotere = Pagamento::elencoDaRiscuotere();
$numDaRiscuotere = count($daRiscuotere);
$totaleDaRiscuotere = array_sum(array_column($daRiscuotere, 'saldo_residuo'));
$eur = fn(float $v): string => '€' . number_format($v, 2, ',', '.');
?>

<?php if ($numDaRiscuotere > 0): ?>
  <div class="alert-box" id="alert-da-riscuotere" onclick="toggleListaDaRiscuotere()" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 8px;">
      <span style="font-size: 1.25rem;">⚠️</span>
      <span>
        <strong>Ci <?= $numDaRiscuotere === 1 ? 'è' : 'sono' ?> <?= $numDaRiscuotere ?> pagament<?= $numDaRiscuotere === 1 ? 'o' : 'i' ?> ancora da riscuotere</strong>
        (totale residuo: <strong><?= $eur($totaleDaRiscuotere) ?></strong>).
      </span>
    </div>
    <span class="btn btn-outline btn-sm" id="btn-toggle-da-riscuotere" style="background:#fff; pointer-events: none;">
      Mostra lista clienti (<?= $numDaRiscuotere ?>) &darr;
    </span>
  </div>

  <section class="panel panel-alert" id="sezione-da-riscuotere" style="display: none; margin-bottom: 24px;">
    <div class="panel-header">
      <h2 style="margin:0">⚠️ Pagamenti ancora da riscuotere (<?= $numDaRiscuotere ?> clienti · Totale <?= $eur($totaleDaRiscuotere) ?>)</h2>
      <a class="btn btn-outline btn-sm" href="finanze.php#acconti">Vedi in Finanze &rarr;</a>
    </div>
    <p class="muted">Elenco clienti con pagamenti in sospeso o acconti parziali da completare:</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Telefono</th>
            <th>Abbonamento</th>
            <th>Data</th>
            <th>Importo totale</th>
            <th>Acconti già versati</th>
            <th>Importo residuo ancora da pagare</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($daRiscuotere as $acc): ?>
          <tr>
            <td><strong><a href="cliente.php?id=<?= $acc['cliente_id'] ?>"><?= htmlspecialchars($acc['cliente_nome']) ?></a></strong></td>
            <td>
              <?php if (!empty($acc['telefono'])): ?>
                <a href="https://wa.me/<?= preg_replace('/\D/', '', $acc['telefono']) ?>" target="_blank" title="Contatta su WhatsApp">💬 <?= htmlspecialchars($acc['telefono']) ?></a>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($acc['abbonamento_nome']) ?></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($acc['data']))) ?></td>
            <td><?= $eur($acc['importo_totale']) ?></td>
            <td>
              <?php if ($acc['versato'] > 0): ?>
                <span class="badge badge-info"><?= $eur($acc['versato']) ?></span>
              <?php else: ?>
                <span class="muted">Nessuno (€0,00)</span>
              <?php endif; ?>
            </td>
            <td><strong class="txt-danger" style="font-size: 1.05em;"><?= $eur($acc['saldo_residuo']) ?></strong></td>
            <td style="text-align: right;">
              <a class="btn btn-primary btn-sm" href="cliente.php?id=<?= $acc['cliente_id'] ?>">Incassa saldo &rarr;</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <script>
  function toggleListaDaRiscuotere() {
    var sec = document.getElementById('sezione-da-riscuotere');
    var btn = document.getElementById('btn-toggle-da-riscuotere');
    if (!sec) return;
    if (sec.style.display === 'none' || sec.style.display === '') {
      sec.style.display = 'block';
      sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (btn) btn.innerHTML = 'Nascondi lista &uarr;';
    } else {
      sec.style.display = 'none';
      if (btn) btn.innerHTML = 'Mostra lista clienti (<?= $numDaRiscuotere ?>) &darr;';
    }
  }
  </script>
<?php endif; ?>

<div class="kpi-grid">
  <div class="kpi-card">
    <span class="kpi-label">Clienti attivi</span>
    <span class="kpi-value"><?= $totClienti ?></span>
  </div>
  <div class="kpi-card kpi-success">
    <span class="kpi-label">Incassato questo mese</span>
    <span class="kpi-value"><?= $eur($incassiMese['abbonamenti'] + $incassiMese['altri']) ?></span>
    <span class="kpi-sub">abbonamenti <?= $eur($incassiMese['abbonamenti']) ?> · altro <?= $eur($incassiMese['altri']) ?></span>
  </div>
  <div class="kpi-card <?= $numDaRiscuotere > 0 ? 'kpi-warn' : '' ?>">
    <span class="kpi-label">Da riscuotere</span>
    <span class="kpi-value"><?= $eur($totaleDaRiscuotere) ?></span>
    <span class="kpi-sub"><?= $numDaRiscuotere > 0 ? $numDaRiscuotere . ' cliente/i da saldare' : 'Nessun saldo aperto' ?></span>
  </div>
  <div class="kpi-card kpi-info">
    <span class="kpi-label">Ricorrenti attivi</span>
    <span class="kpi-value"><?= $eur($ricorrenti['valore_mensile']) ?><small>/mese</small></span>
    <span class="kpi-sub"><?= $ricorrenti['n'] ?> abbonament<?= $ricorrenti['n'] === 1 ? 'o' : 'i' ?> in corso</span>
  </div>
  <div class="kpi-card kpi-warn">
    <span class="kpi-label">In scadenza (7gg)</span>
    <span class="kpi-value"><?= count($scadenzeImminenti) ?></span>
  </div>
  <div class="kpi-card kpi-danger">
    <span class="kpi-label">Abbonamenti scaduti</span>
    <span class="kpi-value"><?= count($scaduti) ?></span>
  </div>
  <div class="kpi-card kpi-info">
    <span class="kpi-label">Nuove richieste dal sito</span>
    <span class="kpi-value"><?= count($leadNuovi) ?></span>
  </div>
  <div class="kpi-card kpi-warn">
    <span class="kpi-label">Certificati in scadenza (30gg)</span>
    <span class="kpi-value"><?= count($certificati) ?></span>
  </div>
</div>

<div class="grid-2">
  <section class="panel">
    <div class="panel-header">
      <h2 style="margin:0">Sessioni di oggi</h2>
      <a class="btn btn-outline btn-sm" href="appuntamenti.php">Calendario settimana &rarr;</a>
    </div>
    <?php if (!$sessioniOggi): ?>
      <p class="empty-state">Nessuna sessione prenotata per oggi.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Ora</th><th>Posti</th><th>Clienti</th></tr></thead>
        <tbody>
        <?php foreach ($sessioniOggi as $s): ?>
          <tr>
            <td><strong><?= $s['ora_inizio'] ?></strong>–<?= $s['ora_fine'] ?></td>
            <td><span class="badge <?= $s['occupati'] >= $s['capienza_max'] ? 'badge-danger' : 'badge-success' ?>"><?= $s['occupati'] ?>/<?= $s['capienza_max'] ?></span></td>
            <td><?= htmlspecialchars(implode(', ', array_map(fn($p) => $p['nome'], $s['partecipanti']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Scadenze abbonamento imminenti</h2>
    <?php if (!$scadenzeMese): ?>
      <p class="empty-state">Nessuna scadenza nei prossimi 30 giorni.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Cliente</th><th>Piano</th><th>Scadenza</th><th>Telefono</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($scadenzeMese, 0, 8) as $s): ?>
          <tr>
            <td><a href="cliente.php?id=<?= $s['cliente_id'] ?>"><?= htmlspecialchars($s['nome'] . ' ' . $s['cognome']) ?></a></td>
            <td><?= htmlspecialchars($s['piano_nome']) ?></td>
            <td><?= htmlspecialchars($s['data_scadenza']) ?></td>
            <td><?= htmlspecialchars($s['telefono'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <a href="abbonamenti.php">Vedi tutto lo scadenziario &rarr;</a>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>Ultime richieste dal sito</h2>
  <?php if (!$leadNuovi): ?>
    <p class="empty-state">Nessuna nuova richiesta.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Nome</th><th>Telefono</th><th>Servizio</th><th>Ricevuta</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($leadNuovi, 0, 8) as $l): ?>
        <tr>
          <td><?= htmlspecialchars($l['nome']) ?></td>
          <td><?= htmlspecialchars($l['telefono']) ?></td>
          <td><?= htmlspecialchars($l['servizio_interesse'] ?? '-') ?></td>
          <td><?= htmlspecialchars($l['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <a href="leads.php">Gestisci tutte le richieste &rarr;</a>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
