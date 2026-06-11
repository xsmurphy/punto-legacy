-- 17_item_image.sql
-- Galería de imágenes por item (máx 5 enforce en backend).
--
-- Cada item puede tener 0..5 imágenes en S3-compatible storage (DO Spaces).
-- La imagen con `sort=0` es la portada — se usa en el listado y la caja.
--
-- objectKey: ruta dentro del bucket, ej. "items/<companyId>/<itemId>/<imageId>.jpg".
-- url:       URL pública absoluta (path-style). Cacheada para evitar recomponer.
-- width/height: dimensiones del archivo final (post-resize).
-- sizeBytes:    peso del archivo (para reportar al admin / cuota).
-- mime:         tipo final (siempre image/jpeg tras el resize, pero queda flex).

CREATE TABLE IF NOT EXISTS item_image (
  imageId    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  itemId     UUID         NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  companyId  UUID         NOT NULL REFERENCES company(companyId),
  objectKey  TEXT         NOT NULL,
  url        TEXT         NOT NULL,
  width      INT,
  height     INT,
  sizeBytes  INT,
  mime       VARCHAR(64)  NOT NULL DEFAULT 'image/jpeg',
  sort       SMALLINT     NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_item_image_item    ON item_image(itemId);
CREATE INDEX IF NOT EXISTS idx_item_image_company ON item_image(companyId);
