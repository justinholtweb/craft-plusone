<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Listeners;

use CraftCms\Cms\User\Events\EmailVerified;
use JustinHoltWeb\PlusOne\Auth\EmailProofs;

/**
 * Core fires `EmailVerified` when someone presents a valid code Craft emailed (activation, email
 * verification, password reset, set password). Every one of those codes comes from the same
 * password broker, and core accepts any of them on any of those routes, so the event can't say
 * which address the code went to: a reset link goes to the current `email`, a verification link to
 * a pending `unverifiedEmail`. So the only address recorded is the current `email`, and only when
 * there's no different pending address — then every possible recipient was that address.
 * (A pending change gets its proof the next time a link comes back after it's applied.)
 */
class RecordEmailProof
{
    public function __construct(private readonly EmailProofs $proofs) {}

    public function handle(EmailVerified $event): void
    {
        $user = $event->user;

        if ($address = self::provenAddress($user->email, $user->unverifiedEmail)) {
            $this->proofs->record($user, $address, EmailProofs::METHOD_EMAIL_LINK);
        }
    }

    /** The address an accepted emailed code proves, or null when it can't be told. */
    public static function provenAddress(?string $email, ?string $unverifiedEmail): ?string
    {
        $email = trim((string) $email);
        $pending = trim((string) $unverifiedEmail);

        if ($email === '' || ($pending !== '' && mb_strtolower($pending) !== mb_strtolower($email))) {
            return null;
        }

        return $email;
    }
}
