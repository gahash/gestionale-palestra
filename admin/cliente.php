<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Models\Cliente;
use App\Models\Abbonamento;
use App\Models\TipoAbbonamento;
use App\Models\Pagamento;
use App\Services\WhatsappService;

$pageTitle = 'Scheda cliente';
require __DIR__ . '/_header.php';

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$cliente = Cliente::find($id);
if (!$cliente) {
    http_response_code(404);
    echo '<div class="panel"><p class="empty-state">Cliente non trovato.</p></div>';
    require __DIR__ . '/_footer.php';
    exit;
}

$flash = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $azione = $_POST['azione'] ?? '';

    try {
        if ($azione === 'riattiva_cliente') {
            Cliente::riattiva($id);
            $flash = 'Cliente riattivato con successo nell\'anagrafica.';
        }

        if ($azione === 'aggiorna_anagrafica') {
            Cliente::update($id, $_POST);
            $flash = 'Anagrafica aggiornata con successo.';
        }

        if ($azione === 'nuovo_abbonamento') {
            $prezzo = (float) $_POST['prezzo_pagato'];
            $incassato = null;
            if (($_POST['registra_incasso'] ?? '1') === '1') {
                $incassato = ($_POST['importo_incassato'] ?? '') !== '' ? (float) $_POST['importo_incassato'] : $prezzo;
                if ($incassato > $prezzo) {
                    throw new \RuntimeException("L'importo incassato non puo' superare il prezzo dell'abbonamento.");
                }
            }
            $dataPagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : date('Y-m-d');
            Abbonamento::crea(
                $id,
                (int) $_POST['tipo_abbonamento_id'],
                $_POST['data_inizio'] ?: date('Y-m-d'),
                $prezzo,
                $user['id'],
                $incassato,
                $_POST['metodo'] ?? 'contanti',
                $dataPagamento
            );
            $flash = $incassato !== null && $incassato > 0
                ? 'Abbonamento attivato e incasso di €' . number_format($incassato, 2, ',', '.') . ' registrato alla data ' . htmlspecialchars($dataPagamento) . '.'
                : 'Abbonamento attivato senza pagamento: ricordati di registrare l\'incasso.';
        }

        if ($azione === 'rinnova_abbonamento') {
            $abbPrec = Abbonamento::find((int) $_POST['abbonamento_id']);
            if (!$abbPrec) {
                throw new \RuntimeException('Abbonamento non trovato.');
            }
            $prezzoRinnovo = ($_POST['prezzo_rinnovo'] ?? '') !== '' ? (float) $_POST['prezzo_rinnovo'] : (float) $abbPrec['piano_prezzo'];
            $incassato = null;
            if (($_POST['registra_incasso'] ?? '1') === '1') {
                $incassato = ($_POST['importo_incassato'] ?? '') !== '' ? (float) $_POST['importo_incassato'] : $prezzoRinnovo;
                if ($incassato > $prezzoRinnovo) {
                    throw new \RuntimeException("L'importo incassato non puo' superare il prezzo del rinnovo.");
                }
            }
            $dataPag = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : date('Y-m-d');
            $dataInizio = !empty($_POST['data_inizio']) ? $_POST['data_inizio'] : null;
            Abbonamento::rinnova((int) $_POST['abbonamento_id'], $user['id'], $incassato, $_POST['metodo'] ?? 'contanti', $prezzoRinnovo, $dataPag, $dataInizio);
            $flash = $incassato !== null && $incassato > 0
                ? 'Abbonamento rinnovato e pagamento di €' . number_format($incassato, 2, ',', '.') . ' registrato con data ' . htmlspecialchars($dataPag) . '.'
                : 'Abbonamento rinnovato senza pagamento registrato.';
        }

        if ($azione === 'annulla_abbonamento' || $azione === 'elimina_abbonamento') {
            $abbId = (int) ($_POST['abbonamento_id'] ?? 0);
            if ($abbId > 0) {
                Abbonamento::elimina($abbId);
                $flash = 'Abbonamento e relativi incassi/acconti collegati eliminati con successo.';
            }
        }

        if ($azione === 'registra_pagamento') {
            $importo = (float) $_POST['importo'];
            $abbId = !empty($_POST['abbonamento_id']) ? (int) $_POST['abbonamento_id'] : null;
            $acconto = !empty($_POST['acconto']);
            $saldoResiduo = $acconto ? (float) ($_POST['saldo_residuo'] ?? 0) : 0.0;

            // Se riferito a un abbonamento, calcola automaticamente acconto e saldo residuo
            if ($abbId > 0) {
                $abb = Abbonamento::find($abbId);
                if ($abb) {
                    $pdo = Database::pdo();
                    $stmtV = $pdo->prepare('SELECT COALESCE(SUM(importo), 0) FROM pagamenti WHERE abbonamento_id = ? AND tenant_id = ?');
                    $stmtV->execute([$abbId, Database::tenantId()]);
                    $versatoPrima = (float) $stmtV->fetchColumn();
                    $calc = Abbonamento::calcolaStatoPagamento((float) $abb['prezzo_pagato'], $versatoPrima + $importo);
                    $acconto = $calc['ha_acconto'];
                    $saldoResiduo = $calc['saldo_residuo'];
                }
            }

            Pagamento::registra(
                $id,
                $abbId,
                $importo,
                $_POST['metodo'] ?: 'contanti',
                $_POST['causale'] ?: null,
                $acconto,
                $saldoResiduo,
                $user['id'],
                !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : null
            );
            $flash = 'Pagamento registrato con successo.';
        }

        if ($azione === 'elimina_pagamento') {
            $pagId = (int) ($_POST['pagamento_id'] ?? 0);
            if ($pagId > 0) {
                Pagamento::elimina($pagId);
                $flash = 'Pagamento eliminato con successo. I residui sono stati ricalcolati.';
            }
        }

        if ($azione === 'invia_whatsapp' && Auth::hasRole('admin', 'staff')) {
            $result = WhatsappService::invia($id, $cliente['telefono'], $_POST['template'], [
                'nome' => $cliente['nome'],
            ], $user['id']);
            $flash = 'WhatsApp: ' . $result['stato'] . '.';
        }

        if ($azione === 'upload_certificato') {
            $filename = null;
            if (!empty($_FILES['certificato']['tmp_name'])) {
                $f = $_FILES['certificato'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || $f['size'] > 5 * 1024 * 1024) {
                    throw new \RuntimeException('File non valido (solo PDF/JPG/PNG, max 5MB).');
                }
                $dir = __DIR__ . '/../storage/uploads/certificati';
                $filename = 'cliente' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $dir . '/' . $filename);
            }
            Cliente::setCertificato($id, $filename, $_POST['certificato_rilascio'] ?: null, $_POST['certificato_scadenza'] ?: null);
            $flash = $filename ? 'Certificato medico caricato e date aggiornate.' : 'Date certificato medico aggiornate.';
        }

        if ($azione === 'upload_foto' && !empty($_FILES['foto']['tmp_name'])) {
            $f = $_FILES['foto'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true) || $f['size'] > 3 * 1024 * 1024) {
                throw new \RuntimeException('Immagine non valida (JPG/PNG, max 3MB).');
            }
            $dir = __DIR__ . '/../storage/uploads/foto';
            $filename = 'cliente' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], $dir . '/' . $filename);
            Cliente::setFoto($id, $filename);
            $flash = 'Foto profilo aggiornata.';
        }

        if ($azione === 'elimina_cliente' && Auth::hasRole('admin', 'staff')) {
            Cliente::softDelete($id);
            header('Location: clienti.php?eliminati=1');
            exit;
        }
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }

    $cliente = Cliente::find($id);
}

