-- Migration 13: seed plan_code=0 (free post-trial) y plan_code=3 (trial 2 semanas)
--
-- Contexto: el código de signUp() asigna company.plan=3 al registrarse (trial),
-- y el cron cronSetAllTrialPlansToFree lo baja a plan=0 (free) después de 2 semanas.
-- Pero solo plan_code=1 (premium dev) estaba seedeado. Resultado: fetchs?load=users
-- hacía LIMIT 0 → users=[] → app POS crasheaba con 'ncmGlobals.users[0]' undefined.
--
-- Idempotente: ON CONFLICT DO NOTHING.

INSERT INTO plans (
    name, type, price, duration_days,
    max_items, max_users, max_customers, max_outlets, max_registers,
    max_suppliers, max_categories, max_brands,
    features, plan_code
) VALUES (
    'Free', 'free', 0.00, 99999,
    50, 1, 50, 1, 1,
    5, 5, 5,
    '{"loyalty":false,"tables":false,"calendar":false,"ordersPanel":false,"electronicInvoice":false,"customRoles":false,"schedule":false,"inventory":false,"delivery":false,"production":false,"drawerControl":false,"activityLog":false,"storeCredit":false}',
    0
) ON CONFLICT DO NOTHING;

INSERT INTO plans (
    name, type, price, duration_days,
    max_items, max_users, max_customers, max_outlets, max_registers,
    max_suppliers, max_categories, max_brands,
    features, plan_code
) VALUES (
    'Trial', 'trial', 0.00, 14,
    99999, 99999, 99999, 99, 99,
    99, 99, 99,
    '{"loyalty":true,"tables":true,"calendar":true,"ordersPanel":true,"electronicInvoice":true,"customRoles":true,"schedule":true,"inventory":true,"delivery":true,"production":true,"drawerControl":true,"activityLog":true,"storeCredit":true}',
    3
) ON CONFLICT DO NOTHING;
