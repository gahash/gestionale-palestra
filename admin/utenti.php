<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Models\User;

$pageTitle = 'Utenti staff';
require __DIR__ . '/_header.php';

Auth::requireRole(['admin']);

$errore = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (($_POST['azione'] ?? '') === 'crea') {
        if (strlen((string) $_POST['password']) < 8) {
            $errore = 'La password deve avere almeno 8 caratteri.';
        } else {
            User::create($_POST['nome'], $_POST['cognome'], $_POST['email'], $_POST['password'], $_POST['ruolo']);
            header('Location: utenti.php');
            exit;
        }
    }
    if (($_POST['azione'] ?? '') === 'toggle') {
        User::setAttivo((int) $_POST['id'], !empty($_POST['attivo']));
        header('Location: utenti.php');
        exit;
    }
}

$utenti = User::all();
?>

<section class="panel">
  <h2>Staff registrato</h2>
  <table class="data-table">
    <thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Ultimo accesso</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($utenti as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['nome'] . ' ' . $u['cognome']) ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['ruolo']) ?></td>
        <td><?= htmlspecialchars($u['ultimo_accesso'] ?? 'mai') ?></td>
        <td><span class="badge badge-<?= $u['attivo'] ? 'success' : 'muted' ?>"><?= $u['attivo'] ? 'attivo' : 'disattivato' ?></span></td>
        <td>
          <form method="post" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="azione" value="toggle">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <input type="hidden" name="attivo" value="<?= $u['attivo'] ? 0 : 1 ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= $u['attivo'] ? 'Disattiva' : 'Riattiva' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Nuovo utente staff</h2>
  <?php if ($errore): ?><p class="flash-err"><?= htmlspecialchars($errore) ?></p><?php endif; ?>
  <form method="post" class="form-row-split">
    <?= Csrf::field() ?>
    <input type="hidden" name="azione" value="crea">
    <div><label>Nome</label><input type="text" name="nome" required></div>
    <div><label>Cognome</label><input type="text" name="cognome" required></div>
    <div><label>Email</label><input type="email" name="email" required></div>
    <div><label>Password iniziale</label><input type="password" name="password" required minlength="8"></div>
    <div>
      <label>Ruolo</label>
      <select name="ruolo">
        <option value="staff">Staff reception</option>
        <option value="trainer">Personal trainer</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div style="align-self:end;"><button class="btn btn-primary" type="submit">Crea utente</button></div>
  </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
