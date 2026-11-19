<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Providers;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Any OpenID Connect (or OIDC-shaped OAuth 2) identity provider — Authentik, Keycloak,
 * Zitadel, Auth0, Okta, a company SSO — configured with its three endpoint URLs in the
 * provider's options: `authorize=…; token=…; userinfo=…`.
 */
class GenericProvider extends AbstractProvider
{
    /** @var list<string> */
    protected $scopes = ['openid', 'email', 'profile'];

    protected $scopeSeparator = ' ';

    private string $authorizeUrl = '';

    private string $tokenUrl = '';

    private string $userinfoUrl = '';

    public function withEndpoints(string $authorizeUrl, string $tokenUrl, string $userinfoUrl): static
    {
        $this->authorizeUrl = $authorizeUrl;
        $this->tokenUrl = $tokenUrl;
        $this->userinfoUrl = $userinfoUrl;

        return $this;
    }

    /** @param string $state */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoint($this->authorizeUrl), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoint($this->tokenUrl);
    }

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->endpoint($this->userinfoUrl), [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
            RequestOptions::TIMEOUT => 15,
        ]);

        $user = json_decode((string) $response->getBody(), true);

        if (! is_array($user)) {
            throw new RuntimeException('The identity provider’s userinfo endpoint didn’t return a JSON object.');
        }

        return $user;
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub', Arr::get($user, 'id')),
            'nickname' => Arr::get($user, 'preferred_username', Arr::get($user, 'nickname')),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }

    private function endpoint(string $url): string
    {
        if ($url === '') {
            throw new RuntimeException('This provider is missing its endpoint URLs (options: authorize=…; token=…; userinfo=…).');
        }

        return $url;
    }
}
