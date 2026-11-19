<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Listeners;

use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use JustinHoltWeb\PlusOne\Gql\ProvidersQuery;

use function CraftCms\Cms\t;

class RegisterGqlComponents
{
    public function handle(GqlSchemaComponentsResolving $event): void
    {
        $event->queries[t('Plus One', category: 'plusone')] = [
            ProvidersQuery::COMPONENT.':read' => [
                'label' => t('List sign-in providers', category: 'plusone'),
                'info' => t('Adds the `plusOneProviders` query: each enabled provider’s name, button label and login URL for a site. No credentials, tokens or user data.', category: 'plusone'),
            ],
        ];
    }
}
