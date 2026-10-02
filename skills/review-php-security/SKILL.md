---
name: review-php-security
description: "Review PHP code for security vulnerabilities, with framework-specific checks for Laravel and Symfony: missing authorisation and IDOR, mass assignment, SQL/DQL and shell injection, unsafe unserialize, XSS in Blade and Twig, CSRF exemptions, file uploads, SSRF, open redirects, CORS, session and login handling, debug/secret exposure. Reports findings with file:line, a short exploit scenario, severity and the framework-idiomatic fix, plus what was checked and found safe. Use when the user asks to security-review, audit, pentest-review or 'check for vulnerabilities' in PHP code, a controller, a route file, a branch or a PR, e.g. 'is this controller safe?', 'security review this branch', 'any IDOR here?', 'check for SQL injection'. Read-only: never changes code or runs anything against a live system. Not for dependency CVE scanning (use composer audit), infrastructure or server hardening, or non-PHP code."
allowed-tools: Read, Glob, Grep, Bash(git diff:*), Bash(git status:*), Bash(git log:*), Bash(git ls-files:*)
---

# Review PHP Security

Review PHP code for vulnerabilities an attacker can actually reach, using the framework's real behaviour to separate findings from false alarms. This skill reports. It does not change code.

**Target to review:** $ARGUMENTS

If no target was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), review what changed: PHP, Blade, Twig and config files from `git diff --name-only main...HEAD` (use the repo's default branch) plus `git status --porcelain`, excluding `vendor/` and `tests/`. Include the routes, middleware and security config those files depend on. If nothing changed, ask for a target.

## Quality Standards

- Trace every candidate from source to sink before reporting it. A grep hit is a lead, not a finding.
- Read what sits in the path: route middleware, controller middleware and attributes, Form Requests, policies and voters, casts and mutators, escaping, security config.
- Report only what you can point to in the code. Say "needs runtime check" when the outcome depends on config or infrastructure you can't see.
- Report what you checked and found safe, with the reason, so the review's coverage is visible.
- Exploit scenarios describe the attack in one or two sentences. No working payloads, no scripts.

## Ground Rules

- Never run exploits, scanners or requests against any running system, local or remote.
- Never edit files. Fixes go in the report.
- Don't run project code (`artisan`, `bin/console`, tests). Read the files instead.

---

## Step 1: Stack and Scope

1. **Detect the stack** from `composer.json` / `composer.lock`: `laravel/framework`, `symfony/security-bundle`, `doctrine/orm`, `twig/twig`, `nelmio/cors-bundle`, and their versions. Several defaults changed between versions (`general/review-method.md`).
2. **List the entry points** in scope: routes and controllers, Livewire/Inertia endpoints, API routes, webhooks, console commands, queue jobs and message handlers that take external data.
3. **Read the security wiring** once: Laravel `bootstrap/app.php` (11+) or `app/Http/Kernel.php` and `app/Http/Middleware/*` (10), `routes/*.php`, `app/Policies`, `AuthServiceProvider` / `AppServiceProvider` gates, `config/cors.php`, `config/session.php`. Symfony `config/packages/security.yaml`, `framework.yaml`, `nelmio_cors.yaml`, `twig.yaml`, `src/Security/`.
4. **Read the rules** for the stack and target type (see Rules Reference).

## Step 2: Find Candidates

Work through each entry point and ask, for every input it takes (route parameters, query, body, headers, cookies, uploaded file names, values other users stored):

1. **Who may call this, and is that enforced?** Authentication, then authorisation of this user on this record.
2. **Which fields can the caller set?** Mass assignment and serializer/form binding.
3. **Where does the input end up?** SQL/DQL, shell, `unserialize`, templates, file paths, outbound URLs, redirects, logs.
4. **What does the surrounding config allow?** CSRF exemptions, CORS, debug mode, session and login settings.

## Step 3: Verify Each Candidate

For each candidate, follow the code path and confirm it with the checklist in `general/review-method.md`:

1. Is the source attacker-controlled on a route an attacker can reach?
2. Does anything on the path neutralise it (middleware, policy, validation rule, cast, binding, escaping)?
3. What does the attacker gain? That sets the severity.

Drop candidates that fail 1 or 2, and list them under "Checked, not vulnerable" with the reason.

## Step 4: Report

Print findings in severity order, then the checked list:

```
## Security Review: {target}

Stack: {framework + version, ORM, template engine}. Scope: {files / entry points reviewed}.

### Findings

#### [H1] {short title}
- **Location:** {path}:{line}
- **Class:** {e.g. Broken access control (IDOR)}
- **Severity:** Critical | High | Medium | Low
- **Confidence:** Confirmed | Likely | Needs runtime check
- **Path:** {source → what was checked on the way → sink}
- **Scenario:** {one or two sentences: who does what, and what they get}
- **Fix:** {framework-idiomatic change, with a short code snippet}

### Checked, not vulnerable
- {area} ({path}:{line}): {why it's safe, naming the mechanism}

### Not reviewed
- {anything in scope you couldn't assess, and why}
```

Number findings by severity: C1, H1, H2, M1, L1. If there are no findings, say so and keep the checked list.

---

## Troubleshooting

**Target not found.** Say which paths you searched and ask.

**Not PHP.** Say so and stop. Plain PHP or another framework: use `general/` and `php/` rules only and say the framework rules didn't apply.

**Codebase too large for one pass.** Start with unauthenticated routes, then anything handling money, files, admin functions or other users' data. List what was left under "Not reviewed".

**Outcome depends on what you can't see** (web server config, production `.env`, a bundle's entry point, a proxy in front of the app). Report it with confidence "Needs runtime check" and name exactly what to confirm.

