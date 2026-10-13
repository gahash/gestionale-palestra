<#
    Import database remoto - Gym Studio
    Da eseguire TU STESSO da PowerShell, dentro la cartella "gym-studio":
        ./db_import.ps1
    La password viene chiesta in modo mascherato. Viene scritta per pochi istanti
    in un file temporaneo locale (necessario al client mysql per non passarla come
    argomento in chiaro nella lista processi) e cancellata subito dopo l'uso.
#>

param(
    # Valori letti dalle variabili d'ambiente (DB_HOST, DB_PORT, DB_NAME, DB_USER, MYSQL_EXE)
    # oppure passati come parametri: nessun host/utente reale e' scritto nello script.
    [string]$DbHost = $env:DB_HOST,
    [int]$DbPort = $(if ($env:DB_PORT) { [int]$env:DB_PORT } else { 3306 }),
    [string]$DbName = $env:DB_NAME,
    [string]$DbUser = $env:DB_USER,
    [string]$MysqlExe = $(if ($env:MYSQL_EXE) { $env:MYSQL_EXE } else { "C:\xampp\mysql\bin\mysql.exe" })
)

if (-not $DbHost -or -not $DbName -or -not $DbUser) {
    Write-Host "Specifica host, nome database e utente: variabili d'ambiente DB_HOST/DB_NAME/DB_USER oppure parametri -DbHost/-DbName/-DbUser." -ForegroundColor Red
    exit 1
}

$ErrorActionPreference = "Stop"

if (-not (Test-Path $MysqlExe)) {
    $found = Get-Command mysql -ErrorAction SilentlyContinue
    if ($found) { $MysqlExe = $found.Source }
    else {
        Write-Host "Client 'mysql' non trovato. Installa MySQL client oppure indica il percorso con -MysqlExe 'C:\percorso\mysql.exe'." -ForegroundColor Red
        exit 1
    }
}

$SecurePassword = Read-Host -Prompt "Password MySQL ($DbUser@$DbHost)" -AsSecureString
$BSTR = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecurePassword)
$PlainPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($BSTR)

$LocalRoot = $PSScriptRoot
$SchemaFile = Join-Path $LocalRoot "sql\schema.sql"
$SeedFile = Join-Path $LocalRoot "sql\seed.sql"

foreach ($f in @($SchemaFile, $SeedFile)) {
    if (-not (Test-Path $f)) {
        Write-Host "File non trovato: $f" -ForegroundColor Red
        exit 1
    }
}

$DefaultsFile = New-TemporaryFile
try {
    @"
[client]
host=$DbHost
port=$DbPort
user=$DbUser
password=$PlainPassword
"@ | Set-Content -Path $DefaultsFile -Encoding ASCII

    Write-Host "Connessione a $DbHost`:$DbPort come $DbUser ..." -ForegroundColor Cyan

    Write-Host "Import schema.sql (creazione tabelle) ..." -ForegroundColor Green
    Get-Content $SchemaFile -Raw | & $MysqlExe "--defaults-extra-file=$DefaultsFile" $DbName
    if ($LASTEXITCODE -ne 0) { throw "Import di schema.sql fallito (exit code $LASTEXITCODE)." }

    Write-Host "Import seed.sql (dati dimostrativi) ..." -ForegroundColor Green
    Get-Content $SeedFile -Raw | & $MysqlExe "--defaults-extra-file=$DefaultsFile" $DbName
    if ($LASTEXITCODE -ne 0) { throw "Import di seed.sql fallito (exit code $LASTEXITCODE)." }

    Write-Host "`nCompletato: database '$DbName' su $DbHost pronto." -ForegroundColor Cyan
    Write-Host "Verifica su phpMyAdmin che siano comparse 14 tabelle." -ForegroundColor Yellow
} finally {
    # Cancella subito il file con la password in chiaro, in ogni caso (successo o errore)
    Remove-Item $DefaultsFile -Force -ErrorAction SilentlyContinue
    $PlainPassword = $null
}
