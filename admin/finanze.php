<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Models\Abbonamento;
use App\Models\EntrataUscita;
use App\Models\Pagamento;

$pageTitle = 'Finanze';
require __DIR__ . '/_header.php';

$mesiIt = ['01' => 'Gennaio', '02' => 'Febbraio', '03' => 'Marzo', '04' => 'Aprile', '05' => 'Maggio', '06' => 'Giugno', '07' => 'Luglio', '08' => 'Agosto', '09' => 'Settembre', '10' => 'Ottobre', '11' => 'Novembre', '12' => 'Dicembre'];
$periodo = $_GET['periodo'] ?? 'mese';
$oggi = date('Y-m-d');

if (preg_match('/^(\d{4})-(\d{2})$/', $periodo, $mMatch)) {
    $annoSel = (int) $mMatch[1];
    $meseSel = $mMatch[2];
    $dataInizio = sprintf('%04d-%02d-01', $annoSel, (int) $meseSel);
    $dataFine = date('Y-m-t', strtotime($dataInizio));
    $labelPeriodo = ($mesiIt[$meseSel] ?? $meseSel) . ' ' . $annoSel;
} else {
    switch ($periodo) {
        case 'trimestre':
            $dataInizio = date('Y-m-01', strtotime('-2 months'));
            $dataFine = date('Y-m-t');
            $labelPeriodo = 'ultimi 3 mesi';
            break;
        case 'anno':
            $dataInizio = date('Y-01-01');
            $dataFine = date('Y-12-31');
            $labelPeriodo = 'anno ' . date('Y');
            break;
        case 'tutto':
            $dataInizio = '2000-01-01';
            $dataFine = '2099-12-31';
            $labelPeriodo = 'dall\'inizio';
            break;
        default:
            $periodo = 'mese';
            $dataInizio = date('Y-m-01');
            $dataFine = date('Y-m-t');
            $labelPeriodo = 'mese corrente (' . ($mesiIt[date('m')] ?? date('m')) . ')';
    }
}

