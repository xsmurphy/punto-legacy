-- Rename camelCase quoted → lowercase para alinear con §44 (contact es tabla legacy).
-- AutoExecute genera SQL sin quotes → PG case-normaliza → buscaba lockpasshash y rompía con 500.
ALTER TABLE contact RENAME COLUMN "lockPassHash" TO lockpasshash;
