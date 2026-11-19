<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\OAuth\Actions\UserPopulator as CoreUserPopulator;
use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\PlusOne\Settings;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Core's populator (email, name, username), plus: users Plus One registers are activated
 * when “Activate new users” is on and the provider verified their email. Existing users are
 * never activated here (core's `activatesUsers` would also unsuspend them, so Plus One's
 * providers leave it off).
 */
class UserPopulator extends CoreUserPopulator
{
    public function handle(
        ProviderDefinition $provider,
        SocialiteUser $socialiteUser,
        User $user,
        string $identity,
        bool $isNew,
    ): User {
        if (! $isNew) {
            // Don't let a provider rename an existing account's email: that's how accounts get taken over.
            $email = $user->email;
            $user = parent::handle($provider, $socialiteUser, $user, $identity, $isNew);
            $user->email = $email;

            return $user;
        }

        $user = parent::handle($provider, $socialiteUser, $user, $identity, $isNew);

        if (app(Settings::class)->activateNewUsers && EmailVerification::isVerified($provider, $socialiteUser)) {
            $user->active = true;
            $user->pending = false;
        }

        return $user;
    }
}
