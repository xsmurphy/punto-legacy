-- 67_strip_phone_plus_prefix.sql
-- Limpia el prefijo '+' de teléfonos guardados antes de la convención canónica
-- (contactphone / outlet JSONB outletPhone+outletWhatsApp deben ir SIN '+').
-- Idempotente: ltrim(value, '+') sobre valor sin '+' no modifica nada.
-- contactphone2 fue eliminado en la mig 25 — no aplica.

BEGIN;

DO $$
DECLARE
  v_contact_rows     INTEGER;
  v_outlet_phone_rows INTEGER;
  v_outlet_wa_rows    INTEGER;
BEGIN

  -- contact.contactphone
  UPDATE contact
     SET contactphone = ltrim(contactphone, '+')
   WHERE contactphone LIKE '+%';
  GET DIAGNOSTICS v_contact_rows = ROW_COUNT;
  RAISE NOTICE '[67] contact.contactphone: % fila(s) actualizada(s)', v_contact_rows;

  -- outlet.data->>'outletPhone'
  UPDATE outlet
     SET data = jsonb_set(data, '{outletPhone}', to_jsonb(ltrim(data->>'outletPhone', '+')))
   WHERE data->>'outletPhone' LIKE '+%';
  GET DIAGNOSTICS v_outlet_phone_rows = ROW_COUNT;
  RAISE NOTICE '[67] outlet.data->outletPhone: % fila(s) actualizada(s)', v_outlet_phone_rows;

  -- outlet.data->>'outletWhatsApp'
  UPDATE outlet
     SET data = jsonb_set(data, '{outletWhatsApp}', to_jsonb(ltrim(data->>'outletWhatsApp', '+')))
   WHERE data->>'outletWhatsApp' LIKE '+%';
  GET DIAGNOSTICS v_outlet_wa_rows = ROW_COUNT;
  RAISE NOTICE '[67] outlet.data->outletWhatsApp: % fila(s) actualizada(s)', v_outlet_wa_rows;

  RAISE NOTICE '[67] Total: contact=%, outlet.phone=%, outlet.wa=%',
    v_contact_rows, v_outlet_phone_rows, v_outlet_wa_rows;

END;
$$;

-- Verificación post-update: debe devolver 0 filas en ambos sets.
DO $$
DECLARE
  v_bad_contacts  INTEGER;
  v_bad_outlets_p INTEGER;
  v_bad_outlets_w INTEGER;
BEGIN
  SELECT COUNT(*) INTO v_bad_contacts
    FROM contact WHERE contactphone LIKE '+%';

  SELECT COUNT(*) INTO v_bad_outlets_p
    FROM outlet   WHERE data->>'outletPhone' LIKE '+%';

  SELECT COUNT(*) INTO v_bad_outlets_w
    FROM outlet   WHERE data->>'outletWhatsApp' LIKE '+%';

  IF v_bad_contacts > 0 OR v_bad_outlets_p > 0 OR v_bad_outlets_w > 0 THEN
    RAISE EXCEPTION '[67] Verificación fallida: contact=%, outlet.phone=%, outlet.wa=%',
      v_bad_contacts, v_bad_outlets_p, v_bad_outlets_w;
  ELSE
    RAISE NOTICE '[67] Verificación OK: ningún teléfono comienza con "+"';
  END IF;
END;
$$;

COMMIT;
