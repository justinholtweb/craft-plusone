<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\OAuth\Contracts\ResolvesOAuthUserGroups;
use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\PlusOne\Providers\ProviderRegistry;
use JustinHoltWeb\PlusOne\Settings;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Groups a newly registered user joins: the default group from Plus One's settings plus
 * the provider's own (`groups` option, or `groups` in config/general.php).
 */
class GroupResolver implements ResolvesOAuthUserGroups
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProviderRegistry $registry,
    ) {}

    /** @return array<int|string> */
    public function handle(ProviderDefinition $provider, SocialiteUser $socialiteUser, User $user, string $identity): array
    {
        $groups = $provider->groupIds;

        if ($managed = $this->registry->get($provider->handle)) {
            array_push($groups, ...$managed->groupHandles());
        }

        if ($this->settings->defaultUserGroup) {
            $groups[] = $this->settings->defaultUserGroup;
        }

        return array_values(array_unique($groups));
    }
}
