---
title: Symfony Access Control (access_control, IsGranted, Voters, Firewalls, Login)
tags: symfony, security, access-control, isgranted, voters, firewall, idor, csrf, login-throttling, session-fixation
---

## Symfony Access Control

### Where Checks Can Live

Before reporting a controller as unprotected, check all of these:

| Place | What it looks like |
|---|---|
| `security.yaml` `access_control` | `- { path: ^/admin, roles: ROLE_ADMIN }` |
| Attribute on class or method | `#[IsGranted('ROLE_ADMIN')]`, `#[IsGranted('edit', 'post')]` (`Symfony\Component\Security\Http\Attribute\IsGranted`) |
| Controller body | `$this->denyAccessUnlessGranted('edit', $post)`, `if (!$this->isGranted(...)) throw $this->createAccessDeniedException()` |
| Services | `Symfony\Bundle\SecurityBundle\Security::isGranted()` |
| Queries | Repository methods that filter by the current user or tenant |

### access_control: First Match Wins, Paths Are Regexes

Each request is matched against the rules in order, and **only the first match applies**. `path` is a regular expression tested against the URL-decoded path with no implicit anchors.

**Incorrect:**
```yaml
access_control:
    - { path: ^/, roles: PUBLIC_ACCESS }
    - { path: ^/admin, roles: ROLE_ADMIN }        # never reached
    - { path: ^/account$, roles: ROLE_USER }      # /account/settings falls through
    - { path: ^/api, roles: ROLE_API, methods: [POST] }  # GET /api/... matches a later rule, or none
```

**Correct:**
```yaml
access_control:
    - { path: ^/admin, roles: ROLE_ADMIN }
    - { path: ^/account, roles: ROLE_USER }
    - { path: ^/api, roles: ROLE_API }
    - { path: ^/, roles: PUBLIC_ACCESS }
```

Check routes with prefixes (`/{_locale}/admin`) against the patterns: `^/admin` doesn't match `/en/admin`.

### Firewalls With security: false

A firewall with `security: false` registers no listeners, so `access_control` does not apply to anything its `pattern` matches. The recipe's `dev` firewall uses this for the profiler and assets. Check that no firewall with `security: false` has a pattern that covers application routes.

### IsGranted

- `#[IsGranted]` on the class applies to every action; on a method, only that one. A new action on a controller with method-level attributes gets nothing.
- The `subject` argument names a controller argument: `#[IsGranted('edit', 'post')]` passes the resolved `$post` to voters.
- In 7.4 and 8.x (not in 6.4), `methods:` restricts the check to those HTTP methods. `#[IsGranted('ROLE_ADMIN', methods: ['POST'])]` leaves GET open.

### IDOR via Entity Value Resolvers

`#[MapEntity]` or a type-hinted entity argument loads any row by ID. It is not an ownership check.

**Incorrect:**
```php
#[Route('/invoices/{id}', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function show(Invoice $invoice): Response
{
    return $this->render('invoice/show.html.twig', ['invoice' => $invoice]);
}
```

**Correct:**
```php
#[Route('/invoices/{id}', methods: ['GET'])]
#[IsGranted('view', 'invoice')]
public function show(Invoice $invoice): Response { ... }

// InvoiceVoter::voteOnAttribute()
return $invoice->getCustomer() === $token->getUser();
```

Same for `$repository->find($request->query->get('id'))` and IDs inside request payloads.

### Voters

- Default strategy is `affirmative`: **one** voter granting is enough, whatever the others say. A broad voter that returns true (e.g. "any staff member may do anything") overrides every specific voter.
- `allow_if_all_abstain` defaults to `false`, so an attribute no voter supports is denied. A typo in the attribute name fails closed.
- Check `supports()` matches only the intended attributes and subject class, and `voteOnAttribute()` ends in `return false` rather than `return true`.

### Remember-Me and Sensitive Actions

`IS_AUTHENTICATED` and roles are satisfied by a remember-me login. For password changes, payment details or admin actions, require `IS_AUTHENTICATED_FULLY`.

### Login and Logout

- **`form_login.enable_csrf` defaults to `false`.** Without it the login form is open to login CSRF (an attacker logs the victim into the attacker's account). Medium on apps that store user data; Low otherwise.
- **Logout CSRF** is off unless `logout.enable_csrf: true`. Low.
- **`login_throttling`** must be configured on the firewall (it needs `symfony/rate-limiter`). Its defaults are `max_attempts: 5` per `interval: '1 minute'`. A firewall with a login authenticator and no throttling (and no custom limiter) is open to password guessing.
- **`session_fixation_strategy`** defaults to `migrate`. `none` is a finding.
- Manual login via `$tokenStorage->setToken(...)` skips session migration and the login events. Use `Security::login($user)`.

### Things That Aren't Access Control

- `{% if is_granted('ROLE_ADMIN') %}` in Twig: hides the link, not the route.
- A route missing from the menu or with an obscure path.
- `Assert\*` validation constraints on an ID: they check the format, not the right to use it.