$flash = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $azione = $_POST['azione'] ?? '';
    try {
        if ($azione === 'registra') {
            EntrataUscita::registra(
                $_POST['tipo'], $_POST['categoria'], (float) $_POST['importo'],
                $_POST['descrizione'] ?: null, $_POST['data'] ?: date('Y-m-d'), $user['id']
            );
            header('Location: finanze.php?periodo=' . $periodo . '&ok=movimento');
            exit;
        }
        if ($azione === 'elimina_movimento' && Auth::hasRole('admin', 'staff')) {
            $movId = (int) ($_POST['movimento_id'] ?? 0);
            if ($movId > 0) {
                EntrataUscita::elimina($movId);
                header('Location: finanze.php?periodo=' . $periodo . '&ok=eliminato');
                exit;
            }
        }
        if ($azione === 'elimina_pagamento' && Auth::hasRole('admin', 'staff')) {
            $pagId = (int) ($_POST['pagamento_id'] ?? 0);
            if ($pagId > 0) {
                Pagamento::elimina($pagId);
                header('Location: finanze.php?periodo=' . $periodo . '&ok=pagamento_eliminato');
                exit;
            }
        }
        if ($azione === 'registra_pagamento_mancante' && Auth::hasRole('admin', 'staff')) {
            $ids = isset($_POST['abbonamento_ids']) ? array_map('intval', (array) $_POST['abbonamento_ids']) : [];
            $candidati = $_POST['candidato'] ?? []; // abbonamento_id => pagamento_id libero da collegare
            $creati = 0;
            $collegati = 0;
            foreach ($ids as $abbId) {
                if ($abbId <= 0) {
                    continue;
                }
                $candId = (int) ($candidati[$abbId] ?? 0);
                if ($candId > 0 && Abbonamento::collegaPagamento($abbId, $candId)) {
                    $collegati++;
                } elseif (Abbonamento::registraPagamentoMancante($abbId, $_POST['metodo'] ?: 'contanti', $user['id'])) {
                    $creati++;
                }
            }
            header('Location: finanze.php?periodo=' . $periodo . '&ok=recuperati&n=' . $creati . '&c=' . $collegati);
            exit;
        }
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

if (($_GET['ok'] ?? '') === 'movimento') {
    $flash = 'Movimento registrato.';
} elseif (($_GET['ok'] ?? '') === 'eliminato') {
    $flash = 'Movimento manuale eliminato con successo.';
} elseif (($_GET['ok'] ?? '') === 'pagamento_eliminato') {
    $flash = 'Pagamento eliminato con successo. I residui e i totali sono stati aggiornati.';
} elseif (($_GET['ok'] ?? '') === 'recuperati') {
    $flash = 'Fatto: ' . (int) ($_GET['n'] ?? 0) . ' pagament' . ((int) ($_GET['n'] ?? 0) === 1 ? 'o' : 'i') . ' registrat' . ((int) ($_GET['n'] ?? 0) === 1 ? 'o' : 'i')
        . ', ' . (int) ($_GET['c'] ?? 0) . ' collegat' . ((int) ($_GET['c'] ?? 0) === 1 ? 'o' : 'i') . ' a incassi già presenti. Ora compaiono nei totali abbonamenti.';
}

$movimentiManuali = EntrataUscita::periodo($dataInizio, $dataFine);
$pagamentiPeriodo = Pagamento::periodo($dataInizio, $dataFine);

// Registro unificato (pagamenti clienti + movimenti manuali), ordinato per data decrescente.
$registro = [];
foreach ($pagamentiPeriodo as $p) {
    $registro[] = [
        'id' => (int) $p['id'],
        'data' => substr($p['created_at'], 0, 10),
        'ora' => substr($p['created_at'], 11, 5),
        'tipo' => 'entrata',
        'origine' => $p['abbonamento_id'] ? 'Abbonamento' : 'Incasso cliente',
        'categoria' => $p['abbonamento_id'] ? ($p['piano_nome'] ?? 'Abbonamento') : ($p['causale'] ?: 'Pagamento libero'),
        'importo' => (float) $p['importo'],
        'descrizione' => trim($p['nome'] . ' ' . $p['cognome']) . ' · ' . $p['metodo'] . ($p['acconto'] ? ' · acconto (residuo €' . number_format((float) $p['saldo_residuo'], 2, ',', '.') . ')' : ''),
        'link' => 'cliente.php?id=' . $p['cliente_id'],
        'manuale_id' => null,
    ];
}
foreach ($movimentiManuali as $m) {
    $registro[] = [
        'id' => (int) $m['id'],
        'data' => $m['data'],
        'ora' => substr($m['created_at'], 11, 5),
        'tipo' => $m['tipo'],
        'origine' => 'Manuale',
        'categoria' => $m['categoria'],
        'importo' => (float) $m['importo'],
        'descrizione' => $m['descrizione'] ?? '',
        'link' => null,
        'manuale_id' => (int) $m['id'],
    ];
}
usort($registro, fn($a, $b) => strcmp($b['data'] . $b['ora'], $a['data'] . $a['ora']));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finanze_' . $periodo . '_' . $oggi . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data', 'Ora', 'Tipo', 'Origine', 'Categoria/Piano', 'Importo', 'Dettaglio'], ';');
    foreach ($registro as $r) {
        fputcsv($out, [$r['data'], $r['ora'], $r['tipo'], $r['origine'], $r['categoria'], number_format($r['importo'], 2, ',', ''), $r['descrizione']], ';');
    }
    fclose($out);
    exit;
}

$totaliManuali = EntrataUscita::totali($dataInizio, $dataFine);
$incassi = Pagamento::incassiPeriodoSuddivisi($dataInizio, $dataFine);
$incassiMeseCorrente = Pagamento::incassiPeriodoSuddivisi(date('Y-m-01'), date('Y-m-t'));
$ricorrenti = Abbonamento::ricorrentiAttivi();
$ricorrentiPiano = Abbonamento::ricorrentiPerPiano();
$storico = Pagamento::storicoAbbonamenti();
$perMetodo = Pagamento::perMetodoPeriodo($dataInizio, $dataFine);
$mensile = Pagamento::riepilogoMensile(12);
$accontiAperti = Pagamento::elencoAccontiAperti();
$saldiAperti = array_sum(array_column($accontiAperti, 'saldo_residuo'));
$senzaPagamento = Abbonamento::senzaPagamento();
$iscrittiVsDisdette = EntrataUscita::nuoviIscrittiVsDisdette($dataInizio, $dataFine);

