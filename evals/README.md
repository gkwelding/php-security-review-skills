# Evals

Checks whether the skill finds more real vulnerabilities than a plain prompt, without reporting more safe code as vulnerable, and which rule changes help or hurt.

`run.sh` scaffolds a fresh Laravel or Symfony app, adds a small fixture app with planted vulnerabilities and safe look-alikes, and asks `claude -p` to review it twice:

| Variant | Prompt |
|---|---|
| `without` | "Review `<target>` for security vulnerabilities and report each finding with file and line." |
| `with` | `/review-php-security <target>`, with this repo's `skills/` copied into the app's `.claude/skills/` |

Both get the same scope (Laravel: `app/ routes/ resources/views/ bootstrap/app.php`; Symfony: `src/ templates/ config/packages/`) and the same read-only tools (`Read`, `Glob`, `Grep`). After each run the app's `git status` is saved to `<name>.changes.txt`; it should be empty.

## Scoring

Each fixture has an answer key, `fixtures/<framework>/answer-key.json` (removed from the scratch app before the review, so the reviewer never sees it). It lists:

- **planted:** id, vulnerability class, file, line range and a short description of each planted issue
- **decoys:** code that looks vulnerable but isn't, with the reason it is safe

Reports are free text, so matching findings to the key is done by `match.sh`: one tool-less `claude -p --json-schema` call per report, which sees the key and the report and nothing else (not the variant, not the file name). It returns the planted ids the report found, the decoys it flagged, and every other finding as one line. Items a report lists as checked and safe don't count. `score.php` then keeps only ids that exist in the key, counts each once, and writes one row per run to `results.csv`:

| Column | Meaning |
|---|---|
| `planted`, `found`, `recall` | Planted issues in the key, how many the report found (same flaw at the same place), and the ratio |
| `decoys`, `decoys_flagged` | Safe look-alikes in the key, and how many the report called exploitable (a hardening note that says the decoy isn't exploitable counts as `unmatched`) |
| `unmatched` | Findings that match nothing in the key. Some may be real issues the key doesn't list (the skeleton's own `.env`, say): read them in `<name>.match.json` before counting them against a run |
| `false_positives` | `decoys_flagged + unmatched` |
| `status` | `success`, or why `claude -p` stopped (`error_max_budget_usd`, ...). A review cut off by the budget has a short or empty report and low recall |
| `cost_usd`, `turns`, `minutes` | From `claude -p --output-format json` (the review only, not the matcher) |
| `report_words` | Length of the final report |
| `missed`, `flagged` | The planted ids not found and the decoy ids flagged |

`classes.csv` has found/planted per vulnerability class (`access-control`, `sql-injection`, `xss`, ...) and the script prints a per-class table at the end. With 8-9 planted issues per app most classes have one or two, so read per-class numbers across several runs.

The scores can fail: a report that finds nothing scores recall 0, and one that flags every decoy scores `decoys_flagged` 4 of 4 (checked with a stub matcher, and with the real matcher on two hand-written reports; see below). If the matcher call fails, the row's scores stay blank rather than reading as zero.

The matcher is blind to the variant name, but the `with` reports follow the skill's report layout (`[H1]`, "Checked, not vulnerable"), so it can guess. It only has to decide whether each finding is in the key, which leaves little room for bias, but spot-check `<name>.match.json` against the reports when a difference matters.

## Fixtures

The planted classes come from the skill's rule files, but the code isn't copied from their examples (that would hand the `with` variant the answers).

| Fixture | Planted | Decoys |
|---|---|---|
| Laravel 13 event-ticket app | IDOR on `TicketController@show`; mass assignment through `except()` with `is_organiser` fillable; `whereRaw` with a concatenated search term; Markdown reviews through `{!! Str::markdown() !!}`; CSRF-exempt payment webhook with no signature check; open redirect on the locale switch; path traversal in the ticket download; `unserialize` of a request field; attendee JSON exposing `api_token` | `whereRaw` with bindings; user-chosen `orderBy` direction (Laravel validates it); `{!! !!}` on an `HtmlString` built from `e()`'d parts; delete with no policy but a query scoped to the user's own tickets |
| Symfony 8.1 shop | `access_control` limited to `methods: [POST, PUT, DELETE]` leaving `GET /manage/customers` public; IDOR on an order receipt (`ROLE_USER` only); DQL built by concatenation; serializer `OBJECT_TO_POPULATE` onto `User` without groups; an `is_safe` Twig filter that doesn't escape; SSRF in an image import; `Process::fromShellCommandline` with a request value; public `$this->json($user)` | `LIKE :term` with `%` in the parameter; user-chosen `orderBy` direction (doctrine/orm 3.7 validates it); `allow_extra_fields`; `json_encode\|raw` inside `<script>`; cancel with no voter but a query scoped to the current user |

