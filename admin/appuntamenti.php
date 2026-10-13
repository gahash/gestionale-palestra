<?php
declare(strict_types=1);

use App\Csrf;
use App\Models\Appuntamento;
use App\Models\Cliente;
use App\Models\User;

$pageTitle = 'Appuntamenti';
require __DIR__ . '/_header.php';

// ---------------------------------------------------------------------
// Parametri vista: settimana (default, stile Teams/Outlook) oppure giorno
// ---------------------------------------------------------------------
$vista = ($_GET['vista'] ?? 'settimana') === 'giorno' ? 'giorno' : 'settimana';
$data = $_GET['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
    $data = date('Y-m-d');
}
$oggi = date('Y-m-d');
$flashErr = null;
$flashOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'prenota') {
    Csrf::requireValid();
    try {
        $dataApp = $_POST['data'] ?: $data;
        $oraInizio = substr($_POST['ora_inizio'], 0, 5);
        $oraFine = substr($_POST['ora_fine'], 0, 5);
        if ($oraFine <= $oraInizio) {
            throw new \RuntimeException("L'ora di fine deve essere successiva all'ora di inizio.");
        }

        // Raccoglie i nomi dei clienti inseriti (fino a 5)
        $nomiClienti = [];
        if (!empty($_POST['clienti_nomi']) && is_array($_POST['clienti_nomi'])) {
            foreach ($_POST['clienti_nomi'] as $n) {
                $n = trim((string) $n);
                if ($n !== '') {
                    $nomiClienti[] = $n;
                }
            }
        } elseif (!empty($_POST['clienti_nomi_json'])) {
            $decoded = json_decode((string) $_POST['clienti_nomi_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') {
                        $nomiClienti[] = $n;
                    }
                }
            }
        } elseif (!empty($_POST['cliente_nome'])) {
            $n = trim((string) $_POST['cliente_nome']);
            if ($n !== '') {
                $nomiClienti[] = $n;
            }
        } elseif (!empty($_POST['cliente_id'])) {
            // Retrocompatibilità
            $c = Cliente::find((int) $_POST['cliente_id']);
            if ($c) {
                $nomiClienti[] = trim($c['nome'] . ' ' . $c['cognome']);
            }
        }

        if (empty($nomiClienti)) {
            throw new \RuntimeException("Inserisci almeno un cliente per la prenotazione.");
        }
        if (count($nomiClienti) > 5) {
            throw new \RuntimeException("È possibile inserire al massimo 5 clienti per prenotazione.");
        }

        // Risolvi o registra ciascun cliente
        $clientiIds = [];
        foreach ($nomiClienti as $nomeC) {
            $clientiIds[] = Cliente::trovaOCreaPerNome($nomeC, $user['id'] ?? null);
        }

        Appuntamento::creaMultiplo(
            $clientiIds,
            !empty($_POST['trainer_id']) ? (int) $_POST['trainer_id'] : null,
            $dataApp,
            $oraInizio . ':00',
            $oraFine . ':00',
            (int) ($_POST['capienza_max'] ?? 4),
            $_POST['note'] ?: null
        );

        header('Location: appuntamenti.php?vista=' . $vista . '&data=' . urlencode($dataApp) . '&ok=1');
        exit;
    } catch (\RuntimeException $e) {
        $flashErr = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'cancella_appuntamento') {
    Csrf::requireValid();
    $appId = (int) ($_POST['appuntamento_id'] ?? 0);
    if ($appId > 0) {
        Appuntamento::cambiaStato($appId, 'cancellato');
        header('Location: appuntamenti.php?vista=' . $vista . '&data=' . urlencode($data) . '&cancellato=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'cancella_sessione') {
    Csrf::requireValid();
    $d = (string) ($_POST['data_sessione'] ?? '');
    $oraInizio = (string) ($_POST['ora_inizio'] ?? '');
    $oraFine = (string) ($_POST['ora_fine'] ?? '');
    $count = Appuntamento::cancellaSessione($d, $oraInizio, $oraFine);
    header('Location: appuntamenti.php?vista=' . $vista . '&data=' . urlencode($d ?: $data) . '&cancellati=' . $count);
    exit;
}

if (($_GET['ok'] ?? '') === '1') {
    $flashOk = 'Prenotazione salvata con successo.';
} elseif (isset($_GET['cancellati'])) {
    $n = (int) $_GET['cancellati'];
    $flashOk = $n === 1 ? '1 prenotazione cancellata con successo.' : "{$n} prenotazioni cancellate con successo. I posti sono stati liberati.";
} elseif (isset($_GET['cancellato'])) {
    $flashOk = 'Prenotazione cancellata con successo. Il posto è stato liberato.';
}

// ---------------------------------------------------------------------
// Calcolo intervallo: settimana Lun-Dom che contiene $data
// ---------------------------------------------------------------------
$ts = strtotime($data);
$lunedi = date('Y-m-d', strtotime('monday this week', $ts));
$domenica = date('Y-m-d', strtotime($lunedi . ' +6 days'));
$settimanaPrec = date('Y-m-d', strtotime($lunedi . ' -7 days'));
$settimanaSucc = date('Y-m-d', strtotime($lunedi . ' +7 days'));
$giornoPrec = date('Y-m-d', strtotime($data . ' -1 day'));
$giornoSucc = date('Y-m-d', strtotime($data . ' +1 day'));

$giorniIt = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
$giorniLunghi = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];
$mesiIt = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

$giorni = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime($lunedi . " +{$i} days"));
    $giorni[] = ['data' => $d, 'label' => $giorniIt[$i], 'num' => (int) date('j', strtotime($d)), 'lungo' => $giorniLunghi[$i], 'oggi' => $d === $oggi];
}

$rangeInizio = $vista === 'settimana' ? $lunedi : $data;
$rangeFine = $vista === 'settimana' ? $domenica : $data;
$appuntamenti = Appuntamento::delPeriodo($rangeInizio, $rangeFine);
$sessioni = Appuntamento::raggruppaInSessioni($appuntamenti);

