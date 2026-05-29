<?php
/**
 * OrderItemsService — manipulación de items dentro de transactionDetails (cluster meta-JSONB).
 *
 * Porta de app/action.php:
 *   removeItemfromOrder    (L332) — marca un item como status=0 (cancelado)
 *   processOrderItems      (L394) — READ: filtra/devuelve los items que matchean
 *   processOrderItemsUpdate (L451) — marca items como status=2 (en proceso) + devuelve
 *   moveOrderItems         (L517) — marca items como status=2 (leyendo por transactionName)
 *
 * transactionDetails vive en meta (jsonb) post-migración → se usa el helper meta_transaction.
 * La lógica de matching (itemId/oPosition/parent) se porta VERBATIM para preservar el
 * comportamiento exacto del legacy (sólo se agregan guards ?? para PHP 8).
 *
 * enc()/dec() son identidad → los IDs se usan tal cual (UUIDs).
 */

require_once __DIR__ . '/../meta_transaction.php';

class OrderItemsService
{
    /**
     * removeItemfromOrder: marca el item (itemId @ oPosition) como status=0.
     * @return bool false si no hay details (replica el $delete=false del legacy → error).
     */
    public function removeItem(string $companyId, string $transId, $itemId, $oPosition): bool
    {
        $details = txDetailsFromMeta(txMetaRead($transId, $companyId));
        if (empty($details)) {
            return false;
        }

        $parent = 0;
        foreach ($details as $index => $js) {
            if ($js['itemId'] == $itemId || ($js['parent'] ?? null) === $parent) {
                if ($oPosition != $index) {
                    continue;
                }
                if (($js['parent'] ?? 0) > 0) {
                    $parent = $js['parent'];
                }
                $details[$index]['status'] = 0;
            }
        }

        return txDetailsWrite($transId, $companyId, $details);
    }

    /**
     * processOrderItems (READ): devuelve los items que matchean, sin escribir.
     * @param array $items  lista de {orderId, itemId, oPosition}
     * @return array $final
     */
    public function selectItems(string $companyId, array $items): array
    {
        $final = [];
        foreach ($items as $value) {
            $orderId   = (string) ($value['orderId'] ?? '');
            $itemId    = $value['itemId'] ?? null;
            $oPosition = (int) ($value['oPosition'] ?? 0);

            $details = txDetailsFromMeta(txMetaRead($orderId, $companyId));
            $parent  = 0;
            $doit    = false;

            foreach ($details as $index => $js) {
                if ($js['itemId'] == $itemId) {
                    if ($oPosition == $index) {
                        if (($js['parent'] ?? 0) > 0) {
                            $parent = $js['parent'];
                        }
                        $doit = true;
                    } else {
                        $doit = false;
                    }
                } elseif (($js['parent'] ?? null) === $parent) {
                    $doit = true;
                } else {
                    $doit = false;
                }

                if ($doit) {
                    $final[] = $details[$index];
                }
            }
        }
        return $final;
    }

    /**
     * processOrderItemsUpdate: marca los items que matchean como status=2 (+ oPosition),
     * persiste y devuelve los items afectados.
     * @return array{ok:bool, items:array}
     */
    public function processUpdate(string $companyId, array $items): array
    {
        $final  = [];
        // $update arranca false (igual que el legacy): sin items → ok=false → el endpoint
        // devuelve error, replicando processOrderItemsUpdate. Con items, queda el resultado
        // de la última escritura.
        $update = false;

        foreach ($items as $value) {
            $orderId   = (string) ($value['orderId'] ?? '');
            $itemId    = $value['itemId'] ?? null;
            $oPosition = (int) ($value['oPosition'] ?? 0);

            $details = txDetailsFromMeta(txMetaRead($orderId, $companyId));
            $parent = 0;
            $doit   = false;

            foreach ($details as $index => $js) {
                if ($js['itemId'] == $itemId) {
                    if ($oPosition == $index) {
                        if (($js['parent'] ?? 0) > 0) {
                            $parent = $js['parent'];
                        }
                        $doit = true;
                    } else {
                        $doit = false;
                    }
                } elseif (($js['parent'] ?? null) === $parent) {
                    $doit = true;
                } else {
                    $doit   = false;
                    $parent = 0;
                }

                if ($doit) {
                    $details[$index]['status']    = 2;
                    $details[$index]['oPosition'] = $oPosition;
                    $final[]                      = $details[$index];
                }
            }

            // Escribe siempre (igual que el legacy, que hacía ncmUpdate aunque no cambiara nada).
            $update = txDetailsWrite($orderId, $companyId, $details);
        }

        return ['ok' => $update, 'items' => $final];
    }

    /**
     * moveOrderItems: como processUpdate pero la lectura se scopea por transactionName=$from
     * (la escritura es por transactionId, igual que el legacy).
     * @return array{ok:bool, items:array}
     */
    public function moveItems(string $companyId, string $from, array $items): array
    {
        global $db;
        $final = [];
        $wrote = false;

        foreach ($items as $value) {
            $orderId   = (string) ($value['orderId'] ?? '');
            $itemId    = $value['itemId'] ?? null;
            $oPosition = (int) ($value['oPosition'] ?? 0);

            // Lectura scopeada por transactionName=$from (meta crudo, sin flatten).
            $res = $db->Execute(
                'SELECT meta FROM transaction
                  WHERE transactionId = ? AND companyId = ? AND transactionName = ? LIMIT 1',
                [$orderId, $companyId, $from]
            );
            if ($res === false || $res->EOF) {
                continue;
            }
            $meta    = json_decode($res->fields['meta'] ?? '{}', true) ?: [];
            $details = txDetailsFromMeta($meta);
            $parent  = 0;

            foreach ($details as $index => $js) {
                if ($oPosition == $index) {
                    if ($js['itemId'] == $itemId || ($js['parent'] ?? null) === $parent) {
                        if (($js['parent'] ?? 0) > 0) {
                            $parent = $js['parent'];
                        }
                        $details[$index]['status']    = 2;
                        $details[$index]['oPosition'] = $oPosition;
                        $final[]                      = $details[$index];
                    }
                }
            }

            $wrote = txDetailsWrite($orderId, $companyId, $details) || $wrote;
        }

        return ['ok' => $wrote, 'items' => $final];
    }
}