The Symfony `orderBy` decoy is also a check on the skill itself: `rules/symfony/doctrine-and-twig.md` says Doctrine concatenates the direction in "ORM 2.20 and 3.x", but from ORM 3.7 `QueryBuilder::orderBy()` / `addOrderBy()` throw on any string other than `asc`/`desc` (`getSortDirection()`). Up to 3.6 it is injectable.

Add a planted issue or decoy by editing the fixture files (paths mirror the app) and adding it to the answer key with its line range. Keep line ranges in step when you edit a fixture file.

## Running

Needs `composer`, `git`, `php` and the `claude` CLI. No coverage driver or test tools: nothing in the app is run.

```
evals/run.sh            # both frameworks
evals/run.sh laravel    # one framework
MODEL=claude-sonnet-5-5 BUDGET_USD=3 evals/run.sh symfony
evals/match.sh evals/.work/results/<timestamp>   # re-match and re-score a saved run
```

The first run scaffolds the apps into `evals/.work/` (gitignored) and reuses them afterwards. Fixture edits are copied in on every run; delete a framework's folder to rebuild it after changing its packages or to pick up newer framework releases. Each run writes the reports (`<name>.report.txt`), raw `claude -p` output, matcher prompts and verdicts, `results.csv` and `classes.csv` to `evals/.work/results/<timestamp>/`.

**Cost:** 2 reviews per framework, each capped by `BUDGET_USD` (default 5), plus one matcher call per review capped by `MATCH_BUDGET_USD` (default 1). The matcher calls are small (key + report, no tools).

**Noise:** results vary between runs. Run each variant a few times before trusting a difference, and compare like with like (same model, same framework versions).

The skeletons ship a `CLAUDE.md`/`AGENTS.md` (Laravel Boost guidelines; Symfony's agent notes). They apply to both variants equally; leave them, or delete them in `evals/.work/<framework>` and recommit the baseline to measure the skill on its own. User-level skills, plugins and `CLAUDE.md` also apply to both variants.

### Windows

Run it from Git Bash. The script handles:

- **Path conversion:** Git Bash rewrites `/review-php-security ...` into a file path when passed to a native exe, so `claude` runs with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`.
- **Native php and MSYS paths:** a native Windows `php` can't open `/tmp/...` style paths, so php only gets paths relative to the folder it runs in.
- **laravel/pao:** Laravel 11+ skeletons ship it, and it switches PHPUnit/PHPStan to JSON output when it detects an agent. Nothing here parses their output, but `PAO_DISABLE=1` is exported anyway.

To test the plumbing without API spend, put a stub `claude` first on `PATH`. A `C:/...` entry breaks `PATH` on its colon, so add it as `export PATH="$(cygpath -u 'C:\path\to\stubs'):$PATH"`, and check `command -v claude` resolves to the stub before running, so the real CLI can't run by accident.

## First result, one sample

Laravel fixture only, one run per variant, default model (Opus 5.5), `BUDGET_USD=3`:

| Variant | Found | Recall | Decoys flagged | Unmatched | Cost | Turns | Minutes | Report words |
|---|---|---|---|---|---|---|---|---|
| `without` | 9/9 | 1.00 | 0/4 | 2 | $0.37 | 22 | 1.2 | 603 |
| `with` | 9/9 | 1.00 | 0/4 | 0 | $0.77 | 10 | 3.2 | 1736 |

- **Recall doesn't separate the variants yet:** both found every planted issue and called no decoy exploitable. The Laravel fixture is too easy for this model. Planting subtler issues (unscoped nested bindings, `validated()` with a bare `array` rule, `Rule::exists` used as an ownership check, `Htmlable` values in `{{ }}`) would give the skill room to show a difference.
- **Noise:** the baseline's two unmatched findings were a hardening note on the `orderBy` decoy (it said itself the direction isn't injectable) and missing rate limits on the webhook and review routes. The skill reported nothing outside the key and listed all four decoys as checked and safe, with the reason.
- **Cost:** the skill cost 2.1x more and took 2.7x longer, and its report was 2.9x longer, mostly the "Checked, not vulnerable" and "Not reviewed" sections.
- **Matcher:** both verdicts read right. It credited the baseline's "attendee list returns whole user records" for the `api_token` exposure, though that finding didn't name the token. On two hand-written reports it scored recall 0 on one that found nothing (it listed several planted issues as "checked, not vulnerable") and 4/4 decoys flagged on one that reported only the decoys. The four matcher calls cost $0.05-0.22 each.
