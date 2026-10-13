<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Tenant;

$tenant = Tenant::data();
$piani = Tenant::piani();
$base = Config::baseUrl();
$nome = $tenant['nome'] ?? Config::get('APP_NAME', 'Gym Studio');
$claim = $tenant['claim'] ?? '';
$descrizione = $tenant['descrizione'] ?? '';
$telefono = $tenant['telefono'] ?? '';
$telefonoPulito = preg_replace('/\s+/', '', (string) $telefono);
$whatsapp = preg_replace('/\D+/', '', (string) ($tenant['whatsapp_numero'] ?? $telefono));
$indirizzo = trim(($tenant['indirizzo'] ?? '') . ', ' . ($tenant['cap'] ?? '') . ' ' . ($tenant['citta'] ?? ''), ' ,');
$colorePrimario = $tenant['colore_primario'] ?? '#f5c400';
$coloreSecondario = $tenant['colore_secondario'] ?? '#111111';
$mapQuery = urlencode($indirizzo);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($nome) ?> — <?= htmlspecialchars($claim) ?></title>
<meta name="description" content="<?= htmlspecialchars($descrizione) ?>">
<meta property="og:title" content="<?= htmlspecialchars($nome) ?>">
<meta property="og:description" content="<?= htmlspecialchars($descrizione) ?>">
<meta property="og:type" content="business.business">
<meta name="theme-color" content="<?= htmlspecialchars($colorePrimario) ?>">
<link rel="icon" href="<?= $base ?>/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $base ?>/css/style.css">
<style>:root{ --primario: <?= htmlspecialchars($colorePrimario) ?>; --secondario: <?= htmlspecialchars($coloreSecondario) ?>; }</style>
</head>
<body>

