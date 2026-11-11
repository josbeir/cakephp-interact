---
title: Authentication and authorization
description: Access the native CakePHP authentication service and authorization policies through optional island traits.
---

# Authentication and authorization {#authorization}

`AuthenticationTrait` exposes the application's native CakePHP authentication
service as `$this->Authentication`. `AuthorizationTrait` provides policy checks
and scopes through `$this->Authorization`. Each trait is optional and independent.

```php
$identity = $this->Authentication->getIdentity();
$userId = $identity?->getIdentifier();
$this->Authorization->authorize($article, 'update');
```

The `authorize(IslandContext $context)` hook controls island access on initial
mounts and subsequent requests. Reading an identity does not authorize a resource.

## Prerequisites

Install the packages for the integrations you use:

```sh
composer require cakephp/authentication:^3.0
composer require cakephp/authorization:^3.0
```

Configure [CakePHP Authentication](https://book.cakephp.org/authentication/3/en/)
and [CakePHP Authorization](https://book.cakephp.org/authorization/) in the
application as needed. Run the relevant middleware on both hosting pages and
island update routes. When using both, Authentication runs before Authorization.

These integrations support Authentication 3.x and Authorization 3.x on CakePHP
5.4+. Applications using neither trait need neither package.

## Enable island helpers {#enable-policy-helpers}

Add the traits you need to a shared application island base class. This example
enables both integrations; omit the import and trait for any integration you do
not use:

```php
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Authentication\AuthenticationTrait;
use Interact\Authorization\AuthorizationTrait;
use Interact\Island;

/**
 * Supplies the application's islands with request-local authentication and policy helpers.
 * File: src/Island/AppIsland.php
 */
abstract class AppIsland extends Island
{
    use AuthenticationTrait;
    use AuthorizationTrait;
}
```

Individual islands can also use either trait directly. No constructor changes or
`initialize()` override are needed. Both properties are get-only and request-local;
neither is a prop or transported state.

## Authentication service

`$this->Authentication` returns the exact `AuthenticationServiceInterface` instance
provided by the current request's `authentication` attribute. Interact does not
wrap the service, cache it, construct authenticators, or repeat authentication.
Replacing the island's request changes the service returned on the next access.

```php
$identity = $this->Authentication->getIdentity();
$userId = $identity?->getIdentifier();
$result = $this->Authentication->getResult();
$authenticated = $result?->isValid() ?? false;
```

`getIdentity()` returns an `Authentication\IdentityInterface` or `null` for a
guest. `getResult()` returns the native authentication result or `null` when no
result is available. The trait does not require an identity or automatically
reject guests; application middleware and the island's `authorize()` hook own
access requirements.

These are native service methods, not `AuthenticationComponent` methods.
Use `$identity?->getIdentifier()` for the user ID. Refer to CakePHP's
[authentication service interface](https://api.cakephp.org/authentication/3.x/interface-Authentication.AuthenticationServiceInterface.html)
for its full contract.

### Service and request identities

`$this->Authentication->getIdentity()` reads the authentication service's identity.
`$this->getRequest()->getAttribute('identity')` reads the request identity, which
Authorization middleware can decorate with policy methods. The two need not be
the same object. `$this->Authorization` uses the request identity for policy checks.
Use the application's configured identity attribute name when reading it directly.

### Authentication changes

The trait adds service access, not login, logout, or impersonation helpers.
Native persistence operations such as `persistIdentity()` and `clearIdentity()`
return replacement request and response objects. Interact does not apply those
objects automatically, propagate their cookies, or coordinate session changes
across mounted islands. Keep login/logout workflows in normal application
controllers; reading authentication data in an island needs no response changes.

## Protect a resource

This example assumes the application supplies an `ArticlesTable`, an `Article`
entity, and an authenticated identity exposing its `id` through array access.
The mount receives an application-supplied `$articleId`. Each request reloads the
entity and checks its current policy before reading its title into a draft.

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use App\Model\Entity\Article;
use App\Model\Table\ArticlesTable;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\IslandContext;

/**
 * Authorizes an article on every request while keeping its editable draft separate.
 * File: src/Island/ArticleEditorIsland.php
 */
class ArticleEditorIsland extends AppIsland
{
    #[Prop]
    public int $articleId;

    #[State(writable: true)]
    public string $title = '';

    protected Article $article;

    public function authorize(IslandContext $context): void
    {
        $this->article = $this->fetchTable(ArticlesTable::class)->get($this->articleId);
        $this->Authorization->authorize($this->article, 'update');
    }

    public function mount(): void
    {
        $this->title = $this->article->title;
    }
}
```

```php [Policy]
<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\Article;
use Authorization\IdentityInterface;

/**
 * Allows the current owner to edit an article, including after permission changes.
 * File: src/Policy/ArticlePolicy.php
 */
class ArticlePolicy
{
    public function canUpdate(?IdentityInterface $identity, Article $article): bool
    {
        return $identity !== null && $identity['id'] === $article->user_id;
    }
}
```

```php [Element]
<?php
/** File: templates/island/article_editor.php */
?>
<?= $this->Form->control('title', ['x-interact:model' => 'title']) ?>
```

```php [Mount]
<?php
/** @var int $articleId Authorized application context selecting the article. */
?>
<?= $this->Interact->render('ArticleEditor', ['articleId' => $articleId]) ?>
```

:::

The entity stays in a protected PHP property. It is never serialized into a
snapshot. `mount()` initializes the draft only once; later requests preserve edits
while `authorize()` loads the current resource and rechecks the policy. Draft
synchronization does not save the article.

## Check an action's resource

An allowed island does not grant access to other records named in action
arguments. Load and authorize those resources in the action before using them:

```php
use App\Model\Table\ArticlesTable;
use Interact\Attribute\Action;

#[Action]
public function inspect(int $otherArticleId): void
{
    $other = $this->fetchTable(ArticlesTable::class)->get($otherArticleId);
    $this->Authorization->authorize($other, 'view');
    // Application behavior using this authorized resource follows here.
}
```

This assumes an application `canView()` policy in addition to the preceding
example's `canUpdate()`. For streaming actions, perform the island's access check
in `authorize()` before response emission; additional action-resource checks can
still run inside the action.

## Policy helpers

| Method                                     | Behavior                                                                                                |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| `authorize($resource, $action)`            | Throws the native `Authorization\Exception\ForbiddenException` on denial, preserving its policy result. |
| `can($resource, $action)`                  | Returns a boolean for an application decision or presentation flag.                                     |
| `canResult($resource, $action)`            | Returns the native `Authorization\Policy\ResultInterface`.                                              |
| `applyScope($resource, $action, ...$args)` | Returns the policy scope's result, forwarding additional arguments.                                     |
| `skipAuthorization()`                      | Explicitly acknowledges the middleware's required-check tracking for the whole HTTP request.            |

Always supply the policy action. `authorize($article, 'update')` checks
`canUpdate()` even if the island action is named `save`, or the request is a mount,
draft synchronization, upload, history restoration, or subscription renewal.
The update controller's action name is not used to select a policy.

Use `can()` for PHP-rendered presentation data where useful. Hiding a button does
not authorize its action: retain the server-side policy check.

### Guest policies

When no identity is present, the middleware service receives `null` as the policy's
identity argument. Policies decide whether guests can access a resource. A
non-null identity that does not implement the Authorization plugin's
`IdentityInterface` is a configuration error, not a guest.

### Query scopes

Apply query scopes before executing or paginating a query:

```php
use App\Model\Table\ArticlesTable;

$query = $this->fetchTable(ArticlesTable::class)->find('published');
$query = $this->Authorization->applyScope($query, 'index');
$articles = $this->paginate($query);
```

This assumes an application `findPublished()` finder, an `ArticlesTablePolicy`
with `scopeIndex()`, and the island's declared pagination state. Keep domain query
logic in Table finders and access restrictions in the policy. Scoping a listing
does not authorize an individual record mutation.

### Explicit public requests

When middleware requires an authorization check, a public island can explicitly
acknowledge that the request needs none:

```php
public function authorize(IslandContext $context): void
{
    $this->Authorization->skipAuthorization();
}
```

`skipAuthorization()` marks the **whole HTTP request** as checked. It does not
grant access to protected child islands or bypass their hooks. A successful check
in one island also satisfies the middleware's request-level tracking; each
protected island must still implement its own resource checks.

The trait never skips automatically. Application middleware requirements apply
to islands with or without the trait.

## Custom request attributes

### Authentication service attribute

Override the lookup method in `AppIsland` for middleware that supplies the service
under a different attribute name:

```php
use Authentication\AuthenticationServiceInterface;
use LogicException;

protected function getAuthenticationService(): AuthenticationServiceInterface
{
    $service = $this->getRequest()->getAttribute('memberAuthentication');
    if (!$service instanceof AuthenticationServiceInterface) {
        throw new LogicException('The memberAuthentication service is missing.');
    }

    return $service;
}
```

The default service attribute is `authentication`. Identity attribute names and
identity construction remain the authentication service's responsibility.

### Authorization attributes

Override the factory in `AppIsland` if your application uses different attribute
names or needs a bridge subclass:

```php
use Interact\Authorization\IslandAuthorization;

protected function createAuthorization(): IslandAuthorization
{
    return new IslandAuthorization(
        $this->getRequest(),
        identityAttribute: 'member',
        serviceAttribute: 'policyService',
    );
}
```

The defaults are `identity` and `authorization`. Configure the application
middleware consistently with these names; the trait does not rename attributes.
No global Interact configuration is required.

## Failures and testing

Denied updates return Interact's safe 403 response and preserve the browser draft.
Streaming failures after emission use terminal failure frames, and denied
subscription renewals use member-error responses. Application
policy details are not copied into those browser errors.

Missing packages, missing middleware services, and invalid service or identity
attributes produce configuration exceptions. They never silently allow access.
The optional packages are only required when their respective property is accessed.

The [island test helpers](/guide/testing#requests-and-authorization) accept a
request containing your native authentication service, authorization service,
and decorated identity.
Middleware tracking and authentication integration should also be tested through
native CakePHP HTTP integration tests. See [Security](/security) and
[Lifecycle](/guide/lifecycle#authorize).
