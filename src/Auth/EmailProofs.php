<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plus One's per-user “this email is proven” marker. Recorded when
 *  - Craft accepts a code it emailed (activation, email verification, password reset — core's
 *    `EmailVerified` event), for the address the code went to, or
 *  - Plus One registers or links a user with an address their provider verified.
 * It holds a hash of the address, so it stops counting the moment the account's email changes,
 * however it changes. No marker (or no table) means not proven: matching by email fails closed.
 */
class EmailProofs
{
    public const string TABLE = 'plusone_email_proofs';

    public const string METHOD_EMAIL_LINK = 'email-link';

    public const string METHOD_PROVIDER = 'provider';

    public static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public function record(User $user, string $email, string $method): void
    {
        if (! $user->id || trim($email) === '' || ! $this->ready()) {
            return;
        }

        DB::table(self::TABLE)->upsert(
            [['userId' => $user->id, 'emailHash' => self::hash($email), 'method' => $method, 'dateProven' => now('UTC')]],
            ['userId'],
            ['emailHash', 'method', 'dateProven'],
        );
    }

    /** Whether the user has proved owning the address their account has now. */
    public function isProven(User $user): bool
    {
        if (! $user->id || ! is_string($user->email) || $user->email === '' || ! $this->ready()) {
            return false;
        }

        $hash = DB::table(self::TABLE)->where('userId', $user->id)->value('emailHash');

        return is_string($hash) && hash_equals($hash, self::hash($user->email));
    }

    private function ready(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable $e) {
            Log::warning('Plus One couldn’t check its email proofs table: '.$e->getMessage());

            return false;
        }
    }
}
