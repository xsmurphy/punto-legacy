-- 173_master_admin_is_internal.sql
--
-- La empresa master del seed (00000000-…-0001, "Master Admin") es la empresa
-- del propio SaaS, no un cliente, pero se creaba con isInternal = 0. Las
-- analíticas de /admin filtran por ese flag (AdminReportsService::notInternalWhere),
-- así que la venían contando como tenant: comercios activos y MRR inflados.
--
-- El seed ya la marca (seeds/postgres/01_master_admin.sql) — esta migración es
-- para las instalaciones que la crearon antes. Idempotente y acotada al UUID
-- fijo del seed: no toca ninguna empresa real.

UPDATE company
   SET isInternal = 1
 WHERE companyId = '00000000-0000-0000-0000-000000000001'
   AND isInternal <> 1;
