---
title: Laravel HTTP Surface and Config (CSRF, CORS, Redirects, Signed URLs, Uploads, SSRF, Login, Debug, Secrets, Logging)
tags: laravel, csrf, cors, open-redirect, signed-urls, uploads, storage, path-traversal, ssrf, session, rate-limiting, trusted-proxies, app-debug, app-key, logging
---

## Laravel HTTP Surface and Config

### CSRF Exemptions

Where exemptions are declared depends on the version (`general/review-method.md`):

```php
// Laravel 10: app/Http/Middleware/VerifyCsrfToken.php
protected $except = ['stripe/*'];

// Laravel 11-12: bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['stripe/*']);
})

// Laravel 13: bootstrap/app.php (validateCsrfTokens() still works, deprecated)
$middleware->preventRequestForgery(except: ['stripe/*']);
```

Also look for `->withoutMiddleware(VerifyCsrfToken::class)` (or `ValidateCsrfToken` / `PreventRequestForgery`) on routes.

Check each exemption:
- **Webhook paths:** fine only if the handler verifies the provider's signature. An exempt webhook that trusts the payload is the finding.
- **Wildcards:** `except: ['api/*']` on routes in the `web` group exempts session-authenticated actions.
- **GET, HEAD and OPTIONS are never checked.** A state-changing `Route::get('/account/delete', ...)` is CSRF-able whatever the config says.
- **Laravel 13 `allowSameSite: true`:** requests with `Sec-Fetch-Site: same-site` pass without a token, so any sibling subdomain (including user-content or marketing subdomains) can forge requests.

Routes in the `api` group have no session and no CSRF middleware. That's correct for token auth.

### CORS

`config/cors.php` (or the framework default when unpublished) is enforced by `HandleCors` using fruitcake/php-cors.

**Incorrect:**
```php
'allowed_origins' => ['*'],
'supports_credentials' => true,
```

With credentials on and `*` in `allowed_origins`, the library reflects whatever `Origin` the browser sends. Any site can make credentialed requests and read the responses.

**Correct:**
```php
'allowed_origins' => [env('FRONTEND_URL')],
'supports_credentials' => true,
```

`allowed_origins_patterns` are passed to `preg_match` as written. An unanchored `'/example\.com/'` also matches `example.com.evil.net`. Use `'#^https://([a-z0-9-]+\.)?example\.com$#'`.

### Open Redirects

`redirect($url)`, `redirect()->to($url)` and `redirect()->away($url)` pass absolute URLs and `//host` URLs through unchanged. `back()` uses the `Referer` header first. `redirect()->intended()` reads `url.intended` from the session, which is safe unless the app writes input into it.

**Incorrect:**
```php
return redirect($request->input('next', '/dashboard'));
session(['url.intended' => $request->query('return')]);
```

**Correct:**
```php
$next = (string) $request->input('next');
$isLocalPath = str_starts_with($next, '/') && ! str_starts_with($next, '//') && ! str_contains($next, '\\');

return redirect($isLocalPath ? $next : route('dashboard'));
```

### Signed URLs

- A route that relies on an unguessable link must have the `signed` middleware or call `$request->hasValidSignature()`. Without it the signature is decoration.
- `URL::signedRoute()` links never expire. Use `URL::temporarySignedRoute()` for anything sent by email.
- Parameters listed in `validateSignatures(except: [...])` (11+) or `ValidateSignature::$except` (10) are left out of the signature check, so the client can change them freely. Make sure the action never reads them.
- Signatures use `APP_KEY`. Anyone with the key can sign any URL.

### File Uploads and Storage

Client-supplied, never trust: `getClientOriginalName()`, `getClientOriginalExtension()`, `clientExtension()`, `getClientMimeType()`.

| Rule | Checks |
|---|---|
| `mimes:jpg,png` | Extension guessed from the file **content** |
| `mimetypes:image/jpeg` | MIME type detected from content |
| `extensions:jpg,png` | The **client** extension only. Pair it with `mimes` |

All three reject client extensions `php`, `php3`-`php8`, `phtml` and `phar` unless `php` is in the list. That is a backstop, not an allow-list.

**Incorrect:**
```php
$request->validate(['avatar' => 'required|extensions:jpg,png']);
$request->file('avatar')->storeAs('avatars', $request->file('avatar')->getClientOriginalName(), 'public');
```

