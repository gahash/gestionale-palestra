-- =====================================================================
-- Gym Studio - Script di Pulizia Dati Sporchi e Aggiornamento Vincoli
-- NOTA: Questo script NON viene eseguito automaticamente.
--       Eseguilo da phpMyAdmin o dal client MySQL solo dopo aver verificato.
-- =====================================================================

-- 1. Anteprima: verifica quanti pagamenti orfani o legati ad abbonamenti cancellati esistono
SELECT id, cliente_id, importo, causale, created_at
FROM pagamenti
WHERE abbonamento_id IS NULL
  AND (causale LIKE 'Abbonamento %' OR causale LIKE 'Rinnovo %');

SELECT p.id, p.cliente_id, p.importo, p.causale, a.stato AS stato_abbonamento
FROM pagamenti p
JOIN abbonamenti a ON a.id = p.abbonamento_id
WHERE a.stato = 'cancellato';

-- 2. Pulizia: rimuove i pagamenti collegati ad abbonamenti cancellati
DELETE p FROM pagamenti p
JOIN abbonamenti a ON a.id = p.abbonamento_id
WHERE a.stato = 'cancellato';

-- 3. Pulizia: rimuove i pagamenti orfani di vecchi abbonamenti di test eliminati
DELETE FROM pagamenti
WHERE abbonamento_id IS NULL
  AND (causale LIKE 'Abbonamento %' OR causale LIKE 'Rinnovo %');

-- 4. Pulizia: rimuove gli abbonamenti rimasti nello stato 'cancellato'
DELETE FROM abbonamenti
WHERE stato = 'cancellato';

-- 5. Migrazione strutturale (opzionale ma consigliata):
-- Imposta ON DELETE CASCADE sulla foreign key di abbonamento_id nei pagamenti,
-- così anche eventuali cancellazioni future dirette sul DB non lasceranno mai pagamenti orfani.
-- ALTER TABLE pagamenti DROP FOREIGN KEY fk_pag_abbonamento;
-- ALTER TABLE pagamenti ADD CONSTRAINT fk_pag_abbonamento
--   FOREIGN KEY (abbonamento_id) REFERENCES abbonamenti(id) ON DELETE CASCADE;
