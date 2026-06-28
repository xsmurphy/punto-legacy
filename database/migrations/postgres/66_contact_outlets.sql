BEGIN;

CREATE TABLE IF NOT EXISTS contact_outlet (
  contactid UUID NOT NULL REFERENCES contact(contactid) ON DELETE CASCADE,
  outletid  UUID NOT NULL REFERENCES outlet(outletid)   ON DELETE CASCADE,
  companyid UUID NOT NULL REFERENCES company(companyid) ON DELETE CASCADE,
  PRIMARY KEY (contactid, outletid)
);

CREATE INDEX IF NOT EXISTS idx_contact_outlet_outlet  ON contact_outlet(outletid);
CREATE INDEX IF NOT EXISTS idx_contact_outlet_company ON contact_outlet(companyid);

-- Backfill: usuarios (type=0) con outletid asignado -> fila en contact_outlet.
-- contact.outletid se mantiene sin drop (back-compat con queries legacy).
INSERT INTO contact_outlet (contactid, outletid, companyid)
  SELECT c.contactid, c.outletid, c.companyid
    FROM contact c
   WHERE c.type = 0
     AND c.outletid IS NOT NULL
     AND EXISTS (
           SELECT 1 FROM outlet o
            WHERE o.outletid = c.outletid
              AND o.companyid = c.companyid
         )
ON CONFLICT DO NOTHING;

COMMIT;
