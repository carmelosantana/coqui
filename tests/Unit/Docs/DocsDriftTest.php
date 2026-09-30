<?php

declare(strict_types=1);

/*
 * Guards the published docs (docs/*.md, README.md, examples/**) against drifting
 * from the code they describe. The docs site at docs.agentcoqui.com is generated
 * verbatim from these files, so every drift here ships to users.
 */

$projectRoot = dirname(__DIR__, 3);

/**
 * @return array<string, string> relative path => contents
 */
function docsDriftPublishedDocs(string $projectRoot): array
{
    $paths = array_merge(
        [$projectRoot . '/README.md'],
        glob($projectRoot . '/docs/*.md') ?: [],
    );

    $examples = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot . '/examples', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($examples as $file) {
        if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['md', 'json'], true)) {
            $paths[] = $file->getPathname();
        }
    }

    $docs = [];
    foreach ($paths as $path) {
        $docs[substr($path, strlen($projectRoot) + 1)] = (string) file_get_contents($path);
    }

    return $docs;
}

it('links GitHub repos under carmelosantana, not the retired coquibot org', function () use ($projectRoot) {
    $offenders = [];
    foreach (docsDriftPublishedDocs($projectRoot) as $path => $content) {
        if (preg_match_all('#https?://github\.com/coquibot/[^\s)"\'>]*#i', $content, $m)) {
            foreach ($m[0] as $url) {
                $offenders[] = "{$path}: {$url}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('tells toolkit authors to require the php-agents minor that core requires', function () use ($projectRoot) {
    $composer = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true);
    $coreConstraint = (string) ($composer['require']['carmelosantana/php-agents'] ?? '');
    expect(preg_match('/^\^(\d+)\.(\d+)/', $coreConstraint, $core))->toBe(1);
    $coreMinor = "^{$core[1]}.{$core[2]}";

    $found = 0;
    $offenders = [];
    foreach (docsDriftPublishedDocs($projectRoot) as $path => $content) {
        preg_match_all('#"carmelosantana/php-agents"\s*:\s*"([^"]+)"#', $content, $m);
        foreach ($m[1] as $constraint) {
            $found++;
            if (!str_starts_with($constraint, $coreMinor)) {
                $offenders[] = "{$path}: {$constraint} (core requires {$coreConstraint})";
            }
        }
    }

    expect($found)->toBeGreaterThan(0)
        ->and($offenders)->toBe([]);
});

it('only names delegation roles that ship in config/roles', function () use ($projectRoot) {
    $readme = (string) file_get_contents($projectRoot . '/README.md');
    expect(preg_match('/spawn specialized agents \(([^)]+)\)/', $readme, $m))->toBe(1);

    $shipped = array_map(
        static fn (string $path): string => basename($path, '.md'),
        glob($projectRoot . '/config/roles/*.md') ?: [],
    );
    $named = array_map('trim', explode(',', $m[1]));

    expect(array_values(array_diff($named, $shipped)))->toBe([]);
});

it('documents every agents.defaults key the code reads in CONFIGURATION.md', function () use ($projectRoot) {
    $keys = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        // Only quoted string literals count as reads — doc comments do not.
        preg_match_all('#[\'"]agents\.defaults\.([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)#', (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $key) {
            $keys[$key] = true;
        }
    }
    $keys = array_keys($keys);
    sort($keys);

    $doc = (string) file_get_contents($projectRoot . '/docs/CONFIGURATION.md');
    $missing = array_values(array_filter(
        $keys,
        static fn (string $key): bool => !str_contains($doc, "agents.defaults.{$key}"),
    ));

    expect($keys)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('documents the code default for every agents.defaults key backed by CoquiDefaults', function () use ($projectRoot) {
    // Constants in CoquiDefaults name the key they default in their docblock:
    // "(config: agents.defaults.x.y)". Those are the code defaults.
    $codeDefaults = [];
    foreach ((new ReflectionClass(\CoquiBot\Coqui\Contract\CoquiDefaults::class))->getReflectionConstants() as $constant) {
        if (preg_match('/config:\s*agents\.defaults\.([A-Za-z0-9_.]*[A-Za-z0-9_])/i', (string) $constant->getDocComment(), $m)) {
            $codeDefaults[$m[1]] = $constant->getValue();
        }
    }
    expect($codeDefaults)->not->toBe([]);

    // The key reference table: "| `agents.defaults.x` | default | purpose |".
    $doc = (string) file_get_contents($projectRoot . '/docs/CONFIGURATION.md');
    preg_match_all('/^\|\s*`agents\.defaults\.([A-Za-z0-9_.]+)`\s*\|\s*([^|]+?)\s*\|/m', $doc, $rows, PREG_SET_ORDER);
    $documented = [];
    foreach ($rows as $row) {
        $documented[$row[1]] = trim($row[2], " `");
    }

    $format = static fn (mixed $value): string => match (true) {
        is_bool($value) => $value ? 'true' : 'false',
        is_float($value) && floor($value) === $value => (string) (int) $value,
        default => (string) $value,
    };

    // Keys the code actually reads (a default for an unread key documents nothing).
    $read = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            preg_match_all('#[\'"]agents\.defaults\.([A-Za-z0-9_.]*[A-Za-z0-9_])#', (string) file_get_contents($file->getPathname()), $m);
            $read += array_fill_keys($m[1], true);
        }
    }

    $mismatches = [];
    foreach ($codeDefaults as $key => $value) {
        if (!isset($read[$key])) {
            continue;
        }
        $expected = $format($value);
        $actual = $documented[$key] ?? '(missing from the key table)';
        if ($actual !== $expected) {
            $mismatches[] = "agents.defaults.{$key}: documented {$actual}, code default {$expected}";
        }
    }

    expect($mismatches)->toBe([]);
});

it('lists every registered API route in the API.md quick reference', function () use ($projectRoot) {
    $normalize = static fn (string $path): string => preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;

    $routes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('#\$router->(get|post|put|patch|delete)\(\s*(\$v1\s*\.\s*)?\'([^\']+)\'#', $source, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $path = ($match[2] !== '' ? '/api/v1' : '') . $match[3];
            $routes[strtoupper($match[1]) . ' ' . $normalize($path)] = true;
        }

        preg_match_all('#addPublicRoute\(\s*\'([A-Z]+)\'\s*,\s*(\$v1\s*\.\s*)?\'([^\']+)\'#', $source, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $path = ($match[2] !== '' ? '/api/v1' : '') . $match[3];
            $routes[$match[1] . ' ' . $normalize($path)] = true;
        }
    }

    $apiDoc = (string) file_get_contents($projectRoot . '/docs/API.md');
    $quickRefStart = strpos($apiDoc, '## Quick Reference');
    expect($quickRefStart)->not->toBeFalse();

    // Endpoint sections: "#### `GET /api/v1/...`" headings above the quick reference.
    preg_match_all('#^\#{3,4}\s*`([A-Z]+)\s+(/api/v1[^`\s?]*)#m', substr($apiDoc, 0, (int) $quickRefStart), $headings, PREG_SET_ORDER);
    $sections = [];
    foreach ($headings as $heading) {
        $sections[$heading[1] . ' ' . $normalize($heading[2])] = true;
    }

    // Quick reference table rows: "| `GET` | `/api/v1/...` | ...".
    preg_match_all('#^\|\s*`([A-Z]+)`\s*\|\s*`([^`]+)`#m', substr($apiDoc, (int) $quickRefStart), $rows, PREG_SET_ORDER);
    $quickRef = [];
    foreach ($rows as $row) {
        $quickRef[$row[1] . ' ' . $normalize($row[2])] = true;
    }

    $registered = array_keys($routes);
    sort($registered);

    expect(count($registered))->toBeGreaterThan(100)
        ->and(array_values(array_diff($registered, array_keys($sections))))->toBe([], 'routes without an endpoint section')
        ->and(array_values(array_diff($registered, array_keys($quickRef))))->toBe([], 'routes missing from the Quick Reference');
});
