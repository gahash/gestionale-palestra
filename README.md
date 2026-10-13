# Gym Studio — Gestionale palestra + landing page

Applicazione web per la gestione di una palestra / studio di personal training: una **landing page pubblica** con modulo di prenotazione e un **gestionale riservato** per clienti, abbonamenti, appuntamenti, pagamenti e finanze.

Scritta in **PHP 8 puro (senza framework) + MySQL/MariaDB**, senza dipendenze da installare (nessun `composer install` o `npm install`): gira su un comune hosting condiviso PHP/MySQL oppure in Docker.

## Funzionalità

**Sito pubblico**
- Landing page con piani e prezzi, form di contatto/prenotazione, mappa, contatti cliccabili (telefono, WhatsApp, email).
- Informativa privacy (GDPR), `sitemap.xml` dinamica, `robots.txt`.

**Gestionale (`/admin`)**
- **Clienti**: anagrafica, scheda cliente, upload certificati medici (serviti solo tramite controllo di accesso).
- **Abbonamenti**: piani configurabili, scadenzario, rinnovi con registrazione contestuale del pagamento (intero o acconto).
- **Appuntamenti**: calendario con vista settimanale e giornaliera, sessioni con limite di capienza, prenotazione multipla (fino a 5 clienti) con autocompletamento.
- **Finanze**: KPI incassi del mese, ricorrenti attivi, storico 12 mesi con grafici, crediti da incassare, registro movimenti unificato ed export CSV.
- **Richieste dal sito** (lead) con stato di lavorazione.
- **WhatsApp**: template di messaggio e log invii; invio reale tramite Meta Cloud API o Twilio (disattivato di default).
- **Audit log** delle operazioni e **gestione utenti staff** con ruoli.
- Layout responsive con menu laterale a scomparsa su mobile.

**Piattaforma**
- **API REST** (`/api`) per il form pubblico e per le azioni AJAX del gestionale.
- **Multi-tenant ready**: ogni tabella ha `tenant_id`; il tenant attivo si sceglie con `TENANT_SLUG`.
- **White-label**: nome, colori, indirizzo e contatti arrivano dalla tabella `tenants` e dal file `.env`, non dal codice.
- **Cron job** per l'aggiornamento automatico delle scadenze e il backup del database.

## Stack tecnologico

| Livello | Tecnologia |
|---|---|
| Backend | PHP 8.x (PDO, nessun framework) |
| Database | MySQL 5.7+ / MariaDB 10.x |
| Frontend | HTML, CSS, JavaScript vanilla, Chart.js (CDN) per i grafici |
| Web server | Apache con `mod_rewrite` (`.htaccess`) oppure server integrato PHP per sviluppo |
| Container | Docker / Docker Compose (PHP 8.2 + Apache, MariaDB, phpMyAdmin) |

## Screenshot

_Sezione da completare con alcune schermate della landing page e del gestionale._

## Requisiti

