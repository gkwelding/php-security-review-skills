#!/usr/bin/env bash
# Match every review report in a results folder to its framework's answer key, then score it.
# One tool-less claude -p call per report. The matcher sees only the key and the report text,
# never which variant wrote it.
#
# Usage: evals/match.sh <results folder>     (run.sh calls this at the end unless MATCH=0)
#        Reads <framework>-<variant>.report.txt (and .claude.json for cost), writes results.csv,
#        classes.csv and a <name>.match.json per report.
# Env:   MODEL             model for claude -p (default: your claude default)
#        MATCH_BUDGET_USD  spend cap per matcher call (default 1)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$1" # relative paths from here also work with a native Windows php
budget=${MATCH_BUDGET_USD:-1}

item='{"type":"object","properties":{"id":{"type":"string"},"finding":{"type":"string"}},"required":["id","finding"]}'
schema="{\"type\":\"object\",\"properties\":{\"matched\":{\"type\":\"array\",\"items\":$item},\"decoys_flagged\":{\"type\":\"array\",\"items\":$item},\"unmatched\":{\"type\":\"array\",\"items\":{\"type\":\"string\"}}},\"required\":[\"matched\",\"decoys_flagged\",\"unmatched\"]}"

echo "framework,variant,status,planted,found,recall,decoys,decoys_flagged,unmatched,false_positives,cost_usd,turns,minutes,report_words,missed,flagged" > results.csv
echo "framework,variant,class,planted,found" > classes.csv

for report in *.report.txt; do
    [ -e "$report" ] || continue
    name=${report%.report.txt}
    key=$root/evals/fixtures/${name%%-*}/answer-key.json
    {
        cat <<'EOF'
You are scoring a security review of a PHP application against an answer key. The key lists the
vulnerabilities planted in the app ("planted") and look-alikes that are safe ("decoys"). The
review was written without seeing the key.

Go through every finding the review presents as a vulnerability or weakness to fix, at any
severity or confidence, including "needs runtime check". Items the review lists as checked and
safe, not vulnerable, informational only, or not reviewed are not findings: ignore them.

For each finding, decide:
- matched: it describes the same flaw as a planted issue, at the same place (the key's file and
  lines, or a file, route or method the key's description names). Wording, severity and class
  label may differ. A different weakness at the same place, or the same kind of weakness
  somewhere else, does not match. One finding that clearly reports two planted issues may match
  both. Each planted id appears at most once.
- decoys_flagged: it says one of the decoys is exploitable. Each decoy id appears at most once.
  A note on decoy code that itself says the code is not exploitable (hardening, robustness)
  goes under unmatched instead.
- unmatched: anything else. One short line per finding, with its file:line.

In "finding", quote or closely paraphrase the review's own title for it.

EOF
        echo "=== Answer key ==="
        cat "$key"
        echo
        echo "=== Review ==="
        cat "$report"
    } > "$name.match-prompt.txt"

    echo "== matching $name"
    claude -p --tools "" --output-format json --json-schema "$schema" --max-budget-usd "$budget" \
        --no-session-persistence ${MODEL:+--model "$MODEL"} \
        < "$name.match-prompt.txt" > "$name.match.json" 2> "$name.match.err" || echo "   matcher failed, see $name.match.err"

    php "$root/evals/score.php" "$key" "$name" >> results.csv
done

echo
column -s, -t < results.csv 2>/dev/null || cat results.csv
php -r '
    $rows = array_map(fn ($l) => str_getcsv($l, escape: ""), array_slice(file("classes.csv", FILE_IGNORE_NEW_LINES), 1));
    $t = [];
    foreach ($rows as [$fw, $variant, $class, $planted, $found]) {
        $t[$class][$variant][0] = ($t[$class][$variant][0] ?? 0) + $found;
        $t[$class][$variant][1] = ($t[$class][$variant][1] ?? 0) + $planted;
    }
    $variants = array_values(array_unique(array_column($rows, 1)));
    printf("\n%-18s", "found / planted");
    foreach ($variants as $v) printf(" %9s", $v);
    echo "\n";
    ksort($t);
    foreach ($t as $class => $byVariant) {
        printf("%-18s", $class);
        foreach ($variants as $v) printf(" %9s", isset($byVariant[$v]) ? "{$byVariant[$v][0]}/{$byVariant[$v][1]}" : "");
        echo "\n";
    }
'
echo
echo "Scores: $1/results.csv, per class: classes.csv, matcher verdicts: <name>.match.json"
