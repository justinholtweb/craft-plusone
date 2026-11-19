<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\GithubProvider;

/**
 * Whether the provider vouches for the email address it returned.
 *
 * OpenID Connect providers say so with `email_verified` (Google's older API: `verified_email`).
 * GitHub returns only verified addresses (its public email must be verified, and Socialite
 * falls back to the primary *verified* address). Anything else is treated as unverified.
 */
final class EmailVerification
{
    public static function isVerified(ProviderDefinition $provider, SocialiteUser $user): bool
    {
        $email = $user->getEmail();

        if (! is_string($email) || trim($email) === '' || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];
        $raw = is_array($raw) ? $raw : [];

        foreach (['email_verified', 'verified_email'] as $claim) {
            if (array_key_exists($claim, $raw)) {
                return in_array($raw[$claim], [true, 1, '1', 'true'], true);
            }
        }

        return $provider->driver === 'github' || $provider->providerClass === GithubProvider::class;
    }

    /** The domain of an email address, lower-cased, or null. */
    public static function domain(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(substr($email, strrpos($email, '@') + 1)));

        return $domain === '' ? null : $domain;
    }
}
