<?php

/**
 * AiAdminService.php — modelos/precios IA, paquetes de créditos y reporte de
 * consumo (realm /admin). Ver context/34-admin-saas-plan.md F4 §3.
 *
 * Tres superficies independientes sobre tres tablas:
 *   - `ai_model_config` (PK=capability): un modelo activo por capability
 *     (chat/vision/…), slug de OpenRouter en texto libre + precio en
 *     créditos por 1K tokens. Consumida por el agente IA tenant-facing
 *     (AI-2 billing) — esta clase solo la edita.
 *   - `ai_credit_package` (F4, migración 111): catálogo de paquetes
 *     comprables (nombre, créditos, precio). Solo el catálogo — el flujo de
 *     compra del tenant es otra fase, no se implementa acá.
 *   - `ai_credit_ledger`: histórico de consumo (ya alimentado por el agente).
 *     Solo lectura acá — reporte agregado por tenant × mes × capability.
 *
 * Mismo patrón que el resto del realm admin: `global $db; $db->Execute(...)`,
 * iterando con while(!$r->EOF).
 *
 * F6 §2 (context/34-admin-saas-plan.md) suma testModel(): "probar modelo"
 * desde /admin/ai — llamada mínima a OpenRouter con la OPENROUTER_API_KEY
 * del SERVER (no debita créditos de ningún tenant, no toca ai_credit_ledger).
 */