// Elenco analitico di tutti gli abbonamenti attivi, rinnovati o che coprono il periodo
$abbonamentiPeriodo = Abbonamento::nelPeriodo($dataInizio, $dataFine);
$totValoreAbbonamenti = array_sum(array_column($abbonamentiPeriodo, 'prezzo_pagato'));
$totVersatoAbbonamenti = array_sum(array_column($abbonamentiPeriodo, 'versato'));
$totResiduoAbbonamenti = array_sum(array_column($abbonamentiPeriodo, 'saldo_residuo'));

$entrateTotali = $incassi['abbonamenti'] + $incassi['altri'] + $totaliManuali['entrata'];
$bilancio = $entrateTotali - $totaliManuali['uscita'];

$eur = fn(float $v): string => '€' . number_format($v, 2, ',', '.');
$mesiBrevi = ['01' => 'Gen', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mag', '06' => 'Giu', '07' => 'Lug', '08' => 'Ago', '09' => 'Set', '10' => 'Ott', '11' => 'Nov', '12' => 'Dic'];
$labelMese = fn(string $ym): string => ($mesiBrevi[substr($ym, 5, 2)] ?? substr($ym, 5, 2)) . ' ' . substr($ym, 2, 2);
?>

<?php if ($flash): ?><p class="flash-ok"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if ($flashErr): ?><p class="flash-err"><?= htmlspecialchars($flashErr) ?></p><?php endif; ?>

<?php if ($senzaPagamento): ?>
<section class="panel panel-alert">
  <div class="panel-header">
    <h2 style="margin:0">⚠️ Abbonamenti senza pagamento registrato (<?= count($senzaPagamento) ?>)</h2>
  </div>
  <p class="muted">Questi abbonamenti hanno un prezzo ma nessun incasso collegato: finché non sistemi la cosa <strong>non compaiono nei totali abbonamenti</strong>. Per ognuno viene proposta l'azione giusta: <em>collegare</em> un incasso libero già registrato (stesso cliente, stesso importo, stessi giorni) oppure <em>registrare</em> un nuovo pagamento con data contabile = inizio abbonamento.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="registra_pagamento_mancante">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.chk-abb').forEach(c=>c.checked=this.checked)" aria-label="Seleziona tutti"></th><th>Cliente</th><th>Piano</th><th>Inizio</th><th>Stato</th><th>Prezzo</th><th>Azione proposta</th></tr></thead>
      <tbody>
      <?php foreach ($senzaPagamento as $a): ?>
        <tr>
          <td><input type="checkbox" class="chk-abb" name="abbonamento_ids[]" value="<?= $a['id'] ?>" checked></td>
          <td><a href="cliente.php?id=<?= $a['cliente_id'] ?>"><?= htmlspecialchars($a['nome'] . ' ' . $a['cognome']) ?></a></td>
          <td><?= htmlspecialchars($a['piano_nome']) ?></td>
          <td><?= htmlspecialchars($a['data_inizio']) ?></td>
          <td><span class="badge badge-muted"><?= htmlspecialchars($a['stato']) ?></span></td>
          <td><strong><?= $eur((float) $a['prezzo_pagato']) ?></strong></td>
          <td>
            <?php if (!empty($a['pagamento_candidato_id'])): ?>
              <input type="hidden" name="candidato[<?= $a['id'] ?>]" value="<?= (int) $a['pagamento_candidato_id'] ?>">
              <span class="badge badge-info">Collega incasso esistente #<?= (int) $a['pagamento_candidato_id'] ?></span>
            <?php else: ?>
              <span class="badge badge-warn">Registra nuovo pagamento</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="form-inline mt-16">
      <label style="margin:0">Metodo (per i nuovi pagamenti)</label>
      <select name="metodo" style="width:auto"><option value="contanti">Contanti</option><option value="carta">Carta</option><option value="bonifico">Bonifico</option><option value="altro">Altro</option></select>
      <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Applicare le azioni proposte agli abbonamenti selezionati?')">Applica alle righe selezionate</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-header" style="flex-wrap: wrap; gap: 12px; align-items: center;">
    <div class="filter-tabs" style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
      <a class="filter-tab <?= $periodo === 'mese' ? 'active' : '' ?>" href="?periodo=mese">Mese corrente</a>
      <a class="filter-tab <?= $periodo === 'trimestre' ? 'active' : '' ?>" href="?periodo=trimestre">Trimestre</a>
      <a class="filter-tab <?= $periodo === 'anno' ? 'active' : '' ?>" href="?periodo=anno">Anno</a>
      <a class="filter-tab <?= $periodo === 'tutto' ? 'active' : '' ?>" href="?periodo=tutto">Tutto</a>
      <div style="display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; background: rgba(0,0,0,0.03); padding: 3px 8px; border-radius: 6px;">
        <label for="sel-mese" style="margin: 0; font-size: 0.85rem; font-weight: 600;">Mese specifico:</label>
        <input type="month" id="sel-mese" value="<?= preg_match('/^\d{4}-\d{2}$/', $periodo) ? $periodo : date('Y-m') ?>" 
               onchange="if(this.value) location.href='?periodo=' + this.value" 
               style="padding: 3px 8px; border-radius: 4px; border: 1px solid var(--border, #ccc); font-size: 0.85rem;">
      </div>
    </div>
    <a class="btn btn-outline btn-sm" href="?periodo=<?= urlencode($periodo) ?>&export=csv">⬇ Esporta CSV</a>
  </div>

  <h2 class="section-title">Entrate da abbonamenti · <?= htmlspecialchars($labelPeriodo) ?></h2>
  <div class="kpi-grid">
    <div class="kpi-card kpi-success">
      <span class="kpi-label">Incassi effettivi abbonamenti</span>
      <span class="kpi-value"><?= $eur($incassi['abbonamenti']) ?></span>
      <span class="kpi-sub">pagamenti incassati dal <?= date('d/m/Y', strtotime($dataInizio)) ?> al <?= date('d/m/Y', strtotime($dataFine)) ?></span>
    </div>
    <div class="kpi-card kpi-info">
      <span class="kpi-label">Abbonamenti del periodo</span>
      <span class="kpi-value"><?= count($abbonamentiPeriodo) ?> <small>abbonat<?= count($abbonamentiPeriodo) === 1 ? 'o' : 'i' ?></small></span>
      <span class="kpi-sub">valore <?= $eur($totValoreAbbonamenti) ?> · incassati <?= $eur($totVersatoAbbonamenti) ?></span>
    </div>
    <div class="kpi-card">
      <span class="kpi-label">Ricorrenti attivi (stima)</span>
      <span class="kpi-value"><?= $eur($ricorrenti['valore_mensile']) ?><small>/mese</small></span>
      <span class="kpi-sub"><?= $ricorrenti['n'] ?> abbonament<?= $ricorrenti['n'] === 1 ? 'o' : 'i' ?> in corso (inclusi rinnovati)</span>
    </div>
    <div class="kpi-card <?= $totResiduoAbbonamenti > 0 || $saldiAperti > 0 ? 'kpi-warn' : '' ?>">
      <span class="kpi-label">Saldi aperti da incassare</span>
      <span class="kpi-value"><?= $eur(max($totResiduoAbbonamenti, $saldiAperti)) ?></span>
      <span class="kpi-sub">
        <?php if ($totResiduoAbbonamenti > 0): ?>
          <a href="#tabella-abbonamenti-periodo" style="color:inherit; font-weight:600; text-decoration:underline;"><?= $eur($totResiduoAbbonamenti) ?> residuo su questo periodo ⬇</a>
        <?php elseif ($saldiAperti > 0): ?>
          <a href="#acconti" style="color:inherit; font-weight:600; text-decoration:underline;"><?= count($accontiAperti) ?> con acconto aperto ⬇</a>
        <?php else: ?>
          Tutti gli abbonamenti sono saldati
        <?php endif; ?>
      </span>
    </div>
  </div>

  <div style="margin-top: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 10px;">
      <h3 style="margin: 0;">📋 Dettaglio abbonamenti attivi e rinnovati nel periodo (<?= count($abbonamentiPeriodo) ?>)</h3>
      <input type="search" placeholder="🔍 Cerca cliente, piano o stato..." oninput="filtraAbbonamenti(this.value)" style="width: auto; min-width: 250px; font-size: 0.85rem; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border, #ccc);">
    </div>
    <p class="muted" style="margin-bottom: 12px; font-size: 0.9rem;">
      Include tutti gli abbonamenti attivi, rinnovati o che coprono <strong><?= htmlspecialchars($labelPeriodo) ?></strong> (compresi rinnovi effettuati a ottobre con scadenza a novembre).
    </p>
    <div class="table-wrap">
      <table class="data-table" id="tabella-abbonamenti-periodo">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Telefono</th>
            <th>Piano</th>
            <th>Validità</th>
            <th>Quota</th>
            <th>Incassato</th>
            <th>Saldo residuo</th>
            <th>Pagamento</th>
            <th>Stato</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($abbonamentiPeriodo as $abb): ?>
          <tr class="abb-row" data-search="<?= htmlspecialchars(strtolower($abb['nome'] . ' ' . $abb['cognome'] . ' ' . $abb['piano_nome'] . ' ' . $abb['stato_pagamento'] . ' ' . $abb['stato'])) ?>">
            <td><strong><a href="cliente.php?id=<?= $abb['cliente_id'] ?>"><?= htmlspecialchars($abb['nome'] . ' ' . $abb['cognome']) ?></a></strong></td>
            <td>
              <?php if (!empty($abb['telefono'])): ?>
                <a href="https://wa.me/<?= preg_replace('/\D/', '', $abb['telefono']) ?>" target="_blank" title="Contatta su WhatsApp">💬 <?= htmlspecialchars($abb['telefono']) ?></a>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($abb['piano_nome']) ?></td>
            <td>
              <?= date('d/m/Y', strtotime($abb['data_inizio'])) ?> &rarr; <strong><?= date('d/m/Y', strtotime($abb['data_scadenza'])) ?></strong>
            </td>
            <td><strong><?= $eur((float) $abb['prezzo_pagato']) ?></strong></td>
            <td><span class="badge badge-<?= $abb['versato'] > 0 ? 'info' : 'muted' ?>"><?= $eur((float) $abb['versato']) ?></span></td>
            <td>
              <?php if ($abb['saldo_residuo'] > 0): ?>
                <strong class="txt-danger"><?= $eur((float) $abb['saldo_residuo']) ?></strong>
              <?php else: ?>
                <span class="txt-success">€0,00</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($abb['is_saldato']): ?>
                <span class="badge badge-success">Saldato ✓</span>
              <?php elseif ($abb['ha_acconto']): ?>
                <span class="badge badge-warn">Acconto</span>
              <?php else: ?>
                <span class="badge badge-danger">Da saldare</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $abb['stato'] === 'attivo' ? 'success' : ($abb['stato'] === 'rinnovato' ? 'info' : 'muted') ?>">
                <?= htmlspecialchars($abb['stato']) ?>
              </span>
            </td>
            <td style="text-align: right;">
              <a class="btn btn-outline btn-sm" href="cliente.php?id=<?= $abb['cliente_id'] ?>">Scheda &rarr;</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$abbonamentiPeriodo): ?>
          <tr><td colspan="10" class="empty-state">Nessun abbonamento attivo o rinnovato in questo periodo.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($abbonamentiPeriodo): ?>
        <tfoot>
          <tr style="font-weight: bold; background: rgba(0,0,0,0.03);">
            <td colspan="4">Totali periodo (<?= count($abbonamentiPeriodo) ?> abbonament<?= count($abbonamentiPeriodo) === 1 ? 'o' : 'i' ?>)</td>
            <td><?= $eur($totValoreAbbonamenti) ?></td>
            <td><?= $eur($totVersatoAbbonamenti) ?></td>
            <td class="<?= $totResiduoAbbonamenti > 0 ? 'txt-danger' : 'txt-success' ?>"><?= $eur($totResiduoAbbonamenti) ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

