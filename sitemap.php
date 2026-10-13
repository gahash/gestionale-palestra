<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// Sitemap generata dinamicamente: usa l'host della richiesta corrente,
// cosi' resta corretta su qualsiasi dominio senza bisogno di modifiche.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = $scheme . '://' . $host;

header('Content-Type: application/xml; charset=utf-8');
$pages = ['/', '/privacy.php'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $page) {
    echo '  <url><loc>' . htmlspecialchars($base . $page) . '</loc></url>' . "\n";
}
echo '</urlset>';
