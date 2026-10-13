<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Models\Cliente;

Auth::requireLogin();

$tipo = $_GET['tipo'] ?? '';
$clienteId = (int) ($_GET['cliente_id'] ?? 0);
$cliente = Cliente::find($clienteId);

if (!$cliente || !in_array($tipo, ['foto', 'certificato'], true)) {
    http_response_code(404);
    exit('Non trovato');
}

$campo = $tipo === 'foto' ? 'foto_path' : 'certificato_medico_path';
$sottocartella = $tipo === 'foto' ? 'foto' : 'certificati';
$filename = $cliente[$campo] ?? null;

if (!$filename) {
    http_response_code(404);
    exit('File non presente');
}

$path = realpath(__DIR__ . '/../storage/uploads/' . $sottocartella . '/' . $filename);
$allowedDir = realpath(__DIR__ . '/../storage/uploads/' . $sottocartella);

if (!$path || !$allowedDir || !str_starts_with($path, $allowedDir) || !is_file($path)) {
    http_response_code(404);
    exit('File non trovato');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
