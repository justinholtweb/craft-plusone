<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Http\Middleware;

use Closure;
use CraftCms\Cms\Auth\Enums\CpAuthPath;
use CraftCms\Cms\Auth\OAuth\OAuth;
use CraftCms\Cms\Http\Controllers\Auth\OAuthController;
use CraftCms\Cms\Http\Controllers\Users\SignInProvidersController;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\PlusOne\Auth\LoginMethods;
use JustinHoltWeb\PlusOne\Http\Controllers\CallbackController;
use JustinHoltWeb\PlusOne\Settings;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Wraps Craft's own OAuth routes (pushed onto the `craft.web` and `craft.cp` groups; it
 * only acts on these routes):
 *
 *  - `oauth/{provider}/callback`: refuses to connect an admin's account (unless allowed); sends
 *    a front-end connect back to its on-site `return` page instead of the CP; sends a failed
 *    front-end sign-in to the site's sign-in page (Settings → Sites) instead of the CP login;
 *  - the CP's “Sign-in providers” screen: the same admin rule for Connect, and Disconnect
 *    refuses to remove a user's last way to sign in (core doesn't check).
 */
class GuardOAuthRoutes
{
    public const string LOGIN_SESSION_KEY = 'plusone.login';

    public const string CONNECT_SESSION_KEY = 'plusone.connect';

    public function __construct(
        private readonly Settings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return match ($request->route()?->getActionName()) {
            OAuthController::class.'@callback', CallbackController::class, CallbackController::class.'@__invoke' => $this->callback($request, $next),
            SignInProvidersController::class.'@connect' => $this->cpConnect($request, $next),
            SignInProvidersController::class.'@destroy' => $this->cpDisconnect($request, $next),
            default => $next($request),
        };
    }

    private function callback(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $connect = $session->pull(self::CONNECT_SESSION_KEY);
        $login = $session->pull(self::LOGIN_SESSION_KEY);
        $connectReturn = is_array($connect) && is_string($connect['return'] ?? null) ? $connect['return'] : null;

        if ($session->has(OAuth::CONNECT_SESSION_KEY) && $this->isBlockedAdmin($request)) {
            $session->forget(OAuth::CONNECT_SESSION_KEY);
            Flash::error(t('Admin accounts can’t be connected to a sign-in provider.', category: 'plusone'));

            return redirect($connectReturn ?? cp_url('myaccount/sign-in-providers'));
        }

        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        if ($connectReturn !== null) {
            // A front-end connect: core finished it (and flashed the outcome); come back to the site.
            return $response->setTargetUrl($connectReturn);
        }

        if (is_array($login) && $request->query('context') !== 'cp' && $this->isLoginFailure($response)) {
            $site = Sites::getSiteByHandle((string) ($login['site'] ?? ''));
            $path = $site ? $this->settings->siteSettings($site->handle)['loginPath'] : null;

            if ($site && $path !== null) {
                $response->setTargetUrl(Url::siteUrl($path, null, null, $site->id));
            }
        }

        return $response;
    }

    private function cpConnect(Request $request, Closure $next): Response
    {
        if ($this->isBlockedAdmin($request)) {
            abort(403, t('Admin accounts can’t be connected to a sign-in provider.', category: 'plusone'));
        }

        return $next($request);
    }

    private function cpDisconnect(Request $request, Closure $next): Response
    {
        $user = $request->craftUser()?->asElement();
        $provider = $request->route('provider');

        if ($user instanceof User && is_string($provider) && ! app(LoginMethods::class)->canDisconnect($user, $provider)) {
            abort(422, t('Set a password or connect another provider before disconnecting this one — otherwise you couldn’t sign in again.', category: 'plusone'));
        }

        return $next($request);
    }

    private function isBlockedAdmin(Request $request): bool
    {
        $user = $request->craftUser()?->asElement();

        return $user instanceof User && $user->admin && ! $this->settings->allowAdmins;
    }

    private function isLoginFailure(RedirectResponse $response): bool
    {
        $target = strtok($response->getTargetUrl(), '?') ?: '';

        return $target === strtok(cp_url(CpAuthPath::Login->value), '?');
    }
}
