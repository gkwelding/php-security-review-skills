# PHP Security Review Skills

An agent skill for reviewing PHP code for security vulnerabilities, with specific rules for Laravel and Symfony.

It is for defensive review of your own code. The rules cover the framework behaviour models usually get wrong: where Laravel 10, 11-12 and 13 keep their CSRF exemptions, which bindings are scoped, what `validated()` actually lets through, which Twig templates aren't escaped, what Doctrine concatenates into DQL. Each finding is traced through the real code path (middleware, policies, voters, casts, escaping) before it is reported, and the report lists what was checked and found safe as well as what wasn't.

**Map entry points → Trace → Verify → Report**

## Skills

| Skill | What it does |
|---|---|
| `review-php-security` | Reviews a file, directory, branch or whole app. Reports each finding with file:line, vulnerability class, a one- or two-sentence exploit scenario, severity, confidence and the framework-idiomatic fix, then a "checked, not vulnerable" list. Read-only. |

Fixing is left to a normal follow-up request. A separate fix skill can be added if the review proves useful on its own.

## What's Covered

**All PHP:** shell commands (`exec`, `shell_exec`, `Process` string vs array), `unserialize` and object injection, `extract`, dynamic calls and includes, `eval`, loose comparison on secrets (`hash_equals`), predictable randomness, URL and path checks that don't hold (`filter_var`, `parse_url` userinfo, `../`).

**Laravel:** every place authorisation can live (route `can`, `HasMiddleware`, `#[Authorize]` in 13, `Gate::authorize`, Form Request `authorize()`), IDOR via route model binding, when nested bindings are and aren't scoped, `authorizeResource` gaps, guest handling in policies, mass assignment (`$fillable`, `$guarded = []`, `unguard`, 13's model attributes, `validated()` with bare `array` rules, ownership from the payload), exposed attributes in responses, raw query methods and unbound identifiers, `Rule::unique()->ignore()`, Blade output contexts (`{!! !!}`, `href`, `<script>`, `@json` vs `json_encode` in attributes), `Htmlable` bypassing `e()`, `Str::markdown` defaults, `Blade::render` template injection, CSRF exemptions per version (including 13's `preventRequestForgery` and `allowSameSite`), CORS `*` with credentials, open redirects, signed URLs, upload validation rules (`mimes` vs `extensions`), public disk, `Storage::path` traversal (10-12), SSRF through `Http::`, session fixation, login timing, rate limiting, `trustProxies(at: '*')`, `APP_DEBUG`, `APP_KEY` exposure, logging secrets.

**Symfony:** `access_control` first-match and regex semantics, `security: false` firewalls, `#[IsGranted]` scope and `methods:`, IDOR via entity value resolvers, voter strategy defaults, remember-me vs `IS_AUTHENTICATED_FULLY`, `form_login.enable_csrf` and logout CSRF defaults, `login_throttling`, session fixation strategy, form binding (and why `allow_extra_fields` isn't mass assignment), form CSRF, serializer and `#[MapRequestPayload]` into entities, groups and data exposure, DQL/DBAL injection, unvalidated `orderBy` direction, `expr()->literal()`, Twig's per-extension autoescape (`.txt.twig`), escaping contexts, `Markup` and `is_safe`, `createTemplate` / `template_from_string`, redirects, `NoPrivateNetworkHttpClient`, upload handling and the `File` constraint, nelmio CORS, `UriSigner` and `APP_SECRET`, trusted proxies, debug mode, logging.

## Install

### Claude Code plugin

```
/plugin marketplace add gkwelding/php-security-review-skills
/plugin install php-security-review-skills@php-security-review-skills
```

The command becomes `/php-security-review-skills:review-php-security <target>`.

### Copy into a project or user skills folder

```
cp -r skills/review-php-security ~/.claude/skills/
# or per project:
cp -r skills/review-php-security .claude/skills/
```

### claude.ai

Build the package, then upload `dist/review-php-security.skill` (Settings → Capabilities → Skills):

```
sh scripts/build-skills.sh
```

The script packages the committed files at `HEAD`; commit edits first.

## Usage

```
/review-php-security app/Http/Controllers/InvoiceController.php
/review-php-security src/Controller/Api/
/review-php-security routes/web.php
/review-php-security          # no target: the files changed on this branch
```

## Ground Rules the Skill Enforces

- Never runs exploits, scanners or requests against any running system, local or remote
- Never changes code during a review; fixes are written into the report
- Doesn't run project code (`artisan`, `bin/console`, tests); `allowed-tools` is limited to reading files and read-only git commands
- Exploit scenarios describe the attack in a sentence or two; no working payloads
- A grep hit is a lead, not a finding: each one is traced from source to sink, and anything that depends on unseen config is marked "Needs runtime check"

## Layout

```
skills/
└── review-php-security/
    ├── SKILL.md
    └── rules/
        ├── general/     # where wiring lives per version, tracing, false-positive traps, severity
        ├── php/         # injection sinks in any PHP code
        ├── laravel/     # authorisation, mass assignment/exposure, queries/views, HTTP surface/config
        └── symfony/     # access control, forms/serializer, Doctrine/Twig, HTTP surface/config
scripts/build-skills.sh  # packages dist/*.skill for claude.ai
evals/                   # with/without-skill comparison on Laravel and Symfony fixtures
```

## Status

First version. The APIs, config keys, defaults and version differences the rules name were checked against the sources of Laravel 10.50, 11.57, 12.69 and 13.34 (plus their skeletons), Symfony 6.4, 7.4 and 8.1, Doctrine ORM 2.20 and 3.7, DBAL 4.5, Twig 3.30, fruitcake/php-cors 1.4, nelmio/cors-bundle 2.6 and league/commonmark 2.10. Treat it as a strong checklist, not a guarantee.

## Evals

[`evals/`](evals/README.md) reviews a small Laravel 13 app and a small Symfony 8.1 app with and without the skill. Each app has 8-9 planted vulnerabilities and 4-5 safe look-alikes, listed in an answer key. A blind, tool-less matcher call maps each report to the key, and each run is scored on recall (overall and per class), decoys flagged, findings matching nothing, cost, turns, time and report length.

First result, one sample on the Laravel app with the default model: both variants found all 9 planted issues and flagged no decoy. The skill reported nothing outside the key (the baseline had 2 hardening notes) and listed every decoy as checked and safe, but cost 2.1x more ($0.77 against $0.37). The Laravel fixture is too easy to separate the variants on recall yet.

## Licence

MIT. See [LICENSE](LICENSE).
