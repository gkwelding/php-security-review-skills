---
title: Laravel Authorisation (Policies, Gates, Form Requests, Route Model Binding, IDOR)
tags: laravel, authorisation, policies, gates, form-request, route-model-binding, scope-bindings, idor
---

## Laravel Authorisation

Authentication (`auth` middleware) says who the user is. Authorisation says whether they may touch *this* record. Most Laravel IDORs are routes with the first and not the second.

### Where Authorisation Can Live

Before reporting "no authorisation", check every one of these on the request's path:

| Place | What it looks like |
|---|---|
| Route | `->can('update', 'post')`, `->middleware('can:update,post')`, a group with `can:` |
| Controller (10) | `$this->middleware('can:...')` or `$this->authorizeResource(Post::class, 'post')` in the constructor |
| Controller (11+) | `implements HasMiddleware` with `public static function middleware(): array { return [new Middleware('can:update,post', only: ['update'])]; }` |
| Controller (13) | `#[Authorize('update', 'post')]` on the class or method (`Illuminate\Routing\Attributes\Controllers\Authorize`) |
| Action | `Gate::authorize('update', $post)`, `$this->authorize(...)` (needs the `AuthorizesRequests` trait), `abort_unless($request->user()->can('update', $post), 403)` |
| Form Request | `authorize()` calling `$this->user()->can(...)` |
| Query | A global scope or relation that limits rows to the user/tenant (`$request->user()->posts()->findOrFail($id)`) |

`$this->authorize()` exists only when the base controller uses `AuthorizesRequests`. The Laravel 11+ skeleton's `App\Http\Controllers\Controller` is empty, so look for `Gate::authorize()` instead.

### IDOR via Route Model Binding

Implicit binding finds the record by ID. It does not check who owns it.

**Incorrect:**
```php
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('auth');

public function show(Invoice $invoice)
{
    return view('invoices.show', ['invoice' => $invoice]);
}
```

**Correct:**
```php
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
    ->middleware('auth')
    ->can('view', 'invoice');
```

The same applies to `find($request->input('id'))`, `whereKey(...)`, and IDs inside JSON bodies (`order_id` on a refund request).

### authorizeResource Covers Resource Methods Only (Laravel 10)

`authorizeResource()` maps `index`, `show`, `create`, `store`, `edit`, `update` and `destroy` to policy abilities. Extra actions on the same controller (`download`, `duplicate`, `publish`) get nothing. Check each non-resource method separately.

### Form Request authorize()

A Form Request with no `authorize()` method is treated as returning `true`. `return true;` is common and fine **when** the route or controller authorises. It is a finding only when nothing else on the path does.

**Incorrect:**
```php
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    // controller: $project->update($request->validated()); no other check
}
```

**Correct:**
```php
public function authorize(): bool
{
    return $this->user()->can('update', $this->route('project'));
}
```

### Nested Routes: Scoped Bindings

On `/users/{user}/posts/{post}`, the `post` is **not** constrained to the `user` by default. Laravel scopes the child binding only when:

- the child uses a custom key (`{post:slug}`), or
- the route or group calls `->scopeBindings()`

and never when `->withoutScopedBindings()` is set.

**Incorrect:**
```php
Route::get('/teams/{team}/projects/{project}', [ProjectController::class, 'show']);
// /teams/1/projects/999 loads project 999 even if it belongs to team 7
```

**Correct:**
```php
Route::scopeBindings()->group(function () {
    Route::get('/teams/{team}/projects/{project}', [ProjectController::class, 'show'])
        ->can('view', 'team');
});
```

Scoping proves the project belongs to the team in the URL. It doesn't prove the user belongs to that team. You still need a policy check on the parent.

### Guests and Policies

Laravel denies guests before calling a policy method or gate unless its first parameter is nullable (`?User $user`) or defaults to `null`. A nullable signature is a deliberate opt-in to guest access: check that the method handles `null` safely.

### Gate::before

A `Gate::before()` callback that returns non-null decides every ability, skipping policies. Check its condition is as narrow as intended (`$user->isSuperAdmin()`, not `$user->is_staff` or a role a user can set on their own profile; see `mass-assignment-and-exposure.md`).

### Things That Aren't Authorisation

- `auth`, `verified`, `password.confirm` middleware: authentication and account state only.
- Hiding a button in Blade with `@can`: the route still accepts the request.
- `Rule::exists('projects', 'id')` in validation: proves the record exists, not that the user may use it. Add `->where('team_id', $teamId)` or authorise afterwards.
