---
title: Laravel Mass Assignment and Data Exposure
tags: laravel, eloquent, mass-assignment, fillable, guarded, validated, hidden, api-resources
---

## Laravel Mass Assignment and Data Exposure

### How Eloquent Decides What Is Fillable

- `$fillable` lists the allowed columns. Anything else is silently dropped (or throws with `Model::preventSilentlyDiscardingAttributes()` / `Model::shouldBeStrict()`).
- Default `$guarded = ['*']` with an empty `$fillable`: `fill()` / `create()` **throws** `MassAssignmentException`. Not exploitable.
- `$guarded = []`, `Model::unguard()`, `Model::unguarded(fn () => ...)`, and in Laravel 13 the `#[Unguarded]` attribute: every column is fillable.
- `forceFill()`, `forceCreate()`: bypass the guard on purpose.
- Laravel 13 can declare these as attributes: `#[Fillable([...])]`, `#[Guarded([...])]`, `#[Hidden([...])]`, `#[Visible([...])]`. Read them as you would the properties.

The vulnerability is the combination: **request data you didn't filter** + **a model that accepts a sensitive column**. Sensitive columns: `role`, `is_admin`, `permissions`, `user_id` / `team_id` / `tenant_id` (ownership), `email_verified_at`, `status`, `balance`, `price`, `approved_at`.

**Incorrect:**
```php
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role'];
}

$request->user()->update($request->all());   // role=admin is accepted
```

**Correct:**
```php
$request->user()->update($request->safe()->only(['name', 'email']));
// and keep role out of $fillable; set it explicitly where an admin assigns it
$user->forceFill(['role' => $role])->save();
```

Treat `$request->all()`, `$request->input()` (no key), `$request->except([...])` and `$request->post()` as unfiltered. `except()` is a deny-list: new sensitive fields slip through.

### validated() Is Only as Narrow as the Rules

`$request->validated()` returns only keys that have rules. But a key with an `array` rule and no child rules (`settings.*`, `settings.theme`) returns the whole array.

**Incorrect:**
```php
'settings' => ['array'],
// validated() returns every key the client sent inside settings
$team->update(['settings' => $request->validated('settings')]);
```

**Correct:**
```php
'settings' => ['array:theme,locale'],      // only these keys allowed
'settings.theme' => ['in:light,dark'],
'settings.locale' => ['string', 'max:10'],
```

### Ownership From the Request

**Incorrect:**
```php
Project::create($request->validated());     // rules include 'team_id' => 'exists:teams,id'
```

**Correct:**
```php
$request->user()->currentTeam->projects()->create($request->safe()->except('team_id'));
```

`exists:teams,id` only proves the team exists. Set ownership columns from the authenticated context, not the payload.

### Data Exposure in Responses

Returning a model or collection from a controller serialises every attribute not in `$hidden` (or not in `$visible`, when set).

The default `User` model hides only `password` and `remember_token`. Columns added later (`two_factor_secret`, `two_factor_recovery_codes`, `api_token`, `stripe_id`, `reset_token`, internal notes) are exposed unless hidden.

**Incorrect:**
```php
return response()->json($user);
return User::where('team_id', $teamId)->get();     // every member's full row
```

**Correct:**
```php
return new UserResource($user);   // toArray() lists the fields explicitly
```

Also check:
- `$appends` accessors that compute sensitive values.
- Eager-loaded relations: `->load('owner')` serialises the owner's non-hidden attributes too.
- Arrays or models passed to front-end props, `@json(...)` or `Js::from(...)` in views: everything in them reaches the browser.
- `makeVisible()` calls in API code.
