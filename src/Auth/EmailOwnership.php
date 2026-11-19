<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\User\Elements\User;

/**
 * Whether Craft itself vouches that an account owns its email address — the other half of
 * matching by email. A provider verifying an address proves the *visitor* owns it; it says
 * nothing about who created the Craft account holding it. Without this check, someone could
 * pre-register an account with a victim's email and be handed the victim's social login.
 *
 * Proven means: active (not pending), not suspended or locked, no email change awaiting
 * verification, and Craft's registration doesn't let people claim emails they haven't verified
 * (public registration off, or on with “Verify email addresses” required).
 */
final class EmailOwnership
{
    public static function isProven(User $user, bool $publicRegistration, bool $requireEmailVerification): bool
    {
        return $user->active
            && ! $user->pending
            && ! $user->suspended
            && ! $user->locked
            && ($user->unverifiedEmail === null || $user->unverifiedEmail === '')
            && (! $publicRegistration || $requireEmailVerification);
    }

    public static function forUser(User $user): bool
    {
        $config = app(ProjectConfig::class);

        return self::isProven(
            $user,
            (bool) $config->get(ProjectConfig::PATH_USERS.'.allowPublicRegistration'),
            (bool) ($config->get(ProjectConfig::PATH_USERS.'.requireEmailVerification') ?? true),
        );
    }
}
