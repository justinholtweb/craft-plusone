<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Gql;

use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Site\Data\Site;
use GraphQL\Error\UserError;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;

class ProvidersResolver
{
    /**
     * @param  array<string, mixed>  $args
     * @return list<array{handle: string, name: string, label: string, loginUrl: string}>
     */
    public static function resolve(mixed $source, array $args): array
    {
        if (! GqlHelper::canSchema(ProvidersQuery::COMPONENT)) {
            throw new UserError('This schema can’t list Plus One’s sign-in providers.');
        }

        $site = self::site(isset($args['site']) ? (string) $args['site'] : null);

        if (! $site->enabled || ! $site->hasUrls || ! PlusOneOAuth::isAvailable()) {
            return [];
        }

        return app(PlusOneOAuth::class)->definitionsForSite($site)
            ->map(fn (ProviderDefinition $d) => [
                'handle' => $d->handle,
                'name' => $d->name,
                'label' => $d->label,
                'loginUrl' => PlusOneOAuth::siteActionUrl('login/'.rawurlencode($d->handle), null, $site->id),
            ])
            ->values()
            ->all();
    }

    /** The requested site, or the primary one the schema can read. */
    private static function site(?string $handle): Site
    {
        $allowed = GqlHelper::getAllowedSites();

        foreach ($allowed as $site) {
            if ($handle === null ? $site->primary : $site->handle === $handle) {
                return $site;
            }
        }

        if ($handle === null && $allowed !== []) {
            return $allowed[0];
        }

        throw new UserError($handle === null ? 'This schema can’t read any site.' : "This schema can’t read the “{$handle}” site.");
    }
}
