<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Providers;

use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Str;
use JustinHoltWeb\PlusOne\Auth\GroupResolver;
use JustinHoltWeb\PlusOne\Auth\SafeUserResolver;
use JustinHoltWeb\PlusOne\Auth\UserPopulator;

use function CraftCms\Cms\t;

/**
 * One provider managed in the CP (a row of the Providers table), validated.
 * Credentials are kept as references (`$GOOGLE_CLIENT_SECRET`) and resolved only
 * when a provider is built; they're never echoed back to the CP or templates.
 */
final readonly class ManagedProvider
{
    public const string NEW_USERS_DEFAULT = 'default';

    public const string NEW_USERS_ALLOW = 'allow';

    public const string NEW_USERS_DENY = 'deny';

    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $sites  Site handles the provider signs in on; null = every site.
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $handle,
        public string $driver,
        public string $name,
        public bool $enabled,
        public string $clientIdRef,
        public string $clientSecretRef,
        public array $scopes,
        public ?array $sites,
        public bool $cp,
        public string $newUsers,
        public bool $linkByEmail,
        public bool $pkce,
        public array $options,
    ) {}

    /**
     * Builds a provider from a settings row, or returns the reasons it's invalid.
     *
     * @param  array<array-key, mixed>  $row
     * @return self|list<string>
     */
    public static function fromRow(array $row): self|array
    {
        $errors = [];
        $handle = trim((string) ($row['handle'] ?? ''));
        $label = $handle !== '' ? $handle : t('(no handle)', category: 'plusone');

        if (! preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $handle) || strlen($handle) > 64) {
            $errors[] = t('Provider “{handle}”: handles are lower-case letters, numbers and dashes, starting with a letter.', ['handle' => $label], category: 'plusone');
        }

        $driver = trim((string) ($row['driver'] ?? ''));
        if (! DriverCatalogue::exists($driver)) {
            $errors[] = t('Provider “{handle}”: unknown provider type “{driver}”. SocialiteProviders packages must be installed with Composer first.', ['handle' => $label, 'driver' => $driver], category: 'plusone');
        }

        $clientId = trim((string) ($row['clientId'] ?? ''));
        if ($clientId === '') {
            $errors[] = t('Provider “{handle}”: a client ID is required.', ['handle' => $label], category: 'plusone');
        }

        $clientSecret = trim((string) ($row['clientSecret'] ?? ''));
        if (! self::isEnvReference($clientSecret)) {
            $errors[] = t('Provider “{handle}”: the client secret must be an environment variable reference like `$GOOGLE_CLIENT_SECRET`, so it never lands in project config.', ['handle' => $label], category: 'plusone');
        }

        $sites = self::parseList((string) ($row['sites'] ?? '*'));
        $sites = $sites === [] || in_array('*', $sites, true) ? null : $sites;

        $newUsers = (string) ($row['newUsers'] ?? self::NEW_USERS_DEFAULT) ?: self::NEW_USERS_DEFAULT;
        if (! in_array($newUsers, [self::NEW_USERS_DEFAULT, self::NEW_USERS_ALLOW, self::NEW_USERS_DENY], true)) {
            $errors[] = t('Provider “{handle}”: “New users” must be default, allow or deny.', ['handle' => $label], category: 'plusone');
        }

        $options = self::parseOptions((string) ($row['options'] ?? ''), $label, $errors);

        if ($driver === DriverCatalogue::GENERIC) {
            foreach (['authorize', 'token', 'userinfo'] as $key) {
                if (! isset($options[$key])) {
                    $errors[] = t('Provider “{handle}”: OpenID Connect providers need `{key}=https://…` in their options.', ['handle' => $label, 'key' => $key], category: 'plusone');
                } elseif (! self::isEndpoint($options[$key])) {
                    $errors[] = t('Provider “{handle}”: `{key}` must be an https:// URL (http:// only for localhost).', ['handle' => $label, 'key' => $key], category: 'plusone');
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $name = trim((string) ($row['name'] ?? ''));

        return new self(
            handle: $handle,
            driver: $driver,
            name: $name !== '' ? $name : (DriverCatalogue::BUILT_IN[$driver] ?? Str::headline($handle)),
            enabled: self::bool($row['enabled'] ?? true),
            clientIdRef: $clientId,
            clientSecretRef: $clientSecret,
            scopes: self::parseList((string) ($row['scopes'] ?? '')),
            sites: $sites,
            cp: self::bool($row['cp'] ?? false),
            newUsers: $newUsers,
            linkByEmail: self::bool($row['linkByEmail'] ?? false),
            pkce: self::bool($row['pkce'] ?? true),
            options: $options,
        );
    }

    public function clientId(): ?string
    {
        $value = Env::parse($this->clientIdRef);

        return is_string($value) && $value !== '' && ! self::isEnvReference($value) ? $value : null;
    }

    public function clientSecret(): ?string
    {
        $value = Env::parse($this->clientSecretRef);

        return is_string($value) && $value !== '' && $value !== $this->clientSecretRef ? $value : null;
    }

    /** Whether both credentials resolve in this environment. */
    public function hasCredentials(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    public function availableOnSite(string $siteHandle): bool
    {
        return $this->sites === null || in_array($siteHandle, $this->sites, true);
    }

    /** The driver core's OAuth service should see: a Socialite driver name or a provider class. */
    public function coreDriver(): string
    {
        return match (true) {
            $this->driver === DriverCatalogue::GENERIC => GenericProvider::class,
            DriverCatalogue::isCommunity($this->driver) => (string) DriverCatalogue::communityClass($this->driver),
            default => $this->driver,
        };
    }

    /**
     * The provider as an `oauthProviders` config entry, for core to normalize.
     * Plus One's resolver, populator and group resolver enforce its rules.
     *
     * @return array<string, mixed>
     */
    public function coreConfig(): array
    {
        return [
            'driver' => $this->coreDriver(),
            'clientId' => $this->clientId(),
            'clientSecret' => $this->clientSecret(),
            'name' => $this->name,
            'label' => t('Sign in with {name}', ['name' => $this->name], category: 'plusone'),
            'scopes' => $this->scopes,
            'with' => $this->authParams(),
            // Never stateless: the `state` check is what stops login CSRF.
            'stateless' => false,
            'createsUsers' => match ($this->newUsers) {
                self::NEW_USERS_ALLOW => true,
                self::NEW_USERS_DENY => false,
                default => null,
            },
            'trustsEmail' => $this->linkByEmail,
            // Core's activation also unsuspends matched users; Plus One activates new users itself.
            'activatesUsers' => false,
            'userResolver' => SafeUserResolver::class,
            'userPopulator' => UserPopulator::class,
            'groupResolver' => GroupResolver::class,
        ];
    }

    /** @return array<string, string> Extra authorization-request parameters (`param.hd=example.com`). */
    public function authParams(): array
    {
        $params = [];

        foreach ($this->options as $key => $value) {
            if (str_starts_with($key, 'param.') && strlen($key) > 6) {
                $params[substr($key, 6)] = $value;
            }
        }

        return $params;
    }

    /** @return list<string> Group handles from the `groups` option. */
    public function groupHandles(): array
    {
        return self::parseList($this->options['groups'] ?? '');
    }

    /** @return array<string, string> A SocialiteProviders package's extra config (its declared keys only). */
    public function communityConfig(): array
    {
        $config = [];

        foreach (DriverCatalogue::communityKeys($this->driver) as $key) {
            if (isset($this->options[$key])) {
                $config[$key] = (string) Env::parse($this->options[$key]);
            }
        }

        return $config;
    }

    public static function isEnvReference(string $value): bool
    {
        return (bool) preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $value);
    }

    public static function isEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /** @return list<string> */
    public static function parseList(string $value): array
    {
        return array_values(array_unique(preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /**
     * `key=value; key=value` (or one per line).
     *
     * @param  list<string>  $errors
     * @return array<string, string>
     */
    private static function parseOptions(string $value, string $label, array &$errors): array
    {
        $options = [];

        foreach (preg_split('/[;\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (trim($pair) === '') {
                continue;
            }

            [$key, $val] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];

            if (! preg_match('/^[A-Za-z][A-Za-z0-9_.\-]*$/', $key) || $val === '') {
                $errors[] = t('Provider “{handle}”: options are `key=value` pairs separated by `;` (couldn’t read “{pair}”).', ['handle' => $label, 'pair' => trim($pair)], category: 'plusone');

                continue;
            }

            $options[$key] = $val;
        }

        return $options;
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
