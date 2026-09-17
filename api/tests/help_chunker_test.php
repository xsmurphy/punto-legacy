<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del fragmentador de la base de conocimiento (`HelpChunker`).
 * Ver context/82-base-de-conocimiento-punto-ai.md D8.
 *
 * Fragmentado PURO, sin Postgres ni red: es el único lugar donde se decide qué
 * entra en un fragmento y con qué texto se lo vectoriza, así que lo que se
 * cubre acá es lo que termina en el índice.
 *
 * Lo que mira, y por qué cada caso muerde:
 *   - documento SIN `##` — no todo lo que carga el owner es un instructivo con
 *     secciones; un texto corrido tiene que dar UN fragmento, no cero;
 *   - secciones ANIDADAS — la ruta de encabezados es la mitad del contexto que
 *     hace encontrable a un párrafo de tres palabras;
 *   - sección MÁS LARGA que el tope — se subdivide sin perder texto;
 *   - MISMO texto ⇒ MISMO hash — es la idempotencia: si el hash se mueve solo,
 *     cada reindexado le vuelve a pagar al proveedor por lo mismo.
 */

require_once dirname(__DIR__) . '/lib/Ai/HelpChunker.php';

use Punto\Api\Ai\HelpChunker;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label — $detail\n";
}

// ── 1. Documento sin encabezados ────────────────────────────────────────────

$plano = "Para cobrar con tarjeta, tocá Cobrar y elegí el medio de pago.\n\nSi el posnet no responde, se puede registrar el cobro a mano.";
$r = HelpChunker::chunk('Cobrar con tarjeta', $plano);

check('sin ## da un solo fragmento', count($r) === 1, 'fragmentos=' . count($r), $failures, $checks);
check('sin ## la ruta de encabezados va vacía', ($r[0]['headingPath'] ?? null) === '', json_encode($r[0]['headingPath'] ?? null), $failures, $checks);
check(
    'el cuerpo entra entero',
    str_contains($r[0]['content'], 'posnet') && str_contains($r[0]['content'], 'Cobrar'),
    mb_substr($r[0]['content'], 0, 80),
    $failures,
    $checks
);
check(
    'el título viaja en el texto que se vectoriza',
    str_starts_with($r[0]['embedText'], 'Cobrar con tarjeta'),
    mb_substr($r[0]['embedText'], 0, 60),
    $failures,
    $checks
);

// ── 2. Secciones anidadas ───────────────────────────────────────────────────

$anidado = <<<MD
Intro del documento antes de cualquier encabezado.

## Impresoras

Texto de impresoras.

### Cocina

Elegí la impresora de la cocina y guardá.

### Mostrador

La del mostrador imprime el ticket.

## Cajas

Cada caja tiene su serie.
MD;

$r     = HelpChunker::chunk('Configuración', $anidado, ['gastronomia']);
$paths = array_column($r, 'headingPath');

check('la intro previa al primer ## sobrevive', in_array('', $paths, true), json_encode($paths), $failures, $checks);
check('ruta anidada con >', in_array('Impresoras > Cocina', $paths, true), json_encode($paths), $failures, $checks);
check('un ## nuevo cierra el ### anterior', in_array('Cajas', $paths, true), json_encode($paths), $failures, $checks);
check('cada sección es un fragmento', count($r) === 5, 'fragmentos=' . count($r) . ' ' . json_encode($paths), $failures, $checks);

$cocina = null;
foreach ($r as $chunk) {
    if ($chunk['headingPath'] === 'Impresoras > Cocina') {
        $cocina = $chunk;
    }
}
check('la ruta entra en el texto vectorizado', $cocina !== null && str_contains($cocina['embedText'], 'Impresoras > Cocina'), json_encode($cocina['embedText'] ?? null), $failures, $checks);
check('los rubros entran en el texto vectorizado', $cocina !== null && str_contains($cocina['embedText'], 'gastronomia'), json_encode($cocina['embedText'] ?? null), $failures, $checks);
check('position es correlativa', array_column($r, 'position') === range(0, count($r) - 1), json_encode(array_column($r, 'position')), $failures, $checks);

// ── 3. Sección más larga que el tope ────────────────────────────────────────

$parrafo = str_repeat('Este es un párrafo de prueba con suficiente texto para ocupar lugar. ', 12); // ~800 chars
$largo   = "## Sección larga\n\n" . implode("\n\n", array_fill(0, 6, trim($parrafo)));