$abbonamenti = Abbonamento::perCliente($id);
$abbonamentoAttivo = null;
foreach ($abbonamenti as $a) {
    if ($a['stato'] === 'attivo') { $abbonamentoAttivo = $a; break; }
}
$piani = TipoAbbonamento::all(true);
$pianiPrezzo = [];
foreach (TipoAbbonamento::all(false) as $p) {
    $pianiPrezzo[(int) $p['id']] = (float) $p['prezzo'];
}
$pagamenti = Pagamento::perCliente($id);
$saldoResiduo = Pagamento::saldoResiduoCliente($id);
$waLog = WhatsappService::logPerCliente($id);
$base = \App\Config::baseUrl();
?>

<p><a href="clienti.php">&larr; Tutti i clienti</a></p>
<?php if ($flash): ?><p class="flash-ok"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if ($flashErr): ?><p class="flash-err"><?= htmlspecialchars($flashErr) ?></p><?php endif; ?>

<div class="cliente-head panel">
  <div class="cliente-foto">
    <?php if ($cliente['foto_path']): ?>
      <img src="file.php?tipo=foto&cliente_id=<?= $id ?>" alt="Foto profilo">
    <?php else: ?>
      <div class="foto-placeholder">👤</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="upload-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="azione" value="upload_foto">
      <input type="file" name="foto" accept="image/*" required>
      <button class="btn btn-outline btn-sm" type="submit">Carica foto</button>
    </form>
  </div>
  <div class="cliente-info">
    <div style="display:flex; align-items:center; gap: 10px; flex-wrap: wrap;">
      <h2 style="margin:0;"><?= htmlspecialchars($cliente['nome'] . ' ' . $cliente['cognome']) ?></h2>
      <?php if (!empty($cliente['attivo'])): ?>
        <span class="badge badge-success">Attivo</span>
      <?php else: ?>
        <span class="badge badge-danger">Disattivato</span>
      <?php endif; ?>
    </div>
    <p><?= htmlspecialchars($cliente['telefono'] ?? '-') ?> · <?= htmlspecialchars($cliente['email'] ?? '-') ?></p>
    <?php if ($abbonamentoAttivo): ?>
      <span class="badge badge-success">Abbonamento attivo: <?= htmlspecialchars($abbonamentoAttivo['piano_nome']) ?> (scade <?= htmlspecialchars($abbonamentoAttivo['data_scadenza']) ?>)</span>
    <?php else: ?>
      <span class="badge badge-danger">Nessun abbonamento attivo</span>
    <?php endif; ?>
    <?php if ($saldoResiduo > 0): ?>
      <span class="badge badge-warn">Saldo residuo: €<?= number_format($saldoResiduo, 2, ',', '.') ?></span>
    <?php endif; ?>
    <?php if (empty($cliente['attivo'])): ?>
      <form method="post" style="margin-top: 8px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="riattiva_cliente">
        <button class="btn btn-primary btn-sm" type="submit">✓ Riattiva questo cliente</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">

  <section class="panel">
    <h2>Anagrafica</h2>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="azione" value="aggiorna_anagrafica">
      <div class="form-row-split">
        <div><label>Nome *</label><input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required></div>
        <div><label>Cognome *</label><input type="text" name="cognome" value="<?= htmlspecialchars($cliente['cognome']) ?>" required></div>
      </div>
      <div class="form-row-split">
        <div><label>Data di nascita</label><input type="date" name="data_nascita" value="<?= htmlspecialchars($cliente['data_nascita'] ?? '') ?>"></div>
        <div><label>Codice fiscale</label><input type="text" name="codice_fiscale" value="<?= htmlspecialchars($cliente['codice_fiscale'] ?? '') ?>" maxlength="16"></div>
      </div>
      <div class="form-row-split">
        <div><label>Telefono</label><input type="tel" name="telefono" value="<?= htmlspecialchars($cliente['telefono'] ?? '') ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>"></div>
      </div>
      <label>Indirizzo</label><input type="text" name="indirizzo" value="<?= htmlspecialchars($cliente['indirizzo'] ?? '') ?>">
      <label>Note staff (obiettivi, infortuni, preferenze)</label>
      <textarea name="note" rows="3"><?= htmlspecialchars($cliente['note'] ?? '') ?></textarea>
      <label class="checkbox-label mt-8" style="margin-bottom: 14px;">
        <input type="checkbox" name="gdpr_consenso" value="1" <?= !empty($cliente['gdpr_consenso']) ? 'checked' : '' ?>>
        Consenso al trattamento dati personali (GDPR) raccolto
      </label>
      <button class="btn btn-primary" type="submit">Salva anagrafica</button>
    </form>

    <?php if (Auth::hasRole('admin', 'staff')): ?>
      <form method="post" onsubmit="return confirm('Disattivare questo cliente? Non comparirà più nell\'elenco attivo e i suoi appuntamenti futuri verranno liberati.');" class="mt-16">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="elimina_cliente">
        <button class="btn btn-danger btn-sm" type="submit">Disattiva cliente</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Certificato medico</h2>
    <?php if ($cliente['certificato_medico_path']): ?>
      <p>
        <a href="file.php?tipo=certificato&cliente_id=<?= $id ?>" target="_blank">📄 Visualizza certificato caricato</a><br>
        Scadenza: <strong><?= htmlspecialchars($cliente['certificato_scadenza'] ?? '-') ?></strong>
      </p>
    <?php else: ?>
      <p class="empty-state">Nessun file caricato.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="azione" value="upload_certificato">
      <div class="form-row-split">
        <div><label>Data rilascio</label><input type="date" name="certificato_rilascio" value="<?= htmlspecialchars($cliente['certificato_rilascio'] ?? '') ?>"></div>
        <div><label>Data scadenza</label><input type="date" name="certificato_scadenza" value="<?= htmlspecialchars($cliente['certificato_scadenza'] ?? '') ?>"></div>
      </div>
      <label>Nuovo file (PDF/JPG/PNG, max 5MB - opzionale)</label>
      <input type="file" name="certificato" accept=".pdf,.jpg,.jpeg,.png">
      <button class="btn btn-primary btn-sm" type="submit">Salva / Aggiorna certificato</button>
    </form>
  </section>