<header class="site-header">
  <div class="container header-inner">
    <a href="#top" class="brand">
      <img src="<?= $base ?>/assets/logo.svg" alt="Logo <?= htmlspecialchars($nome) ?>" class="brand-logo">
      <span class="brand-name"><?= htmlspecialchars($nome) ?></span>
    </a>
    <nav class="main-nav" id="main-nav">
      <a href="#servizi">Servizi</a>
      <a href="#prezzi">Prezzi</a>
      <a href="#musica">Musica</a>
      <a href="#contatti">Contatti</a>
    </nav>
    <div class="header-actions">
      <a class="btn btn-primary nav-cta" href="#prenota">Prenota ora</a>
      <button type="button" class="nav-toggle" id="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Apri menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<main id="top">

  <section class="hero">
    <div class="hero-training-bg" aria-hidden="true">
      <img src="<?= $base ?>/assets/foto-hero.jpg" alt="">
    </div>
    <div class="container hero-inner">
      <h1><?= htmlspecialchars($nome) ?></h1>
      <p class="hero-claim"><?= htmlspecialchars($claim) ?></p>
      <p class="hero-desc"><?= htmlspecialchars($descrizione) ?></p>
      <div class="hero-actions">
        <a href="#prenota" class="btn btn-primary btn-lg">Prenota la tua sessione</a>
        <?php if ($telefono): ?>
          <a href="tel:<?= htmlspecialchars($telefonoPulito) ?>" class="btn btn-outline btn-lg">Chiama <?= htmlspecialchars($telefono) ?></a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="servizi" class="features">
    <div class="container">
      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon">👥</div>
          <h3>Massimo 4 persone</h3>
          <p>Sessioni in piccolo gruppo per un'attenzione reale del personal trainer.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">🎯</div>
          <h3>Obiettivi garantiti</h3>
          <p>Percorsi personalizzati costruiti sui tuoi obiettivi, non standard.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">💶</div>
          <h3>Prezzi accessibili</h3>
          <p>Iscrizione gratuita: paghi solo la quota mensile, tutto incluso.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="prezzi" class="pricing">
    <div class="container">
      <h2>Scegli la tua sessione fitness</h2>
      <p class="section-sub">Iscrizione gratuita. Inizia oggi, paghi solo un pagamento mensile: tutto il resto è incluso.</p>
      <div class="pricing-grid">
        <?php foreach ($piani as $piano): ?>
          <div class="price-card <?= $piano['in_evidenza'] ? 'price-card--highlight' : '' ?>">
            <?php if ($piano['in_evidenza']): ?><span class="price-badge">Più scelto</span><?php endif; ?>
            <h3><?= htmlspecialchars($piano['nome']) ?></h3>
            <p class="price-amount">
              €<?= number_format((float) $piano['prezzo'], 0) ?>
              <span><?= $piano['tipo'] === 'pacchetto_seduta' ? '/ seduta' : '/ mese' ?></span>
            </p>
            <p class="price-desc"><?= htmlspecialchars($piano['descrizione']) ?></p>
            <a href="#prenota" class="btn btn-outline btn-block">Scegli questo piano</a>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="section-note">Più prenoti, meno spendi! Scegli il tuo abbonamento ideale, scopri il tuo benessere.</p>
    </div>
  </section>

  <section id="musica" class="music">
    <div class="container music-inner">
      <div class="music-text">
        <h2>🎧 Allenati con la musica giusta</h2>
        <p class="section-sub" style="margin:0 0 18px;">La nostra playlist per la sala pesi: premi play e allenati a ritmo, direttamente dal browser (funziona anche da smartphone).</p>
        <a href="https://www.youtube.com/watch?v=AMkHbApwxrA" target="_blank" rel="noopener" class="btn btn-outline">Apri su YouTube ↗</a>
      </div>
      <div class="music-player" id="audio-player">
        <button type="button" class="player-toggle" id="player-toggle" aria-label="Play">
          <svg class="icon-play" viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>
          <svg class="icon-pause" viewBox="0 0 24 24" width="26" height="26" hidden><path fill="currentColor" d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
        </button>
        <div class="player-info">
          <p class="player-title">Workout Music 2026</p>
          <p class="player-sub">Fitness Motivation Music</p>
          <div class="player-progress">
            <div class="player-progress-bar" id="player-progress-bar"></div>
          </div>
          <div class="player-times">
            <span id="player-time-current">0:00</span>
            <span id="player-time-total">--:--</span>
          </div>
        </div>
        <!-- player YouTube reale, presente ma non visibile: fornisce solo l'audio -->
        <div id="yt-audio-target" class="player-hidden-frame"></div>
      </div>
    </div>
  </section>

  <section id="prenota" class="booking">
    <div class="container booking-inner">
      <div class="booking-info">
        <h2>Richiedi info o prenota</h2>
        <p>Compila il modulo: ti ricontattiamo per confermare il tuo primo allenamento gratuito di prova.</p>
        <?php if ($indirizzo): ?><p><strong>📍 Indirizzo:</strong> <?= htmlspecialchars($indirizzo) ?></p><?php endif; ?>
        <?php if ($telefono): ?><p><strong>📞 Telefono:</strong> <a href="tel:<?= htmlspecialchars($telefonoPulito) ?>"><?= htmlspecialchars($telefono) ?></a></p><?php endif; ?>
        <?php if ($whatsapp): ?><p><strong>💬 WhatsApp:</strong> <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>" target="_blank" rel="noopener">Scrivici su WhatsApp</a></p><?php endif; ?>
        <?php if (!empty($tenant['orari_apertura'])): ?><p><strong>🕒 Orari:</strong> <?= htmlspecialchars($tenant['orari_apertura']) ?></p><?php endif; ?>
      </div>

      <form id="contact-form" class="booking-form" novalidate>
        <div class="form-row">
          <label for="nome">Nome e cognome *</label>
          <input type="text" id="nome" name="nome" required>
        </div>
        <div class="form-row">
          <label for="telefono">Telefono *</label>
          <input type="tel" id="telefono" name="telefono" required>
        </div>
        <div class="form-row">
          <label for="email">Email</label>
          <input type="email" id="email" name="email">
        </div>
        <div class="form-row">
          <label for="servizio_interesse">Servizio di interesse</label>
          <select id="servizio_interesse" name="servizio_interesse">
            <option value="">Seleziona...</option>
            <?php foreach ($piani as $piano): ?>
              <option value="<?= htmlspecialchars($piano['nome']) ?>"><?= htmlspecialchars($piano['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row form-row-split">
          <div>
            <label for="giorno_preferito">Giorno preferito</label>
            <input type="text" id="giorno_preferito" name="giorno_preferito" placeholder="es. Lunedì">
          </div>
          <div>
            <label for="fascia_oraria">Fascia oraria</label>
            <input type="text" id="fascia_oraria" name="fascia_oraria" placeholder="es. Sera">
          </div>
        </div>
        <div class="form-row">
          <label for="messaggio">Messaggio</label>
          <textarea id="messaggio" name="messaggio" rows="3"></textarea>
        </div>

        <!-- honeypot anti-spam: campo invisibile, deve restare vuoto -->
        <div class="hp-field" aria-hidden="true">
          <label for="sito_web">Non compilare</label>
          <input type="text" id="sito_web" name="sito_web" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-row form-row-consent">
          <label class="checkbox-label">
            <input type="checkbox" id="gdpr_consenso" name="gdpr_consenso" required>
            Ho letto la <a href="<?= $base ?>/privacy.php" target="_blank">informativa privacy</a> e acconsento al trattamento dei miei dati per essere ricontattato. *
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Invia richiesta</button>
        <p id="form-feedback" class="form-feedback" role="status"></p>
      </form>
    </div>
  </section>

  <?php if ($indirizzo): ?>
  <section class="map">
    <iframe
      src="https://www.google.com/maps?q=<?= $mapQuery ?>&output=embed"
      width="100%" height="380" style="border:0;" allowfullscreen loading="lazy"
      referrerpolicy="no-referrer-when-downgrade" title="Mappa <?= htmlspecialchars($nome) ?>"></iframe>
  </section>
  <?php endif; ?>

</main>

<footer id="contatti" class="site-footer">
  <div class="container footer-grid">

    <div class="footer-col footer-brand">
      <div class="footer-brand-name">
        <img src="<?= $base ?>/assets/logo.svg" alt="" class="footer-logo">
        <strong><?= htmlspecialchars($nome) ?></strong>
      </div>
      <?php if ($descrizione): ?><p class="footer-desc"><?= htmlspecialchars($descrizione) ?></p><?php endif; ?>
      <?php if ($indirizzo): ?><p>📍 <?= htmlspecialchars($indirizzo) ?></p><?php endif; ?>
      <?php if ($telefono): ?><p>📞 <a href="tel:<?= htmlspecialchars($telefonoPulito) ?>"><?= htmlspecialchars($telefono) ?></a></p><?php endif; ?>
      <?php if ($whatsapp): ?><p>💬 <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>" target="_blank" rel="noopener">Scrivici su WhatsApp</a></p><?php endif; ?>
    </div>

    <div class="footer-col">
      <h4>Link utili</h4>
      <a href="#servizi">Servizi</a>
      <a href="#prezzi">Prezzi</a>
      <a href="#prenota">Prenota</a>
      <a href="<?= $base ?>/admin/login.php">Area riservata</a>
    </div>

    <div class="footer-col">
      <h4>Orari di apertura</h4>
      <?php if (!empty($tenant['orari_apertura'])): ?>
        <?php foreach (explode(',', (string) $tenant['orari_apertura']) as $riga): ?>
          <p><?= htmlspecialchars(trim($riga)) ?></p>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="footer-muted">Orari su richiesta telefonica.</p>
      <?php endif; ?>
    </div>

    <div class="footer-col">
      <h4>Privacy &amp; GDPR</h4>
      <p class="footer-desc">Trattiamo i tuoi dati personali nel rispetto del Regolamento (UE) 2016/679. Puoi richiedere in ogni momento accesso, rettifica o cancellazione dei tuoi dati.</p>
      <a href="<?= $base ?>/privacy.php">📄 Informativa Privacy completa</a>
      <?php if (!empty($tenant['email'])): ?>
        <a href="mailto:<?= htmlspecialchars($tenant['email']) ?>">✉️ Richieste sui tuoi dati</a>
      <?php endif; ?>
    </div>

  </div>

  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($nome) ?>. Tutti i diritti riservati.</p>
    <p><a href="<?= $base ?>/privacy.php">Privacy Policy</a></p>
  </div>
</footer>

<script src="<?= $base ?>/js/main.js"></script>
</body>
</html>
