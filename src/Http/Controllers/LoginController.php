<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;
use JustinHoltWeb\PlusOne\Auth\ReturnUrl;
use JustinHoltWeb\PlusOne\Http\Middleware\GuardOAuthRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET {site}/actions/plusone/login/{provider}?return=/path — starts a front-end sign-in.
 *
 * Like core's `oauth/{provider}/redirect`, plus a `return` path (on-site only) and a
 * note so a failed sign-in lands on this site's sign-in page instead of the CP's.
 */
class LoginController
{
    public function __invoke(Request $request, PlusOneOAuth $oauth, string $provider): Response
    {
        abort_unless(PlusOneOAuth::isAvailable(), 404);
        abort_if(! $definition = $oauth->siteDefinition($provider), 404);

        $site = Sites::getCurrentSite();
        $return = ReturnUrl::resolve(
            is_string($request->query('return')) ? $request->query('return') : null,
            Url::siteUrl((string) Cms::config()->getPostLoginRedirect($site->handle)),
        );

        if ($request->craftUser()) {
            return redirect($return);
        }

        Redirect::setIntendedUrl($return);
        $request->session()->put(GuardOAuthRoutes::LOGIN_SESSION_KEY, ['site' => $site->handle]);

        return $oauth->buildProvider($definition, false)->redirect();
    }
}