</div>

<section class="panel">
  <h2>Abbonamenti</h2>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Piano</th>
        <th>Inizio</th>
        <th>Scadenza</th>
        <th>Prezzo totale</th>
        <th>Acconti versati</th>
        <th>Residuo da pagare</th>
        <th>Stato</th>
        <th style="text-align: right;">Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($abbonamenti as $a): ?>
      <tr>
        <td><strong><?= htmlspecialchars($a['piano_nome']) ?></strong></td>
        <td><?= htmlspecialchars($a['data_inizio']) ?></td>
        <td><?= htmlspecialchars($a['data_scadenza']) ?></td>
        <td>€<?= number_format((float) $a['prezzo_totale'], 2, ',', '.') ?></td>
        <td>
          <?php if ((float) $a['versato'] > 0): ?>
            <span class="badge badge-info">€<?= number_format((float) $a['versato'], 2, ',', '.') ?></span>
          <?php else: ?>
            <span class="muted">€0,00</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((float) $a['prezzo_totale'] <= 0): ?>
            <span class="muted">-</span>
          <?php elseif ($a['is_saldato']): ?>
            <span class="badge badge-success">Saldato ✓</span>
          <?php else: ?>
            <span class="badge badge-warn">€<?= number_format((float) $a['saldo_residuo'], 2, ',', '.') ?></span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge-<?= $a['stato'] === 'attivo' ? 'success' : ($a['stato'] === 'scaduto' ? 'danger' : 'muted') ?>"><?= htmlspecialchars($a['stato']) ?></span>
        </td>
        <td style="text-align: right;">
          <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end;">
            <?php if ($a['stato'] === 'attivo' || $a['stato'] === 'scaduto'): ?>
              <button class="btn btn-outline btn-sm" type="button"
                data-abb-id="<?= $a['id'] ?>" data-piano="<?= htmlspecialchars($a['piano_nome']) ?>" data-prezzo="<?= (float) ($pianiPrezzo[(int) $a['tipo_abbonamento_id']] ?? $a['prezzo_totale']) ?>"
                data-scadenza="<?= htmlspecialchars($a['data_scadenza']) ?>"
                onclick="apriRinnovo(this)">Rinnova</button>
            <?php endif; ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Eliminare definitivamente questo abbonamento e tutti i relativi pagamenti collegati? L\'operazione non è reversibile.');">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="azione" value="elimina_abbonamento">
              <input type="hidden" name="abbonamento_id" value="<?= $a['id'] ?>">
              <button class="btn btn-outline btn-danger btn-sm" type="submit" title="Elimina definitivamente abbonamento e incassi collegati">🗑️</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$abbonamenti): ?><tr><td colspan="8" class="empty-state">Nessun abbonamento registrato.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <h3 class="mt-16">Nuovo abbonamento</h3>
  <form method="post" class="form-row-split" id="form-nuovo-abb">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="nuovo_abbonamento">
    <div>
      <label>Piano</label>
      <select name="tipo_abbonamento_id" required onchange="var p=this.options[this.selectedIndex].dataset.prezzo||''; this.form.prezzo_pagato.value=p; this.form.importo_incassato.value=p;">
        <option value="">Seleziona...</option>
        <?php foreach ($piani as $p): ?>
          <option value="<?= $p['id'] ?>" data-prezzo="<?= $p['prezzo'] ?>">
            <?= htmlspecialchars($p['nome']) ?> — €<?= number_format((float) $p['prezzo'], 2) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Data inizio</label><input type="date" name="data_inizio" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Data incasso</label><input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Prezzo abbonamento</label><input type="number" step="0.01" min="0" name="prezzo_pagato" required oninput="this.form.importo_incassato.value=this.value"></div>
    <div>
      <label>Incassato ora</label>
      <input type="number" step="0.01" min="0" name="importo_incassato" placeholder="= prezzo">
      <small class="muted">Se inferiore al prezzo viene registrato come acconto.</small>
    </div>
    <div>
      <label>Metodo</label>
      <select name="metodo"><option value="contanti">Contanti</option><option value="carta">Carta</option><option value="bonifico">Bonifico</option><option value="altro">Altro</option></select>
    </div>
    <div>
      <label>Pagamento</label>
      <select name="registra_incasso"><option value="1">Registra incasso adesso</option><option value="0">Non ancora pagato</option></select>
    </div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Attiva abbonamento</button></div>
  </form>
