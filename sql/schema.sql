-- =====================================================================
-- Gym Studio - Gestionale Palestra Enterprise + Landing Page
-- Schema database (multi-tenant ready: colonna tenant_id su ogni tabella)
-- Motore: InnoDB, charset utf8mb4 (emoji/accenti sicuri)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- TENANTS: una riga per ogni palestra/cliente white-label installata
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL UNIQUE,
  nome VARCHAR(150) NOT NULL,
  logo_path VARCHAR(255) NULL,
  colore_primario VARCHAR(7) NOT NULL DEFAULT '#f5c400',
  colore_secondario VARCHAR(7) NOT NULL DEFAULT '#111111',
  indirizzo VARCHAR(255) NULL,
  cap VARCHAR(10) NULL,
  citta VARCHAR(100) NULL,
  telefono VARCHAR(30) NULL,
  whatsapp_numero VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  orari_apertura TEXT NULL,
  claim VARCHAR(255) NULL,
  descrizione TEXT NULL,
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- USERS: staff del gestionale (admin / staff reception / personal trainer)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  nome VARCHAR(100) NOT NULL,
  cognome VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  ruolo ENUM('admin','staff','trainer') NOT NULL DEFAULT 'staff',
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_accesso DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_tenant_email (tenant_id, email),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- CLIENTI: scheda cliente completa
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clienti (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  nome VARCHAR(100) NOT NULL,
  cognome VARCHAR(100) NOT NULL,
  data_nascita DATE NULL,
  codice_fiscale VARCHAR(16) NULL,
  telefono VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  indirizzo VARCHAR(255) NULL,
  foto_path VARCHAR(255) NULL,
  certificato_medico_path VARCHAR(255) NULL,
  certificato_rilascio DATE NULL,
  certificato_scadenza DATE NULL,
  note TEXT NULL,
  gdpr_consenso TINYINT(1) NOT NULL DEFAULT 0,
  gdpr_consenso_data DATETIME NULL,
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  creato_da INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_clienti_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_clienti_creato_da FOREIGN KEY (creato_da) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_clienti_tenant (tenant_id),
  INDEX idx_clienti_certificato_scadenza (certificato_scadenza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- TIPI ABBONAMENTO: piani configurabili (letti anche dalla landing page)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipi_abbonamento (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  nome VARCHAR(150) NOT NULL,
  descrizione VARCHAR(255) NULL,
  prezzo DECIMAL(10,2) NOT NULL,
  frequenza_settimanale TINYINT UNSIGNED NULL,
  durata_giorni INT UNSIGNED NOT NULL DEFAULT 30,
  tipo ENUM('abbonamento','pacchetto_seduta') NOT NULL DEFAULT 'abbonamento',
  in_evidenza TINYINT(1) NOT NULL DEFAULT 0,
  ordine INT UNSIGNED NOT NULL DEFAULT 0,
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tipiabb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- ABBONAMENTI: storicizzati, con collegamento al precedente al rinnovo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abbonamenti (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  tipo_abbonamento_id INT UNSIGNED NOT NULL,
  data_inizio DATE NOT NULL,
  data_scadenza DATE NOT NULL,
  prezzo_pagato DECIMAL(10,2) NOT NULL,
  stato ENUM('attivo','scaduto','rinnovato','cancellato') NOT NULL DEFAULT 'attivo',
  abbonamento_precedente_id INT UNSIGNED NULL,
  creato_da INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_abb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_abb_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
  CONSTRAINT fk_abb_tipo FOREIGN KEY (tipo_abbonamento_id) REFERENCES tipi_abbonamento(id),
  CONSTRAINT fk_abb_precedente FOREIGN KEY (abbonamento_precedente_id) REFERENCES abbonamenti(id) ON DELETE SET NULL,
  INDEX idx_abb_scadenza (tenant_id, data_scadenza, stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- PAGAMENTI / ACCONTI
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagamenti (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  abbonamento_id INT UNSIGNED NULL,
  importo DECIMAL(10,2) NOT NULL,
  metodo ENUM('contanti','carta','bonifico','altro') NOT NULL DEFAULT 'contanti',
  causale VARCHAR(255) NULL,
  acconto TINYINT(1) NOT NULL DEFAULT 0,
  saldo_residuo DECIMAL(10,2) NOT NULL DEFAULT 0,
  operatore_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pag_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pag_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
  CONSTRAINT fk_pag_abbonamento FOREIGN KEY (abbonamento_id) REFERENCES abbonamenti(id) ON DELETE SET NULL,
  CONSTRAINT fk_pag_operatore FOREIGN KEY (operatore_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_pag_data (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- RICORRENZE: pattern di prenotazione fissa (es. Lun-Mer-Ven 18:00)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ricorrenze (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  giorni_settimana VARCHAR(20) NOT NULL COMMENT 'CSV 1-7 (1=Lunedi)',
  ora_inizio TIME NOT NULL,
  ora_fine TIME NOT NULL,
  data_inizio DATE NOT NULL,
  data_fine DATE NULL,
  attiva TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ric_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ric_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- APPUNTAMENTI: calendario sessioni, capienza massima, trainer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appuntamenti (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  trainer_id INT UNSIGNED NULL,
  data DATE NOT NULL,
  ora_inizio TIME NOT NULL,
  ora_fine TIME NOT NULL,
  capienza_max TINYINT UNSIGNED NOT NULL DEFAULT 4,
  stato ENUM('prenotato','confermato','presente','assente','cancellato') NOT NULL DEFAULT 'prenotato',
  ricorrenza_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_app_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
  CONSTRAINT fk_app_trainer FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_app_ricorrenza FOREIGN KEY (ricorrenza_id) REFERENCES ricorrenze(id) ON DELETE SET NULL,
  INDEX idx_app_data (tenant_id, data, ora_inizio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- PRESENZE: storico check-in (anche walk-in senza appuntamento)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS presenze (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  appuntamento_id INT UNSIGNED NULL,
  check_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  CONSTRAINT fk_pres_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pres_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
  CONSTRAINT fk_pres_app FOREIGN KEY (appuntamento_id) REFERENCES appuntamenti(id) ON DELETE SET NULL,
  INDEX idx_pres_cliente (cliente_id, check_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- WHATSAPP: template configurabili + log invii
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  chiave VARCHAR(60) NOT NULL COMMENT 'es. scadenza_abbonamento, benvenuto, promemoria_appuntamento',
  nome VARCHAR(150) NOT NULL,
  testo TEXT NOT NULL COMMENT 'placeholder tipo {{nome}}, {{data}}, {{importo}}',
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_wa_tpl (tenant_id, chiave),
  CONSTRAINT fk_watpl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NULL,
  template_id INT UNSIGNED NULL,
  telefono VARCHAR(30) NOT NULL,
  messaggio TEXT NOT NULL,
  stato ENUM('in_coda','inviato','errore','disabilitato') NOT NULL DEFAULT 'in_coda',
  provider_response TEXT NULL,
  inviato_da INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_walog_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_walog_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
  CONSTRAINT fk_walog_template FOREIGN KEY (template_id) REFERENCES whatsapp_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- ENTRATE / USCITE manuali (costi fissi/variabili, incassi extra)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entrate_uscite (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrata','uscita') NOT NULL,
  categoria VARCHAR(100) NOT NULL,
  importo DECIMAL(10,2) NOT NULL,
  descrizione VARCHAR(255) NULL,
  data DATE NOT NULL,
  operatore_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_eu_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_eu_operatore FOREIGN KEY (operatore_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_eu_data (tenant_id, data, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- AUDIT LOG: tracciamento modifiche critiche
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  utente_id INT UNSIGNED NULL,
  azione VARCHAR(50) NOT NULL COMMENT 'create/update/delete/login/...',
  entita VARCHAR(50) NOT NULL COMMENT 'cliente/abbonamento/pagamento/...',
  entita_id INT UNSIGNED NULL,
  dati_precedenti JSON NULL,
  dati_nuovi JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_utente FOREIGN KEY (utente_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entita (tenant_id, entita, entita_id),
  INDEX idx_audit_data (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- LEADS: richieste dal form della landing page pubblica
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  nome VARCHAR(150) NOT NULL,
  telefono VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  servizio_interesse VARCHAR(150) NULL,
  giorno_preferito VARCHAR(50) NULL,
  fascia_oraria VARCHAR(50) NULL,
  messaggio TEXT NULL,
  gdpr_consenso TINYINT(1) NOT NULL DEFAULT 0,
  stato ENUM('nuovo','contattato','convertito','archiviato') NOT NULL DEFAULT 'nuovo',
  ip_richiesta VARCHAR(45) NULL,
  cliente_id INT UNSIGNED NULL COMMENT 'valorizzato se convertito in cliente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_leads_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_leads_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
  INDEX idx_leads_stato (tenant_id, stato, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