<?php if ($accontiAperti): ?>
  <div id="acconti" style="margin-top: 24px; padding: 16px; background: rgba(230, 126, 34, 0.08); border-left: 4px solid var(--warning, #e67e22); border-radius: 6px;">
    <h3 style="margin-top:0; margin-bottom: 8px;">⚠️ Dettaglio acconti e saldi da incassare (<?= count($accontiAperti) ?> clienti · Totale <?= $eur($saldiAperti) ?>)</h3>
    <p class="muted" style="margin-bottom: 12px;">Clienti che hanno versato un acconto parziale e hanno ancora una somma da saldare:</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Telefono</th>
            <th>Servizio / Piano</th>
            <th>Data</th>
            <th>Prezzo totale</th>
            <th>Acconto versato</th>
            <th>Saldo da incassare</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($accontiAperti as $acc): ?>
          <tr>
            <td><strong><a href="cliente.php?id=<?= $acc['cliente_id'] ?>"><?= htmlspecialchars($acc['cliente_nome']) ?></a></strong></td>
            <td>
              <?php if (!empty($acc['telefono'])): ?>
                <a href="https://wa.me/<?= preg_replace('/\D/', '', $acc['telefono']) ?>" target="_blank" title="Contatta su WhatsApp">💬 <?= htmlspecialchars($acc['telefono']) ?></a>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($acc['servizio']) ?></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($acc['data']))) ?></td>
            <td><?= $eur($acc['prezzo_totale']) ?></td>
            <td><span class="badge badge-info"><?= $eur($acc['versato']) ?></span></td>
            <td><strong class="txt-danger" style="font-size: 1.05em;"><?= $eur($acc['saldo_residuo']) ?></strong></td>
            <td style="text-align: right;">
              <a class="btn btn-primary btn-sm" href="cliente.php?id=<?= $acc['cliente_id'] ?>">Incassa saldo &rarr;</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

  <h2 class="section-title">Bilancio · <?= htmlspecialchars($labelPeriodo) ?></h2>
  <div class="kpi-grid">
    <div class="kpi-card kpi-success">
      <span class="kpi-label">Entrate totali</span>
      <span class="kpi-value"><?= $eur($entrateTotali) ?></span>
      <span class="kpi-sub">abbonamenti <?= $eur($incassi['abbonamenti']) ?> · altri incassi <?= $eur($incassi['altri']) ?> · manuali <?= $eur($totaliManuali['entrata']) ?></span>
    </div>
    <div class="kpi-card kpi-danger"><span class="kpi-label">Uscite totali</span><span class="kpi-value"><?= $eur($totaliManuali['uscita']) ?></span></div>
    <div class="kpi-card <?= $bilancio >= 0 ? 'kpi-success' : 'kpi-danger' ?>"><span class="kpi-label">Bilancio netto</span><span class="kpi-value"><?= $eur($bilancio) ?></span></div>
    <div class="kpi-card kpi-info"><span class="kpi-label">Nuovi iscritti</span><span class="kpi-value"><?= $iscrittiVsDisdette['nuovi'] ?></span></div>
    <div class="kpi-card kpi-warn"><span class="kpi-label">Disdette</span><span class="kpi-value"><?= $iscrittiVsDisdette['disdette'] ?></span></div>
  </div>

  <div class="chart-wrap"><canvas id="chart-mensile"></canvas></div>
