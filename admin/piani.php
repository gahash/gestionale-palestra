<?php
declare(strict_types=1);

use App\Csrf;
use App\Models\TipoAbbonamento;

$pageTitle = 'Piani abbonamento';
require __DIR__ . '/_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (($_POST['azione'] ?? '') === 'crea') {
        TipoAbbonamento::create($_POST);
    }
    if (($_POST['azione'] ?? '') === 'aggiorna') {
        TipoAbbonamento::update((int) $_POST['id'], $_POST);
    }
    header('Location: piani.php');
    exit;
}

$piani = TipoAbbonamento::all();
?>

<p class="empty-state">Questi piani alimentano automaticamente la sezione "Prezzi" della landing page pubblica.</p>

<section class="panel">
  <h2>Piani esistenti</h2>
  <?php foreach ($piani as $p): ?>
    <form method="post" class="template-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="azione" value="aggiorna">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div class="form-row-split">
        <div><label>Nome</label><input type="text" name="nome" value="<?= htmlspecialchars($p['nome']) ?>" required></div>
        <div><label>Prezzo (€)</label><input type="number" step="0.01" name="prezzo" value="<?= $p['prezzo'] ?>" required></div>
      </div>
      <div class="form-row-split">
        <div><label>Frequenza settimanale</label><input type="number" name="frequenza_settimanale" value="<?= htmlspecialchars((string) $p['frequenza_settimanale']) ?>"></div>
        <div><label>Durata (giorni)</label><input type="number" name="durata_giorni" value="<?= $p['durata_giorni'] ?>" required></div>
      </div>
      <div class="form-row-split">
        <div>
          <label>Tipo</label>
          <select name="tipo">
            <option value="abbonamento" <?= $p['tipo'] === 'abbonamento' ? 'selected' : '' ?>>Abbonamento mensile</option>
            <option value="pacchetto_seduta" <?= $p['tipo'] === 'pacchetto_seduta' ? 'selected' : '' ?>>Pacchetto a seduta</option>
          </select>
        </div>
        <div><label>Ordine</label><input type="number" name="ordine" value="<?= $p['ordine'] ?>"></div>
      </div>
      <label>Descrizione (visibile sulla landing)</label>
      <input type="text" name="descrizione" value="<?= htmlspecialchars($p['descrizione'] ?? '') ?>">
      <div class="template-actions">
        <label class="checkbox-label"><input type="checkbox" name="in_evidenza" value="1" <?= $p['in_evidenza'] ? 'checked' : '' ?>> In evidenza ("più scelto")</label>
        <label class="checkbox-label"><input type="checkbox" name="attivo" value="1" <?= $p['attivo'] ? 'checked' : '' ?>> Attivo (visibile online)</label>
        <button class="btn btn-outline btn-sm" type="submit">Salva</button>
      </div>
    </form>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2>Nuovo piano</h2>
  <form method="post" class="form-row-split">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="crea">
    <div><label>Nome</label><input type="text" name="nome" required></div>
    <div><label>Prezzo (€)</label><input type="number" step="0.01" name="prezzo" required></div>
    <div><label>Frequenza settimanale</label><input type="number" name="frequenza_settimanale"></div>
    <div><label>Durata (giorni)</label><input type="number" name="durata_giorni" value="30" required></div>
    <div>
      <label>Tipo</label>
      <select name="tipo"><option value="abbonamento">Abbonamento mensile</option><option value="pacchetto_seduta">Pacchetto a seduta</option></select>
    </div>
    <div><label>Descrizione</label><input type="text" name="descrizione"></div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Crea piano</button></div>
  </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