- PHP **8.0+** con estensioni `pdo_mysql`, `mbstring`, `curl` (quest'ultima solo per WhatsApp)
- MySQL 5.7+ o MariaDB 10.x
- Apache con `mod_rewrite` (in produzione)
- In alternativa: Docker e Docker Compose

## Installazione

### Con Docker

```bash
cp .env.example .env
# Nel file .env impostare almeno: DB_HOST=db, DB_NAME, DB_USER, DB_PASS, DB_ROOT_PASSWORD, APP_SECRET
docker compose up -d
```

- Applicazione: <http://localhost:8080>
- phpMyAdmin: <http://localhost:8081>

Al primo avvio il container MariaDB importa automaticamente `sql/schema.sql` e `sql/seed.sql` (cartella montata in `docker-entrypoint-initdb.d`).

### Senza Docker (sviluppo locale)

```bash
cp .env.example .env            # poi compilare i dati del database
mysql -u root -p -e "CREATE DATABASE gym_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p gym_studio < sql/schema.sql
mysql -u root -p gym_studio < sql/seed.sql
php -S localhost:8000 router.php
```

Aprire <http://localhost:8000> (sito) e <http://localhost:8000/admin/login.php> (gestionale).

`router.php` serve **solo** al server integrato di PHP; in produzione le stesse regole sono in `.htaccess`.

### Hosting condiviso

Per il deploy su un hosting PHP/MySQL tradizionale (import database, upload FTP, cron job) vedere **[DEPLOY.md](DEPLOY.md)**.

### Accesso demo

Il file `sql/seed.sql` crea un tenant dimostrativo con dati fittizi e un utente amministratore:

- URL: `/admin/login.php`
- Email: `admin@example.com`
- Password: `ChangeMe!2026`

**Cambiare la password al primo accesso** (sezione "Utenti staff").

## Configurazione (`.env`)

Tutta la configurazione avviene tramite il file `.env` nella root del progetto (letto da `src/Config.php`; in assenza di un valore viene usata la variabile d'ambiente omonima). Il file `.env.example` elenca tutte le variabili con valori segnaposto.

| Gruppo | Variabili |
|---|---|
| Applicazione | `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_DEBUG`, `APP_TIMEZONE`, `APP_SECRET` |
| Tenant | `TENANT_SLUG` |
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, `DB_ROOT_PASSWORD` (solo Docker) |
| Email | `MAIL_ENABLED`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME`, `MAIL_TO_STAFF` |
| WhatsApp | `WHATSAPP_ENABLED`, `WHATSAPP_PROVIDER` (`meta` / `twilio`), `WHATSAPP_META_TOKEN`, `WHATSAPP_META_PHONE_ID`, `WHATSAPP_TWILIO_SID`, `WHATSAPP_TWILIO_TOKEN`, `WHATSAPP_TWILIO_FROM` |
| Sessione | `SESSION_LIFETIME_MIN` |
| Script di deploy | `FTP_HOST`, `FTP_USER`, `FTP_PORT`, `FTP_REMOTE_ROOT`, `MYSQL_EXE` (usati solo dagli script `.ps1`) |

`APP_URL` può restare vuoto: il percorso base viene calcolato automaticamente, quindi l'applicazione funziona sia nella root del dominio sia in una sottocartella.

## Struttura del progetto

```
gym-studio/
├── index.php, privacy.php, sitemap.php, robots.txt   landing page pubblica
├── css/, js/, assets/                                 risorse statiche
├── admin/                                             gestionale (richiede login)
├── api/index.php                                      API REST
├── src/                                               logica applicativa (accesso web negato)
│   ├── Config.php, Database.php, Auth.php, Csrf.php, Tenant.php, AuditLogger.php
│   ├── Models/                                        Cliente, Abbonamento, Appuntamento, Pagamento, ...
│   └── Services/WhatsappService.php
├── sql/
│   ├── schema.sql                                     struttura del database (14 tabelle)
│   ├── seed.sql                                       dati dimostrativi fittizi
│   └── maintenance/                                   script SQL di manutenzione (da eseguire a mano)
├── cron/                                              job pianificabili (scadenze, backup)
├── storage/uploads/, storage/backups/                 file caricati e backup (accesso web negato)
├── tests/                                             script di test eseguibili da CLI
├── .env.example                                       modello di configurazione
├── Dockerfile, docker-compose.yml                     ambiente Docker
├── upload_ftp.ps1, db_import.ps1                      script di deploy opzionali (PowerShell)
└── router.php                                         router per `php -S` (solo sviluppo)
```

## Test

Gli script in `tests/` si eseguono da riga di comando su un database di sviluppo (configurato in `.env`):

```bash
php tests/test_prenotazione_multipla.php
php tests/test_fix_verifica.php
php tests/test_form_post.php
php tests/test_page_render.php
```

Alcuni test inseriscono e rimuovono dati: **non eseguirli sul database di produzione**.

## Note di sicurezza

- Password degli utenti salvate con `password_hash()` (bcrypt); sessione admin con cookie `HttpOnly`, `SameSite` e `Secure` su HTTPS, durata configurabile.
- Protezione **CSRF** su tutti i form del gestionale e query parametrizzate (PDO prepared statements).
- Rate limiting sul form pubblico dell'API.
- `.htaccess` nega l'accesso diretto a `.env`, `src/`, `sql/` e `storage/`; i file caricati sono serviti solo tramite `admin/file.php` dopo il login.
- **Audit log** delle operazioni sensibili.
- Il file `.env` non deve mai essere versionato (è escluso da `.gitignore`). In produzione impostare `APP_DEBUG=false` e un `APP_SECRET` lungo e casuale.
- Non pubblicare sul server di produzione `router.php`, la cartella `tests/` e gli script `.ps1`.
- L'immagine di sfondo `assets/foto-hero.jpg` non è inclusa nel repository (immagine stock con licenza): aggiungerne una propria con lo stesso nome.

## Licenza

Tutti i diritti riservati. Il codice è pubblicato a solo scopo di consultazione/portfolio; non ne è consentito l'uso, la copia, la modifica o la distribuzione senza autorizzazione scritta dell'autore.