// Fascia oraria della griglia: 07:00-22:00, allargata se ci sono sessioni fuori orario
$oraMin = 7;
$oraMax = 22;
foreach ($sessioni as $s) {
    $oraMin = min($oraMin, (int) substr($s['ora_inizio'], 0, 2));
    $oraMax = max($oraMax, (int) ceil(((int) substr($s['ora_fine'], 0, 2)) + ((int) substr($s['ora_fine'], 3, 2) > 0 ? 1 : 0)));
}
$oraMax = min(max($oraMax, $oraMin + 1), 24);
$slotMinuti = 60;
$altezzaOra = 64; // px per ora nella griglia desktop
$nOre = $oraMax - $oraMin;

// Sessioni indicizzate per giorno, con offset/altezza in px per la griglia
$sessioniPerGiorno = [];
foreach ($sessioni as $s) {
    $inizioMin = ((int) substr($s['ora_inizio'], 0, 2)) * 60 + (int) substr($s['ora_inizio'], 3, 2);
    $fineMin = ((int) substr($s['ora_fine'], 0, 2)) * 60 + (int) substr($s['ora_fine'], 3, 2);
    $s['top'] = (int) round(($inizioMin - $oraMin * 60) / 60 * $altezzaOra);
    $s['height'] = max(28, (int) round(($fineMin - $inizioMin) / 60 * $altezzaOra) - 2);
    $s['pieno'] = $s['occupati'] >= $s['capienza_max'];
    $sessioniPerGiorno[$s['data']][] = $s;
}

$clienti = Cliente::all();
$trainers = User::trainers();
$base = \App\Config::baseUrl();
$titoloSettimana = date('j', strtotime($lunedi)) . ' – ' . date('j', strtotime($domenica)) . ' ' . $mesiIt[(int) date('n', strtotime($domenica))] . ' ' . date('Y', strtotime($domenica));
if (date('n', strtotime($lunedi)) !== date('n', strtotime($domenica))) {
    $titoloSettimana = date('j', strtotime($lunedi)) . ' ' . $mesiIt[(int) date('n', strtotime($lunedi))] . ' – ' . date('j', strtotime($domenica)) . ' ' . $mesiIt[(int) date('n', strtotime($domenica))] . ' ' . date('Y', strtotime($domenica));
}
$titoloGiorno = $giorniLunghi[(int) date('N', $ts) - 1] . ' ' . date('j', $ts) . ' ' . $mesiIt[(int) date('n', $ts)] . ' ' . date('Y', $ts);
$stati = ['prenotato' => 'Prenotato', 'confermato' => 'Confermato', 'presente' => 'Presente', 'assente' => 'Assente', 'cancellato' => 'Cancellato'];
?>

