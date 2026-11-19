<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Providers;

use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\PlusOne\Settings;

use function CraftCms\Cms\t;

/**
 * The CP-managed providers from Plus One's settings. Rows that don't validate (say a
 * config-file override with a typo) are skipped with a log warning, never half-used.
 */
class ProviderRegistry
{
    /** @var array<string, ManagedProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly GeneralConfig $generalConfig,
    ) {}

    /** @return array<string, ManagedProvider> Every valid managed provider, enabled or not, by handle. */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        $configHandles = $this->configFileHandles();

        foreach ($this->settings->providers ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $provider = ManagedProvider::fromRow($row);

            if (is_array($provider)) {
                $this->warnOnce('invalid:'.md5(serialize($row)), 'Plus One skipped an invalid provider: '.implode(' ', $provider));

                continue;
            }

            if (isset($configHandles[$provider->handle]) || isset($providers[$provider->handle])) {
                $this->warnOnce('dupe:'.$provider->handle, "Plus One skipped provider “{$provider->handle}”: the handle is already in use.");

                continue;
            }

            $providers[$provider->handle] = $provider;
        }

        return $this->providers = $providers;
    }

    public function get(string $handle): ?ManagedProvider
    {
        return $this->all()[$handle] ?? null;
    }

    /** @return array<string, true> Handles configured in `GeneralConfig::oauthProviders()`. */
    public function configFileHandles(): array
    {
        $handles = [];

        foreach ($this->generalConfig->oauthProviders as $key => $value) {
            $handle = is_int($key) ? (is_string($value) ? $value : null) : $key;
            if ($handle !== null && $handle !== '') {
                $handles[$handle] = true;
            }
        }

        return $handles;
    }

    /**
     * Validation errors for the Providers table.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<string>
     */
    public static function validateRows(array $rows): array
    {
        $errors = [];
        $seen = [];
        $configHandles = app(self::class)->configFileHandles();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $provider = ManagedProvider::fromRow($row);

            if (is_array($provider)) {
                array_push($errors, ...$provider);

                continue;
            }

            if (isset($seen[$provider->handle])) {
                $errors[] = t('Two providers use the handle “{handle}”.', ['handle' => $provider->handle], category: 'plusone');
            }

            if (isset($configHandles[$provider->handle])) {
                $errors[] = t('“{handle}” is already configured in `oauthProviders` (config/general.php). Pick another handle.', ['handle' => $provider->handle], category: 'plusone');
            }

            foreach ($provider->sites ?? [] as $site) {
                if (Sites::getSiteByHandle($site, true) === null) {
                    $errors[] = t('Provider “{handle}”: there’s no site with the handle “{site}”.', ['handle' => $provider->handle, 'site' => $site], category: 'plusone');
                }
            }

            $seen[$provider->handle] = true;
        }

        return $errors;
    }

    private function warnOnce(string $key, string $message): void
    {
        if (Cache::add('plusone:warned:'.$key, true, now()->addHour())) {
            Log::warning($message);
        }
    }
}
