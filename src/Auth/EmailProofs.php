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
 *  - Plus One registers or links a user with an address a provider verified — only a provider set to
 *    match by email, and the proof stops counting if that provider is removed or no longer trusted.
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

    public function record(User $user, string $email, string $method, ?string $provider = null): void
    {
        if (! $user->id || trim($email) === '' || ! $this->ready()) {
            return;
        }

        // Never let bookkeeping break Craft's own sign-in or verification flow: a proof that
        // isn't recorded just means no email match later (fail closed).
        try {
            DB::table(self::TABLE)->upsert(
                [['userId' => $user->id, 'emailHash' => self::hash($email), 'method' => $method, 'provider' => $provider, 'dateProven' => now('UTC')]],
                ['userId'],
                ['emailHash', 'method', 'provider', 'dateProven'],
            );
        } catch (Throwable $e) {
            Log::warning('Plus One couldn’t record an email proof: '.$e->getMessage());
        }
    }

    /** Whether the user has proved owning the address their account has now. */
    public function isProven(User $user): bool
    {
        if (! $user->id || ! is_string($user->email) || $user->email === '' || ! $this->ready()) {
            return false;
        }

        try {
            $proof = DB::table(self::TABLE)->where('userId', $user->id)->first(['emailHash', 'method', 'provider']);
        } catch (Throwable $e) {
            Log::warning('Plus One couldn’t read its email proofs: '.$e->getMessage());

            return false;
        }

        if ($proof === null || ! is_string($proof->emailHash) || ! hash_equals($proof->emailHash, self::hash($user->email))) {
            return false;
        }

        return self::counts((string) $proof->method, is_string($proof->provider) ? $proof->provider : null, $this->providerTrustsEmail(...));
    }

    /**
     * Whether a stored proof still counts. A provider's word only counts while that provider is
     * configured and trusted for email.
     *
     * @param  callable(string): bool  $providerTrustsEmail
     */
    public static function counts(string $method, ?string $provider, callable $providerTrustsEmail): bool
    {
        return match ($method) {
            self::METHOD_EMAIL_LINK => true,
            self::METHOD_PROVIDER => $provider !== null && $provider !== '' && $providerTrustsEmail($provider),
            default => false,
        };
    }

    private function providerTrustsEmail(string $handle): bool
    {
        $definition = app(PlusOneOAuth::class)->allProviderDefinitions()->get($handle);

        return $definition !== null && $definition->trustsEmail;
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
