-- Migration: columna lockPassHash para hash bcrypt del PIN del POS.
-- La columna lockPass plana se conserva durante la transición (ver 49_lockpass_hash_backfill.php).
-- Se eliminará en un follow-up tras verificación en prod.
ALTER TABLE contact ADD COLUMN IF NOT EXISTS "lockPassHash" VARCHAR(255);
