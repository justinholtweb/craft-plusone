<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Http\Controllers;

use CraftCms\Cms\Http\Controllers\Auth\OAuthController;
use Illuminate\Http\Request;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET {site}/actions/plusone/callback/{provider} — the redirect URI of Plus One's providers.
 *
 * It runs Craft's own OAuth callback (state check, identity, user resolution, login), so
 * nothing is duplicated. It exists because core's `oauth/{provider}/callback` is a plain web
 * route, which doesn't match on sites that live under a path (`/fr/oauth/…` 404s), while
 * action URLs work on every site.
 */
class CallbackController
{
    public function __invoke(Request $request, PlusOneOAuth $oauth, string $provider): Response
    {
        abort_unless(PlusOneOAuth::isAvailable(), 404);

        $definition = $oauth->getProviderDefinition($provider);
        abort_unless($definition !== null && $oauth->managed($definition) !== null, 404);

        /** @var Response */
        return app()->call([app(OAuthController::class), 'callback'], ['provider' => $provider]);
    }
}
