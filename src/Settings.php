<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne;

use Closure;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use JustinHoltWeb\PlusOne\Auth\ReturnUrl;
use JustinHoltWeb\PlusOne\Providers\ProviderRegistry;

use function CraftCms\Cms\t;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 *
 * Everything here can also be set in `config/craft/plusone.php` (array values work there).
 */
class Settings extends PluginSettings
{
    /**
     * Provider rows: enabled, handle, driver, name, clientId, clientSecret (an `$ENV_VAR`
     * reference, never a literal), scopes, sites (`*` or site handles), cp, newUsers
     * (`default`|`allow`|`deny`), linkByEmail, pkce, options (`key=value; …`).
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $providers = [];

    /** Only register new users whose email is at one of these domains (comma or newline separated). Blank = any. */
    public ?string $allowedEmailDomains = null;

    /** Handle of the user group new users join (on top of any groups a provider's options add). */
    public ?string $defaultUserGroup = null;

    /** Refuse to register a user unless the provider says it verified their email address. */
    public bool $requireVerifiedEmail = true;

    /** Activate users Plus One registers (they proved their email to the provider) instead of leaving them pending. */
    public bool $activateNewUsers = true;

    /** Let admins sign in with, connect or be matched to a social account. Off by default: social sign-in skips Craft's two-step verification. */
    public bool $allowAdmins = false;

    /** Let logged-in users connect and disconnect providers from the front end. */
    public bool $frontEndLinking = true;

    /**
     * Per-site rows: site (handle), loginPath (where failed sign-ins land), accountPath
     * (where connect/disconnect return to by default).
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $sites = [];

    /** @return list<string> Lower-cased domains, without a leading `@`. */
    public function allowedDomains(): array
    {
        $domains = preg_split('/[\s,]+/', mb_strtolower((string) $this->allowedEmailDomains), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn (string $d) => ltrim($d, '@'), $domains)));
    }

    /**
     * The settings row for a site, keyed by its handle.
     *
     * @return array{loginPath: ?string, accountPath: ?string}
     */
    public function siteSettings(string $siteHandle): array
    {
        foreach ($this->sites ?? [] as $row) {
            if (is_array($row) && ($row['site'] ?? null) === $siteHandle) {
                return [
                    'loginPath' => self::blankToNull($row['loginPath'] ?? null),
                    'accountPath' => self::blankToNull($row['accountPath'] ?? null),
                ];
            }
        }

        return ['loginPath' => null, 'accountPath' => null];
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    public function getRules(): array
    {
        return [
            'providers' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (ProviderRegistry::validateRows(is_array($value) ? $value : []) as $error) {
                    $fail($error);
                }
            }],
            'allowedEmailDomains' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                foreach ($this->allowedDomains() as $domain) {
                    if (! preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain)) {
                        $fail(t('“{domain}” isn’t a domain name.', ['domain' => $domain], category: 'plusone'));
                    }
                }
            }],
            'defaultUserGroup' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && UserGroups::getGroupByHandle($value) === null) {
                    $fail(t('There’s no user group with the handle “{handle}”.', ['handle' => $value], category: 'plusone'));
                }
            }],
            'sites' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (is_array($value) ? $value : [] as $row) {
                    $handle = is_array($row) ? (string) ($row['site'] ?? '') : '';
                    if (Sites::getSiteByHandle($handle, true) === null) {
                        $fail(t('There’s no site with the handle “{handle}”.', ['handle' => $handle], category: 'plusone'));

                        continue;
                    }
                    foreach (['loginPath', 'accountPath'] as $key) {
                        $path = self::blankToNull($row[$key] ?? null);
                        if ($path !== null && ReturnUrl::normalizePath($path) === null) {
                            $fail(t('“{path}” must be a path on this site, like `account` or `/account`.', ['path' => $path], category: 'plusone'));
                        }
                    }
                }
            }],
        ];
    }
}