**Known-vulnerable dependency suspected.** Out of scope for this skill. Suggest `composer audit`.

**User asks you to fix the findings.** Finish the report first. Fixing is a separate request: make one change per finding and keep behaviour otherwise identical.

---

## Example

```
User: /review-php-security app/Http/Controllers/InvoiceController.php

Step 1: Laravel 11.x, Eloquent, Blade. routes/web.php puts InvoiceController
        under ['auth', 'verified']. bootstrap/app.php: no CSRF exemptions.
        Base Controller is empty (no AuthorizesRequests). InvoicePolicy exists.

Step 2: download(Invoice $invoice) has no Gate/policy call, and the route has
        no can: middleware. index() uses orderBy($request->input('sort')).
        show.blade.php prints {!! $invoice->notes !!}.

Step 3: download: no authorisation anywhere on the path → IDOR, confirmed.
        orderBy: direction is validated by the builder, but the column is any
        string the user sends → sort by any column, confirmed (Low).
        notes: written by the customer in InvoiceNoteController, no purifier → stored XSS.

## Security Review: InvoiceController

#### [H1] Any signed-in user can download any invoice
- **Location:** app/Http/Controllers/InvoiceController.php:58
- **Class:** Broken access control (IDOR)
- **Severity:** High
- **Confidence:** Confirmed
- **Path:** GET /invoices/{invoice}/download → auth, verified → route model binding → download() → Storage::download
- **Scenario:** A customer changes the invoice ID in the URL and downloads other customers' invoices.
- **Fix:** `Gate::authorize('view', $invoice);` at the top of download(), or `->can('view', 'invoice')` on the route.

#### [H2] Stored XSS in invoice notes ...
#### [L1] Sort column taken from the query string ...

### Checked, not vulnerable
- CSRF on store/update: web group, no exemptions in bootstrap/app.php.
- Mass assignment in store(): uses $request->validated(); Invoice $fillable has no
  status, total or customer_id.
```

---

## Rules Reference

Paths are relative to `./rules/`. Read the ones that apply before reviewing.

### Always

- `general/review-method.md` - version differences, tracing, false-positive traps, severity, the checked list
- `php/injection.md` - shell, `unserialize`, `extract`, dynamic code, comparisons, randomness, URLs and paths

### By Target

| Target | Also read |
|---|---|
| **Laravel** routes, controllers, Form Requests, policies, gates | `laravel/authorisation.md` |
| **Laravel** models, controllers that create/update, API resources | `laravel/mass-assignment-and-exposure.md` |
| **Laravel** queries, repositories, Blade views, components, Markdown | `laravel/queries-and-views.md` |
| **Laravel** middleware config, uploads, storage, HTTP client, redirects, signed URLs, login, config, logging | `laravel/http-and-config.md` |
| **Symfony** controllers, `security.yaml`, voters, authenticators | `symfony/access-control.md` |
| **Symfony** form types, `#[MapRequestPayload]`, serializer use, API responses | `symfony/forms-and-serializer.md` |
| **Symfony** repositories, DQL/DBAL, Twig templates and extensions | `symfony/doctrine-and-twig.md` |
| **Symfony** uploads, HttpClient, redirects, CORS, `framework.yaml`, secrets, logging | `symfony/http-and-config.md` |

A full-application review reads all the rules for the detected framework.
