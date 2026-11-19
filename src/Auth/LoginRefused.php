<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use RuntimeException;

/**
 * Thrown from Plus One's resolver to stop a sign-in. Craft's OAuth controller turns it
 * into its generic “Authentication failed.” (so the page never says whether an email
 * has an account) and logs this message, which never includes the email address.
 */
class LoginRefused extends RuntimeException
{
    public const string ADMIN = 'admin';

    public const string EMAIL_IN_USE = 'email-in-use';

    public const string UNVERIFIED_EMAIL = 'unverified-email';

    public const string NO_EMAIL = 'no-email';

    public const string DOMAIN = 'domain-not-allowed';

    public const string EMAIL_UNPROVEN = 'account-email-unverified';

    /** The refusal raised during this request, for the callback middleware's message. */
    private static ?self $last = null;

    public static function last(): ?self
    {
        return self::$last;
    }

    public static function forget(): void
    {
        self::$last = null;
    }

    public function __construct(public readonly string $reason, string $provider)
    {
        parent::__construct(sprintf('Plus One refused an OAuth sign-in via “%s”: %s.', $provider, $reason));
        self::$last = $this;
    }
}
