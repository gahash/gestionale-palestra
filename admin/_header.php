<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Tenant;
use App\Models\Lead;
use App\Models\Cliente;
use App\Models\Abbonamento;
use App\Models\Pagamento;

Auth::requireLogin();
$user = Auth::user();
$base = Config::baseUrl();
$tenant = Tenant::data();
$currentPage = basename($_SERVER['SCRIPT_NAME']);

$notifScadenze = Abbonamento::scadenziario(7);
$notifScaduti = Abbonamento::scadenziario('scaduti');
$notifDaRiscuotere = Pagamento::elencoDaRiscuotere();
$notifCertificati = Cliente::certificatiInScadenza(30);
$notifLead = Lead::all('nuovo');

$badgeScadenze = count($notifScadenze) + count($notifScaduti);
$badgeDaRiscuotere = count($notifDaRiscuotere);
$badgeCertificati = count($notifCertificati);
$badgeLead = count($notifLead);
$totaleNotifiche = $badgeScadenze + $badgeDaRiscuotere + $badgeCertificati + $badgeLead;

if (!function_exists('navLink')) {
    function navLink(string $file, string $label, string $icon, string $current, string $base, int $badge = 0, string $badgeType = 'badge-info'): string
    {
        $active = $current === $file ? ' active' : '';
        $badgeHtml = $badge > 0 ? ' <span class="nav-pill ' . $badgeType . '">' . $badge . '</span>' : '';
        return '<a class="nav-link' . $active . '" href="' . $base . '/admin/' . $file . '"><span>' . $icon . '</span><span class="nav-label">' . $label . '</span>' . $badgeHtml . '</a>';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Gestionale') ?> — <?= htmlspecialchars($tenant['nome'] ?? 'Gym Studio') ?></title>
<link rel="icon" href="<?= $base ?>/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $base ?>/css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?: '1' ?>">
</head>
<body>
<div class="admin-shell">
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="<?= $base ?>/assets/logo.svg" alt="">
      <span><?= htmlspecialchars($tenant['nome'] ?? 'Gym Studio') ?></span>
      <button type="button" class="sidebar-close" id="sidebar-close" aria-label="Chiudi menu">×</button>
    </div>
    <nav class="sidebar-nav">
      <?= navLink('dashboard.php', 'Dashboard', '📊', $currentPage, $base) ?>
      <?= navLink('clienti.php', 'Clienti', '🧑‍🤝‍🧑', $currentPage, $base, $badgeCertificati, 'badge-warn') ?>
      <?= navLink('abbonamenti.php', 'Scadenziario', '📅', $currentPage, $base, $badgeScadenze, 'badge-danger') ?>
      <?= navLink('appuntamenti.php', 'Appuntamenti', '🗓️', $currentPage, $base) ?>
      <?= navLink('leads.php', 'Richieste sito', '📥', $currentPage, $base, $badgeLead, 'badge-info') ?>
      <?= navLink('finanze.php', 'Finanze', '💶', $currentPage, $base, $badgeDaRiscuotere, 'badge-warn') ?>
      <?= navLink('whatsapp.php', 'WhatsApp', '💬', $currentPage, $base) ?>
      <?= navLink('piani.php', 'Piani abbonamento', '🏷️', $currentPage, $base) ?>
      <?php if (Auth::hasRole('admin')): ?>
        <?= navLink('utenti.php', 'Utenti staff', '🔑', $currentPage, $base) ?>
        <?= navLink('audit.php', 'Audit log', '🛡️', $currentPage, $base) ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <span class="nav-link" style="color:#8f8f8f;font-weight:500;"><?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?> · <?= htmlspecialchars($user['ruolo']) ?></span>
      <a href="<?= $base ?>/" target="_blank" class="nav-link">🌐 Vedi sito pubblico</a>
      <a href="<?= $base ?>/admin/logout.php" class="nav-link">🚪 Esci</a>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <button type="button" class="topbar-toggle" id="topbar-toggle" aria-label="Apri menu" aria-controls="sidebar" aria-expanded="false"><span></span></button>
      <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      
      <div class="topbar-right">
        <!-- CENTRO NOTIFICHE GESTIONALE -->
        <div class="notifiche-dropdown-wrap" id="notifiche-wrap">
          <button type="button" class="topbar-notif-btn" id="btn-toggle-notifiche" aria-label="Centro Notifiche" aria-expanded="false" title="Centro notifiche e avvisi">
            <span class="notif-icon">🔔</span>
            <?php if ($totaleNotifiche > 0): ?>
              <span class="notif-badge-bubble"><?= $totaleNotifiche ?></span>
            <?php endif; ?>
          </button>

          <div class="notifiche-flyout" id="notifiche-flyout">
            <div class="notif-flyout-head">
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:1.15rem;">🔔</span>
                <strong>Notifiche e Avvisi</strong>
              </div>
              <span class="badge <?= $totaleNotifiche > 0 ? 'badge-danger' : 'badge-success' ?>">
                <?= $totaleNotifiche ?> <?= $totaleNotifiche === 1 ? 'notifica' : 'notifiche' ?>
              </span>
            </div>

            <div class="notif-flyout-body">
              <?php if ($totaleNotifiche === 0): ?>
                <div class="notif-empty-state">
                  <div style="font-size: 2rem; margin-bottom: 6px;">✨</div>
                  <strong style="color:var(--testo);">Nessun avviso in sospeso</strong>
                  <p class="muted" style="margin:4px 0 0 0; font-size:0.85rem;">Tutti gli abbonamenti, pagamenti e certificati sono in regola.</p>
                </div>
              <?php else: ?>

                <?php if (!empty($notifDaRiscuotere)): ?>
                  <div class="notif-group">
                    <div class="notif-group-title">
                      <span>⚠️ Pagamenti da riscuotere</span>
                      <span class="notif-group-count"><?= count($notifDaRiscuotere) ?></span>
                    </div>
                    <?php foreach (array_slice($notifDaRiscuotere, 0, 4) as $d): ?>
                      <a class="notif-card notif-card-warn" href="<?= $base ?>/admin/cliente.php?id=<?= $d['cliente_id'] ?>">
                        <div class="notif-card-text">
                          <span class="notif-card-title"><?= htmlspecialchars($d['cliente_nome']) ?></span>
                          <span class="notif-card-desc"><?= htmlspecialchars($d['abbonamento_nome']) ?></span>
                        </div>
                        <div class="notif-card-meta txt-danger">
                          Residuo <strong>€<?= number_format((float)$d['saldo_residuo'], 2, ',', '.') ?></strong>
                        </div>
                      </a>
                    <?php endforeach; ?>
                    <?php if (count($notifDaRiscuotere) > 4): ?>
                      <a class="notif-see-all" href="<?= $base ?>/admin/finanze.php#acconti">Vedi tutti i <?= count($notifDaRiscuotere) ?> da riscuotere &rarr;</a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($notifScadenze)): ?>
                  <div class="notif-group">
                    <div class="notif-group-title">
                      <span>📅 In scadenza (7 giorni)</span>
                      <span class="notif-group-count"><?= count($notifScadenze) ?></span>
                    </div>
                    <?php foreach (array_slice($notifScadenze, 0, 4) as $s): ?>
                      <?php
                        $ggDiff = (int) floor((strtotime($s['data_scadenza']) - strtotime(date('Y-m-d'))) / 86400);
                      ?>
                      <a class="notif-card notif-card-info" href="<?= $base ?>/admin/cliente.php?id=<?= $s['cliente_id'] ?>">
                        <div class="notif-card-text">
                          <span class="notif-card-title"><?= htmlspecialchars($s['nome'] . ' ' . $s['cognome']) ?></span>
                          <span class="notif-card-desc"><?= htmlspecialchars($s['piano_nome']) ?></span>
                        </div>
                        <div class="notif-card-meta">
                          <span class="badge badge-warn"><?= $ggDiff === 0 ? 'Scade oggi' : "tra {$ggDiff}gg" ?></span>
                        </div>
                      </a>
                    <?php endforeach; ?>
                    <?php if (count($notifScadenze) > 4): ?>
                      <a class="notif-see-all" href="<?= $base ?>/admin/abbonamenti.php?filtro=7">Vedi tutti i <?= count($notifScadenze) ?> in scadenza &rarr;</a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($notifScaduti)): ?>
                  <div class="notif-group">
                    <div class="notif-group-title">
                      <span>⌛ Abbonamenti scaduti</span>
                      <span class="notif-group-count"><?= count($notifScaduti) ?></span>
                    </div>
                    <?php foreach (array_slice($notifScaduti, 0, 4) as $sc): ?>
                      <a class="notif-card notif-card-danger" href="<?= $base ?>/admin/cliente.php?id=<?= $sc['cliente_id'] ?>">
                        <div class="notif-card-text">
                          <span class="notif-card-title"><?= htmlspecialchars($sc['nome'] . ' ' . $sc['cognome']) ?></span>
                          <span class="notif-card-desc"><?= htmlspecialchars($sc['piano_nome']) ?> · Scaduto il <?= htmlspecialchars(date('d/m/Y', strtotime($sc['data_scadenza']))) ?></span>
                        </div>
                        <div class="notif-card-meta">
                          <span class="badge badge-danger">Scaduto</span>
                        </div>
                      </a>
                    <?php endforeach; ?>
                    <?php if (count($notifScaduti) > 4): ?>
                      <a class="notif-see-all" href="<?= $base ?>/admin/abbonamenti.php?filtro=scaduti">Vedi tutti gli scaduti &rarr;</a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($notifCertificati)): ?>
                  <div class="notif-group">
                    <div class="notif-group-title">
                      <span>🩺 Certificati medici in scadenza</span>
                      <span class="notif-group-count"><?= count($notifCertificati) ?></span>
                    </div>
                    <?php foreach (array_slice($notifCertificati, 0, 3) as $c): ?>
                      <a class="notif-card" href="<?= $base ?>/admin/cliente.php?id=<?= $c['id'] ?>">
                        <div class="notif-card-text">
                          <span class="notif-card-title"><?= htmlspecialchars($c['nome'] . ' ' . $c['cognome']) ?></span>
                          <span class="notif-card-desc">Scadenza: <?= htmlspecialchars(date('d/m/Y', strtotime($c['certificato_scadenza']))) ?></span>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($notifLead)): ?>
                  <div class="notif-group">
                    <div class="notif-group-title">
                      <span>📥 Nuove richieste dal sito</span>
                      <span class="notif-group-count"><?= count($notifLead) ?></span>
                    </div>
                    <?php foreach (array_slice($notifLead, 0, 3) as $l): ?>
                      <a class="notif-card" href="<?= $base ?>/admin/leads.php">
                        <div class="notif-card-text">
                          <span class="notif-card-title"><?= htmlspecialchars($l['nome']) ?> (<?= htmlspecialchars($l['telefono']) ?>)</span>
                          <span class="notif-card-desc">Interesse: <?= htmlspecialchars($l['servizio_interesse'] ?? 'Generale') ?></span>
                        </div>
                        <div class="notif-card-meta">
                          <span class="badge badge-info">Nuovo</span>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

              <?php endif; ?>
            </div>

            <div class="notif-flyout-foot">
              <a href="<?= $base ?>/admin/dashboard.php">Panoramica completa in Dashboard &rarr;</a>
            </div>
          </div>
        </div>

        <div class="topbar-user">
          <span><?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?> <em>(<?= htmlspecialchars($user['ruolo']) ?>)</em></span>
        </div>
      </div>
    </header>
    <main class="content">
