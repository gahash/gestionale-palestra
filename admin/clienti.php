<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Models\Abbonamento;
use App\Models\Cliente;
use App\Models\TipoAbbonamento;

$pageTitle = 'Clienti';
require __DIR__ . '/_header.php';

$piani = TipoAbbonamento::all(true);

$errore = null;
$successo = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'crea') {
    Csrf::requireValid();
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $cognome = trim((string) ($_POST['cognome'] ?? ''));
    if ($nome === '' || $cognome === '') {
        $errore = 'Nome e cognome sono obbligatori.';
    } else {
        $id = Cliente::create($_POST, $user['id']);
        $abbCreato = false;
        if (!empty($_POST['tipo_abbonamento_id']) && (!empty($_POST['attiva_abbonamento']) || ($_POST['attiva_abbonamento'] ?? '') === '1' || !isset($_POST['attiva_abbonamento']))) {
            $tipoAbbId = (int) $_POST['tipo_abbonamento_id'];
            $prezzo = (float) ($_POST['prezzo_pagato'] ?? 0);
            $incassato = null;
            if (($_POST['registra_incasso'] ?? '1') === '1') {
                $incassato = ($_POST['importo_incassato'] ?? '') !== '' ? (float) $_POST['importo_incassato'] : $prezzo;
                if ($incassato > $prezzo) {
                    $incassato = $prezzo;
                }
            }
            $dataInizio = !empty($_POST['data_inizio']) ? $_POST['data_inizio'] : date('Y-m-d');
            $dataPag = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : date('Y-m-d');
            $metodo = $_POST['metodo'] ?? 'contanti';
            Abbonamento::crea($id, $tipoAbbId, $dataInizio, $prezzo, $user['id'], $incassato, $metodo, $dataPag);
            $abbCreato = true;
        }
        header('Location: cliente.php?id=' . $id . ($abbCreato ? '&ok=abb_attivato' : ''));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina_multiplo') {
    Csrf::requireValid();
    $ids = (array) ($_POST['clienti_ids'] ?? []);
    $count = Cliente::softDeleteMultiplo($ids);
    header('Location: clienti.php?eliminati=' . $count);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina_singolo') {
    Csrf::requireValid();
    $cid = (int) ($_POST['cliente_id'] ?? 0);
    if ($cid > 0) {
        Cliente::softDelete($cid);
        header('Location: clienti.php?eliminati=1' . (!empty($_GET['stato']) ? '&stato=' . urlencode($_GET['stato']) : ''));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'riattiva_singolo') {
    Csrf::requireValid();
    $cid = (int) ($_POST['cliente_id'] ?? 0);
    if ($cid > 0) {
        Cliente::riattiva($cid);
        header('Location: clienti.php?riattivato=1' . (!empty($_GET['stato']) ? '&stato=' . urlencode($_GET['stato']) : ''));
        exit;
    }
}

if (isset($_GET['eliminati'])) {
    $num = (int) $_GET['eliminati'];
    $successo = $num === 1 ? '1 cliente disattivato con successo (i suoi appuntamenti futuri sono stati liberati).' : "{$num} clienti disattivati con successo.";
}
if (isset($_GET['riattivato'])) {
    $successo = 'Cliente riattivato con successo nell\'anagrafica.';
}

$q = trim((string) ($_GET['q'] ?? ''));
$filtroStato = $_GET['stato'] ?? 'attivi';
$soloAttivi = $filtroStato !== 'disattivati' && $filtroStato !== 'tutti';
$clienti = Cliente::all($q ?: null, $soloAttivi);
if ($filtroStato === 'disattivati') {
    $clienti = array_filter($clienti, fn($c) => empty($c['attivo']));
}
?>

<section class="panel">
  <div class="panel-header" style="flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
      <div class="filter-tabs">
        <a class="filter-tab <?= $filtroStato === 'attivi' ? 'active' : '' ?>" href="?stato=attivi<?= $q ? '&q=' . urlencode($q) : '' ?>">Attivi</a>
        <a class="filter-tab <?= $filtroStato === 'disattivati' ? 'active' : '' ?>" href="?stato=disattivati<?= $q ? '&q=' . urlencode($q) : '' ?>">Disattivati</a>
        <a class="filter-tab <?= $filtroStato === 'tutti' ? 'active' : '' ?>" href="?stato=tutti<?= $q ? '&q=' . urlencode($q) : '' ?>">Tutti</a>
      </div>
      <form method="get" class="inline-search">
        <?php if ($filtroStato !== 'attivi'): ?><input type="hidden" name="stato" value="<?= htmlspecialchars($filtroStato) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cerca per nome, telefono, email...">
        <button class="btn btn-outline" type="submit">Cerca</button>
      </form>
    </div>
    <button class="btn btn-primary" type="button" onclick="document.getElementById('modal-nuovo-cliente').classList.add('open')">+ Nuovo cliente</button>
  </div>

  <?php if ($successo): ?><p class="flash-ok"><?= htmlspecialchars($successo) ?></p><?php endif; ?>

  <form method="post" id="form-bulk-clienti">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="elimina_multiplo">

    <div id="clienti-bulk-bar" class="bulk-actions-toolbar" style="display: none; margin-bottom: 14px;">
      <span id="clienti-bulk-count">0 clienti selezionati</span>
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirmEliminaClienti()">🗑️ Disattiva clienti selezionati</button>
    </div>

    <table class="data-table">
      <thead>
        <tr>
          <th style="width: 36px; text-align: center;"><input type="checkbox" id="check-all-clienti" title="Seleziona tutti" onchange="toggleClientiAll(this.checked)"></th>
          <th>Nome</th>
          <th>Stato</th>
          <th>Telefono</th>
          <th>Email</th>
          <th>Certificato</th>
          <th>Iscritto dal</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clienti as $c): ?>
        <tr>
          <td style="text-align: center;"><input type="checkbox" name="clienti_ids[]" value="<?= $c['id'] ?>" class="check-cliente-item" onchange="aggiornaClientiBulkBar()"></td>
          <td>
            <a href="cliente.php?id=<?= $c['id'] ?>"><strong><?= htmlspecialchars($c['nome'] . ' ' . $c['cognome']) ?></strong></a>
          </td>
          <td>
            <?php if (!empty($c['attivo'])): ?>
              <span class="badge badge-success">Attivo</span>
            <?php else: ?>
              <span class="badge badge-danger">Disattivato</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($c['telefono'] ?? '-') ?></td>
          <td><?= htmlspecialchars($c['email'] ?? '-') ?></td>
          <td>
            <?php if (!$c['certificato_scadenza']): ?>
              <span class="badge badge-muted">non caricato</span>
            <?php elseif ($c['certificato_scadenza'] < date('Y-m-d')): ?>
              <span class="badge badge-danger">scaduto</span>
            <?php elseif ($c['certificato_scadenza'] <= date('Y-m-d', strtotime('+30 days'))): ?>
              <span class="badge badge-warn">in scadenza</span>
            <?php else: ?>
              <span class="badge badge-success">valido</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars(substr($c['created_at'], 0, 10)) ?></td>
          <td style="white-space: nowrap; text-align: right;">
            <a class="btn btn-outline btn-sm" href="cliente.php?id=<?= $c['id'] ?>">Apri / Modifica</a>
            <?php if (!empty($c['attivo'])): ?>
              <button type="submit" form="form-elimina-<?= $c['id'] ?>" class="btn btn-outline btn-danger btn-sm" title="Disattiva cliente">🗑️</button>
            <?php else: ?>
              <button type="submit" form="form-riattiva-<?= $c['id'] ?>" class="btn btn-primary btn-sm" title="Riattiva cliente">✓ Riattiva</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clienti): ?>
        <tr><td colspan="8" class="empty-state">Nessun cliente trovato.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </form>

  <!-- Moduli singoli nascosti per disattivare / riattivare dalla tabella -->
  <?php foreach ($clienti as $c): ?>
    <?php if (!empty($c['attivo'])): ?>
      <form method="post" id="form-elimina-<?= $c['id'] ?>" style="display:none;" onsubmit="return confirm('Disattivare <?= htmlspecialchars(addslashes($c['nome'] . ' ' . $c['cognome'])) ?>? I posti nei suoi appuntamenti futuri verranno liberati.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="azione" value="elimina_singolo">
        <input type="hidden" name="cliente_id" value="<?= $c['id'] ?>">
      </form>
    <?php else: ?>
      <form method="post" id="form-riattiva-<?= $c['id'] ?>" style="display:none;" onsubmit="return confirm('Riattivare <?= htmlspecialchars(addslashes($c['nome'] . ' ' . $c['cognome'])) ?> nell\'anagrafica?');">
        <?= Csrf::field() ?>
        <input type="hidden" name="azione" value="riattiva_singolo">
        <input type="hidden" name="cliente_id" value="<?= $c['id'] ?>">
      </form>
    <?php endif; ?>
  <?php endforeach; ?>
