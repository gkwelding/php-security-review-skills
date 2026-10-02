---
title: Laravel Queries and Views (SQL Injection, Blade XSS, Markdown, Template Injection)
tags: laravel, sql-injection, query-builder, eloquent, raw, blade, xss, markdown, json, htmlstring
---

## Laravel Queries and Views

### What the Query Builder Binds and What It Doesn't

| Bound (safe for input) | Not bound (input here is injection or worse) |
|---|---|
| Values in `where('col', $v)`, `whereIn('col', $vs)`, `insert`/`update` arrays, the `$bindings` argument of `*Raw` methods | The SQL string of `DB::raw`, `whereRaw`, `orWhereRaw`, `selectRaw`, `havingRaw`, `orderByRaw`, `groupByRaw`, `fromRaw`, `DB::select/statement/unprepared` |
| | Column and table names anywhere |

**Incorrect:**
```php
User::whereRaw("email = '{$request->email}'")->first();
DB::select("select * from orders where status = '$status'");
Order::selectRaw("sum({$request->input('field')}) as total")->get();
```

**Correct:**
```php
User::whereRaw('email = ?', [$request->email])->first();
DB::select('select * from orders where status = ?', [$status]);
```

### Column Names from Input

Identifiers are quoted (`"col"`, or backticks on MySQL), not bound. Quoting stops breaking out of the identifier, but the user still chooses *which* column: sorting or filtering on `password`, `remember_token` or `two_factor_secret` leaks those values one comparison at a time. The grammar also interprets `a as b` aliases and `col->path` JSON selectors in names.

`orderBy()` validates the direction (anything but `asc`/`desc` throws), so only the column is a concern.

**Incorrect:**
```php
$query->orderBy($request->input('sort', 'created_at'), $request->input('dir', 'asc'));
$query->where($request->input('filter'), $request->input('value'));
```

**Correct:**
```php
$request->validate(['sort' => ['nullable', Rule::in(['created_at', 'total', 'status'])]]);
$query->orderBy($request->input('sort', 'created_at'), $request->input('dir') === 'desc' ? 'desc' : 'asc');
```

### Unique Rule ignore()

Never pass request input to `Rule::unique(...)->ignore(...)`. Pass the model being updated (`->ignore($this->route('user'))`) or its key from the server side. Otherwise the client chooses which row to exclude and can bypass the uniqueness check.

### Blade Output Contexts

`{{ $x }}` runs `e()`: `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. That is correct for HTML text and **quoted** attributes, and wrong elsewhere.

| Context | Safe | Not safe |
|---|---|---|
| HTML text, quoted attribute | `{{ $x }}` | `{!! $x !!}` with user data |
| `href` / `src` | `{{ $url }}` **after** checking the scheme is `http`/`https` | `{{ $url }}` alone: `javascript:` URLs pass `e()` unchanged |
| Inside `<script>` | `@json($data)`, `{{ Js::from($data) }}` | `'{{ $x }}'`: HTML-escaping is the wrong encoding for JS |
| Attribute holding JSON (`x-data`, `data-*`) | `@json($data)` (uses `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT`) or `{{ json_encode($data) }}` | `{!! json_encode($data) !!}`: a `"` in the data ends the attribute |
| Unquoted attribute | Quote it | `<div class={{ $x }}>` |

**Incorrect:**
```blade
<div x-data="{!! json_encode($filters) !!}"></div>
<a href="{{ $user->website }}">Website</a>
<script>const name = '{{ $user->name }}';</script>
```

**Correct:**
```blade
<div x-data="@json($filters)"></div>
<a href="{{ Str::startsWith($user->website, ['https://', 'http://']) ? $user->website : '#' }}">Website</a>
<script>const name = @json($user->name);</script>
```

### Values That Skip Escaping

`e()` returns `toHtml()` unchanged for any `Htmlable`. So these print raw HTML even inside `{{ }}`:

- `new HtmlString($x)` / `str($x)->toHtmlString()`
- Any class implementing `Illuminate\Contracts\Support\Htmlable`, including `Illuminate\View\View` (a `view(...)` passed as data) and `Illuminate\Support\Js`

**Incorrect:**
```php
public function bioHtml(): HtmlString
{
    return new HtmlString(nl2br($this->bio));   // bio is user input
}
```

**Correct:**
```php
return new HtmlString(nl2br(e($this->bio)));
```

### Str::markdown Allows Raw HTML by Default

`Str::markdown()` and `Str::inlineMarkdown()` use league/commonmark with its defaults: `html_input` is `allow` and `allow_unsafe_links` is `true`. User-written Markdown rendered with `{!! Str::markdown($post->body) !!}` is stored XSS.

**Correct:**
```php
Str::markdown($post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
```

### Server-Side Template Injection

`Blade::render($string, $data)` compiles the string as a Blade template, which is PHP. Input in `$string` is RCE. Pass user content as `$data`, never as the template.

**Incorrect:**
```php
Blade::render($settings->email_template, ['user' => $user]);   // template editable by tenants
```

**Correct:** use a placeholder replacement (`strtr($template, [':name' => e($user->name)])`) or a sandboxed engine for user-editable templates.

### Uploaded SVG and HTML

The `image` rule accepts SVG in Laravel 10 and 11. In 12 and 13 it doesn't unless `image:allow_svg` is given. SVG and HTML files served from the app's own origin run script in that origin. See `http-and-config.md` for storage.
