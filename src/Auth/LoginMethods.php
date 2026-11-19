<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\Models\SsoIdentity;
use CraftCms\Cms\Auth\Passkeys\Passkeys;
use CraftCms\Cms\User\Elements\User;
use Throwable;

/**
 * Whether a user can still sign in after disconnecting a provider: they need a password,
 * a passkey, or another connected provider that's still configured.
 */
class LoginMethods
{
    public function __construct(private readonly PlusOneOAuth $oauth) {}

    /** @return list<string> Provider handles the user is connected to. */
    public function connectedHandles(User $user): array
    {
        if (! $user->id) {
            return [];
        }

        return SsoIdentity::query()
            ->where('userId', $user->id)
            ->pluck('provider')
            ->map(fn (mixed $p) => (string) $p)
            ->unique()
            ->values()
            ->all();
    }

    public function canDisconnect(User $user, string $provider): bool
    {
        if ($user->getHasPassword() || $this->hasPasskeys($user)) {
            return true;
        }

        $usable = $this->oauth->allProviderDefinitions()->keys()->all();

        foreach ($this->connectedHandles($user) as $handle) {
            if ($handle !== $provider && in_array($handle, $usable, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasPasskeys(User $user): bool
    {
        try {
            return app(Passkeys::class)->hasPasskeys($user);
        } catch (Throwable) {
            return false;
        }
    }
}