<section class="panel cal-panel">
  <div class="panel-header cal-toolbar">
    <div class="date-nav">
      <?php if ($vista === 'settimana'): ?>
        <a class="btn btn-outline btn-sm" href="?vista=settimana&data=<?= $settimanaPrec ?>" aria-label="Settimana precedente">&larr;</a>
        <a class="btn btn-outline btn-sm" href="?vista=settimana&data=<?= $oggi ?>">Oggi</a>
        <a class="btn btn-outline btn-sm" href="?vista=settimana&data=<?= $settimanaSucc ?>" aria-label="Settimana successiva">&rarr;</a>
        <strong class="cal-title"><?= htmlspecialchars($titoloSettimana) ?></strong>
      <?php else: ?>
        <a class="btn btn-outline btn-sm" href="?vista=giorno&data=<?= $giornoPrec ?>" aria-label="Giorno precedente">&larr;</a>
        <a class="btn btn-outline btn-sm" href="?vista=giorno&data=<?= $oggi ?>">Oggi</a>
        <a class="btn btn-outline btn-sm" href="?vista=giorno&data=<?= $giornoSucc ?>" aria-label="Giorno successivo">&rarr;</a>
        <strong class="cal-title"><?= htmlspecialchars($titoloGiorno) ?></strong>
      <?php endif; ?>
      <form method="get" class="inline-search cal-datepick">
        <input type="hidden" name="vista" value="<?= $vista ?>">
        <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" onchange="this.form.submit()" aria-label="Vai alla data">
      </form>
    </div>
    <div class="cal-actions">
      <div class="filter-tabs">
        <a class="filter-tab <?= $vista === 'settimana' ? 'active' : '' ?>" href="?vista=settimana&data=<?= $data ?>">Settimana</a>
        <a class="filter-tab <?= $vista === 'giorno' ? 'active' : '' ?>" href="?vista=giorno&data=<?= $data ?>">Giorno</a>
      </div>
      <button class="btn btn-primary" type="button" onclick="apriPrenotazione('<?= $data ?>', '', '')">+ Nuova prenotazione</button>
    </div>
  </div>
  <?php if ($flashErr): ?><p class="flash-err"><?= htmlspecialchars($flashErr) ?></p><?php endif; ?>
  <?php if ($flashOk): ?><p class="flash-ok"><?= htmlspecialchars($flashOk) ?></p><?php endif; ?>

  <p class="cal-legend">
    <span class="cal-dot cal-dot-free"></span> posti liberi
    <span class="cal-dot cal-dot-full"></span> sessione al completo
    <span class="muted">· clicca una sessione per il dettaglio, uno spazio vuoto per prenotare</span>
  </p>

  <?php if ($vista === 'settimana'): ?>
  <!-- ================= VISTA SETTIMANA (griglia oraria) ================= -->
  <div class="cal-scroll">
    <div class="cal-week" style="--cal-hour-h: <?= $altezzaOra ?>px; --cal-hours: <?= $nOre ?>;">
      <div class="cal-head cal-head-time"></div>
      <?php foreach ($giorni as $g): ?>
        <div class="cal-head <?= $g['oggi'] ? 'is-today' : '' ?>">
          <span class="cal-head-day"><?= $g['label'] ?></span>
          <a class="cal-head-num" href="?vista=giorno&data=<?= $g['data'] ?>" title="Apri <?= $g['lungo'] ?> in vista giorno"><?= $g['num'] ?></a>
          <span class="cal-head-count"><?= count($sessioniPerGiorno[$g['data']] ?? []) ?: '–' ?> sess.</span>
        </div>
      <?php endforeach; ?>

      <div class="cal-times">
        <?php for ($h = $oraMin; $h < $oraMax; $h++): ?>
          <div class="cal-time"><?= sprintf('%02d:00', $h) ?></div>
        <?php endfor; ?>
      </div>

      <?php foreach ($giorni as $g): ?>
        <div class="cal-col <?= $g['oggi'] ? 'is-today' : '' ?>" data-data="<?= $g['data'] ?>">
          <?php for ($h = $oraMin; $h < $oraMax; $h++): ?>
            <button type="button" class="cal-slot" data-ora="<?= sprintf('%02d:00', $h) ?>" onclick="apriPrenotazione('<?= $g['data'] ?>', '<?= sprintf('%02d:00', $h) ?>', '<?= sprintf('%02d:00', min($h + 1, 23)) ?>')" aria-label="Prenota <?= $g['lungo'] ?> <?= $g['num'] ?> alle <?= sprintf('%02d:00', $h) ?>"></button>
          <?php endfor; ?>
          <?php foreach ($sessioniPerGiorno[$g['data']] ?? [] as $s): ?>
            <button type="button" class="cal-event <?= $s['pieno'] ? 'is-full' : '' ?>" style="top:<?= $s['top'] ?>px;height:<?= $s['height'] ?>px;" data-session="<?= htmlspecialchars($s['key']) ?>" onclick="apriDettaglio(this.dataset.session)">
              <span class="cal-event-time"><?= $s['ora_inizio'] ?>–<?= $s['ora_fine'] ?></span>
              <span class="cal-event-cap"><?= $s['occupati'] ?>/<?= $s['capienza_max'] ?></span>
              <span class="cal-event-names">
                <?php foreach ($s['partecipanti'] as $p): ?>
                  <span class="cal-name st-<?= $p['stato'] ?>" title="<?= htmlspecialchars($p['nome']) ?>"><?= htmlspecialchars($p['nome']) ?></span>
                <?php endforeach; ?>
              </span>
            </button>
          <?php endforeach; ?>
          <div class="cal-agenda">
            <?php if (empty($sessioniPerGiorno[$g['data']])): ?>
              <p class="cal-agenda-empty">Nessuna sessione</p>
            <?php else: ?>
              <?php foreach ($sessioniPerGiorno[$g['data']] as $s): ?>
                <button type="button" class="cal-agenda-item <?= $s['pieno'] ? 'is-full' : '' ?>" data-session="<?= htmlspecialchars($s['key']) ?>" onclick="apriDettaglio(this.dataset.session)">
                  <span class="cal-agenda-time"><?= $s['ora_inizio'] ?>–<?= $s['ora_fine'] ?></span>
                  <span class="cal-agenda-body">
                    <strong><?= $s['occupati'] ?>/<?= $s['capienza_max'] ?> posti</strong><?= $s['trainer'] ? ' · ' . htmlspecialchars($s['trainer']) : '' ?><br>
                    <span class="muted"><?= htmlspecialchars(implode(', ', array_map(fn($p) => $p['nome'], $s['partecipanti']))) ?></span>
                  </span>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm cal-agenda-add" onclick="apriPrenotazione('<?= $g['data'] ?>', '', '')">+ Prenota</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- ================= VISTA GIORNO (lista sessioni) ================= -->
  <div class="cal-day">
    <div id="day-bulk-bar" class="bulk-actions-toolbar" style="display: none; margin-bottom: 12px;">
      <span id="day-bulk-count">0 appuntamenti selezionati</span>
      <button type="button" class="btn btn-danger btn-sm" onclick="cancellaSelezionatiGiorno()">🗑️ Elimina selezionati</button>
    </div>
    <?php if (!$sessioni): ?>
      <p class="empty-state">Nessuna prenotazione per questo giorno.</p>
    <?php endif; ?>
    <?php foreach ($sessioni as $s): ?>
      <div class="cal-day-session <?= $s['pieno'] ? 'is-full' : '' ?>">
        <div class="cal-day-time">
          <strong><?= $s['ora_inizio'] ?></strong><span><?= $s['ora_fine'] ?></span>
          <span class="badge <?= $s['pieno'] ? 'badge-danger' : 'badge-success' ?>"><?= $s['occupati'] ?>/<?= $s['capienza_max'] ?></span>
        </div>
        <div class="cal-day-body">
          <?php if ($s['trainer']): ?><p class="muted">Trainer: <?= htmlspecialchars($s['trainer']) ?></p><?php endif; ?>
          <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 36px; text-align: center;"><input type="checkbox" class="day-check-session-all" title="Seleziona tutti nella sessione" onchange="toggleDaySessionAll(this)"></th>
                <th>Cliente</th>
                <th>Stato</th>
                <th>Note</th>
                <th></th>
                <th style="width: 36px;"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($s['partecipanti'] as $p): ?>
              <tr data-app-id="<?= $p['id'] ?>">
                <td style="text-align: center;"><input type="checkbox" class="day-check-item" value="<?= $p['id'] ?>" onchange="aggiornaDayBulkBar()"></td>
                <td><a href="cliente.php?id=<?= $p['cliente_id'] ?>"><strong><?= htmlspecialchars($p['nome']) ?></strong></a><?php if ($p['telefono']): ?> <span class="muted"><?= htmlspecialchars($p['telefono']) ?></span><?php endif; ?></td>
                <td>
                  <select class="stato-select" data-app-id="<?= $p['id'] ?>">
                    <?php foreach ($stati as $val => $lab): ?>
                      <option value="<?= $val ?>" <?= $p['stato'] === $val ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><?= htmlspecialchars($p['note'] ?? '-') ?></td>
                <td class="stato-feedback"></td>
                <td style="text-align: right;">
                  <button type="button" class="btn btn-outline btn-danger btn-sm" title="Cancella questa prenotazione" onclick="if(confirm('Cancellare la prenotazione di <?= htmlspecialchars(addslashes($p['nome'])) ?>?')) { salvaStato(<?= $p['id'] ?>, 'cancellato', this.closest('tr').querySelector('.stato-feedback'), this.closest('tr')); }">🗑️</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; flex-wrap: wrap; gap: 8px;">
            <?php if (!$s['pieno']): ?>
              <button type="button" class="btn btn-outline btn-sm" onclick="apriPrenotazione('<?= $s['data'] ?>', '<?= $s['ora_inizio'] ?>', '<?= $s['ora_fine'] ?>')">+ Aggiungi a questa sessione</button>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-danger btn-sm" onclick="cancellaSessioneRapida('<?= $s['data'] ?>', '<?= $s['ora_inizio'] ?>', '<?= $s['ora_fine'] ?>')">🗑️ Elimina intera sessione</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ================= MODALE DETTAGLIO SESSIONE ================= -->
