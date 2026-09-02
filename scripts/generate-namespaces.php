<?php

declare(strict_types=1);

/**
 * Reads openapi.json and emits typed service classes (`$aw->charts()->natal($body)`)
 * — one class per namespace plus an accessor trait the `Astroway` client uses.
 *
 * Naming rule (matches the TypeScript / Python SDKs):
 *   * `_` is the namespace separator (`vedic_dashas_vimshottari_maha`).
 *   * `-` is a word separator within a part (`aspect-grid`).
 *   * Single-segment opIds get `compute()`.
 *
 * Outputs:
 *   - src/Namespaces/{Ns}Service.php  — one per namespace
 *   - src/HasServices.php             — trait with accessor methods, memoizes services
 */

$root = dirname(__DIR__);
$specPath = $root.'/openapi.json';
$nsDir = $root.'/src/Namespaces';
$traitPath = $root.'/src/HasServices.php';

if (!is_file($specPath)) {
    fwrite(STDERR, "openapi.json missing at {$specPath} — run scripts/sync-spec first.\n");
    exit(1);
}
$spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);

const RESERVED = [
    'class', 'default', 'delete', 'do', 'else', 'enum', 'export', 'extends',
    'false', 'finally', 'for', 'function', 'if', 'import', 'in', 'instanceof',
    'list', 'match', 'new', 'null', 'return', 'super', 'switch', 'this', 'throw',
    'true', 'try', 'typeof', 'var', 'void', 'while', 'with', 'yield', 'echo',
    'print', 'self', 'parent', 'static', 'global', 'public', 'private', 'protected',
    'final', 'abstract', 'interface', 'trait', 'use', 'namespace', 'and', 'or', 'xor',
];

function safeKey(string $name): string
{
    return in_array($name, RESERVED, true) ? $name.'_' : $name;
}

function dashToLowerCamel(string $s): string
{
    $parts = preg_split('/-+/', $s) ?: [];
    $parts = array_values(array_filter(array_map(
        static fn($p) => preg_replace('/[^a-zA-Z0-9]/', '', (string) $p) ?? '',
        $parts,
    )));
    if (!$parts) {
        return '';
    }
    $head = strtolower($parts[0]);
    $tail = array_map(static fn($p) => ucfirst(strtolower((string) $p)), array_slice($parts, 1));

    return $head.implode('', $tail);
}

function dashToUpperCamel(string $s): string
{
    $c = dashToLowerCamel($s);

    return $c === '' ? '' : ucfirst($c);
}

function deriveNames(string $opId): ?array
{
    $parts = array_values(array_filter(array_map(
        static fn($p) => preg_replace('/[{}]/', '', $p),
        explode('_', $opId),
    )));
    if (!$parts) {
        return null;
    }
    $ns = dashToLowerCamel($parts[0]);
    if ($ns === '') {
        return null;
    }
    $rest = array_slice($parts, 1);
    if (!$rest) {
        $method = 'compute';
    } else {
        $first = dashToLowerCamel($rest[0]);
        $tail = array_map(static fn($p) => dashToUpperCamel($p), array_slice($rest, 1));
        $method = $first.implode('', $tail);
    }
    if ($method === '') {
        return null;
    }

    return [safeKey($ns), safeKey($method)];
}

/** @var array<string, array<int, array{method: string, path: string, summary: ?string}>> $byNs */
$byNs = [];

