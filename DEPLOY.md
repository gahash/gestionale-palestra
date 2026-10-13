# Guida al deploy — hosting condiviso (PHP + MySQL)

Il progetto è pensato per non dipendere da un dominio fisso: puoi installarlo nella root del dominio o in una sottocartella, senza cambiare una riga di codice. Questi passaggi si eseguono dal pannello dell'hosting, da un client FTP e da phpMyAdmin. Le password non vanno mai scritte nei file del repository.

## 1. Importa il database

**Opzione A — script PowerShell incluso (`db_import.ps1`):**
Imposta le variabili d'ambiente (oppure passa i parametri `-DbHost`, `-DbName`, `-DbUser`) e lancia lo script dalla root del progetto:
```powershell
$env:DB_HOST = "db.example.com"
$env:DB_NAME = "nome_database"
$env:DB_USER = "utente_database"
./db_import.ps1
```
Lo script chiede la password MySQL in modo mascherato (non salvata su disco) e importa `sql/schema.sql` + `sql/seed.sql`. Richiede il client `mysql` (es. quello incluso in XAMPP; percorso personalizzabile con `MYSQL_EXE` o `-MysqlExe`).

**Opzione B — phpMyAdmin manuale:**
1. Accedi a phpMyAdmin dal pannello del tuo hosting con le credenziali del database.
2. Seleziona il database e apri la scheda **Importa**.
3. Importa **prima** `sql/schema.sql` (crea tutte le tabelle).
4. Importa **poi** `sql/seed.sql` (crea la palestra dimostrativa, l'utente admin e 3 piani di esempio).
5. Verifica in **Struttura** che siano comparse 14 tabelle (tenants, users, clienti, abbonamenti, ecc.).

Gli script SQL non contengono il nome del database: funzionano dentro qualsiasi database, basta importarli in quello giusto.

## 2. Configura il file `.env`

1. Copia `.env.example` in un nuovo file chiamato **`.env`** nella root del progetto.
2. Compila almeno i dati del database:
   ```
   DB_HOST=db.example.com
   DB_PORT=3306
   DB_NAME=nome_database
   DB_USER=utente_database
   DB_PASS=la-tua-password
   ```
3. Lascia `APP_URL` **vuoto**: il sito calcola da solo il proprio percorso, così funziona sia in `example.com` sia in `example.com/palestra`.
4. Cambia `APP_SECRET` con una stringa lunga e casuale.
5. Metti `APP_DEBUG=false` in produzione.
6. Per le email di notifica nuovo lead imposta `MAIL_ENABLED=true` e i dati SMTP (oppure lascia `false`: le richieste restano comunque visibili nel gestionale in "Richieste sito").

## 3. Carica i file via FTP

**Opzione A — client grafico (consigliata):**
1. Apri FileZilla/WinSCP (o il File Manager del pannello hosting) e collegati con host, utente e password FTP forniti dal tuo hosting.
2. Vai in `public_html` (o in una sottocartella se vuoi installarlo lì).
3. Carica **tutto il contenuto** della cartella del progetto (non la cartella stessa) dentro `public_html`, **incluso** il file `.env` compilato al passo 2.
4. **Non caricare**: `router.php` (serve solo per i test in locale), `.git/`, gli script `.ps1`.
5. Verifica che `storage/uploads/` e `storage/backups/` siano scrivibili (di norma 755; se i caricamenti falliscono prova 775).

**Opzione B — script PowerShell incluso (`upload_ftp.ps1`):**
```powershell
$env:FTP_HOST = "ftp.example.com"
$env:FTP_USER = "utente-ftp"
./upload_ftp.ps1
```
La password FTP viene chiesta in modo mascherato. Lo script **non tocca** `.env` e la cartella `storage/` sul server: al primo deploy carica `.env` a mano, nei deploy successivi basta rilanciarlo. Se l'account FTP atterra già dentro la cartella del sito, lo script se ne accorge e carica nella radice.

## 4. Verifica online

1. Apri il tuo dominio: dovresti vedere la landing page.
2. Apri `/admin/login.php` ed entra con le credenziali demo indicate in `README.md` (cambiale subito da "Utenti staff").
3. Invia il modulo di contatto dalla landing e verifica che compaia in **Richieste sito** nel gestionale.

## 5. Pianifica i Cron Job (automatismi)

Dal pannello dell'hosting (sezione Cron Job) crea:

| Comando | Frequenza consigliata |
|---|---|
| `php /home/<utente>/public_html/cron/aggiorna_scaduti.php` | ogni giorno alle 03:00 |
| `php /home/<utente>/public_html/cron/backup_database.php` | ogni giorno alle 03:30 |

(Adatta il percorso a dove hai effettivamente caricato i file.)

## 6. (Opzionale) Attivare l'invio WhatsApp reale

Il gestionale registra già i messaggi nel log, ma non li invia finché non colleghi un provider:

- **Meta Cloud API** (WhatsApp Business ufficiale): crea un'app su [developers.facebook.com](https://developers.facebook.com), attiva il prodotto WhatsApp e copia `WHATSAPP_META_TOKEN` e `WHATSAPP_META_PHONE_ID` nel `.env`.
- **Twilio**: crea un WhatsApp Sender su Twilio e copia SID/Token/numero nel `.env`.
- Imposta `WHATSAPP_ENABLED=true` quando pronto.

## 7. Spostare il progetto su un altro dominio

Non serve modificare il codice: basta ripetere i passi 1–3 sulla nuova installazione (nuovo database + nuovo `.env`). Il branding (nome, logo, colori, indirizzo) si cambia dalla tabella `tenants`, non dal codice.
