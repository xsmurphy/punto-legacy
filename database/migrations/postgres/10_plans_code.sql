-- Añade plan_code (smallint) a plans para que company.plan (smallint) pueda
-- hacer lookup sin depender del UUID primary key.
-- Migración segura: DEFAULT 0, se actualiza vía seed / admin.

ALTER TABLE plans ADD COLUMN IF NOT EXISTS plan_code smallint NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX IF NOT EXISTS plans_plan_code_key ON plans (plan_code)
    WHERE plan_code != 0;