foreach (($spec['paths'] ?? []) as $path => $methods) {
    if (!is_array($methods)) {
        continue;
    }
    // GET lookups get namespace methods too. Filtering on 'post' alone left
    // the spec's GET-only paths reachable only through the raw client.
    $op = $methods['post'] ?? $methods['get'] ?? null;
    $httpMethod = isset($methods['post']) ? 'POST' : 'GET';
    if (!is_array($op) || empty($op['operationId'])) {
        continue;
    }
    if (str_contains((string) $path, '{')) {
        continue;
    }
    // Only endpoints answering with the JSON envelope get a typed method. The
    // /embed/* widgets serve HTML; api-calc declares text/html for them since
    // 2026-08-04. The explicit /embed/ skip is belt and braces until
    // openapi.json is resynced past that date.
    $okContent = $op['responses']['200']['content'] ?? [];
    if (!isset($okContent['application/json'])) {
        continue;
    }
    if (str_starts_with((string) $path, '/embed/')) {
        continue;
    }
    // /public/* mirrors keyed endpoints the SDK already exposes.
    if (str_starts_with((string) $path, '/public/')) {
        continue;
    }
    // System endpoints are hand-written on the client as health()/version().
    if (in_array('System', $op['tags'] ?? [], true)) {
        continue;
    }
    $names = deriveNames((string) $op['operationId']);
    if ($names === null) {
        continue;
    }
    [$ns, $method] = $names;
    $byNs[$ns][] = [
        'method' => $method,
        'path' => (string) $path,
        'httpMethod' => $httpMethod,
        'summary' => $op['summary'] ?? null,
    ];
}

// Detect collisions.
$collisions = 0;
foreach ($byNs as $ns => $items) {
    $seen = [];
    foreach ($items as $it) {
        if (isset($seen[$it['method']]) && $seen[$it['method']] !== $it['path']) {
            fwrite(STDERR, "Collision: {$ns}.{$it['method']} -> {$seen[$it['method']]} vs {$it['path']}\n");
            $collisions++;
        }
        $seen[$it['method']] = $it['path'];
    }
}
if ($collisions > 0) {
    fwrite(STDERR, "Aborting: {$collisions} collisions.\n");
    exit(1);
}

ksort($byNs);
foreach ($byNs as &$items) {
    usort($items, static fn($a, $b) => strcmp((string) $a['method'], (string) $b['method']));
}
unset($items);

// Wipe the namespaces dir so removed endpoints don't leave stale files.
if (is_dir($nsDir)) {
    foreach (glob($nsDir.'/*Service.php') ?: [] as $f) {
        unlink($f);
    }
} else {
    mkdir($nsDir, 0o755, true);
}

function classNameForNs(string $ns): string
{
    return ucfirst($ns).'Service';
}

function escapeDoc(string $s): string
{
    $s = str_replace('*/', '* /', $s);

    return str_replace(["\r", "\n"], [' ', ' '], $s);
}

