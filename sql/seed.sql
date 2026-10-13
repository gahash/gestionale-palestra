-- =====================================================================
-- Dati dimostrativi iniziali (fittizi: indirizzo, telefono ed email sono segnaposto)
-- Eseguire DOPO schema.sql
-- =====================================================================

INSERT INTO tenants
  (slug, nome, colore_primario, colore_secondario, indirizzo, cap, citta, telefono, whatsapp_numero, email, orari_apertura, claim, descrizione, attivo)
VALUES
  ('gym-studio', 'Gym Studio', '#f5c400', '#111111',
   'Via Roma 1', '00100', 'Citta Demo', '+39 000 0000000', '390000000000', 'info@example.com',
   'Lun-Ven 08:00-21:00, Sab 09:00-13:00',
   'Allenati in studio con un personal trainer!',
   'Massimo 4 persone per sessione. Obiettivi garantiti a prezzi accessibili. Trasforma il tuo stile di vita con il giusto supporto!',
   1);

SET @tenant_id = LAST_INSERT_ID();

-- Utente amministratore di default.
-- Email: admin@example.com | Password demo: ChangeMe!2026 (DA CAMBIARE al primo accesso)
INSERT INTO users (tenant_id, nome, cognome, email, password_hash, ruolo, attivo)
VALUES (@tenant_id, 'Admin', 'Gym Studio', 'admin@example.com',
        '$2y$10$TSV.FWerMqR.DNj2pEuaf.MiT.7qigCrSAsaTMQkK777sVh8fNsny',
        'admin', 1);

-- Piani di esempio
INSERT INTO tipi_abbonamento (tenant_id, nome, descrizione, prezzo, frequenza_settimanale, durata_giorni, tipo, in_evidenza, ordine, attivo)
VALUES
  (@tenant_id, '2 volte a settimana', 'Sessione fitness in studio con personal trainer, iscrizione gratuita', 40.00, 2, 30, 'abbonamento', 0, 1, 1),
  (@tenant_id, '3 volte a settimana', 'Sessione fitness in studio con personal trainer, iscrizione gratuita', 60.00, 3, 30, 'abbonamento', 1, 2, 1),
  (@tenant_id, 'EMS / Vacumstep a seduta', 'Allenamento con macchinari EMS e Vacumstep, a partire da questa quota', 10.00, NULL, 1, 'pacchetto_seduta', 0, 3, 1);

-- Template WhatsApp di base (invio disattivato finché non si configurano le API in .env)
INSERT INTO whatsapp_templates (tenant_id, chiave, nome, testo, attivo) VALUES
  (@tenant_id, 'benvenuto', 'Messaggio di benvenuto', 'Ciao {{nome}}! Benvenuto/a in Gym Studio. Siamo felici di averti con noi.', 1),
  (@tenant_id, 'scadenza_abbonamento', 'Promemoria scadenza abbonamento', 'Ciao {{nome}}, il tuo abbonamento scade il {{data_scadenza}}. Passa in studio per rinnovarlo!', 1),
  (@tenant_id, 'conferma_rinnovo', 'Conferma rinnovo/pagamento', 'Ciao {{nome}}, abbiamo registrato il tuo pagamento di {{importo}}€. Grazie e buon allenamento!', 1),
  (@tenant_id, 'promemoria_appuntamento', 'Promemoria appuntamento', 'Ciao {{nome}}, ti ricordiamo la sessione di domani {{data}} alle {{ora}} da Gym Studio.', 1);