<div id="modal-dettaglio" class="modal">
  <div class="modal-card">
    <div class="modal-head">
      <h2 id="det-titolo">Sessione</h2>
      <button type="button" class="modal-close" aria-label="Chiudi" onclick="document.getElementById('modal-dettaglio').classList.remove('open')">×</button>
    </div>
    <p class="muted" id="det-sub"></p>
    <div class="bulk-actions-toolbar" id="det-bulk-bar" style="display: none; margin-bottom: 12px;">
      <span id="det-bulk-count">0 partecipanti selezionati</span>
      <button type="button" class="btn btn-danger btn-sm" id="det-btn-cancella-selezionati" onclick="cancellaSelezionatiSessione()">🗑️ Elimina selezionati</button>
    </div>
    <div class="table-wrap">
    <table class="data-table" id="det-table">
      <thead>
        <tr>
          <th style="width: 36px; text-align: center;"><input type="checkbox" id="det-check-all" title="Seleziona tutti" onchange="toggleDetCheckAll(this.checked)"></th>
          <th>Cliente</th>
          <th>Stato</th>
          <th></th>
          <th style="width: 36px;"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    </div>
    <div class="modal-actions" style="justify-content: space-between; flex-wrap: wrap; gap: 10px;">
      <div style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-danger btn-outline btn-sm" id="det-btn-cancella-sessione" onclick="cancellaInteraSessione()">🗑️ Elimina intera sessione</button>
      </div>
      <div style="display: flex; gap: 8px;">
        <a class="btn btn-outline" id="det-link-giorno" href="#">Vista giorno</a>
        <button type="button" class="btn btn-primary" id="det-add">+ Aggiungi cliente</button>
      </div>
    </div>
  </div>
</div>