</section>

<div id="modal-rinnovo" class="modal">
  <div class="modal-card">
    <h2>Rinnova abbonamento</h2>
    <p class="muted" id="rinnovo-piano"></p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="azione" value="rinnova_abbonamento">
      <input type="hidden" name="abbonamento_id" id="rinnovo-abb-id">
      <div class="form-row-split">
        <div><label>Prezzo rinnovo</label><input type="number" step="0.01" min="0" name="prezzo_rinnovo" id="rinnovo-prezzo" oninput="document.getElementById('rinnovo-incassato').value=this.value"></div>
        <div><label>Incassato ora</label><input type="number" step="0.01" min="0" name="importo_incassato" id="rinnovo-incassato"><small class="muted">Se inferiore al prezzo = acconto.</small></div>
      </div>
      <div class="form-row-split">
        <div><label>Data inizio nuovo periodo</label><input type="date" name="data_inizio" id="rinnovo-data-inizio"></div>
        <div><label>Data incasso</label><input type="date" name="data_pagamento" id="rinnovo-data-pagamento" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-row-split">
        <div><label>Metodo</label><select name="metodo"><option value="contanti">Contanti</option><option value="carta">Carta</option><option value="bonifico">Bonifico</option><option value="altro">Altro</option></select></div>
        <div><label>Pagamento</label><select name="registra_incasso"><option value="1">Registra incasso adesso</option><option value="0">Non ancora pagato</option></select></div>
      </div>
      <p class="muted">Il nuovo periodo parte dalla scadenza attuale (o da oggi se già scaduto).</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-rinnovo').classList.remove('open')">Annulla</button>
        <button type="submit" class="btn btn-primary">Conferma rinnovo</button>
      </div>
    </form>
  </div>
