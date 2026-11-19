<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Gql;

use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\Type;

/**
 * `plusOneProviders` — for headless front ends: which sign-in buttons to draw on a site
 * (handle, name, label, login URL). That's what a public login page shows anyway. Never
 * credentials, tokens, identities or who is connected to what.
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 */
class ProvidersQuery extends Query
{
    public const string COMPONENT = 'plusone.providers';

    /** @return array<string, UnnamedFieldDefinitionConfig> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlHelper::canSchema(self::COMPONENT)) {
            return [];
        }

        return [
            'plusOneProviders' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(ProviderType::getType()))),
                'args' => [
                    'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'A site handle (default: the primary site the schema can read).'],
                ],
                'resolve' => [ProvidersResolver::class, 'resolve'],
                'description' => 'The social sign-in providers enabled on a site, with their login URLs.',
            ],
        ];
    }
}