<!-- ================= MODALE NUOVA PRENOTAZIONE ================= -->
<div id="modal-app" class="modal">
  <div class="modal-card">
    <div class="modal-head">
      <h2>Nuova prenotazione</h2>
      <button type="button" class="modal-close" aria-label="Chiudi" onclick="document.getElementById('modal-app').classList.remove('open')">×</button>
    </div>
    <form method="post" id="form-prenota">
      <?= Csrf::field() ?>
      <input type="hidden" name="azione" value="prenota">
      <label for="cliente-input-nome">Cliente / Partecipanti <span class="muted" style="font-weight: normal;">(scrivi e premi Invio o "+ Aggiungi", max 5)</span></label>
      <div class="clienti-multi-box">
        <div class="clienti-input-row">
          <div class="clienti-input-wrap">
            <input type="text" id="cliente-input-nome" placeholder="🔍 Cerca cliente in anagrafica (es. Mario Rossi, Rossi o telefono)..." autocomplete="off">
            <div id="cliente-suggerimenti-dropdown" class="clienti-suggerimenti-dropdown" style="display: none;"></div>
          </div>
          <button type="button" class="btn btn-outline btn-sm" id="btn-add-cliente" onclick="aggiungiClienteDaInput()">+ Aggiungi</button>
        </div>
        <div class="clienti-list" id="clienti-list"></div>
        <div class="clienti-status-bar" id="clienti-status-bar">
          <span id="clienti-count-label">0 di 5 persone inserite</span>
          <span class="clienti-limit-msg" id="clienti-limit-msg" style="display: none;">Limite massimo di 5 persone raggiunto</span>
        </div>
        <div id="clienti-hidden-inputs"></div>
      </div>
      <label>Personal trainer</label>
      <select name="trainer_id">
        <option value="">Non assegnato</option>
        <?php foreach ($trainers as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nome'] . ' ' . $t['cognome']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-row-split">
        <div><label>Data</label><input type="date" name="data" id="pren-data" value="<?= htmlspecialchars($data) ?>" required></div>
        <div><label>Capienza massima</label><input type="number" name="capienza_max" value="4" min="1" max="20" required></div>
      </div>
      <div class="form-row-split">
        <div><label>Ora inizio</label><input type="time" name="ora_inizio" id="pren-inizio" step="900" required onchange="autoFine()"></div>
        <div><label>Ora fine</label><input type="time" name="ora_fine" id="pren-fine" step="900" required></div>
      </div>
      <label>Note</label><input type="text" name="note" maxlength="255">
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-app').classList.remove('open')">Annulla</button>
        <button type="submit" class="btn btn-primary">Prenota</button>
      </div>
    </form>
  </div>
</div>

<script>
var SESSIONI = <?= json_encode(array_column($sessioni, null, 'key'), JSON_UNESCAPED_UNICODE) ?>;
var STATI = <?= json_encode($stati, JSON_UNESCAPED_UNICODE) ?>;
var API_BASE = '<?= $base ?>/api/appuntamenti/';
var GIORNI_LUNGHI = <?= json_encode($giorniLunghi, JSON_UNESCAPED_UNICODE) ?>;
var CLIENTI_ANAGRAFICA = <?= json_encode(array_values(array_map(function($c) {
    $n = trim((string)($c['nome'] ?? ''));
    $cog = trim((string)($c['cognome'] ?? ''));
    return [
        'id' => (int) $c['id'],
        'nome' => $n,
        'cognome' => $cog,
        'nome_completo' => trim($n . ' ' . $cog),
        'cognome_nome' => trim($cog . ' ' . $n),
        'telefono' => trim((string)($c['telefono'] ?? '')),
        'email' => trim((string)($c['email'] ?? '')),
    ];
}, $clienti)), JSON_UNESCAPED_UNICODE) ?>;

// Gestione elenco clienti nella modale nuova prenotazione (max 5)
var listaClienti = [];

function renderClientiList() {
  var listEl = document.getElementById('clienti-list');
  var hiddenEl = document.getElementById('clienti-hidden-inputs');
  var countEl = document.getElementById('clienti-count-label');
  var limitMsg = document.getElementById('clienti-limit-msg');
  var inputEl = document.getElementById('cliente-input-nome');
  var btnAdd = document.getElementById('btn-add-cliente');
  if (!listEl || !hiddenEl) return;

  listEl.innerHTML = '';
  hiddenEl.innerHTML = '';

  listaClienti.forEach(function (nome, idx) {
    var item = document.createElement('div');
    item.className = 'cliente-item';

    var nameSpan = document.createElement('span');
    nameSpan.className = 'cliente-item-name';

    var numBadge = document.createElement('span');
    numBadge.className = 'cliente-item-num';
    numBadge.textContent = (idx + 1);

    var textNode = document.createTextNode(nome);

    nameSpan.appendChild(numBadge);
    nameSpan.appendChild(textNode);

    var actions = document.createElement('div');
    actions.className = 'cliente-item-actions';

    var btnEdit = document.createElement('button');
    btnEdit.type = 'button';
    btnEdit.className = 'cliente-btn-action cliente-btn-edit';
    btnEdit.innerHTML = '✏️ Modifica';
    btnEdit.title = 'Modifica questo nome';
    btnEdit.onclick = function () { modificaCliente(idx); };

    var btnRemove = document.createElement('button');
    btnRemove.type = 'button';
    btnRemove.className = 'cliente-btn-action cliente-btn-remove';
    btnRemove.innerHTML = '✕';
    btnRemove.title = 'Rimuovi questo nome';
    btnRemove.onclick = function () { rimuoviCliente(idx); };

    actions.appendChild(btnEdit);
    actions.appendChild(btnRemove);

    item.appendChild(nameSpan);
    item.appendChild(actions);
    listEl.appendChild(item);

    var hid = document.createElement('input');
    hid.type = 'hidden';
    hid.name = 'clienti_nomi[]';
    hid.value = nome;
    hiddenEl.appendChild(hid);
  });

  if (countEl) {
    countEl.textContent = listaClienti.length === 0
      ? 'Nessun cliente inserito (minimo 1, massimo 5)'
      : listaClienti.length + ' di 5 persone inserite';
  }

  var raggiuntoLimite = listaClienti.length >= 5;
  if (inputEl) {
    inputEl.disabled = raggiuntoLimite;
    inputEl.placeholder = raggiuntoLimite ? 'Limite di 5 clienti raggiunto' : '🔍 Cerca cliente in anagrafica (es. Mario Rossi, Rossi o telefono)...';
  }
  if (btnAdd) {
    btnAdd.disabled = raggiuntoLimite;
  }
  if (limitMsg) {
    limitMsg.style.display = raggiuntoLimite ? 'inline' : 'none';
  }
}

function aggiungiClienteDaInput() {
  var inputEl = document.getElementById('cliente-input-nome');
  if (!inputEl) return;
  var val = inputEl.value.trim();
  if (!val) return;
  if (listaClienti.length >= 5) {
    alert('È possibile inserire al massimo 5 clienti per prenotazione.');
    return;
  }
  listaClienti.push(val);
  inputEl.value = '';
  renderClientiList();
  if (listaClienti.length < 5) {
    inputEl.focus();
  }
}

function modificaCliente(idx) {
  if (idx < 0 || idx >= listaClienti.length) return;
  var nome = listaClienti[idx];
  listaClienti.splice(idx, 1);
  renderClientiList();
  var inputEl = document.getElementById('cliente-input-nome');
  var btnAdd = document.getElementById('btn-add-cliente');
  if (inputEl) {
    inputEl.disabled = false;
    inputEl.value = nome;
    inputEl.focus();
    inputEl.select();
  }
  if (btnAdd) btnAdd.disabled = false;
}

function rimuoviCliente(idx) {
  if (idx < 0 || idx >= listaClienti.length) return;
  listaClienti.splice(idx, 1);
  renderClientiList();
  var inputEl = document.getElementById('cliente-input-nome');
  if (inputEl && listaClienti.length < 5) {
    inputEl.focus();
  }
}

// ================= AUTOCOMPLETE SUGGERIMENTI CLIENTI IN ANAGRAFICA =================
var indexSuggerimentoAttivo = -1;
var listaSuggerimentiCorrente = [];

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function normalizzaTesto(str) {
  if (!str) return '';
  return String(str)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();
}

function tokenToRegexPattern(token) {
  var map = {
    'a': '[aàáâäã]',
    'e': '[eèéêë]',
    'i': '[iìíîï]',
    'o': '[oòóôöõ]',
    'u': '[uùúûü]',
    'c': '[cç]'
  };
  return token.split('').map(function(ch) {
    var low = ch.toLowerCase();
    return map[low] || ch.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');
  }).join('');
}

function evidenziaMultiMatch(testo, tokens) {
  if (!testo) return '';
  if (!tokens || tokens.length === 0) return escapeHtml(testo);

  var patterns = [];
  tokens.forEach(function(t) {
    if (t && t.length > 0) {
      patterns.push(tokenToRegexPattern(t));
    }
  });

  if (patterns.length === 0) return escapeHtml(testo);

  var escaped = escapeHtml(testo);
  try {
    var re = new RegExp('(' + patterns.join('|') + ')', 'gi');
    return escaped.replace(re, '<mark class="sugg-mark">$1</mark>');
  } catch (e) {
    return escaped;
  }
}

function filtraSuggerimenti(testo) {
  var rawQ = (testo || '').trim();
  var drop = document.getElementById('cliente-suggerimenti-dropdown');
  if (!drop) return;

  if (rawQ.length < 1) {
    nascondiSuggerimenti();
    return;
  }

  var normQ = normalizzaTesto(rawQ);
  var tokens = normQ.split(/\s+/).filter(Boolean);

  // Filtra clienti in anagrafica escludendo chi è già stato aggiunto
  var filtrati = (CLIENTI_ANAGRAFICA || []).filter(function (c) {
    if (listaClienti.indexOf(c.nome_completo) !== -1) {
      return false;
    }
    var complN = normalizzaTesto(c.nome_completo);
    var cognComplN = normalizzaTesto(c.cognome_nome);
    var telN = normalizzaTesto(c.telefono).replace(/[\s\-\.\/]/g, '');
    var emN = normalizzaTesto(c.email);

    // Ogni token digitato deve trovare corrispondenza in almeno uno dei campi
    return tokens.every(function (t) {
      var tClean = t.replace(/[\s\-\.\/]/g, '');
      return complN.indexOf(t) !== -1
        || cognComplN.indexOf(t) !== -1
        || (tClean.length >= 2 && telN.indexOf(tClean) !== -1)
        || emN.indexOf(t) !== -1;
    });
  });

  // Ordinamento intelligente per pertinenza:
  // 1. Chi inizia con la query intera (nome o cognome)
  // 2. Ordine alfabetico
  filtrati.sort(function (a, b) {
    var aCompl = normalizzaTesto(a.nome_completo);
    var bCompl = normalizzaTesto(b.nome_completo);
    var aCogn = normalizzaTesto(a.cognome_nome);
    var bCogn = normalizzaTesto(b.cognome_nome);

    var aStarts = aCompl.indexOf(normQ) === 0 || aCogn.indexOf(normQ) === 0;
    var bStarts = bCompl.indexOf(normQ) === 0 || bCogn.indexOf(normQ) === 0;
    if (aStarts && !bStarts) return -1;
    if (!aStarts && bStarts) return 1;

    return a.nome_completo.localeCompare(b.nome_completo);
  });

  listaSuggerimentiCorrente = filtrati.slice(0, 8);
  indexSuggerimentoAttivo = -1;
  drop.innerHTML = '';

  if (listaSuggerimentiCorrente.length === 0) {
    var emptyDiv = document.createElement('div');
    emptyDiv.className = 'suggerimento-empty';
    emptyDiv.innerHTML = '<span>Nessun cliente in anagrafica trovato per "<strong>' + escapeHtml(rawQ) + '</strong>".</span><br><small style="color:var(--testo-chiaro);">Premi <strong>+ Aggiungi</strong> o Invio per inserirlo come nuovo cliente.</small>';
    drop.appendChild(emptyDiv);
    drop.style.display = 'block';
    return;
  }

  listaSuggerimentiCorrente.forEach(function (c, idx) {
    var item = document.createElement('div');
    item.className = 'suggerimento-item';
    item.setAttribute('data-idx', idx);

    var left = document.createElement('div');
    left.className = 'suggerimento-left';

    var nomeSpan = document.createElement('div');
    nomeSpan.className = 'suggerimento-nome';
    nomeSpan.innerHTML = evidenziaMultiMatch(c.nome_completo, tokens);

    var metaSpan = document.createElement('div');
    metaSpan.className = 'suggerimento-meta';
    var info = [];
    if (c.telefono) info.push('📞 ' + evidenziaMultiMatch(c.telefono, tokens));
    if (c.email) info.push('✉️ ' + evidenziaMultiMatch(c.email, tokens));
    metaSpan.innerHTML = info.length > 0 ? info.join(' &middot; ') : 'Cliente in anagrafica';

    left.appendChild(nomeSpan);
    left.appendChild(metaSpan);

    var badge = document.createElement('span');
    badge.className = 'suggerimento-badge';
    badge.textContent = '✓ In anagrafica';

    item.appendChild(left);
    item.appendChild(badge);

    item.addEventListener('mousedown', function (e) {
      e.preventDefault();
      selezionaSuggerimento(c);
    });

    item.addEventListener('mouseenter', function () {
      impostaSuggerimentoAttivo(idx);
    });

    drop.appendChild(item);
  });

  drop.style.display = 'block';
}

function impostaSuggerimentoAttivo(idx) {
  indexSuggerimentoAttivo = idx;
  var drop = document.getElementById('cliente-suggerimenti-dropdown');
  if (!drop) return;
  var items = drop.querySelectorAll('.suggerimento-item');
  items.forEach(function (el, i) {
    if (i === idx) {
      el.classList.add('active');
      el.scrollIntoView({ block: 'nearest' });
    } else {
      el.classList.remove('active');
    }
  });
}

function selezionaSuggerimento(c) {
  if (!c || !c.nome_completo) return;
  if (listaClienti.length >= 5) {
    alert('È possibile inserire al massimo 5 clienti per prenotazione.');
    return;
  }
  listaClienti.push(c.nome_completo);
  var inputEl = document.getElementById('cliente-input-nome');
  if (inputEl) inputEl.value = '';
  renderClientiList();
  nascondiSuggerimenti();
  if (listaClienti.length < 5 && inputEl) {
    inputEl.focus();
  }
}

function nascondiSuggerimenti() {
  var drop = document.getElementById('cliente-suggerimenti-dropdown');
  if (drop) {
    drop.style.display = 'none';
    drop.innerHTML = '';
  }
  indexSuggerimentoAttivo = -1;
  listaSuggerimentiCorrente = [];
}

// Gestione invio rapido e validazione form
document.addEventListener('DOMContentLoaded', function () {
  var inputEl = document.getElementById('cliente-input-nome');
  if (inputEl) {
    inputEl.addEventListener('input', function () {
      filtraSuggerimenti(this.value);
    });

    inputEl.addEventListener('focus', function () {
      if (this.value.trim().length >= 1) {
        filtraSuggerimenti(this.value);
      }
    });

    inputEl.addEventListener('keydown', function (e) {
      var drop = document.getElementById('cliente-suggerimenti-dropdown');
      var dropAperto = drop && drop.style.display !== 'none';

      if (e.key === 'ArrowDown') {
        if (dropAperto && listaSuggerimentiCorrente.length > 0) {
          e.preventDefault();
          var next = (indexSuggerimentoAttivo + 1) % listaSuggerimentiCorrente.length;
          impostaSuggerimentoAttivo(next);
        }
      } else if (e.key === 'ArrowUp') {
        if (dropAperto && listaSuggerimentiCorrente.length > 0) {
          e.preventDefault();
          var prev = indexSuggerimentoAttivo <= 0 ? listaSuggerimentiCorrente.length - 1 : indexSuggerimentoAttivo - 1;
          impostaSuggerimentoAttivo(prev);
        }
      } else if (e.key === 'Enter') {
        if (dropAperto && indexSuggerimentoAttivo >= 0 && listaSuggerimentiCorrente[indexSuggerimentoAttivo]) {
          e.preventDefault();
          selezionaSuggerimento(listaSuggerimentiCorrente[indexSuggerimentoAttivo]);
        } else if (dropAperto && listaSuggerimentiCorrente.length === 1 && (normalizzaTesto(listaSuggerimentiCorrente[0].nome_completo) === normalizzaTesto(this.value) || normalizzaTesto(listaSuggerimentiCorrente[0].cognome_nome) === normalizzaTesto(this.value))) {
          // Corrispondenza esatta automatica
          e.preventDefault();
          selezionaSuggerimento(listaSuggerimentiCorrente[0]);
        } else {
          e.preventDefault();
          aggiungiClienteDaInput();
          nascondiSuggerimenti();
        }
      } else if (e.key === 'Escape') {
        nascondiSuggerimenti();
      }
    });

    document.addEventListener('click', function (e) {
      var wrap = document.querySelector('.clienti-input-wrap');
      if (wrap && !wrap.contains(e.target)) {
        nascondiSuggerimenti();
      }
    });
  }

  var formPrenota = document.getElementById('form-prenota');
  if (formPrenota) {
    formPrenota.addEventListener('submit', function (e) {
      // Se l'utente ha scritto un nome nel campo ma non ha premuto Aggiungi, aggiungilo ora
      var val = (inputEl ? inputEl.value : '').trim();
      if (val && listaClienti.length < 5) {
        listaClienti.push(val);
        inputEl.value = '';
        renderClientiList();
      }
      if (listaClienti.length === 0) {
        e.preventDefault();
        alert('Inserisci almeno un nome di cliente prima di salvare la prenotazione.');
        if (inputEl) inputEl.focus();
        return false;
      }
    });
  }
});

function autoFine() {
  var i = document.getElementById('pren-inizio');
  var f = document.getElementById('pren-fine');
  if (!i.value) return;
  if (!f.value || f.value <= i.value) {
    var parts = i.value.split(':');
    var h = Math.min(parseInt(parts[0], 10) + 1, 23);
    f.value = (h < 10 ? '0' : '') + h + ':' + parts[1];
  }
}

function apriPrenotazione(data, inizio, fine) {
  document.getElementById('modal-dettaglio').classList.remove('open');
  if (data) document.getElementById('pren-data').value = data;
  document.getElementById('pren-inizio').value = inizio || '';
  document.getElementById('pren-fine').value = fine || '';

  // Resetta la lista dei clienti inseriti
  listaClienti = [];
  var inp = document.getElementById('cliente-input-nome');
  if (inp) inp.value = '';
  renderClientiList();
  nascondiSuggerimenti();

  document.getElementById('modal-app').classList.add('open');
  setTimeout(function () { if (inp) inp.focus(); }, 60);
}


function salvaStato(id, stato, feedbackEl, rowEl) {
  fetch(API_BASE + id + '/stato', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ stato: stato })
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (feedbackEl) feedbackEl.textContent = res.success ? '✓' : ('Errore: ' + res.error);
    if (res.success) {
      // aggiorna la cache locale e il colore nel calendario
      Object.keys(SESSIONI).forEach(function (k) {
        SESSIONI[k].partecipanti.forEach(function (p) { if (p.id == id) p.stato = stato; });
      });
      document.querySelectorAll('.cal-name').forEach(function () {});
      if (stato === 'cancellato' && rowEl) rowEl.style.opacity = '.4';
      if (stato === 'cancellato' || stato === 'assente') {
        // ricarica per aggiornare il conteggio posti
        setTimeout(function () { location.reload(); }, 400);
      }
    }
  }).catch(function () { if (feedbackEl) feedbackEl.textContent = 'Errore di rete'; });
}

