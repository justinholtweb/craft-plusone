<?php

// Plus One harness: a fake OAuth 2 / OpenID Connect identity provider, so the whole
// redirect → authorize → callback → token → userinfo flow runs for real, with no
// provider account. check.sh copies this to the harness as routes/plusone-fake-idp.php,
// which the harness routes/web.php requires when present. Harness-only — never shipped.
//
//   GET  /plusone-fake-idp/authorize   (also Keycloak's realms/{realm}/protocol/openid-connect/auth)
//        Standard params, plus the identity to return: sub, email, verified (1|0, omit = no claim),
//        name; deny=1 answers access_denied.
//   POST /plusone-fake-idp/token       checks client id/secret, redirect_uri, one-time code and PKCE
//   GET  /plusone-fake-idp/userinfo    the claims for a bearer token
//
// Every token request is recorded in the cache as `plusone-idp:last` (pkce sent? verified?).

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$client = 'plusone-client';
$secret = 'plusone-secret';

$authorize = function (Request $request) use ($client) {
    if ($request->query('client_id') !== $client) {
        return response('unknown client', 400);
    }

    $redirect = (string) $request->query('redirect_uri');
    $state = $request->query('state');
    $sep = str_contains($redirect, '?') ? '&' : '?';

    if ($request->query('deny')) {
        return redirect($redirect.$sep.http_build_query(['error' => 'access_denied', 'state' => $state]));
    }

    $code = Str::random(40);
    $claims = array_filter([
        'sub' => (string) $request->query('sub', 'fake-user'),
        'email' => $request->query('email'),
        'name' => $request->query('name'),
        'preferred_username' => $request->query('username'),
    ], fn ($v) => $v !== null && $v !== '');

    if ($request->has('verified')) {
        $claims['email_verified'] = $request->query('verified') === '1';
    }

    Cache::put("plusone-idp:code:$code", [
        'redirect_uri' => $redirect,
        'challenge' => $request->query('code_challenge'),
        'method' => $request->query('code_challenge_method'),
        'claims' => $claims,
    ], 300);

    return redirect($redirect.$sep.http_build_query(['code' => $code, 'state' => $state]));
};

$token = function (Request $request) use ($client, $secret) {
    $id = $request->input('client_id');
    $sentSecret = $request->input('client_secret');

    if ($basic = $request->getUser()) {
        [$id, $sentSecret] = [$basic, $request->getPassword()];
    }

    $grant = Cache::pull('plusone-idp:code:'.$request->input('code'));
    $verifier = $request->input('code_verifier');
    $pkceOk = $grant !== null && (
        empty($grant['challenge'])
            ? true
            : is_string($verifier) && rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') === $grant['challenge']
    );

    Cache::put('plusone-idp:last', [
        'challengeSent' => ! empty($grant['challenge']),
        'method' => $grant['method'] ?? null,
        'verifierSent' => is_string($verifier) && $verifier !== '',
        'pkceOk' => $pkceOk,
    ], 600);

    if ($id !== $client || $sentSecret !== $secret) {
        return response()->json(['error' => 'invalid_client'], 401);
    }

    if ($grant === null || $grant['redirect_uri'] !== $request->input('redirect_uri') || ! $pkceOk) {
        return response()->json(['error' => 'invalid_grant'], 400);
    }

    $access = Str::random(40);
    Cache::put("plusone-idp:token:$access", $grant['claims'], 300);

    return response()->json(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 300]);
};

$userinfo = function (Request $request) {
    $claims = Cache::get('plusone-idp:token:'.$request->bearerToken());

    return $claims === null ? response()->json(['error' => 'invalid_token'], 401) : response()->json($claims);
};

Route::prefix('plusone-fake-idp')->withoutMiddleware([PreventRequestForgery::class])->group(function () use ($authorize, $token, $userinfo) {
    Route::get('authorize', $authorize);
    Route::post('token', $token);
    Route::get('userinfo', $userinfo);
    // Keycloak's layout, for the socialiteproviders/keycloak package.
    Route::get('realms/{realm}/protocol/openid-connect/auth', $authorize);
    Route::post('realms/{realm}/protocol/openid-connect/token', $token);
    Route::get('realms/{realm}/protocol/openid-connect/userinfo', $userinfo);
});
