<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\OAuth\Contracts\ResolvesOAuthUser;
use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\Auth\OAuth\Events\OAuthUserLinkResolving;
use CraftCms\Cms\Auth\OAuth\OAuth;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\Users;
use JustinHoltWeb\PlusOne\Settings;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Who an OAuth identity signs in as — the account-takeover guard.
 *
 *  1. An identity already linked to a user signs in as that user (unless it's an admin and admins are off).
 *  2. Craft's `OAuthUserLinkResolving` event can still pick a user (same admin rule).
 *  3. An email that matches an existing account links to it ONLY if the provider is set to link by
 *     email, the provider verified that address, Craft vouches the account owns it too
 *     ({@see EmailOwnership}: active, no unverified email change, and a Plus One proof for
 *     the account's current address), and the account isn't an admin's. The account's other sessions end. Otherwise the
 *     sign-in is refused — it never creates a second account with the same email, and never links.
 *  4. No match: a new user may be registered (Craft then applies public-registration / “New users”),
 *     but only with a verified email (when required) at an allowed domain.
 *
 * Refusals throw {@see LoginRefused}; Craft shows its generic “Authentication failed.”.
 */
class SafeUserResolver implements ResolvesOAuthUser
{
    public function __construct(
        protected readonly OAuth $oauth,
        protected readonly Settings $settings,
        protected readonly Users $users,
    ) {}

    public function handle(ProviderDefinition $provider, SocialiteUser $socialiteUser, string $identity): ?User
    {
        if ($user = $this->oauth->findLinkedUser($provider, $identity)) {
            return $this->guardAdmin($user, $provider);
        }

        event($event = new OAuthUserLinkResolving($provider, $socialiteUser, $identity));

        if ($event->user) {
            return $this->guardAdmin($event->user, $provider);
        }

        $email = $socialiteUser->getEmail();
        $email = is_string($email) ? trim($email) : '';
        $verified = EmailVerification::isVerified($provider, $socialiteUser);

        if ($email !== '' && ($existing = $this->oauth->findUserByEmail($email))) {
            if (! $provider->trustsEmail || ! $verified) {
                throw new LoginRefused($verified ? LoginRefused::EMAIL_IN_USE : LoginRefused::UNVERIFIED_EMAIL, $provider->handle);
            }

            $this->guardAdmin($existing, $provider);

            // The provider proved the visitor owns the address; Craft must also vouch that the
            // account does, or a pre-registered account would capture the victim's login.
            if (! EmailOwnership::forUser($existing)) {
                throw new LoginRefused(LoginRefused::EMAIL_UNPROVEN, $provider->handle);
            }

            // A new way into this account: sessions and remember-me tokens from before don't carry over.
            $this->users->destroyOtherSessions($existing);
            $this->noteVerifiedEmail($email, $provider);

            return $existing;
        }

        // Registration rules. (Whether registration is allowed at all is Craft's call, after this.)
        if ($email === '') {
            throw new LoginRefused(LoginRefused::NO_EMAIL, $provider->handle);
        }

        if ($this->settings->requireVerifiedEmail && ! $verified) {
            throw new LoginRefused(LoginRefused::UNVERIFIED_EMAIL, $provider->handle);
        }

        $domains = $this->settings->allowedDomains();

        if ($domains !== [] && ! in_array(EmailVerification::domain($email), $domains, true)) {
            throw new LoginRefused(LoginRefused::DOMAIN, $provider->handle);
        }

        // Only a provider trusted for email may vouch for an address: otherwise any provider with a
        // weak `email_verified` could mint a proof that a trusted provider's match then relies on.
        if ($verified && $provider->trustsEmail) {
            $this->noteVerifiedEmail($email, $provider);
        }

        return null;
    }

    /** Lets the OAuth service record the proof once the identity is linked (the user may not exist yet). */
    protected function noteVerifiedEmail(string $email, ProviderDefinition $provider): void
    {
        if ($this->oauth instanceof PlusOneOAuth) {
            $this->oauth->noteVerifiedEmail($email, $provider->handle);
        }
    }

    protected function guardAdmin(User $user, ProviderDefinition $provider): User
    {
        if ($user->admin && ! $this->settings->allowAdmins) {
            throw new LoginRefused(LoginRefused::ADMIN, $provider->handle);
        }

        return $user;
    }
}