$totalMethods = 0;
foreach ($byNs as $ns => $items) {
    $cls = classNameForNs($ns);
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = '// AUTO-GENERATED by scripts/generate-namespaces.php — DO NOT EDIT.';
    $lines[] = '// Run `composer generate:namespaces` to refresh from openapi.json.';
    $lines[] = '';
    $lines[] = 'namespace Astroway\\Namespaces;';
    $lines[] = '';
    $lines[] = 'use Astroway\\Astroway;';
    $lines[] = '';
    $lines[] = "/** Service for {$ns}.* endpoints. */";
    $lines[] = "final class {$cls}";
    $lines[] = '{';
    $lines[] = '    public function __construct(private readonly Astroway $client)';
    $lines[] = '    {';
    $lines[] = '    }';
    $lines[] = '';
    foreach ($items as $i => $item) {
        $totalMethods++;
        $verb = $item['httpMethod'];
        $doc = escapeDoc($item['summary'] ?? "{$verb} {$item['path']}");
        $lines[] = '    /**';
        $lines[] = "     * {$doc} ({$verb} {$item['path']}).";
        $lines[] = '     *';
        if ($verb === 'GET') {
            // No body and no idempotency key: neither means anything on a read.
            $lines[] = '     * @param array{headers?: array<string, string>, query?: array<string, scalar|array<int|string, scalar>>} $options';
            $lines[] = '     */';
            $lines[] = "    public function {$item['method']}(array \$options = []): mixed";
            $lines[] = '    {';
            $lines[] = '        $opts = [];';
            $lines[] = '        if (!empty($options[\'query\'])) {';
            $lines[] = '            $opts[\'query\'] = $options[\'query\'];';
            $lines[] = '        }';
            $lines[] = '        if (!empty($options[\'headers\'])) {';
            $lines[] = '            $opts[\'headers\'] = $options[\'headers\'];';
            $lines[] = '        }';
            $lines[] = '';
            $lines[] = "        return \$this->client->request('GET', '{$item['path']}', \$opts);";
            $lines[] = '    }';
        } else {
            $lines[] = '     * @param array<string, mixed>|list<mixed>|object|null $body  Array, list, or DTO with `toArray()`.';
            $lines[] = '     * @param array{headers?: array<string, string>, query?: array<string, scalar|array<int|string, scalar>>, idempotencyKey?: string} $options';
            $lines[] = '     */';
            $lines[] = "    public function {$item['method']}(array|object|null \$body = null, array \$options = []): mixed";
            $lines[] = '    {';
            $lines[] = '        $opts = [];';
            $lines[] = '        if ($body !== null) {';
            $lines[] = '            $opts[\'json\'] = $body;';
            $lines[] = '        }';
            $lines[] = '        if (!empty($options[\'query\'])) {';
            $lines[] = '            $opts[\'query\'] = $options[\'query\'];';
            $lines[] = '        }';
            $lines[] = '        if (!empty($options[\'headers\'])) {';
            $lines[] = '            $opts[\'headers\'] = $options[\'headers\'];';
            $lines[] = '        }';
            $lines[] = '        if (isset($options[\'idempotencyKey\'])) {';
            $lines[] = '            $opts[\'idempotencyKey\'] = $options[\'idempotencyKey\'];';
            $lines[] = '        }';
            $lines[] = '';
            $lines[] = "        return \$this->client->request('POST', '{$item['path']}', \$opts);";
            $lines[] = '    }';
        }
        if ($i < count($items) - 1) {
            $lines[] = '';
        }
    }
    $lines[] = '}';
    $lines[] = '';
    file_put_contents($nsDir.'/'.$cls.'.php', implode("\n", $lines));
}

// Emit accessor trait.
$traitLines = [];
$traitLines[] = '<?php';
$traitLines[] = '';
$traitLines[] = 'declare(strict_types=1);';
$traitLines[] = '';
$traitLines[] = '// AUTO-GENERATED by scripts/generate-namespaces.php — DO NOT EDIT.';
$traitLines[] = '// Run `composer generate:namespaces` to refresh from openapi.json.';
$traitLines[] = '';
$traitLines[] = 'namespace Astroway;';
$traitLines[] = '';
foreach (array_keys($byNs) as $ns) {
    $cls = classNameForNs($ns);
    $traitLines[] = "use Astroway\\Namespaces\\{$cls};";
}
$traitLines[] = '';
$traitLines[] = '/**';
$traitLines[] = ' * Lazy accessors for typed service namespaces. Each accessor returns a memoized';
$traitLines[] = ' * service tied to the Astroway client (constructed once per Astroway instance).';
$traitLines[] = ' */';
$traitLines[] = 'trait HasServices';
$traitLines[] = '{';
$traitLines[] = '    /** @var array<string, object> */';
$traitLines[] = '    private array $services = [];';
$traitLines[] = '';
foreach ($byNs as $ns => $_) {
    $cls = classNameForNs($ns);
    $traitLines[] = "    public function {$ns}(): {$cls}";
    $traitLines[] = '    {';
    $traitLines[] = "        return \$this->services['{$ns}'] ??= new {$cls}(\$this);";
    $traitLines[] = '    }';
    $traitLines[] = '';
}
$traitLines[] = '}';
$traitLines[] = '';
file_put_contents($traitPath, implode("\n", $traitLines));

$nsCount = count($byNs);
echo "Wrote {$nsCount} services to {$nsDir}\n";
echo "Wrote accessor trait to {$traitPath}\n";
echo "Namespaces: {$nsCount}, methods: {$totalMethods}\n";
