-- Reemplazo del PIN hash bcrypt por SHA-256 (más simple, browser-side via Web Crypto API).
-- contact es tabla legacy (§44) → columna lowercase sin quotes.
ALTER TABLE contact ADD COLUMN IF NOT EXISTS pinhash VARCHAR(64);
CREATE INDEX IF NOT EXISTS idx_contact_pinhash ON contact (companyid, pinhash) WHERE pinhash IS NOT NULL;
