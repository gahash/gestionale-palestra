<?php
// Router SOLO per il server di sviluppo PHP integrato (php -S) usato in locale.
// In produzione su hosting reale (Apache) questo file NON viene usato: le regole
// equivalenti sono in .htaccess. Non caricare questo file sull'hosting definitivo.

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}

if (preg_match('#^/api(/|$)#', $uri)) {
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // lascia servire il file statico direttamente
}

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// prova a risolvere <path>.php per pagine dirette (es. /admin/dashboard.php)
$phpFile = __DIR__ . $uri;
if (is_dir($phpFile)) {
    $phpFile = rtrim($phpFile, '/') . '/index.php';
}
if (file_exists($phpFile)) {
    chdir(dirname($phpFile));
    require $phpFile;
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;
