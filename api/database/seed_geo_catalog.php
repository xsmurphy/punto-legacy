<?php
declare(strict_types=1);

/**
 * Carga del catálogo geográfico fiscal (mig 207) desde el seed de SIFEN.
 *
 * Corre en CADA boot del container, después de migrate (docker-entrypoint), y
 * es IDEMPOTENTE: el upsert de `GeoCatalogSync` deja las mismas filas y los
 * mismos conteos corrida tras corrida.
 *
 * ── Por qué al boot y no en un cron ──────────────────────────────────────
 *
 * Hasta esta versión el catálogo se bajaba del proveedor fiscal por red, y por
 * eso tenía sentido un cron semanal. Ahora sale de un archivo VERSIONADO del
 * repo (`database/seeds/sifen-geo.json`): un cron semanal releería el mismo
 * archivo para no cambiar nada 51 domingos de 52. El catálogo cambia cuando
 * cambia el seed, y el seed cambia cuando hay un deploy — así que el disparo
 * correcto es el deploy. Se sacó la entrada del crontab por eso.
 *
 * ── Por qué un script aparte y no una migración ──────────────────────────
 *
 * Mismo criterio que `seed_admin.php`: las migraciones corren UNA sola vez. Si
 * mañana la SET agrega ciudades y el seed se regenera, una migración ya
 * aplicada no volvería a mirarlo y el catálogo nuevo nunca entraría.
 *
 * ── Best-effort: no aborta el boot ───────────────────────────────────────
 *
 * Un catálogo que no cargó degrada UNA pantalla (el domicilio de los
 * establecimientos vuelve a pedir códigos a mano, que es como estaba antes de
 * la mig 207) y deja al asistente sin poder resolverlos. No servir la API
 * entera por eso sería mucho peor. El error se escribe a stderr — o sea a
 * `docker logs` — y el sync manual (`POST /v1/geo?action=sync`) sigue estando
 * para reintentarlo sin un deploy.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $source = new \Punto\Api\EInvoice\SifenGeoSource();
    $result = (new \Punto\Api\EInvoice\GeoCatalogSync($source))->run();

    fwrite(STDERR, sprintf(
        "[seed-geo] catálogo %s/%s cargado: %d departamentos, %d distritos, %d ciudades (bajas: %d, salteadas: %d)\n",
        $result['source'],
        $result['countryCode'],
        $result['departments'],
        $result['districts'],
        $result['cities'],
        $result['deactivated'],
        $result['skipped']
    ));
    // La identidad del seed en el log: es lo que permite saber QUÉ versión del
    // catálogo está cargada sin abrir la base.
    fwrite(STDERR, '[seed-geo] ' . $source->origin() . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, '[seed-geo] la carga del catálogo geográfico falló (ignorado, la API arranca igual): '
        . $e->getMessage() . "\n");
    exit(0);
}
