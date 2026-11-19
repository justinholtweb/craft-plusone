<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Http\Controllers;

use CraftCms\Cms\Auth\Models\SsoIdentity;
use CraftCms\Cms\Auth\OAuth\OAuth;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use JustinHoltWeb\PlusOne\Auth\LoginMethods;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;
use JustinHoltWeb\PlusOne\Auth\ReturnUrl;
use JustinHoltWeb\PlusOne\Http\Middleware\GuardOAuthRoutes;
use JustinHoltWeb\PlusOne\Settings;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\t;

/**
 * Front-end account linking for logged-in users (POST, CSRF-protected):
 *
 *  - POST {site}/actions/plusone/connect/{provider} — starts Craft's own connect flow (so core's checks
 *    apply: same user on return, one identity per provider, an identity can't belong to two users)
 *    and returns to `return` (on-site only) instead of the CP;
 *  - POST {site}/actions/plusone/disconnect/{provider} — refuses to remove someone's last way to sign in.
 *
 * Connecting needs the user's password (or a recently confirmed one) when they have one,
 * as core's CP screen does: a stolen session alone can't attach an attacker's account.
 */
class ConnectController
{
    public function __construct(
        private readonly PlusOneOAuth $oauth,
        private readonly Settings $settings,
        private readonly LoginMethods $loginMethods,
    ) {}

    public function connect(Request $request, string $provider): Response
    {
        $user = $this->requireUser($request);
        $return = $this->returnUrl($request);
        $definition = $this->oauth->getProviderDefinition($provider);

        if (! $definition) {
            abort(404);
        }

        if ($definition->stateless) {
            return $this->respond($request, false, t('This provider can’t be connected to an account.', category: 'plusone'), $return);
        }

        if ($user->admin && ! $this->settings->allowAdmins) {
            return $this->respond($request, false, t('Admin accounts can’t be connected to a sign-in provider.', category: 'plusone'), $return);
        }

        if ($this->oauth->identityFor($user, $definition) !== null) {
            return $this->respond($request, true, t('{provider} is already connected.', ['provider' => $definition->name], category: 'plusone'), $return);
        }

        if (! $this->confirmPassword($request, $user)) {
            return $this->respond($request, false, t('Enter your current password to connect {provider}.', ['provider' => $definition->name], category: 'plusone'), $return, 403);
        }

        $request->session()->put(OAuth::CONNECT_SESSION_KEY, [
            'provider' => $definition->handle,
            'userId' => $user->id,
        ]);
        $request->session()->put(GuardOAuthRoutes::CONNECT_SESSION_KEY, [
            'return' => $return,
            'site' => Sites::getCurrentSite()->handle,
        ]);

        $redirect = $this->oauth->buildProvider($definition, false)->redirect();

        return $request->expectsJson()
            ? new JsonResponse(['url' => $redirect->getTargetUrl()])
            : $redirect;
    }

    public function disconnect(Request $request, string $provider): Response
    {
        $user = $this->requireUser($request);
        $return = $this->returnUrl($request);

        if (! in_array($provider, $this->loginMethods->connectedHandles($user), true)) {
            return $this->respond($request, false, t('That provider isn’t connected.', category: 'plusone'), $return);
        }

        $name = $this->oauth->allProviderDefinitions()->get($provider)->name ?? Str::headline($provider);

        if (! $this->loginMethods->canDisconnect($user, $provider)) {
            return $this->respond($request, false, t('Set a password or connect another provider before disconnecting {provider} — otherwise you couldn’t sign in again.', ['provider' => $name], category: 'plusone'), $return, 422);
        }

        // By handle, so an identity for a provider since disabled on this site can still be removed.
        SsoIdentity::query()->where('userId', $user->id)->where('provider', $provider)->delete();
        $user->setHasSsoIdentity(null);

        return $this->respond($request, true, t('{provider} disconnected.', ['provider' => $name], category: 'plusone'), $return);
    }

    private function requireUser(Request $request): User
    {
        abort_unless(PlusOneOAuth::isAvailable() && $this->settings->frontEndLinking, 404);

        $user = $request->craftUser()?->asElement();
        abort_unless($user instanceof User, 403, t('Sign in first.', category: 'plusone'));

        return $user;
    }

    private function returnUrl(Request $request): string
    {
        $default = $this->settings->siteSettings(Sites::getCurrentSite()->handle)['accountPath'];

        return ReturnUrl::resolve(is_string($request->input('return')) ? $request->input('return') : null, $default);
    }

    /**
     * True when the user has no password, confirmed it recently, or sent it now (checked,
     * throttled to 5 tries a minute per user). A correct password starts Craft's elevated session.
     */
    private function confirmPassword(Request $request, User $user): bool
    {
        $hash = DB::table(Table::USERS)->where('id', $user->id)->value('password');

        if (! is_string($hash) || $hash === '') {
            return true;
        }

        $key = Cms::config()->getPasswordConfirmationKey();
        $confirmedAt = $request->session()->get($key);
        $timeout = (int) config('auth.password_timeout', 300);

        if ($timeout === -1 || (is_numeric($confirmedAt) && now()->unix() - (int) $confirmedAt <= $timeout)) {
            return true;
        }

        $password = $request->input('password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        $limiter = 'plusone-confirm:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return false;
        }

        if (! Hash::check($password, Str::replaceStart('$2a$', '$2y$', $hash))) {
            RateLimiter::hit($limiter, 60);

            return false;
        }

        RateLimiter::clear($limiter);
        $request->session()->put($key, now()->unix());

        return true;
    }

    private function respond(Request $request, bool $success, string $message, string $return, int $status = 200): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? 200 : ($status === 200 ? 400 : $status));
        }

        $success ? Flash::success($message) : Flash::error($message);

        return redirect($return);
    }
}