var currentDetailSession = null;

function apriDettaglio(key) {
  var s = SESSIONI[key];
  if (!s) return;
  currentDetailSession = s;
  var d = new Date(s.data + 'T00:00:00');
  var giorno = GIORNI_LUNGHI[(d.getDay() + 6) % 7];
  document.getElementById('det-titolo').textContent = giorno + ' ' + d.getDate() + '/' + (d.getMonth() + 1) + ' · ' + s.ora_inizio + '–' + s.ora_fine;
  document.getElementById('det-sub').textContent = s.occupati + '/' + s.capienza_max + ' posti occupati' + (s.trainer ? ' · Trainer: ' + s.trainer : '');
  
  var checkAll = document.getElementById('det-check-all');
  if (checkAll) checkAll.checked = false;
  var bulkBar = document.getElementById('det-bulk-bar');
  if (bulkBar) bulkBar.style.display = 'none';

  var tbody = document.querySelector('#det-table tbody');
  tbody.innerHTML = '';
  s.partecipanti.forEach(function (p) {
    var tr = document.createElement('tr');
    
    var tdCheck = document.createElement('td');
    tdCheck.style.textAlign = 'center';
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'det-check-item';
    cb.value = p.id;
    cb.addEventListener('change', aggiornaDetBulkBar);
    tdCheck.appendChild(cb);

    var tdNome = document.createElement('td');
    var a = document.createElement('a');
    a.href = 'cliente.php?id=' + p.cliente_id;
    a.textContent = p.nome;
    tdNome.appendChild(a);
    if (p.note) {
      var n = document.createElement('div'); n.className = 'muted'; n.textContent = p.note; tdNome.appendChild(n);
    }

    var tdStato = document.createElement('td');
    var sel = document.createElement('select');
    Object.keys(STATI).forEach(function (val) {
      var o = document.createElement('option'); o.value = val; o.textContent = STATI[val]; if (p.stato === val) o.selected = true; sel.appendChild(o);
    });
    var tdFb = document.createElement('td'); tdFb.className = 'stato-feedback';
    sel.addEventListener('change', function () { salvaStato(p.id, sel.value, tdFb, tr); });
    tdStato.appendChild(sel);

    var tdAction = document.createElement('td');
    tdAction.style.textAlign = 'right';
    var btnCanc = document.createElement('button');
    btnCanc.type = 'button';
    btnCanc.className = 'btn btn-outline btn-danger btn-sm';
    btnCanc.innerHTML = '🗑️';
    btnCanc.title = 'Cancella questa prenotazione';
    btnCanc.onclick = (function (idApp, nomePart, fbElem, trElem) {
      return function () {
        if (confirm('Cancellare la prenotazione di ' + nomePart + '?')) {
          salvaStato(idApp, 'cancellato', fbElem, trElem);
        }
      };
    })(p.id, p.nome, tdFb, tr);
    tdAction.appendChild(btnCanc);

    tr.appendChild(tdCheck);
    tr.appendChild(tdNome);
    tr.appendChild(tdStato);
    tr.appendChild(tdFb);
    tr.appendChild(tdAction);
    tbody.appendChild(tr);
  });
  var add = document.getElementById('det-add');
  add.disabled = s.occupati >= s.capienza_max;
  add.textContent = add.disabled ? 'Sessione al completo' : '+ Aggiungi cliente';
  add.onclick = function () { apriPrenotazione(s.data, s.ora_inizio, s.ora_fine); };
  document.getElementById('det-link-giorno').href = '?vista=giorno&data=' + s.data;
  document.getElementById('modal-dettaglio').classList.add('open');
}

