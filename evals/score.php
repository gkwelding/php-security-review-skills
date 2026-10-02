<?php

// Score one review from the matcher's verdict.
// Usage (from the results folder): php evals/score.php <answer-key.json> <framework>-<variant>
//        Reads <name>.match.json, <name>.claude.json and <name>.report.txt. Prints one results.csv
//        row and appends one classes.csv row per vulnerability class in the key.

[, $keyPath, $name] = $argv;
[$framework, $variant] = explode('-', $name, 2);

$key = json_decode(file_get_contents($keyPath), true, flags: JSON_THROW_ON_ERROR);
$verdict = json_decode((string) @file_get_contents("{$name}.match.json"), true)['structured_output'] ?? null;
$run = json_decode((string) @file_get_contents("{$name}.claude.json"), true) ?? [];
$report = (string) @file_get_contents("{$name}.report.txt");

$planted = array_column($key['planted'], 'class', 'id');
$decoys = array_column($key['decoys'], 'id');

$cost = isset($run['total_cost_usd']) ? round($run['total_cost_usd'], 2) : '';
$minutes = isset($run['duration_ms']) ? round($run['duration_ms'] / 60000, 1) : '';
$tail = [$cost, $run['num_turns'] ?? '', $minutes, count(preg_split('/\s+/', $report, flags: PREG_SPLIT_NO_EMPTY))];

if (!is_array($verdict)) {
    // No verdict (matcher failed): leave the scores blank rather than reporting zero recall.
    echo csv([$framework, $variant, $run['subtype'] ?? '', count($planted), '', '', count($decoys), '', '', '', ...$tail, '', '']);
    exit;
}

// Only ids that exist in the key count, each once, so a matcher slip can't inflate a score.
$found = array_values(array_intersect(array_keys($planted), array_column($verdict['matched'] ?? [], 'id')));
$flagged = array_values(array_intersect($decoys, array_column($verdict['decoys_flagged'] ?? [], 'id')));
$unmatched = count($verdict['unmatched'] ?? []);

echo csv([
    $framework, $variant, $run['subtype'] ?? '',
    count($planted), count($found), round(count($found) / count($planted), 2),
    count($decoys), count($flagged), $unmatched, count($flagged) + $unmatched,
    ...$tail,
    implode(' ', array_diff(array_keys($planted), $found)),
    implode(' ', $flagged),
]);

$classes = fopen('classes.csv', 'a');
foreach (array_count_values($planted) as $class => $count) {
    $hits = count(array_filter($found, fn (string $id) => $planted[$id] === $class));
    fputcsv($classes, [$framework, $variant, $class, $count, $hits], escape: '');
}

function csv(array $fields): string
{
    $out = fopen('php://memory', 'r+');
    fputcsv($out, $fields, escape: '');
    rewind($out);

    return stream_get_contents($out);
}
