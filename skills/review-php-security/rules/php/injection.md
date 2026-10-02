---
title: PHP Injection Sinks (Shell, Unserialize, Dynamic Code, Comparisons, Randomness, URLs, Paths)
tags: php, injection, shell, process, unserialize, extract, eval, hash_equals, random, ssrf, path-traversal
---

## PHP Injection Sinks

Sinks that exist in any PHP code, with the framework wrappers that change how they behave.

### Shell Commands

`exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open` with a string, and backticks all run through a shell.

`escapeshellcmd()` on a whole command line does not stop extra arguments: `escapeshellcmd('report.txt --output=/tmp/x')` comes back unchanged. Escape each argument with `escapeshellarg()`, or better, avoid the shell.

**Laravel `Process`:** a string goes through `Process::fromShellCommandline()` (a shell); an array does not.

**Incorrect:**
```php
Process::run("convert {$request->input('file')} out.png");
```

**Correct:**
```php
Process::run(['convert', $path, 'out.png']); // $path from an allow-list or a stored file, not raw input
```

**Symfony `Process`:** `new Process([...])` takes an argument array, no shell. If a shell is needed, pass input as environment placeholders, which Symfony quotes:

```php
$process = Process::fromShellCommandline('grep -- "${:PATTERN}" data.log');
$process->run(null, ['PATTERN' => $pattern]);
```

An argument array still allows option injection when the value starts with `-`. Put `--` before user values where the program supports it.

### Unserialize

`unserialize()` on anything an attacker can influence instantiates arbitrary classes and runs their magic methods (object injection; gadget chains in common packages make this RCE).

**Incorrect:**
```php
$prefs = unserialize($request->cookie('prefs'));
$prefs = unserialize(base64_decode($request->input('state')));
```

**Correct:**
```php
$prefs = json_decode($request->cookie('prefs'), true, 512, JSON_THROW_ON_ERROR);
// If serialize() is unavoidable:
$prefs = unserialize($data, ['allowed_classes' => false]);
```

**Laravel specifics:**
- `decrypt()` / `Crypt::decrypt($payload)` unserializes by default (`$unserialize = true`). Data encrypted with `encrypt()` is safe only while `APP_KEY` stays secret; a leaked key turns every `decrypt()` of client-supplied data into object injection. Use `encryptString()` / `decryptString()` for strings.
- Laravel's own cookie encryption does not serialize (`EncryptCookies::$serialize = false`). Flag a subclass that sets it to `true`.
- Cache stores unserialize values. Laravel 13's `config/cache.php` ships `'serializable_classes' => false`; when the key is absent (older skeletons, upgraded apps), any class is allowed. That matters when anyone else can write to the cache backend (a shared Redis, the database store).

### extract, Variable Variables, Dynamic Calls

**Incorrect:**
```php
extract($request->all());          // overwrites $isAdmin, $userId, ...
$handler = $request->input('action');
$handler($payload);                // calls any function
$class = $request->input('type');
new $class($data);                 // instantiates any autoloadable class
include $request->input('page') . '.php';
```

**Correct:** map input to a fixed set.

```php
$handler = match ($request->input('action')) {
    'archive' => $this->archive(...),
    'restore' => $this->restore(...),
    default => abort(422),
};
```

`eval()` with any input-derived string is RCE.

### Comparisons on Secrets

`==` on two numeric-looking strings compares them as numbers: `'0e1234' == '0e5678'` is `true` (PHP 8.5). Hashes and tokens that start with `0e` followed by digits compare equal.

**Incorrect:**
```php
if ($request->input('token') == $user->reset_token) { ... }
if (md5($input) == $storedHash) { ... }
```

**Correct:**
```php
if (hash_equals($user->reset_token, (string) $request->input('token'))) { ... }
```

`hash_equals` is also constant-time. Use `password_verify()` / `Hash::check()` / Symfony's `UserPasswordHasherInterface::isPasswordValid()` for passwords, never a direct comparison.

Allow-lists need strict mode: `in_array('1e1', ['10'])` is `true`; `in_array('1e1', ['10'], true)` is `false`.

### Randomness for Secrets

`rand()`, `mt_rand()`, `uniqid()`, `str_shuffle()` and `lcg_value()` are predictable. Tokens, reset codes, API keys and filenames that must not be guessed use `random_bytes()`, `random_int()`, Laravel `Str::random()` (backed by `random_bytes`) or Symfony `ByteString::fromRandom()`.

### URLs from Input

`filter_var($url, FILTER_VALIDATE_URL)` accepts `javascript:` URLs, so it is not a safe check for links or redirects. `parse_url('https://good.example@evil.example/')['host']` is `evil.example`, so check the parsed host, not a string prefix.

**Incorrect:**
```php
if (str_starts_with($url, 'https://app.example.com')) { return redirect($url); }
```

**Correct:**
```php
$parts = parse_url($url);
if (($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'app.example.com') { ... }
```

### File Paths from Input

`file_get_contents`, `fopen`, `readfile`, `include` and `unlink` accept `../` and, for reads, stream wrappers (`php://`, and `http://` when `allow_url_fopen` is on).

**Correct:**
```php
$base = realpath(storage_path('exports'));
$path = realpath($base . DIRECTORY_SEPARATOR . basename($name));
if ($path === false || ! str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    abort(404);
}
```

Prefer looking files up by a database ID the user is authorised for, rather than by name.
