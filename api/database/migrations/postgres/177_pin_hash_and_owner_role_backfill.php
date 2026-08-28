<?php
/**
 * Migration 177 — El PIN del POS vuelve a funcionar, y el Dueño deja de
 * aparecer sin rol.
 *
 * DOS SÍNTOMAS, EL MISMO ORIGEN: el alta de tenant (`SignupService`) creaba al
 * dueño a mano, con menos campos de los que el resto del sistema espera.
 *
 * ── 1. "Código incorrecto" en /pos con el PIN correcto ──────────────────────
 *
 * El alta escribía `lockPass = '1111'` (el número en claro, que es lo que
 * muestra la ficha del panel) y NADA más. Pero el lock screen del POS compara
 * contra `pinhash` —y solo contra eso—: el match es LOCAL, en el browser, con
 * los hashes que bajan en el bootstrap (`UsersService::rosterForOutlet`),
 * porque la caja tiene que desbloquear sin red. Con `pinhash` en NULL el
 * usuario no existe para ese match: el panel muestra 1111 y la caja dice
 * "Código incorrecto".
 *
 * La mig 55 creó la columna y nunca la backfilleó, así que arrastra a todo
 * usuario cuyo PIN no se haya vuelto a guardar desde el panel (guardarlo desde
 * ahí sí escribe los tres campos — `UsersService::update`).
 *
 * `lockpass` es SMALLINT, así que un PIN con cero inicial se guardó sin él
 * (0111 → 111). El backfill hace `lpad(...,4,'0')` para reconstruir los cuatro
 * dígitos ANTES de hashear — hashear "111" dejaría el PIN roto igual, pero en
 * silencio y con la columna ya poblada, que es peor que el estado actual.
 *
 * ── 2. El campo Rol del Dueño aparece en blanco ─────────────────────────────
 *
 * El alta escribía `role = 1`, el entero legacy. `RoleService::ownerRoleSql()`
 * acepta esa forma, así que el login y los permisos nunca fallaron — pero el
 * selector de Rol del panel lista los roles del tenant por UUID, y `'1'` no
 * matchea ninguna opción. El Dueño abría su ficha con Rol vacío; al guardar, el
 * panel mandaba `roleId: null`, que a cualquier OTRO usuario editado así le
 * borraba el rol.
 *
 * Esta migración lo reemplaza por el UUID del rol `owner` de SU empresa (el que
 * siembra `seedCompanyRoles`). Es la misma identidad, escrita en la forma que
 * entiende todo el sistema: `ownerRoleSql()` sigue reconociéndolo por la rama
 * del UUID. Si una empresa no tiene el rol sembrado, se la SALTA — dejarla con
 * el `1` legacy sigue funcionando; inventarle un rol, no.
 *
 * IDEMPOTENTE: las dos partes filtran por el estado que corrigen (`pinhash IS
 * NULL`, `role = '1'`). Correrla dos veces no toca ninguna fila la segunda vez.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 177: migrationPdo no disponible\n");
    return;
}

try {
    $pdo->beginTransaction();

    // ── 1. pinhash (+ lockpasshash) desde el PIN en claro ───────────────────
    // El SHA-256 se calcula en PHP y no en SQL a propósito: `sha256()` de PG
    // vive en pgcrypto, que no es una dependencia declarada de este esquema.
    $rows = $pdo->query(
        "SELECT contactid, lpad(lockpass::text, 4, '0') AS pin
           FROM contact
          WHERE lockpass IS NOT NULL
            AND pinhash IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    $updPin = $pdo->prepare(
        'UPDATE contact SET pinhash = ?, lockpasshash = COALESCE(lockpasshash, ?) WHERE contactid = ?::uuid'
    );
    $pins = 0;
    foreach ($rows as $row) {
        $pin = (string) $row['pin'];
        if (!preg_match('/^\d{4}$/', $pin)) {
            // PIN imposible de reconstruir (más de 4 dígitos). Se deja como
            // está: el usuario lo vuelve a guardar desde el panel.
            continue;
        }
        $updPin->execute([
            hash('sha256', $pin),
            password_hash($pin, PASSWORD_BCRYPT),
            $row['contactid'],
        ]);
        $pins++;
    }

    // ── 2. role legacy '1' → UUID del rol owner de la empresa ───────────────
    $roleUpd = $pdo->exec(
        "UPDATE contact c
            SET role = r.taxonomyid::text
           FROM taxonomy r
          WHERE c.role = '1'
            AND r.companyid    = c.companyid
            AND r.taxonomytype = 'role'
            AND r.taxonomyextra::json->>'slug' = 'owner'"
    );

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 177: $pins PIN(es) hasheado(s), " . (int) $roleUpd . " dueño(s) con rol UUID\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate] ERROR 177: " . $e->getMessage() . "\n");
    throw $e;
}
