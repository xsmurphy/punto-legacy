-- =============================================================
-- SEED PostgreSQL: Plan de desarrollo local (ilimitado)
-- plan_code=1 → referenciado por company.plan=1 en el seed de empresa demo
-- =============================================================

INSERT INTO plans (
    name, type, price, duration_days,
    max_items, max_users, max_customers, max_outlets, max_registers,
    max_suppliers, max_categories, max_brands,
    features, plan_code
) VALUES (
    'Local Dev Plan', 'premium', 0.00, 99999,
    99999, 99999, 99999, 99, 99,
    99, 99, 99,
    '{"loyalty":true,"tables":true,"calendar":true,"ordersPanel":true,"electronicInvoice":true,"customRoles":true,"schedule":true,"inventory":true,"delivery":true,"production":true,"drawerControl":true,"activityLog":true,"storeCredit":true}',
    1
) ON CONFLICT DO NOTHING;