</section>

<div class="grid-2">
  <section class="panel">
    <h2>Ricorrenti attivi per piano</h2>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Piano</th><th>Attivi</th><th>Valore periodo</th><th>Equivalente mensile</th></tr></thead>
      <tbody>
      <?php foreach ($ricorrentiPiano as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['piano_nome']) ?> <span class="muted">(<?= (int) $r['durata_giorni'] ?>gg)</span></td>
          <td><?= (int) $r['n'] ?></td>
          <td><?= $eur((float) $r['valore_periodo']) ?></td>
          <td><strong><?= $eur((float) $r['valore_mensile']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ricorrentiPiano): ?><tr><td colspan="4" class="empty-state">Nessun abbonamento attivo al momento.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <p class="muted mt-16">I pacchetti a seduta (es. EMS) non sono ricorrenti e restano fuori da questo conteggio; i loro incassi rientrano comunque nelle entrate.</p>
  </section>

  <section class="panel">
    <h2>Incassi per metodo · <?= htmlspecialchars($labelPeriodo) ?></h2>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Metodo</th><th>Pagamenti</th><th>Totale</th></tr></thead>
      <tbody>
      <?php foreach ($perMetodo as $m): ?>
        <tr><td><?= htmlspecialchars(ucfirst($m['metodo'])) ?></td><td><?= (int) $m['n'] ?></td><td><strong><?= $eur((float) $m['tot']) ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if (!$perMetodo): ?><tr><td colspan="3" class="empty-state">Nessun incasso nel periodo.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<section class="panel">
  <h2>Storico mensile · ultimi 12 mesi</h2>
  <div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Mese</th><th>Abbonamenti</th><th>Altri incassi</th><th>Entrate manuali</th><th>Uscite</th><th>Netto</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($mensile) as $r): ?>
      <tr class="<?= $r['mese'] === date('Y-m') ? 'row-current' : '' ?>">
        <td><?= $labelMese($r['mese']) ?><?= $r['mese'] === date('Y-m') ? ' <span class="badge badge-info">in corso</span>' : '' ?></td>
        <td><strong><?= $eur($r['abbonamenti']) ?></strong></td>
        <td><?= $eur($r['altri']) ?></td>
        <td><?= $eur($r['entrate_manuali']) ?></td>
        <td class="txt-danger"><?= $eur($r['uscite']) ?></td>
        <td class="<?= $r['netto'] >= 0 ? 'txt-success' : 'txt-danger' ?>"><strong><?= $eur($r['netto']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2>Registra movimento manuale</h2>
  <form method="post" class="form-row-split">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="registra">
    <div><label>Tipo</label><select name="tipo"><option value="uscita">Uscita</option><option value="entrata">Entrata</option></select></div>
    <div><label>Categoria</label><input type="text" name="categoria" placeholder="es. Affitto, Utenze, Personale" required></div>
    <div><label>Importo</label><input type="number" step="0.01" min="0.01" name="importo" required></div>
    <div><label>Data</label><input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Descrizione</label><input type="text" name="descrizione"></div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Registra</button></div>
  </form>
  <p class="muted mt-16">Gli incassi dei clienti (abbonamenti, sedute) non vanno inseriti qui: si registrano dalla scheda cliente e compaiono automaticamente nel registro sotto.</p>
</section>

<section class="panel">
  <div class="panel-header" style="flex-wrap: wrap; gap: 12px;">
    <h2>Registro movimenti · <?= htmlspecialchars($labelPeriodo) ?> (<span id="registro-count"><?= count($registro) ?></span>)</h2>
    <input type="search" id="filtro-registro" placeholder="🔍 Cerca per cliente, causale, categoria..." oninput="filtraRegistro(this.value)" style="width: auto; min-width: 260px;">
  </div>
  <div class="table-wrap">
  <table class="data-table" id="tabella-registro">
    <thead><tr><th>Data</th><th>Tipo</th><th>Origine</th><th>Categoria / Piano</th><th>Importo</th><th>Dettaglio</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($registro as $r): ?>
      <tr class="registro-row" data-search="<?= htmlspecialchars(strtolower($r['data'] . ' ' . $r['tipo'] . ' ' . $r['origine'] . ' ' . $r['categoria'] . ' ' . $r['descrizione'] . ' ' . $r['importo'])) ?>">
        <td><?= htmlspecialchars(date('d/m/Y', strtotime($r['data']))) ?> <span class="muted"><?= htmlspecialchars($r['ora']) ?></span></td>
        <td><span class="badge badge-<?= $r['tipo'] === 'entrata' ? 'success' : 'danger' ?>"><?= htmlspecialchars($r['tipo']) ?></span></td>
        <td><?= htmlspecialchars($r['origine']) ?></td>
        <td><?= htmlspecialchars($r['categoria']) ?></td>
        <td class="<?= $r['tipo'] === 'entrata' ? 'txt-success' : 'txt-danger' ?>"><strong><?= $r['tipo'] === 'entrata' ? '+' : '−' ?><?= $eur($r['importo']) ?></strong></td>
        <td><?php if ($r['link']): ?><a href="<?= $r['link'] ?>"><?= htmlspecialchars($r['descrizione']) ?></a><?php else: ?><?= htmlspecialchars($r['descrizione'] ?: '-') ?><?php endif; ?></td>
        <td style="text-align: right;">
          <?php if (!empty($r['manuale_id'])): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo movimento manuale da <?= $eur($r['importo']) ?>?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="azione" value="elimina_movimento">
              <input type="hidden" name="movimento_id" value="<?= (int) $r['manuale_id'] ?>">
              <button type="submit" class="btn btn-outline btn-danger btn-sm" title="Elimina questo movimento">🗑️</button>
            </form>
          <?php elseif (Auth::hasRole('admin', 'staff')): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo pagamento di <?= $eur($r['importo']) ?>?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="azione" value="elimina_pagamento">
              <input type="hidden" name="pagamento_id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-outline btn-danger btn-sm" title="Elimina pagamento errato">🗑️</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$registro): ?><tr><td colspan="7" class="empty-state">Nessun movimento nel periodo.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<script>