function toggleDetCheckAll(checked) {
  document.querySelectorAll('#det-table .det-check-item').forEach(function (cb) {
    cb.checked = checked;
  });
  aggiornaDetBulkBar();
}

function aggiornaDetBulkBar() {
  var checked = document.querySelectorAll('#det-table .det-check-item:checked');
  var bar = document.getElementById('det-bulk-bar');
  var countEl = document.getElementById('det-bulk-count');
  var checkAll = document.getElementById('det-check-all');
  var total = document.querySelectorAll('#det-table .det-check-item').length;

  if (checked.length > 0) {
    if (bar) bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length + (checked.length === 1 ? ' partecipante selezionato' : ' partecipanti selezionati');
  } else {
    if (bar) bar.style.display = 'none';
  }
  if (checkAll) {
    checkAll.checked = total > 0 && checked.length === total;
  }
}

function cancellaSelezionatiSessione() {
  var checked = document.querySelectorAll('#det-table .det-check-item:checked');
  if (checked.length === 0) return;
  var ids = Array.from(checked).map(function (cb) { return parseInt(cb.value, 10); });
  if (!confirm('Sei sicuro di voler cancellare i ' + ids.length + ' partecipanti selezionati? I posti verranno liberati.')) {
    return;
  }
  fetch(API_BASE + 'cancella-multiplo', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: ids })
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (res.success) {
      location.reload();
    } else {
      alert('Errore durante la cancellazione: ' + (res.error || 'errore sconosciuto'));
    }
  }).catch(function () { alert('Errore di rete durante la cancellazione.'); });
}

