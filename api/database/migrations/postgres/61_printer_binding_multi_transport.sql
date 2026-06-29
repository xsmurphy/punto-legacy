ALTER TABLE printer_binding ADD COLUMN IF NOT EXISTS bluetoothdeviceid VARCHAR(64);
ALTER TABLE printer_binding ADD COLUMN IF NOT EXISTS networkhost VARCHAR(128);
ALTER TABLE printer_binding ADD COLUMN IF NOT EXISTS networkport INTEGER;
