-- Migration: tabla franchiser_to_tenant — relación N-a-N de ACCESO franquiciador→tenant
--
-- Ver context/adr/ADR-001-franchiser-tenant-acceso.md.
--
-- Modela qué tenant "franquiciador" puede gestionar/impersonar a qué tenant hijo.
-- Es PURAMENTE acceso: NO confiere propiedad ni billing (cada company tiene su propio
-- dueño/plan/facturación, intactos). Reemplaza a company.parentId (1→N) que no soportaba
-- que un hijo tenga varios franquiciadores (N→N).
--
-- Autorización de impersonalización del franquiciador:
--   EXISTS(SELECT 1 FROM franchiser_to_tenant WHERE franchiserId = <actor> AND tenantId = <target>)
-- (El SaaS super-admin / encom sigue pudiendo entrar a cualquiera, sin pasar por esta tabla.)
--
-- Backfill: cada company con parentId NOT NULL pasa a una fila (franchiserId=parentId,
-- tenantId=companyId). company.parentId queda deprecado para acceso (la junction es la
-- fuente de verdad); se elimina en una migración futura cuando nada lo lea.
--
-- Idempotente.

CREATE TABLE IF NOT EXISTS franchiser_to_tenant (
    franchiserTenantId UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    franchiserId       UUID        NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
    tenantId           UUID        NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
    relationType       TEXT        NOT NULL DEFAULT 'manager',
    status             SMALLINT    NOT NULL DEFAULT 1,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (franchiserId, tenantId),
    CHECK (franchiserId <> tenantId)
);

CREATE INDEX IF NOT EXISTS idx_f2t_franchiser ON franchiser_to_tenant(franchiserId);
CREATE INDEX IF NOT EXISTS idx_f2t_tenant     ON franchiser_to_tenant(tenantId);

-- Backfill desde el modelo 1→N actual (company.parentId).
-- Guarda contra parentId huérfano (FK) y contra auto-referencia.
INSERT INTO franchiser_to_tenant (franchiserId, tenantId)
SELECT c.parentId, c.companyId
FROM company c
WHERE c.parentId IS NOT NULL
  AND c.parentId <> c.companyId
  AND c.parentId IN (SELECT companyId FROM company)
ON CONFLICT (franchiserId, tenantId) DO NOTHING;