</section>

<script>
function toggleClientiAll(checked) {
  document.querySelectorAll('.check-cliente-item').forEach(function (cb) {
    cb.checked = checked;
  });
  aggiornaClientiBulkBar();
}

function aggiornaClientiBulkBar() {
  var checked = document.querySelectorAll('.check-cliente-item:checked');
  var bar = document.getElementById('clienti-bulk-bar');
  var countEl = document.getElementById('clienti-bulk-count');
  var checkAll = document.getElementById('check-all-clienti');
  var total = document.querySelectorAll('.check-cliente-item').length;

  if (checked.length > 0) {
    if (bar) bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length + (checked.length === 1 ? ' cliente selezionato' : ' clienti selezionati');
  } else {
    if (bar) bar.style.display = 'none';
  }
  if (checkAll) {
    checkAll.checked = total > 0 && checked.length === total;
  }
}

function confirmEliminaClienti() {
  var count = document.querySelectorAll('.check-cliente-item:checked').length;
  if (count === 0) return false;
  return confirm('Sei sicuro di voler eliminare i ' + count + ' clienti selezionati? Verranno disattivati dall\'anagrafica.');
}
</script>

<div id="modal-nuovo-cliente" class="modal">
  <div class="modal-card">
    <h2>Nuovo cliente</h2>
    <?php if ($errore): ?><p class="form-error"><?= htmlspecialchars($errore) ?></p><?php endif; ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="azione" value="crea">
      <div class="form-row-split">
        <div><label>Nome *</label><input type="text" name="nome" required></div>
        <div><label>Cognome *</label><input type="text" name="cognome" required></div>
      </div>
      <div class="form-row-split">
        <div><label>Data di nascita</label><input type="date" name="data_nascita"></div>
        <div><label>Codice fiscale</label><input type="text" name="codice_fiscale" maxlength="16"></div>
      </div>
      <div class="form-row-split">
        <div><label>Telefono</label><input type="tel" name="telefono"></div>
        <div><label>Email</label><input type="email" name="email"></div>
      </div>
      <label>Indirizzo</label><input type="text" name="indirizzo">
      <label>Note</label><textarea name="note" rows="2"></textarea>
      <label class="checkbox-label"><input type="checkbox" name="gdpr_consenso" value="1"> Consenso privacy raccolto</label>

      <div class="mt-16 pt-16" style="border-top: 1px solid var(--bordo, #eee);">
        <label class="checkbox-label" style="font-weight: 600; font-size: 1.02em; color: var(--primario, #f5c400); cursor: pointer;">
          <input type="checkbox" id="chk-attiva-abb" name="attiva_abbonamento" value="1" checked onchange="document.getElementById('box-nuovo-abb').style.display=this.checked?'block':'none'">
          ⚡ Attiva subito anche l'abbonamento
        </label>
        
        <div id="box-nuovo-abb" style="display: block; margin-top: 12px; padding: 14px; background: rgba(245, 196, 0, 0.05); border: 1px dashed var(--primario, #f5c400); border-radius: 8px;">
          <div class="form-row-split">
            <div>
              <label>Piano abbonamento</label>
              <select name="tipo_abbonamento_id" id="nuovo-tipo-abb" onchange="var opt=this.options[this.selectedIndex]; var p=opt.dataset.prezzo||''; document.getElementById('nuovo-abb-prezzo').value=p; document.getElementById('nuovo-abb-incassato').value=p;">
                <option value="">-- Nessun abbonamento per ora (solo anagrafica) --</option>
                <?php foreach ($piani as $p): ?>
                  <option value="<?= $p['id'] ?>" data-prezzo="<?= $p['prezzo'] ?>">
                    <?= htmlspecialchars($p['nome']) ?> — €<?= number_format((float) $p['prezzo'], 2, ',', '.') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Data inizio</label>
              <input type="date" name="data_inizio" value="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <div class="form-row-split">
            <div>
              <label>Prezzo abbonamento (€)</label>
              <input type="number" step="0.01" min="0" name="prezzo_pagato" id="nuovo-abb-prezzo" oninput="document.getElementById('nuovo-abb-incassato').value=this.value">
            </div>
            <div>
              <label>Incassato ora (€)</label>
              <input type="number" step="0.01" min="0" name="importo_incassato" id="nuovo-abb-incassato" placeholder="= prezzo">
              <small class="muted">Se inferiore al prezzo genera acconto.</small>
            </div>
          </div>

          <div class="form-row-split">
            <div>
              <label>Data incasso</label>
              <input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
              <label>Metodo di pagamento</label>
              <select name="metodo">
                <option value="contanti">Contanti</option>
                <option value="carta">Carta</option>
                <option value="bonifico">Bonifico</option>
                <option value="altro">Altro</option>
              </select>
            </div>
          </div>

          <div>
            <label>Registrazione pagamento</label>
            <select name="registra_incasso">
              <option value="1">Registra incasso adesso (compare subito in Finanze)</option>
              <option value="0">Non ancora pagato (da saldare)</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-nuovo-cliente').classList.remove('open')">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva cliente</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
