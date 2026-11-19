<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Listeners;

use CraftCms\Cms\User\Events\EmailVerified;
use JustinHoltWeb\PlusOne\Auth\EmailProofs;

/**
 * Core fires `EmailVerified` when someone presents a valid code Craft emailed them
 * (activation, email verification, password reset). The code went to the pending
 * `unverifiedEmail` if there is one, otherwise to the account's email, so that's the
 * address proven. (Core moves `unverifiedEmail` into `email` right after.)
 */
class RecordEmailProof
{
    public function __construct(private readonly EmailProofs $proofs) {}

    public function handle(EmailVerified $event): void
    {
        $user = $event->user;
        $address = $user->unverifiedEmail ?: $user->email;

        if (is_string($address) && $address !== '') {
            $this->proofs->record($user, $address, EmailProofs::METHOD_EMAIL_LINK);
        }
    }
}
