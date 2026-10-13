<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Tenant;

$tenant = Tenant::data();
$nome = $tenant['nome'] ?? Config::get('APP_NAME', 'Gym Studio');
$email = $tenant['email'] ?? '';
$base = Config::baseUrl();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy — <?= htmlspecialchars($nome) ?></title>
<link rel="stylesheet" href="<?= $base ?>/css/style.css">
</head>
<body>
<main class="container" style="padding:60px 24px; max-width:820px;">
  <h1><?= htmlspecialchars($nome) ?> — Informativa Privacy</h1>
  <p><em>Informativa semplificata ai sensi del Regolamento (UE) 2016/679 (GDPR). Personalizzala con il tuo consulente privacy prima della pubblicazione definitiva.</em></p>

  <h3>Titolare del trattamento</h3>
  <p><?= htmlspecialchars($nome) ?><?= $email ? ' — ' . htmlspecialchars($email) : '' ?></p>

  <h3>Dati raccolti</h3>
  <p>Attraverso il modulo di contatto raccogliamo: nome, telefono, email (facoltativa), servizio di interesse, giorno/fascia oraria preferita e messaggio libero. In fase di iscrizione in studio raccogliamo inoltre: data di nascita, codice fiscale, indirizzo, certificato medico e storico allenamenti/pagamenti.</p>

  <h3>Finalità del trattamento</h3>
  <p>I dati sono trattati per: rispondere alla richiesta di contatto, gestire l'iscrizione e l'erogazione dei servizi, adempiere obblighi contabili/fiscali, inviare comunicazioni di servizio (es. promemoria appuntamenti/scadenze via WhatsApp o email) previo consenso.</p>

  <h3>Base giuridica</h3>
  <p>Consenso dell'interessato (form di contatto) ed esecuzione di un contratto/misure precontrattuali (iscrizione ai corsi).</p>

  <h3>Conservazione</h3>
  <p>I dati dei lead non convertiti sono conservati per un massimo di 24 mesi. I dati dei clienti sono conservati per la durata del rapporto e per gli obblighi di legge successivi.</p>

  <h3>Diritti dell'interessato</h3>
  <p>In ogni momento puoi richiedere accesso, rettifica, cancellazione o limitazione del trattamento dei tuoi dati scrivendo a <?= $email ? htmlspecialchars($email) : 'l\'indirizzo email dello studio' ?>.</p>

  <p><a href="<?= $base ?>/">&larr; Torna alla home</a></p>
</main>
</body>
</html>
