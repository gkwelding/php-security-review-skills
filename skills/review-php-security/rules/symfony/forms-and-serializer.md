---
title: Symfony Forms and Serializer (Mass Assignment, Data Exposure, Form CSRF)
tags: symfony, forms, serializer, mass-assignment, map-request-payload, object-to-populate, groups, csrf
---

## Symfony Forms and Serializer

### Forms Bind Only Declared Fields

A form maps only the fields added in its type. Extra submitted keys land in `$form->getExtraData()` and are never written to the object.

`allow_extra_fields: true` only stops the "This form should not contain extra fields" error. **It is not mass assignment.** Don't report it as such.

The real form risks:

**Incorrect:**
```php
// ProfileType, used for the user's own profile page
$builder
    ->add('email')
    ->add('displayName')
    ->add('roles', ChoiceType::class, [...]);  // added for the admin screen, same type reused
```

A field that is in the type but not rendered in the template is still bound if submitted.

**Correct:**
```php
$builder->add('email')->add('displayName');
if ($options['is_admin']) {
    $builder->add('roles', ChoiceType::class, [...]);
}
```

Fields with `'disabled' => true` ignore submitted values; `'mapped' => false` fields aren't written to the object.

### Form CSRF

Forms are CSRF-protected by default when the CSRF component is enabled. Look for:

- `'csrf_protection' => false` in a form type's `configureOptions()` or in `createForm(..., ['csrf_protection' => false])` on a form handling a session-authenticated, state-changing action.
- `framework.csrf_protection: false`, or `framework.form.csrf_protection.enabled: false`.
- Hand-rolled forms (no form component) on state-changing POST actions with no `isCsrfTokenValid()` call and no `#[IsCsrfTokenValid]` attribute (7.x; absent in 6.4).

Disabling CSRF on forms used only by stateless token-authenticated APIs is fine.

### Serializer Into Entities

`#[MapRequestPayload]`, `$serializer->deserialize()` and `denormalize()` set every writable property the payload names (public properties and setters) unless restricted. Without `groups` in the context, serializer groups are ignored and all attributes are allowed (except `#[Ignore]`d ones).

**Incorrect:**
```php
#[Route('/api/users/{id}', methods: ['PATCH'])]
public function update(User $user, Request $request, SerializerInterface $serializer): Response
{
    $serializer->deserialize($request->getContent(), User::class, 'json', [
        AbstractNormalizer::OBJECT_TO_POPULATE => $user,     // setRoles(), setIsVerified() reachable
    ]);
    // ...
}

public function create(#[MapRequestPayload] User $user): Response { ... }  // entity straight from the payload
```

**Correct:**
```php
public function update(User $user, #[MapRequestPayload] UpdateProfileDto $dto): Response
{
    $user->setDisplayName($dto->displayName);
    // ...
}

// or restrict the context:
$serializer->deserialize($json, User::class, 'json', [
    AbstractNormalizer::OBJECT_TO_POPULATE => $user,
    AbstractNormalizer::GROUPS => ['profile:write'],
]);
```

`#[MapRequestPayload(serializationContext: ['groups' => ['profile:write']])]` restricts the attribute too. Mapping into a DTO and copying fields explicitly is the clearest fix.

### Data Exposure in Responses

`$this->json($entity)` and `$serializer->serialize($entity, 'json')` without groups normalise every readable property and getter, including `getPassword()`, `getRoles()`, tokens, and related entities.

**Incorrect:**
```php
return $this->json($user);
return $this->json($repository->findAll());
```

**Correct:**
```php
return $this->json($user, context: ['groups' => ['user:read']]);
```

Then check what's in `user:read`, and that sensitive properties carry `#[Ignore]` or no read group. Related entities in the group are serialised with their own grouped fields.
