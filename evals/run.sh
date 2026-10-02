#!/usr/bin/env bash
# Review each fixture app for vulnerabilities with and without the skill, then score each report
# against the app's answer key (evals/match.sh): recall, false positives and recall per class.
#
# Usage: evals/run.sh [all|laravel|symfony]
# Env:   MODEL       model for claude -p (default: your claude default)
#        BUDGET_USD  spend cap per review (default 5)
#        WORK        scratch directory for the scaffolded apps (default evals/.work)
#        MATCH       0 skips matching the reports to the answer keys at the end
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
work=${WORK:-$root/evals/.work}
budget=${BUDGET_USD:-5}
which=${1:-all}
stamp=$(date +%Y%m%d-%H%M%S)
results=$work/results/$stamp
mkdir -p "$results"

# laravel/pao (in Laravel 11+ skeletons) switches PHPUnit/PHPStan to JSON output when it detects an
# agent. Nothing here parses their output today; this keeps it that way if a step is added.
export PAO_DISABLE=1

# framework|what to review (the same scope for both variants)
targets=(
    "laravel|app/ routes/ resources/views/ bootstrap/app.php"
    "symfony|src/ templates/ config/packages/"
)

scaffold() {
    local fw=$1 dir=$work/$1
    if [ ! -d "$dir/.git" ]; then
        rm -rf "$dir"
        case $fw in
            laravel)
                # Laravel 13 skeletons ship CLAUDE.md/AGENTS.md telling agents to install Laravel Boost; a run that follows it adds ~75 files and skews the scores.
                composer create-project -n --quiet laravel/laravel "$dir" && rm -f "$dir/CLAUDE.md" "$dir/AGENTS.md" ;;
            symfony)
                composer create-project -n --quiet symfony/skeleton "$dir"
                (cd "$dir" && composer require -n --quiet symfony/security-bundle symfony/orm-pack symfony/twig-bundle \
                    symfony/form symfony/serializer-pack symfony/http-client symfony/process symfony/validator symfony/rate-limiter) ;;
        esac
        (cd "$dir" && git init -q && git config core.longpaths true)
    fi

    # Copy fixtures on every run so edits reach an existing scaffold. Package changes need a fresh
    # scaffold: delete $work/<framework>.
    (cd "$dir" && if git rev-parse -q --verify HEAD > /dev/null; then git reset -q --hard && git clean -qfd; fi)
    cp -r "$root/evals/fixtures/$fw/." "$dir/"
    rm "$dir/answer-key.json" # the reviewer must never see the key
    (cd "$dir" && git add -A 2>/dev/null \
        && { git diff --cached --quiet || git -c user.name=eval -c user.email=eval@localhost commit -qm baseline; })
}

run_one() {
    local fw=$1 target=$2 variant=$3 dir=$work/$1 name=$1-$3 prompt
    # Paths below are relative to $dir so they also work with a native Windows php.
    local out=../results/$stamp/$name

    (cd "$dir" && git reset -q --hard && git clean -qfd)
    if [ "$variant" = with ]; then
        mkdir -p "$dir/.claude/skills"
        cp -r "$root"/skills/* "$dir/.claude/skills/"
        prompt="/review-php-security $target"
    else
        prompt="Review $target for security vulnerabilities and report each finding with file and line."
    fi

    echo "== $name"
    # Prompt on stdin: as an argument, Git Bash rewrites "/review-php-security" into a file path, and MSYS_NO_PATHCONV
    # would leak into Claude's own shell. --setting-sources project keeps user plugins and hooks out.
    # Read-only: Claude may read and search files, nothing else.
    (cd "$dir" && printf '%s' "$prompt" | claude -p --setting-sources project ${MODEL:+--model "$MODEL"} \
        --max-budget-usd "$budget" --no-session-persistence --output-format json --allowedTools "Read,Glob,Grep" \
        > "$out.claude.json" 2> "$out.claude.err") || echo "   claude exited non-zero, see $results/$name.claude.err"
    (cd "$dir" && php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); file_put_contents($argv[2], $j["result"] ?? "");' \
        "$out.claude.json" "$out.report.txt")
    # A review must not change the app. Anything listed here is a bug in the run, not a finding.
    (cd "$dir" && git status --porcelain -- . ':!.claude' > "$out.changes.txt")
    [ -s "$results/$name.changes.txt" ] && echo "   warning: the review changed files, see $results/$name.changes.txt"
    return 0
}

for entry in "${targets[@]}"; do
    IFS='|' read -r fw target <<< "$entry"
    [ "$which" = all ] || [ "$which" = "$fw" ] || continue
    scaffold "$fw"
    for variant in without with; do
        run_one "$fw" "$target" "$variant"
    done
done

echo "Reports and logs: $results"
[ "${MATCH:-1}" = 0 ] || bash "$root/evals/match.sh" "$results"
