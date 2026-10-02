---
title: Symfony HTTP Surface and Config (Redirects, SSRF, Uploads, CORS, Signed URLs, Proxies, Debug, Secrets, Logging)
tags: symfony, open-redirect, ssrf, http-client, uploads, cors, nelmio, uri-signer, trusted-proxies, app-secret, debug, logging
---

## Symfony HTTP Surface and Config

### Open Redirects

`$this->redirect($url)` and `new RedirectResponse($url)` redirect anywhere. The security component's own login and logout redirects (`_target_path`, `target_path_parameter`) go through `HttpUtils`, which replaces absolute URLs to other hosts (outside the session cookie domain) with `/`. Controller code gets no such check.

**Incorrect:**
```php
return $this->redirect($request->query->get('next', '/'));
```

**Correct:**
```php
$next = (string) $request->query->get('next');
$isLocalPath = str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\');

return $this->redirect($isLocalPath ? $next : $this->generateUrl('app_home'));
```

### SSRF via HttpClient

`HttpClientInterface::request()` has no destination restrictions and follows up to 20 redirects by default (`max_redirects`). For user-supplied URLs, wrap the client in `NoPrivateNetworkHttpClient`, which blocks private, loopback and link-local ranges (`IpUtils::PRIVATE_SUBNETS`) and re-checks the resolved IP on each redirect.

**Incorrect:**
```php
$this->httpClient->request('GET', $dto->webhookUrl);
```

**Correct:**
```php
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

$client = new NoPrivateNetworkHttpClient($this->httpClient);
$client->request('GET', $dto->webhookUrl, ['max_redirects' => 0, 'timeout' => 5]);
```

Where the destinations are known, a host allow-list is stricter still.

### File Uploads

`UploadedFile::getClientOriginalName()`, `getClientOriginalExtension()` and `getClientMimeType()` are client-supplied. `getClientOriginalName()` strips directories, but keeps the attacker's extension. `guessExtension()` and `getMimeType()` come from the file content.

**Incorrect:**
```php
$file->move($this->getParameter('kernel.project_dir') . '/public/uploads', $file->getClientOriginalName());
```

**Correct:**
```php
#[Assert\File(maxSize: '2M', extensions: ['jpg', 'png'])]   // checks the extension and the detected MIME type
public ?UploadedFile $avatar = null;

$name = bin2hex(random_bytes(16)) . '.' . $file->guessExtension();
$file->move($this->getParameter('uploads_dir'), $name);   // outside public/, served by a controller
```

The `File` constraint's `extensions` option checks the client extension **and** that the detected MIME type matches it. `mimeTypes` alone checks only content. Files stored under `public/` are served directly: HTML and SVG run script on the app's origin, and whether `.php` executes depends on the web server ("Needs runtime check").

### CORS (nelmio/cors-bundle)

**Incorrect:**
```yaml
nelmio_cors:
    defaults:
        allow_origin: ['*']
        allow_credentials: true
```

With `allow_origin: ['*']`, the listener sends back the request's own `Origin`, and with `allow_credentials: true` any site can make credentialed requests and read the responses.

With `origin_regex: true`, each `allow_origin` entry is matched with `preg_match('{' . $pattern . '}i', ...)`, unanchored. `'example\.com'` also matches `example.com.evil.net`; write `'^https://(.+\.)?example\.com$'`.

### Signed URLs and APP_SECRET

`UriSigner` (`uri_signer` service) signs with `kernel.secret` (`APP_SECRET`). So do remember-me cookies and login links by default. A leaked `APP_SECRET` lets an attacker forge all three.

- A controller serving a signed link must call `$uriSigner->checkRequest($request)` (or `verify()`, in 8.x) itself.
- In 7.4 and 8.x the signer accepts an expiry (`sign($uri, $expiration)`); 6.4 doesn't. Links without one never expire.

### Trusted Proxies

`framework.trusted_proxies` set to a broad range (or `REMOTE_ADDR` via `TRUSTED_PROXIES`) while the app is reachable directly makes `$request->getClientIp()`, `isSecure()` and `getHost()` spoofable through `X-Forwarded-*`. That defeats IP-based rate limits (`login_throttling` keys on the client IP) and IP allow-lists. "Needs runtime check".

### Debug Mode and Secrets

- **`APP_ENV=dev` or `APP_DEBUG=1` in production:** exception pages with stack traces and source; with web-profiler-bundle enabled for that environment, `/_profiler` exposes requests, headers, sessions and config. Check deployment env files, Dockerfiles and CI, not just `.env`.
- **Secrets committed:** `APP_SECRET`, database URLs and API keys in `.env` (committed by design for defaults) must be dev-only values; real values belong in `.env.local` (git-ignored), real environment variables, or `secrets:set` vaults. A production-looking secret in committed `.env` or `config/packages/*.yaml` is a finding.
- **Document root** must be `public/`, not the project root.

### Logging

**Incorrect:**
```php
$this->logger->info('Payment request', $request->request->all());
$this->logger->debug('Token exchange', ['response' => $response->toArray()]);
```

**Correct:**
```php
$this->logger->info('Payment request', ['orderId' => $order->getId()]);
```

`#[\SensitiveParameter]` keeps argument values out of stack traces.
