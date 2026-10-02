---
title: Review Method (Versions, Tracing, False Positives, Severity)
tags: review, method, verification, severity, false-positives, laravel, symfony
---

## Review Method

How to turn grep hits into findings that hold up, and how to report what was checked.

### 1. Where the Security Wiring Lives, by Version

Read the file for the installed version. Looking in the wrong place is the commonest cause of "no CSRF exemptions found" when there are some.

| Version | CSRF exemptions | Middleware, proxies, throttling | Base controller |
|---|---|---|---|
| Laravel 10 | `app/Http/Middleware/VerifyCsrfToken.php` `$except` | `app/Http/Kernel.php`; `api` group includes `throttle:api` | Uses `AuthorizesRequests`, so `$this->authorize()` exists |
| Laravel 11-12 | `bootstrap/app.php`: `$middleware->validateCsrfTokens(except: [...])` | `bootstrap/app.php` `withMiddleware()`; the `api` group has no throttle unless `$middleware->throttleApi()` | Empty abstract class: no `$this->authorize()` unless the trait was added |
| Laravel 13 | `bootstrap/app.php`: `$middleware->preventRequestForgery(except: [...], originOnly: ..., allowSameSite: ...)`; `validateCsrfTokens()` is a deprecated alias | As 11-12 | As 11-12; `#[Authorize]` controller attributes also exist |
| Symfony 6.4-8.x | Per form (`csrf_protection` option), `framework.csrf_protection`, `form_login.enable_csrf` | `config/packages/security.yaml`, `framework.yaml` (`trusted_proxies`) | n/a |

Laravel 11+ apps upgraded from 10 may still have `app/Http/Kernel.php`. Check which one `bootstrap/app.php` actually uses.

`config/cors.php` is in the Laravel 10 skeleton. From 11 it exists only if published; otherwise the framework's default applies (`allowed_origins` `['*']`, `supports_credentials` false, paths `api/*` and `sanctum/csrf-cookie`).

### 2. Trace Source → Path → Sink

For each candidate, write down three things before calling it a finding:

- **Source:** Is the value attacker-controlled? Route parameters, query, body, headers (including `Referer`, `X-Forwarded-*`), cookies, uploaded file names and MIME types, and anything another user stored. Values from config, the authenticated user's own ID, or server-generated IDs are not.
- **Path:** Can an attacker reach it, and what runs on the way? Route group middleware, controller middleware/attributes, Form Request `rules()` and `authorize()`, policies/voters, model casts and mutators, global scopes.
- **Sink:** What the value does there, and whether the sink neutralises it (bindings, escaping, allow-lists).

Follow calls into services, repositories, traits, base classes and Blade/Twig partials. A controller that looks bare may inherit a constructor that registers middleware.

### 3. False-Positive Traps

These look vulnerable and usually aren't:

- **Laravel `orderBy($column, $direction)`:** the direction is validated (anything but `asc`/`desc` throws). Only a user-chosen *column* is a finding.
- **Eloquent model with no `$fillable` and the default `$guarded = ['*']`:** `create($request->all())` throws `MassAssignmentException`. It's a bug, not a vulnerability.
- **Form Request with no `authorize()` method:** treated as `true`, same as `return true;`. Only a finding when nothing else on the path authorises.
- **Symfony `allow_extra_fields: true`:** extra fields are kept in `getExtraData()` and never mapped to the object. It suppresses the validation error, nothing more.
- **Symfony `framework.csrf_protection.stateless_token_ids` (present in 7.4 and 8.x, not 6.4):** those token IDs are validated by `SameOriginCsrfTokenManager` (Sec-Fetch-Site / Origin / Referer checks). It is not "CSRF disabled".
- **Laravel 13 `PreventRequestForgery`:** a request with `Sec-Fetch-Site: same-origin` passes without a token. That is intended.
- **Blade `{{ }}` and Twig `{{ }}` in HTML text or a quoted attribute:** escaped. Check the context (`laravel/queries-and-views.md`, `symfony/doctrine-and-twig.md`).
- **Unreachable code:** tests, seeders, factories, migrations, commands only an operator runs with operator-supplied input.

### 4. Severity

| Severity | Typical cases |
|---|---|
| **Critical** | Unauthenticated RCE, SQL injection, or full auth bypass; leaked `APP_KEY`/`APP_SECRET` with an unserialize sink |
| **High** | Authenticated RCE or SQL injection; IDOR on sensitive data or writes; privilege escalation via mass assignment; stored XSS reachable by other users; SSRF to internal services |
| **Medium** | Reflected XSS; CSRF on a state-changing action; open redirect used in a login flow; CORS reflecting origins with credentials; debug mode in production |
| **Low** | Sorting/filtering on unintended columns; user enumeration by timing or message; missing rate limit on non-login endpoints; logout CSRF |

Adjust for reach (unauthenticated vs admin-only) and data sensitivity. Say why when you move a finding up or down.

### 5. Confidence

- **Confirmed:** the whole path is in the code you read.
- **Likely:** one link depends on something conventional but unseen (e.g. a value "probably" comes from the request via a service you couldn't find).
- **Needs runtime check:** depends on production config, web server rules or infrastructure. Name the exact thing to check.

### 6. Checked, Not Vulnerable

List every area you reviewed and found safe, with the mechanism that makes it safe:

```
- Mass assignment in UserController@update (app/Http/Controllers/UserController.php:41):
  uses $request->safe()->only(['name', 'email']).
- XSS in profile.blade.php: all output via {{ }}; bio is plain text.
- CSRF: no exemptions in bootstrap/app.php.
```

This tells the reader what coverage the review had. An empty findings list with no checked list is not a review.
