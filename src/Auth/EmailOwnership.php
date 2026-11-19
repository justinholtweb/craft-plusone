<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\User\Elements\User;

/**
 * Whether an existing account may be matched to a social sign-in by its email — the other half
 * of “match by verified email”. A provider verifying an address proves the *visitor* owns it;
 * it says nothing about who created the Craft account holding it, or who last changed its email.
 * Without this, someone could pre-register (or re-address) an account with a victim's email and
 * be handed the victim's social login.
 *
 * Matchable means: active (not pending), not suspended or locked, no email change awaiting
 * verification, and a Plus One proof ({@see EmailProofs}) for the account's current address —
 * recorded only when a code Craft emailed to that address came back, or a provider verified it.
 * Craft's settings (public registration, “Verify email addresses”, edition) aren't consulted:
 * they don't cover admin-, console- or import-created accounts, or email changes made with
 * verification off, and the proof covers all of those by being absent.
 */
final class EmailOwnership
{
    public static function isProven(User $user, bool $hasProof): bool
    {
        return $hasProof
            && $user->active
            && ! $user->pending
            && ! $user->suspended
            && ! $user->locked
            && ($user->unverifiedEmail === null || $user->unverifiedEmail === '');
    }

    public static function forUser(User $user): bool
    {
        return self::isProven($user, app(EmailProofs::class)->isProven($user));
    }
}
