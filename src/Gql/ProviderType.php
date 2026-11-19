<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class ProviderType
{
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('PlusOneProvider', fn () => new ObjectType([
            'name' => 'PlusOneProvider',
            'description' => 'A social sign-in provider enabled on a site.',
            'fields' => fn () => [
                'handle' => ['type' => Type::nonNull(Type::string()), 'description' => 'The provider’s handle.'],
                'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'The provider’s name, e.g. “Google”.'],
                'label' => ['type' => Type::nonNull(Type::string()), 'description' => 'The button label, e.g. “Sign in with Google”.'],
                'loginUrl' => ['type' => Type::nonNull(Type::string()), 'description' => 'Send the visitor here to sign in (add `?return=/path` for an on-site page to come back to).'],
            ],
        ]));
    }
}
