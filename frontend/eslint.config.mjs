import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),

  // ── Un cliente HTTP = un realm ──────────────────────────────────────────
  //
  // El POS corre con la sesión del DISPOSITIVO (Bearer `_jwt`, sin
  // vencimiento). El panel corre con la cookie del OPERADOR (`_jwt_panel`,
  // 24 h). Cuando código del POS pide datos con el cliente del panel, funciona
  // en desarrollo y en la primera jornada — y se rompe solo cuando la cookie
  // del operador vence, típicamente de un día para el otro.
  //
  // Ese bug apareció TRES veces: el Bearer faltante en `/api/pos/*`, el panel
  // operando con el outlet de la caja (espacios, 2026-07-19) y los módulos que
  // desaparecían del sidebar (2026-08-17). Las tres veces se arregló el
  // call-site; ninguna evitó la siguiente. Esta regla lo hace imposible de
  // introducir: el lint falla antes del commit.
  //
  // Si un archivo del POS necesita datos que hoy solo expone el panel, la
  // salida NO es importar `api-client`: es agregar el realm `pos-app` al
  // endpoint (GET-only) y un BFF bajo `/api/pos/`, como se hizo con
  // `/v1/modules`.
  {
    files: [
      "app/(pos)/**/*.{ts,tsx}",
      "app/(screen)/**/*.{ts,tsx}",
      "components/register/**/*.{ts,tsx}",
      "components/spaces/**/*.{ts,tsx}",
      "lib/cart/**/*.{ts,tsx}",
      "lib/kds/**/*.{ts,tsx}",
    ],
    rules: {
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "@/lib/api-client",
              importNames: ["api"],
              message:
                "El POS no puede usar el cliente del panel (cookie `_jwt_panel`, vence a las 24 h). " +
                "Usá `posFetch` (@/lib/api/pos-fetch) contra un BFF de /api/pos/. " +
                "Importar `ApiError` para tipar errores sí está permitido.",
            },
          ],
        },
      ],
    },
  },
]);

export default eslintConfig;
