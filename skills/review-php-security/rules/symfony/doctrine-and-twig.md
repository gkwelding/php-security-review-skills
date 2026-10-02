---
title: Doctrine and Twig (DQL/SQL Injection, Escaping Contexts, Template Injection)
tags: symfony, doctrine, dql, dbal, sql-injection, query-builder, twig, xss, autoescape, raw, markup, ssti
---

## Doctrine and Twig

### DQL Is Injectable Like SQL

String-built DQL lets an attacker change the query: read other entities' fields, add conditions, or use subqueries. Bind values with parameters.

**Incorrect:**
```php
$em->createQuery("SELECT u FROM App\Entity\User u WHERE u.email = '$email'");
$qb->where("p.title LIKE '%" . $term . "%'");
$qb->andWhere('o.status = ' . $request->query->get('status'));
```

**Correct:**
```php
$em->createQuery('SELECT u FROM App\Entity\User u WHERE u.email = :email')->setParameter('email', $email);
$qb->where('p.title LIKE :term')->setParameter('term', '%' . $term . '%');
```

### orderBy and Field Names

`QueryBuilder::orderBy($sort, $order)` / `addOrderBy()` concatenate `$sort` into the DQL unvalidated in every version. The direction is concatenated unvalidated too up to ORM 3.6; from 3.7 `orderBy()` / `addOrderBy()` throw `InvalidArgumentException` for anything but asc/desc, but `new Expr\OrderBy($sort, $order)` still concatenates it, so check how the order is built. Field names in `where()`, `select()` and `expr()` calls are DQL too.

**Incorrect:**
```php
$qb->orderBy('p.' . $request->query->get('sort'), $request->query->get('dir'));
```

**Correct:**
```php
$sort = match ($request->query->get('sort')) {
    'price' => 'p.price',
    'name' => 'p.name',
    default => 'p.createdAt',
};
$qb->orderBy($sort, $request->query->get('dir') === 'desc' ? 'DESC' : 'ASC');
```

### expr()->literal()

`$qb->expr()->literal($value)` quotes a value as a DQL string literal (doubling single quotes). It is escaping, not binding. It's acceptable for constants in your own code; for request input use `setParameter()`, which keeps value and query separate. `literal()` does nothing for identifiers: `$qb->expr()->eq($input, ':v')` with input in the field position is injectable.

### DBAL and Native SQL

`Connection::executeQuery()`, `executeStatement()`, `fetchAllAssociative()`, `createNativeQuery()` and the DBAL `QueryBuilder` take raw SQL. Same rule: placeholders plus the parameters array.

**Incorrect:**
```php
$conn->fetchAllAssociative("SELECT * FROM orders WHERE customer_id = $id");
```

**Correct:**
```php
$conn->fetchAllAssociative('SELECT * FROM orders WHERE customer_id = ?', [$id]);
```

For identifiers, use an allow-list. `quoteSingleIdentifier()` (DBAL 4; `quoteIdentifier()` is deprecated) stops breakout but still lets the user pick the column.

### Twig Escaping

TwigBundle's default autoescape strategy is `name`: chosen by template file extension. `*.html.twig` escapes for HTML, `*.js.twig` for JS, `*.css.twig` for CSS, and **`*.txt.twig` is not escaped at all**. A text template rendered into an HTML response or email body is XSS.

The `html` strategy escapes `& < > " '`. That covers HTML text and quoted attributes only.

| Context | Safe | Not safe |
|---|---|---|
| HTML text, quoted attribute | `{{ x }}` | `{{ x|raw }}`, `{% autoescape false %}` with user data |
| Unquoted attribute | `{{ x|e('html_attr') }}`, or quote it | `<div class={{ x }}>` |
| `href` / `src` | `{{ url }}` after a scheme check | `{{ url }}` alone: `javascript:` passes HTML escaping |
| Inside `<script>` | `{{ x|e('js') }}` inside a JS string; `{{ data|json_encode|raw }}` (PHP's `json_encode` escapes `/`, so `</script>` can't close the tag) | `'{{ x }}'` (HTML escaping is the wrong encoding) |
| Attribute holding JSON | `data-props="{{ data|json_encode }}"` | `data-props="{{ data|json_encode|raw }}"`: a `"` in the data ends the attribute |

### Values That Skip Escaping

- `|raw`, and `{% autoescape false %}` blocks
- `Twig\Markup` objects (`new Markup($html, 'UTF-8')`) returned from PHP
- Twig functions and filters declared with `'is_safe' => ['html']`: their output isn't escaped, so they must escape their inputs

**Incorrect:**
```php
new TwigFilter('highlight', fn (string $text, string $term) => str_replace($term, "<mark>$term</mark>", $text), ['is_safe' => ['html']]);
```

**Correct:**
```php
new TwigFilter('highlight', function (string $text, string $term): string {
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $term = htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return str_replace($term, "<mark>$term</mark>", $text);
}, ['is_safe' => ['html']]);
```

### Server-Side Template Injection

`$twig->createTemplate($string)` and the `template_from_string()` function (StringLoaderExtension) compile the string as a template. User-controlled template source can call any function or filter the environment exposes and read any variable in scope. Pass user content as variables, or render user-editable templates in a separate environment with Twig's `SandboxExtension` and a strict `SecurityPolicy`.
