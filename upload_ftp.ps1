<#
    Upload FTP self-service - Gym Studio
    Da eseguire TU STESSO da PowerShell, dentro la cartella "gym-studio":
        ./upload_ftp.ps1
    La password viene chiesta in modo mascherato e non viene mai scritta su disco
    ne' passata come parametro/variabile d'ambiente: resta solo in memoria per la sessione.
#>

param(
    # Valori letti dalle variabili d'ambiente (FTP_HOST, FTP_USER, FTP_PORT, FTP_REMOTE_ROOT)
    # oppure passati come parametri: nessun host/utente reale e' scritto nello script.
    # Su molti hosting condivisi l'utente FTP contiene il dominio del sito: verifica il nome
    # esatto nel pannello dell'hosting e, se necessario, passalo con -FtpUser.
    [string]$FtpHost = $env:FTP_HOST,
    [string]$FtpUser = $env:FTP_USER,
    [int]$FtpPort = $(if ($env:FTP_PORT) { [int]$env:FTP_PORT } else { 21 }),
    [string]$RemoteRoot = $(if ($env:FTP_REMOTE_ROOT) { $env:FTP_REMOTE_ROOT } else { "public_html" })
)

if (-not $FtpHost -or -not $FtpUser) {
    Write-Host "Specifica host e utente FTP: variabili d'ambiente FTP_HOST/FTP_USER oppure parametri -FtpHost/-FtpUser." -ForegroundColor Red
    exit 1
}

$ErrorActionPreference = "Stop"

$SecurePassword = Read-Host -Prompt "Password FTP" -AsSecureString
$BSTR = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecurePassword)
$PlainPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($BSTR)

$LocalRoot = $PSScriptRoot
$Exclude = @('router.php', '.git', '.gitignore', 'upload_ftp.ps1', 'db_import.ps1', '.env.example', '.env', 'storage/backups', 'storage/uploads')

function Get-RemoteRelativePath($fullPath) {
    $rel = $fullPath.Substring($LocalRoot.Length).TrimStart('\', '/')
    return ($rel -replace '\\', '/')
}

function Test-Excluded($fullPath) {
    $rel = Get-RemoteRelativePath $fullPath
    foreach ($ex in $Exclude) {
        if ($rel -eq $ex -or $rel.StartsWith("$ex/")) { return $true }
    }
    return $false
}

function Join-RemotePath($rel) {
    if ($RemoteRoot -eq "") { return $rel }
    return "$RemoteRoot/$rel"
}

function New-RemoteDirectory($remotePath) {
    $uri = "ftp://$FtpHost`:$FtpPort/" + (Join-RemotePath $remotePath)
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $PlainPassword)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
    $req.UsePassive = $true
    try {
        $resp = $req.GetResponse()
        $resp.Close()
    } catch [System.Net.WebException] {
        # ignora errore "cartella gia' esistente"
    }
}

function Send-RemoteFile($localPath, $remotePath) {
    $uri = "ftp://$FtpHost`:$FtpPort/" + (Join-RemotePath $remotePath)
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $PlainPassword)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $req.UsePassive = $true
    $req.UseBinary = $true
    $content = [System.IO.File]::ReadAllBytes($localPath)
    $req.ContentLength = $content.Length
    $stream = $req.GetRequestStream()
    $stream.Write($content, 0, $content.Length)
    $stream.Close()
    $resp = $req.GetResponse()
    $resp.Close()
}

Write-Host "Connessione a ftp://$FtpHost`:$FtpPort ..." -ForegroundColor Cyan

# Verifica subito le credenziali con un semplice elenco cartella: se sono sbagliate ci si ferma qui
# con un messaggio chiaro, invece di fallire al primo file.
function Get-RemoteListing($path) {
    $req = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost`:$FtpPort/$path")
    $req.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $PlainPassword)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $req.UsePassive = $true
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $lines = @()
    while (-not $reader.EndOfStream) { $lines += $reader.ReadLine().Trim() }
    $reader.Close(); $resp.Close()
    return $lines
}

try {
    # Elenco della radice FTP: serve sia a verificare le credenziali sia a capire dove siamo.
    $root = Get-RemoteListing ""
    Write-Host "Accesso FTP riuscito come $FtpUser." -ForegroundColor Green

    if ($RemoteRoot -ne "" -and -not ($root -contains $RemoteRoot)) {
        # L'account FTP del dominio (su molti hosting condivisi) atterra gia' DENTRO public_html:
        # la cartella non esiste come sottocartella, quindi si carica direttamente nella radice.
        Write-Host "La radice FTP non contiene '$RemoteRoot': l'account e' gia' posizionato dentro la cartella del sito. Carico nella radice." -ForegroundColor Yellow
        $RemoteRoot = ""
    }
    $hint = if ($root -contains "index.php") { "trovato index.php: e' la cartella giusta" } else { "ATTENZIONE: nessun index.php qui, controlla di essere nella cartella del sito" }
    Write-Host "Contenuto remoto: $($root -join ', ') ($hint)" -ForegroundColor DarkGray
} catch [System.Net.WebException] {
    $msg = $_.Exception.Message
    if ($msg -match '530') {
        Write-Host "ACCESSO RIFIUTATO (530): utente o password FTP non corretti." -ForegroundColor Red
        Write-Host "Controlla nel pannello dell'hosting (sezione Account FTP): l'utente deve essere esattamente '$FtpUser'." -ForegroundColor Yellow
        Write-Host "Se non ricordi la password, da quella pagina puoi impostarne una nuova e riprovare." -ForegroundColor Yellow
    } else {
        Write-Host "Connessione FTP fallita: $msg" -ForegroundColor Red
    }
    exit 1
}

# Crea prima tutte le sottocartelle necessarie (ordinate per profondita')
$dirs = Get-ChildItem -Path $LocalRoot -Recurse -Directory |
    Where-Object { -not (Test-Excluded $_.FullName) } |
    Sort-Object { (Get-RemoteRelativePath $_.FullName).Split('/').Count }

foreach ($d in $dirs) {
    $rel = Get-RemoteRelativePath $d.FullName
    Write-Host "Creo cartella remota: $rel" -ForegroundColor DarkGray
    New-RemoteDirectory $rel
}

$files = Get-ChildItem -Path $LocalRoot -Recurse -File |
    Where-Object { -not (Test-Excluded $_.FullName) }

$total = $files.Count
$i = 0
foreach ($f in $files) {
    $i++
    $rel = Get-RemoteRelativePath $f.FullName
    Write-Host "[$i/$total] Carico $rel" -ForegroundColor Green
    Send-RemoteFile $f.FullName $rel
}

$dest = $RemoteRoot
if ($dest -eq "") { $dest = "radice del sito (public_html)" }
Write-Host "`nCompletato: $total file caricati su $FtpHost (cartella remota: $dest)." -ForegroundColor Cyan
Write-Host "Nota: .env e la cartella storage/ NON vengono toccati (restano quelli del server). Se e' il primo deploy carica .env a mano e importa sql/schema.sql + sql/seed.sql." -ForegroundColor Yellow