$r = HelpChunker::chunk('Doc largo', $largo);

check('la sección larga se subdivide', count($r) > 1, 'fragmentos=' . count($r), $failures, $checks);
check(
    'ningún fragmento pasa el tope',
    !array_filter($r, static fn (array $c): bool => mb_strlen($c['content']) > HelpChunker::MAX_CHARS),
    json_encode(array_map(static fn (array $c): int => mb_strlen($c['content']), $r)),
    $failures,
    $checks
);
check(
    'todos los subfragmentos conservan la ruta',
    array_unique(array_column($r, 'headingPath')) === ['Sección larga'],
    json_encode(array_column($r, 'headingPath')),
    $failures,
    $checks
);

$textoOriginal = preg_replace('/\s+/', '', implode('', array_fill(0, 6, trim($parrafo)))) ?? '';
$textoPartido  = preg_replace('/\s+/', '', implode('', array_column($r, 'content'))) ?? '';
check('no se pierde texto al subdividir', $textoOriginal === $textoPartido, 'orig=' . mb_strlen($textoOriginal) . ' partido=' . mb_strlen($textoPartido), $failures, $checks);

// Un párrafo único que por sí solo pasa el tope: se corta igual.
$monolito = "## Monolito\n\n" . str_repeat('palabra ', 500);
$r = HelpChunker::chunk('Doc monolito', $monolito);
check('un párrafo único gigante también se corta', count($r) > 1, 'fragmentos=' . count($r), $failures, $checks);
check(
    'y ninguno de esos pedazos pasa el tope',
    !array_filter($r, static fn (array $c): bool => mb_strlen($c['content']) > HelpChunker::MAX_CHARS),
    json_encode(array_map(static fn (array $c): int => mb_strlen($c['content']), $r)),
    $failures,
    $checks
);

// ── 4. Hash estable = idempotencia ──────────────────────────────────────────

$a = HelpChunker::chunk('Configuración', $anidado, ['gastronomia']);
$b = HelpChunker::chunk('Configuración', $anidado, ['gastronomia']);
check('el mismo documento da los mismos hashes', array_column($a, 'contentHash') === array_column($b, 'contentHash'), '', $failures, $checks);

$c = HelpChunker::chunk('Configuración', $anidado, ['GASTRONOMIA', 'gastronomia', '  ']);
check(
    'el orden y la repetición de rubros no mueven el hash',
    array_column($a, 'contentHash') === array_column($c, 'contentHash'),
    json_encode([array_column($a, 'contentHash')[0] ?? null, array_column($c, 'contentHash')[0] ?? null]),
    $failures,
    $checks
);

$d = HelpChunker::chunk('Configuración del local', $anidado, ['gastronomia']);
check(
    'cambiar el TÍTULO sí cambia el hash',
    array_column($a, 'contentHash') !== array_column($d, 'contentHash'),
    'el título viaja en el texto embebido: si no cambiara el hash, el fragmento quedaría desactualizado sin re-embeberse',
    $failures,
    $checks
);

$e = HelpChunker::chunk('Configuración', $anidado, ['retail']);
check('cambiar los rubros cambia el hash', array_column($a, 'contentHash') !== array_column($e, 'contentHash'), '', $failures, $checks);

// ── 5. Casos borde ──────────────────────────────────────────────────────────

check('documento vacío da cero fragmentos', HelpChunker::chunk('Vacío', "   \n\n  ") === [], '', $failures, $checks);
check(
    'un encabezado sin cuerpo no genera fragmento vacío',
    HelpChunker::chunk('Solo títulos', "## Uno\n\n## Dos\n") === [],
    json_encode(HelpChunker::chunk('Solo títulos', "## Uno\n\n## Dos\n")),
    $failures,
    $checks
);

// Un `#` dentro de un bloque de código es un comentario de consola, no un
// encabezado: si partiera el documento, el instructivo quedaría en pedazos.
$conCodigo = "## Instalación\n\nCorré esto:\n\n```bash\n# instala todo\nnpm install\n```\n\nY listo.";
$r = HelpChunker::chunk('Doc con código', $conCodigo);
check(
    'el # dentro de un bloque de código no parte el documento',
    count($r) === 1 && $r[0]['headingPath'] === 'Instalación',
    json_encode(array_column($r, 'headingPath')),
    $failures,
    $checks
);

echo "\n";
harnessFinish($failures, $checks);