</div>
<script>
function apriRinnovo(btn) {
  var prezzo = parseFloat(btn.dataset.prezzo || '0');
  var oggi = '<?= date('Y-m-d') ?>';
  var scadenza = btn.dataset.scadenza || oggi;
  var inizio = (scadenza > oggi) ? scadenza : oggi;
  document.getElementById('rinnovo-abb-id').value = btn.dataset.abbId;
  document.getElementById('rinnovo-piano').textContent = 'Piano: ' + btn.dataset.piano + ' (scadenza precedente: ' + scadenza + ')';
  document.getElementById('rinnovo-prezzo').value = prezzo.toFixed(2);
  document.getElementById('rinnovo-incassato').value = prezzo.toFixed(2);
  document.getElementById('rinnovo-data-inizio').value = inizio;
  document.getElementById('rinnovo-data-pagamento').value = oggi;
  document.getElementById('modal-rinnovo').classList.add('open');
}
</script>

<section class="panel">
  <h2>Pagamenti / acconti</h2>
  <div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Data</th><th>Importo</th><th>Abbonamento</th><th>Metodo</th><th>Causale</th><th>Acconto</th><th>Saldo residuo</th><th>Operatore</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pagamenti as $p): ?>
      <tr>
        <td><?= htmlspecialchars(substr($p['created_at'], 0, 16)) ?></td>
        <td>€<?= number_format((float) $p['importo'], 2, ',', '.') ?></td>
        <td><?= $p['abbonamento_id'] ? '<span class="badge badge-info">' . htmlspecialchars($p['piano_nome'] ?? 'Abbonamento') . '</span>' : '<span class="muted">libero</span>' ?></td>
        <td><?= htmlspecialchars($p['metodo']) ?></td>
        <td><?= htmlspecialchars($p['causale'] ?? '-') ?></td>
        <td><?= $p['acconto'] ? 'Sì' : 'No' ?></td>
        <td><?= $p['acconto'] ? '€' . number_format((float) $p['saldo_residuo'], 2, ',', '.') : '-' ?></td>
        <td><?= htmlspecialchars($p['operatore_nome'] ?? '-') ?></td>
        <td style="text-align: right;">
          <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo pagamento di €<?= number_format((float) $p['importo'], 2, ',', '.') ?>?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="azione" value="elimina_pagamento">
            <input type="hidden" name="pagamento_id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn btn-outline btn-danger btn-sm" title="Elimina pagamento">🗑️</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pagamenti): ?><tr><td colspan="9" class="empty-state">Nessun pagamento registrato.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <form method="post" class="form-row-split mt-16">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="registra_pagamento">
    <div><label>Importo</label><input type="number" step="0.01" min="0.01" name="importo" required></div>
    <div>
      <label>Riferito a</label>
      <select name="abbonamento_id">
        <option value="">Pagamento libero (seduta, altro)</option>
        <?php foreach ($abbonamenti as $a): ?>
          <?php if (in_array($a['stato'], ['attivo', 'scaduto'], true)): ?>
            <option value="<?= $a['id'] ?>" <?= $abbonamentoAttivo && $a['id'] === $abbonamentoAttivo['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($a['piano_nome']) ?> (dal <?= htmlspecialchars($a['data_inizio']) ?>)<?= $a['saldo_residuo'] > 0.005 ? ' · residuo €' . number_format((float) $a['saldo_residuo'], 2, ',', '.') : ' · saldato' ?>
            </option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Data</label><input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div>
      <label>Metodo</label>
      <select name="metodo"><option value="contanti">Contanti</option><option value="carta">Carta</option><option value="bonifico">Bonifico</option><option value="altro">Altro</option></select>
    </div>
    <div><label>Causale</label><input type="text" name="causale"></div>
    <div><label class="checkbox-label"><input type="checkbox" name="acconto" value="1" onchange="document.getElementById('saldo-residuo-field').style.display=this.checked?'block':'none'"> È un acconto</label></div>
    <div id="saldo-residuo-field" style="display:none;"><label>Saldo residuo dopo l'acconto</label><input type="number" step="0.01" name="saldo_residuo"></div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Registra pagamento</button></div>
  </form>
</section>

<section class="panel">
  <h2>WhatsApp</h2>
  <form method="post" class="form-row-split">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="invia_whatsapp">
    <div>
      <label>Template</label>
      <select name="template">
        <option value="benvenuto">Benvenuto</option>
        <option value="scadenza_abbonamento">Promemoria scadenza</option>
        <option value="conferma_rinnovo">Conferma pagamento</option>
        <option value="promemoria_appuntamento">Promemoria appuntamento</option>
      </select>
    </div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Invia messaggio</button></div>
  </form>
  <table class="data-table mt-16">
    <thead><tr><th>Data</th><th>Stato</th><th>Messaggio</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($waLog, 0, 10) as $w): ?>
      <tr>
        <td><?= htmlspecialchars(substr($w['created_at'], 0, 16)) ?></td>
        <td><span class="badge badge-<?= $w['stato'] === 'inviato' ? 'success' : ($w['stato'] === 'errore' ? 'danger' : 'muted') ?>"><?= htmlspecialchars($w['stato']) ?></span></td>
        <td><?= htmlspecialchars($w['messaggio']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$waLog): ?><tr><td colspan="3" class="empty-state">Nessun messaggio inviato.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
