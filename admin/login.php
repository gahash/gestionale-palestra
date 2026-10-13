<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\Tenant;

Auth::start();
if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$errore = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (Auth::attempt($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }
    $errore = 'Credenziali non valide.';
}

$base = Config::baseUrl();
$tenant = Tenant::data();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accesso gestionale — <?= htmlspecialchars($tenant['nome'] ?? 'Gym Studio') ?></title>
<link rel="icon" href="<?= $base ?>/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $base ?>/css/admin.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" novalidate>
    <img src="<?= $base ?>/assets/logo.svg" alt="" class="login-logo">
    <h1><?= htmlspecialchars($tenant['nome'] ?? 'Gym Studio') ?></h1>
    <p class="login-sub">Area riservata gestionale</p>
    <?php if ($errore): ?><p class="login-error"><?= htmlspecialchars($errore) ?></p><?php endif; ?>
    <?= Csrf::field() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <button type="submit" class="btn btn-primary btn-block">Accedi</button>
    <a class="login-back" href="<?= $base ?>/">&larr; Torna al sito</a>
  </form>
</body>
</html>