class AiAdminService
{
    /** Rate limit del botón "Probar": 1 test cada N segundos, por admin. */
    private const TEST_RATE_LIMIT_SECONDS = 10;
    /** Modelos/precios por capability. */
    public function listModels(): array
    {
        global $db;
        $r   = $db->Execute('SELECT capability, model, enabled, creditsperktoken, updatedat FROM ai_model_config ORDER BY capability ASC');
        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $f     = $r->fields;
                $out[] = [
                    'capability'       => (string) ($f['capability'] ?? ''),
                    'model'            => (string) ($f['model'] ?? ''),
                    'enabled'          => (bool) ($f['enabled'] ?? true),
                    'creditsPerKToken' => (float) ($f['creditsperktoken'] ?? 1),
                    'updatedAt'        => $f['updatedat'] ?? null,
                ];
                $r->MoveNext();
            }
        }
        return $out;
    }

    /**
     * Upsert de un modelo por capability. `capability` puede ser nueva
     * (crea la fila) o existente (actualiza model/enabled/creditsPerKToken).
     * El slug (`model`) es texto libre — validación laxa de formato
     * "algo/algo" (convención OpenRouter), sin whitelist.
     */
    public function upsertModel(array $input): array
    {
        global $db;

        $capability = trim((string) ($input['capability'] ?? ''));
        $model      = trim((string) ($input['model'] ?? ''));

        if ($capability === '') {
            return ['ok' => false, 'error' => 'capability es requerido', 'code' => 422];
        }
        if (!preg_match('/^[a-z0-9_-]+$/i', $capability)) {
            return ['ok' => false, 'error' => 'capability debe ser alfanumérico (a-z0-9_-)', 'code' => 422];
        }
        if ($model === '' || !preg_match('#^[\w.-]+/[\w.:-]+$#', $model)) {
            return ['ok' => false, 'error' => 'model debe tener formato "proveedor/slug" (convención OpenRouter)', 'code' => 422];
        }

        $enabled = array_key_exists('enabled', $input) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : true;
        $credits = array_key_exists('creditsPerKToken', $input) ? max(0, (float) $input['creditsPerKToken']) : 1.0;

        $ok = $db->Execute(
            "INSERT INTO ai_model_config (capability, model, enabled, creditsperktoken, updatedat)
             VALUES (?, ?, ?, ?, now())
             ON CONFLICT (capability) DO UPDATE SET
               model = EXCLUDED.model, enabled = EXCLUDED.enabled,
               creditsperktoken = EXCLUDED.creditsperktoken, updatedat = now()",
            [$capability, $model, $enabled, $credits]
        );

        if ($ok === false) {
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo guardar el modelo', 'code' => 500];
        }

        return ['ok' => true, 'model' => [
            'capability'       => $capability,
            'model'            => $model,
            'enabled'          => $enabled,
            'creditsPerKToken' => $credits,
        ]];
    }

    // ── Paquetes de créditos ─────────────────────────────────────────────

    public function listPackages(bool $includeArchived = true): array
    {
        global $db;
        $sql = 'SELECT packageid, name, credits, price, archived, created_at FROM ai_credit_package';
        if (!$includeArchived) {
            $sql .= ' WHERE archived = 0';
        }
        $sql .= ' ORDER BY credits ASC';

        $r   = $db->Execute($sql);
        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $out[] = $this->packageRow($r->fields->toArray());
                $r->MoveNext();
            }
        }
        return $out;
    }

    public function createPackage(array $input): array
    {
        global $db;

        $name    = trim((string) ($input['name'] ?? ''));
        $credits = (int) ($input['credits'] ?? 0);
        $price   = (float) ($input['price'] ?? -1);

        if ($name === '') {
            return ['ok' => false, 'error' => 'name es requerido', 'code' => 422];
        }
        if ($credits <= 0) {
            return ['ok' => false, 'error' => 'credits debe ser mayor a 0', 'code' => 422];
        }
        if ($price < 0) {
            return ['ok' => false, 'error' => 'price debe ser mayor o igual a 0', 'code' => 422];
        }

        $ok = $db->Insert('ai_credit_package', [
            'name'    => $name,
            'credits' => $credits,
            'price'   => $price,
            'archived'=> 0,
        ]);
        if (!$ok) {
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear el paquete', 'code' => 500];
        }

        return ['ok' => true, 'packageId' => $db->Insert_ID()];
    }

    public function updatePackage(string $packageId, array $input): array
    {
        global $db;

        $current = $this->getPackageRaw($packageId);
        if (!$current) {
            return ['ok' => false, 'error' => 'Paquete no encontrado', 'code' => 404];
        }

        $record = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                return ['ok' => false, 'error' => 'name no puede quedar vacío', 'code' => 422];
            }
            $record['name'] = $name;
        }
        if (array_key_exists('credits', $input)) {
            $credits = (int) $input['credits'];
            if ($credits <= 0) {
                return ['ok' => false, 'error' => 'credits debe ser mayor a 0', 'code' => 422];
            }
            $record['credits'] = $credits;
        }
        if (array_key_exists('price', $input)) {
            $price = (float) $input['price'];
            if ($price < 0) {
                return ['ok' => false, 'error' => 'price debe ser mayor o igual a 0', 'code' => 422];
            }
            $record['price'] = $price;
        }

        if (!empty($record)) {
            $db->AutoExecute('ai_credit_package', $record, 'UPDATE', 'packageid = ?', [$packageId]);
        }

        return ['ok' => true, 'package' => $this->packageRow($this->getPackageRaw($packageId) ?? $current)];
    }

    public function archivePackage(string $packageId): array
    {
        global $db;
        $current = $this->getPackageRaw($packageId);
        if (!$current) {
            return ['ok' => false, 'error' => 'Paquete no encontrado', 'code' => 404];
        }
        $db->AutoExecute('ai_credit_package', ['archived' => 1], 'UPDATE', 'packageid = ?', [$packageId]);
        return ['ok' => true];
    }

    // ── Reporte de consumo (solo lectura) ────────────────────────────────

    /**
     * Créditos consumidos por tenant × mes (últimos 3 meses, incl. el
     * actual), con desglose por capability. Solo movimientos de consumo
     * (delta < 0) — grants manuales (delta > 0) no cuentan como consumo.
     *
     * meta->>'capability' vive en ai_credit_ledger.meta (jsonb) — se usa el
     * operador ->> (NO el operador `?` de jsonb, que colisiona con el
     * placeholder posicional de PDO).
     */
    public function consumptionReport(): array
    {
        global $db;

        $sql = "SELECT l.companyId AS companyid,
                       COALESCE(c.config->>'companyName', l.companyId::text) AS companyname,
                       to_char(date_trunc('month', l.createdAt), 'YYYY-MM') AS month,
                       COALESCE(l.meta->>'capability', 'other') AS capability,
                       SUM(-l.delta) AS credits
                FROM ai_credit_ledger l
                LEFT JOIN company c ON c.companyId = l.companyId
                WHERE l.delta < 0
                  AND l.createdAt >= date_trunc('month', now()) - interval '2 months'
                GROUP BY l.companyId, c.config->>'companyName', month, capability
                ORDER BY month DESC, companyname ASC, capability ASC";

        $r   = $db->Execute($sql);
        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $f     = $r->fields;
                $out[] = [
                    'companyId'   => (string) ($f['companyid'] ?? ''),
                    'companyName' => (string) ($f['companyname'] ?? ''),
                    'month'       => (string) ($f['month'] ?? ''),
                    'capability'  => (string) ($f['capability'] ?? ''),
                    'credits'     => (int) ($f['credits'] ?? 0),
                ];
                $r->MoveNext();
            }
        }
        return $out;
    }

    // ── Test de conectividad ("Probar modelo") ───────────────────────────

    /**
     * Llama a OpenRouter con el slug configurado para $capability: prompt
     * mínimo ("responde OK"), maxTokens ~10, timeout corto. Usa la
     * OPENROUTER_API_KEY del server — NUNCA la del tenant, NUNCA debita
     * ai_credit_ledger (esto es un smoke test de plataforma, no un uso real).
     *
     * Rate limit: 1 test cada 10s por admin, guardado en platform_config
     * (key 'adminAiTestRate.<adminId>') — evita que alguien golpee OpenRouter
     * en loop desde el botón "Probar".
     */
    public function testModel(string $capability, string $actingAdminId): array
    {
        require_once __DIR__ . '/PlatformConfig.php';

        $capability = trim($capability);
        if ($capability === '') {
            return ['ok' => false, 'error' => 'capability es requerido', 'code' => 422];
        }

        $rateKey = 'adminAiTestRate.' . $actingAdminId;
        $last    = \PlatformConfig::get($rateKey, null);
        if (is_array($last) && isset($last['at'])) {
            $elapsed = time() - strtotime((string) $last['at']);
            if ($elapsed < self::TEST_RATE_LIMIT_SECONDS) {
                return [
                    'ok'    => false,
                    'error' => 'Esperá ' . (self::TEST_RATE_LIMIT_SECONDS - $elapsed) . 's antes de probar de nuevo',
                    'code'  => 429,
                ];
            }
        }
        \PlatformConfig::set($rateKey, ['at' => date('c')]);

        $models = $this->listModels();
        $model  = null;
        foreach ($models as $m) {
            if ($m['capability'] === $capability) {
                $model = $m;
                break;
            }
        }
        if ($model === null) {
            return ['ok' => false, 'error' => 'Capability no encontrada', 'code' => 404];
        }

        $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'OPENROUTER_API_KEY no configurada en el server', 'code' => 500];
        }

        $payload = json_encode([
            'model'      => $model['model'],
            'messages'   => [['role' => 'user', 'content' => 'Responde únicamente: OK']],
            'max_tokens' => 10,
        ]);

        $start = microtime(true);
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($response === false) {
            return ['ok' => false, 'error' => 'Error de red: ' . $curlErr, 'latencyMs' => $latencyMs, 'code' => 502];
        }

        $decoded = json_decode((string) $response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
            return ['ok' => false, 'error' => (string) $errMsg, 'latencyMs' => $latencyMs, 'code' => $httpCode];
        }

        $reply = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? '') : '';
        return [
            'ok'        => true,
            'latencyMs' => $latencyMs,
            'reply'     => trim((string) $reply),
        ];
    }

    // ── privados ─────────────────────────────────────────────────────────

    private function getPackageRaw(string $packageId): ?array
    {
        global $db;
        $r = $db->Execute('SELECT * FROM ai_credit_package WHERE packageid = ? LIMIT 1', [$packageId]);
        if (!$r || $r->EOF) {
            return null;
        }
        return $r->fields->toArray();
    }

    private function packageRow(array $r): array
    {
        return [
            'packageId' => (string) ($r['packageid'] ?? ''),
            'name'      => (string) ($r['name'] ?? ''),
            'credits'   => (int) ($r['credits'] ?? 0),
            'price'     => (float) ($r['price'] ?? 0),
            'archived'  => ((int) ($r['archived'] ?? 0)) === 1,
            'createdAt' => $r['created_at'] ?? null,
        ];
    }
}