function cancellaInteraSessione() {
  if (!currentDetailSession) return;
  var s = currentDetailSession;
  if (!confirm('Sei sicuro di voler eliminare TUTTI i partecipanti di questa sessione (' + s.ora_inizio + '–' + s.ora_fine + ')?\nI posti verranno liberati e la sessione svuotata.')) {
    return;
  }
  fetch(API_BASE + 'cancella-sessione', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: s.data, ora_inizio: s.ora_inizio, ora_fine: s.ora_fine })
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (res.success) {
      location.reload();
    } else {
      alert('Errore durante la cancellazione: ' + (res.error || 'errore sconosciuto'));
    }
  }).catch(function () { alert('Errore di rete.'); });
}

function cancellaSessioneRapida(data, oraInizio, oraFine) {
  if (!confirm('Sei sicuro di voler cancellare tutti i partecipanti della sessione ' + oraInizio + '–' + oraFine + '?')) {
    return;
  }
  fetch(API_BASE + 'cancella-sessione', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: data, ora_inizio: oraInizio, ora_fine: oraFine })
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (res.success) {
      location.reload();
    } else {
      alert('Errore: ' + (res.error || 'errore sconosciuto'));
    }
  }).catch(function () { alert('Errore di connessione.'); });
}

function toggleDaySessionAll(masterCb) {
  var table = masterCb.closest('table');
  if (!table) return;
  table.querySelectorAll('.day-check-item').forEach(function (cb) {
    cb.checked = masterCb.checked;
  });
  aggiornaDayBulkBar();
}

function aggiornaDayBulkBar() {
  var checked = document.querySelectorAll('.day-check-item:checked');
  var bar = document.getElementById('day-bulk-bar');
  var countEl = document.getElementById('day-bulk-count');
  if (checked.length > 0) {
    if (bar) bar.style.display = 'flex';
    if (countEl) countEl.textContent = checked.length + (checked.length === 1 ? ' appuntamento selezionato' : ' appuntamenti selezionati');
  } else {
    if (bar) bar.style.display = 'none';
  }
}

function cancellaSelezionatiGiorno() {
  var checked = document.querySelectorAll('.day-check-item:checked');
  if (checked.length === 0) return;
  var ids = Array.from(checked).map(function (cb) { return parseInt(cb.value, 10); });
  if (!confirm('Sei sicuro di voler cancellare i ' + ids.length + ' appuntamenti selezionati? I posti verranno liberati.')) {
    return;
  }
  fetch(API_BASE + 'cancella-multiplo', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: ids })
  }).then(function (r) { return r.json(); }).then(function (res) {
    if (res.success) {
      location.reload();
    } else {
      alert('Errore durante la cancellazione: ' + (res.error || 'errore sconosciuto'));
    }
  }).catch(function () { alert('Errore di rete.'); });
}

// Vista giorno: cambio stato inline
document.querySelectorAll('.stato-select').forEach(function (sel) {
  sel.addEventListener('change', function () {
    var tr = sel.closest('tr');
    salvaStato(sel.dataset.appId, sel.value, tr.querySelector('.stato-feedback'), tr);
  });
});

// Scrolla la griglia alle 8:00 circa se la settimana e' quella corrente
(function () {
  var scroll = document.querySelector('.cal-scroll');
  if (!scroll) return;
  var today = scroll.querySelector('.cal-col.is-today');
  if (today && window.innerWidth < 760) today.scrollIntoView({ block: 'nearest' });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