function filtraAbbonamenti(query) {
  var q = (query || '').toLowerCase().trim();
  var rows = document.querySelectorAll('.abb-row');
  rows.forEach(function (row) {
    var searchData = row.getAttribute('data-search') || '';
    row.style.display = (!q || searchData.indexOf(q) !== -1) ? '' : 'none';
  });
}

function filtraRegistro(query) {
  var q = (query || '').toLowerCase().trim();
  var rows = document.querySelectorAll('.registro-row');
  var visibili = 0;
  rows.forEach(function (row) {
    var searchData = row.getAttribute('data-search') || '';
    if (!q || searchData.indexOf(q) !== -1) {
      row.style.display = '';
      visibili++;
    } else {
      row.style.display = 'none';
    }
  });
  var countEl = document.getElementById('registro-count');
  if (countEl) countEl.textContent = visibili;
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
(function () {
  var mensile = <?= json_encode($mensile, JSON_UNESCAPED_UNICODE) ?>;
  var mesi = <?= json_encode($mesiBrevi) ?>;
  var labels = mensile.map(function (r) { return (mesi[r.mese.substr(5, 2)] || r.mese.substr(5, 2)) + ' ' + r.mese.substr(2, 2); });
  var el = document.getElementById('chart-mensile');
  if (!el || typeof Chart === 'undefined') return;
  new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Abbonamenti', data: mensile.map(function (r) { return r.abbonamenti; }), backgroundColor: '#f5c400', stack: 'in' },
        { label: 'Altri incassi', data: mensile.map(function (r) { return r.altri; }), backgroundColor: '#8a7100', stack: 'in' },
        { label: 'Entrate manuali', data: mensile.map(function (r) { return r.entrate_manuali; }), backgroundColor: '#1c7c3f', stack: 'in' },
        { label: 'Uscite', data: mensile.map(function (r) { return -r.uscite; }), backgroundColor: '#c0392b', stack: 'out' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: function (v) { return '€' + v; } } } },
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': €' + Math.abs(c.raw).toFixed(2); } } } }
    }
  });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