**Correct:**
```php
$request->validate(['avatar' => ['required', File::image()->max(2048)]]);
$path = $request->file('avatar')->store('avatars', 'public');   // random name, extension from content
```

- **`public` disk** (`storage/app/public`, served through the `public/storage` link): HTML and SVG stored there run script on the app's origin. Whether a stored `.php` executes depends on the web server: mark it "Needs runtime check".
- **Flysystem calls** (`Storage::get/put/download/delete`) throw on `..` that climbs above the disk root, but resolve `..` inside it: `invoices/../avatars/x` reaches other directories on the same disk. Build paths from IDs the user is authorised for.
- **`Storage::path($input)`** returns the absolute filesystem path. In Laravel 10-12 it does no normalisation, so `Storage::path('../../.env')` points outside the disk; fed to `response()->download()` or `response()->file()` that's arbitrary file read. Laravel 13 normalises and throws on traversal.
- `response()->download($path)` and `response()->file($path)` with an input-derived path are traversal in any version.
- The `local` disk root is `storage/app` in 10 and `storage/app/private` from 11.

### SSRF via the HTTP Client

`Http::get($url)` has no destination restrictions and follows up to 5 redirects by default (Guzzle). User-supplied URLs (webhook targets, "import from URL", avatar URLs, link previews, PDF renderers) can reach internal services and cloud metadata endpoints.

**Correct:**
```php
$host = parse_url($url, PHP_URL_HOST);
abort_unless(in_array($host, config('services.importer.allowed_hosts'), true), 422);

Http::withoutRedirecting()->timeout(5)->get($url);
```

If arbitrary hosts must be allowed, resolve the host, reject private, loopback, link-local and reserved ranges, and connect to the checked IP. Checking the name and then letting Guzzle resolve it again is open to DNS rebinding.

### Login, Sessions, Rate Limiting

- `Auth::attempt()` and `Auth::login()` regenerate the session ID. Custom login that writes `session(['user_id' => $id])` without `$request->session()->regenerate()` allows session fixation.
- Logout: `Auth::logout()`, `$request->session()->invalidate()`, `$request->session()->regenerateToken()`.
- `Auth::attempt()` runs inside a `Timebox`, so unknown emails and wrong passwords take similar time. Hand-written `User::where('email', ...)->first()` then `Hash::check()` with an early return leaks which emails exist (Low).
- Login, password reset, 2FA and OTP routes need `throttle:` middleware or `RateLimiter::attempt()`/`hit()`. From Laravel 11 the `api` group has no throttle unless `$middleware->throttleApi()` is called.
- `$middleware->trustProxies(at: '*')` (11+) or `$proxies = '*'` in `TrustProxies` (10) trusts `X-Forwarded-For` from anyone. If the app is reachable without going through the proxy, `$request->ip()` is spoofable, which defeats IP rate limits and IP allow-lists. "Needs runtime check".

### Debug Mode and Secrets

- **`APP_DEBUG=true` in production:** full exception pages with stack traces and source; JSON error responses include `exception`, `file`, `line` and `trace`. `config/app.php` defaults to `false`, `.env.example` sets `true`. Check deploy config, Dockerfiles and CI env, not just `.env.example`.
- **`APP_KEY` exposure** (committed `.env`, key literal in `config/app.php`, in CI logs): an attacker can forge and decrypt cookies, sessions (when `SESSION_ENCRYPT=true`), signed URLs and anything passed through `encrypt()`. With any `decrypt()` of client data it becomes object injection (`php/injection.md`). Rotate with `APP_PREVIOUS_KEYS` (11+) after a leak.
- **`.env` in the web root:** the document root must be `public/`. "Needs runtime check" unless the server config is in the repo.
- **Secrets in code:** API keys, private keys and passwords as literals in `config/*.php`, service classes or seeders instead of `env()` in config.

### Logging

**Incorrect:**
```php
Log::info('Login attempt', $request->all());          // password in the log
Log::debug('OAuth token response', $response->json()); // access and refresh tokens
```

**Correct:**
```php
Log::info('Login attempt', ['email' => $request->input('email')]);
```

- Input flashed to the session after a validation failure excludes only the `dontFlash` list (`current_password`, `password`, `password_confirmation`). Add other secrets with `$exceptions->dontFlash([...])` (11+) or the handler's `$dontFlash` (10).
- `#[\SensitiveParameter]` on parameters keeps their values out of stack traces.
