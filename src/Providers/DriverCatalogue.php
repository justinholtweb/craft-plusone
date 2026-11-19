<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Providers;

use Composer\InstalledVersions;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * The drivers a CP-managed provider can use:
 *  - Socialite's own OAuth 2 drivers (`google`, `github`, …),
 *  - `generic`: Plus One's OpenID Connect provider (endpoint URLs in the options),
 *  - every installed `socialiteproviders/*` OAuth 2 package, as `socialiteproviders/<name>`.
 */
final class DriverCatalogue
{
    public const string GENERIC = 'generic';

    public const string COMMUNITY_PREFIX = 'socialiteproviders/';

    /** Socialite's built-in OAuth 2 drivers. (`twitter` is OAuth 1, which Craft's OAuth doesn't support.) */
    public const array BUILT_IN = [
        'google' => 'Google',
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'facebook' => 'Facebook',
        'linkedin-openid' => 'LinkedIn',
        'slack-openid' => 'Slack',
        'twitch' => 'Twitch',
        'x' => 'X',
    ];

    /** @var array<string, array{label: string, class: class-string<AbstractProvider>, keys: list<string>}>|null */
    private static ?array $community = null;

    /** @return array<string, string> driver => label, for the CP's driver menu. */
    public static function options(): array
    {
        $options = self::BUILT_IN + [self::GENERIC => 'OpenID Connect (any provider)'];

        foreach (self::community() as $driver => $package) {
            $options[$driver] = $package['label'].' (SocialiteProviders)';
        }

        return $options;
    }

    public static function exists(string $driver): bool
    {
        return isset(self::BUILT_IN[$driver]) || $driver === self::GENERIC || isset(self::community()[$driver]);
    }

    public static function isCommunity(string $driver): bool
    {
        return str_starts_with($driver, self::COMMUNITY_PREFIX);
    }

    public static function label(string $driver): string
    {
        return self::options()[$driver] ?? $driver;
    }

    /** @return class-string<AbstractProvider>|null */
    public static function communityClass(string $driver): ?string
    {
        return self::community()[$driver]['class'] ?? null;
    }

    /** @return list<string> A SocialiteProviders package's extra config keys (e.g. Keycloak's `base_url`, `realms`). */
    public static function communityKeys(string $driver): array
    {
        return self::community()[$driver]['keys'] ?? [];
    }

    /**
     * Installed SocialiteProviders OAuth 2 packages, discovered from Composer's metadata
     * (each package's PSR-4 namespace + `Provider` class). OAuth 1 packages are skipped.
     *
     * @return array<string, array{label: string, class: class-string<AbstractProvider>, keys: list<string>}>
     */
    public static function community(): array
    {
        if (self::$community !== null) {
            return self::$community;
        }

        $found = [];

        if (class_exists(InstalledVersions::class)) {
            foreach (InstalledVersions::getInstalledPackages() as $package) {
                if (! str_starts_with($package, self::COMMUNITY_PREFIX) || $package === 'socialiteproviders/manager') {
                    continue;
                }

                $class = self::providerClass($package);

                if ($class === null) {
                    continue;
                }

                $keys = [];
                try {
                    $declared = method_exists($class, 'additionalConfigKeys') ? $class::additionalConfigKeys() : [];
                    $keys = array_values(array_filter(is_array($declared) ? $declared : [], 'is_string'));
                } catch (Throwable) {
                    // A provider that can't describe itself is still usable with no extra keys.
                }

                $name = substr($package, strlen(self::COMMUNITY_PREFIX));
                $found[$package] = [
                    'label' => ucwords(str_replace(['-', '_'], ' ', $name)),
                    'class' => $class,
                    'keys' => $keys,
                ];
            }
        }

        ksort($found);

        return self::$community = $found;
    }

    /** For tests: forget discovered packages. */
    public static function reset(): void
    {
        self::$community = null;
    }

    /** @return class-string<AbstractProvider>|null */
    private static function providerClass(string $package): ?string
    {
        try {
            $path = InstalledVersions::getInstallPath($package);
        } catch (Throwable) {
            return null;
        }

        $manifest = $path ? @file_get_contents(rtrim($path, '/').'/composer.json') : false;
        $json = is_string($manifest) ? json_decode($manifest, true) : null;
        $namespaces = is_array($json) ? array_keys((array) ($json['autoload']['psr-4'] ?? [])) : [];

        foreach ($namespaces as $namespace) {
            $class = rtrim((string) $namespace, '\\').'\\Provider';

            if (class_exists($class) && is_subclass_of($class, AbstractProvider::class)) {
                return $class;
            }
        }

        return null;
    }
}
