<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Auth\OAuth\Actions\UserResolver as CoreUserResolver;
use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\Auth\OAuth\Exceptions\ProviderConfigurationException;
use CraftCms\Cms\Auth\OAuth\OAuth;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\UserGroups;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\PlusOne\Providers\DriverCatalogue;
use JustinHoltWeb\PlusOne\Providers\GenericProvider;
use JustinHoltWeb\PlusOne\Providers\ManagedProvider;
use JustinHoltWeb\PlusOne\Providers\ProviderRegistry;
use JustinHoltWeb\PlusOne\Settings;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\Config as CommunityConfig;
use Throwable;

/**
 * Craft's OAuth service, extended (bound in its place, so core's controllers, login page
 * and “Sign-in providers” screen all use it):
 *
 *  - CP-managed providers are added to the ones in `oauthProviders` — each normalized by
 *    core itself, so they behave exactly like config-file providers;
 *  - providers are filtered per site (front end) and by their “Control panel” switch (CP);
 *  - managed providers get PKCE, generic OIDC endpoints and SocialiteProviders config;
 *  - every provider using core's default user resolver gets Plus One's {@see SafeUserResolver},
 *    and no provider signs an admin in unless admins are allowed.
 */
#[Scoped]
class PlusOneOAuth extends OAuth
{
    /** @var Collection<string, ProviderDefinition>|null */
    private ?Collection $allDefinitions = null;

    /** @var array<string, true> Handles of definitions that came from Plus One. */
    private array $managedHandles = [];

    /** An address the provider verified during this sign-in, to record once the identity is linked. */
    private ?string $verifiedEmail = null;

    public function __construct(
        private readonly GeneralConfig $config,
        private readonly ProjectConfig $projects,
        private readonly UserGroups $groups,
        private readonly ProviderRegistry $registry,
        private readonly Settings $settings,
    ) {
        parent::__construct($config, $projects, $groups);
    }

    /**
     * Every provider (config file + CP-managed, enabled, with credentials), regardless of site.
     *
     * @return Collection<string, ProviderDefinition>
     */
    public function allProviderDefinitions(): Collection
    {
        if ($this->allDefinitions !== null) {
            return $this->allDefinitions;
        }

        $definitions = parent::getProviderDefinitions();

        if (self::isAvailable()) {
            foreach ($this->registry->all() as $handle => $managed) {
                if (! $managed->enabled || $definitions->has($handle)) {
                    continue;
                }

                if (! $managed->hasCredentials()) {
                    $this->warnOnce("creds:$handle", "Plus One provider “{$handle}” is skipped: its client ID or secret environment variable isn’t set.");

                    continue;
                }

                if ($definition = $this->normalizeManaged($managed)) {
                    $definitions->put($handle, $definition);
                    $this->managedHandles[$handle] = true;
                }
            }
        }

        return $this->allDefinitions = $definitions;
    }

    /**
     * The providers usable on this request: the CP login (or a `context=cp` callback) sees
     * providers switched on for the control panel; a site sees those enabled for it.
     *
     * @return Collection<string, ProviderDefinition>
     */
    #[\Override]
    public function getProviderDefinitions(): Collection
    {
        if ($this->isCpContext()) {
            return $this->allProviderDefinitions()->filter(fn (ProviderDefinition $d) => $this->availableInCp($d));
        }

        $site = rescue(fn () => Sites::getCurrentSite(), null, false);

        return $site ? $this->definitionsForSite($site) : collect();
    }

    /** @return Collection<string, ProviderDefinition> Providers that sign in on a site's front end. */
    public function definitionsForSite(Site $site): Collection
    {
        return $this->allProviderDefinitions()->filter(fn (ProviderDefinition $d) => $this->availableOnSite($d, $site));
    }

    /** A provider that signs in on the current site's front end (ignores `?context=cp`). */
    public function siteDefinition(string $handle): ?ProviderDefinition
    {
        $site = rescue(fn () => Sites::getCurrentSite(), null, false);

        return $site ? $this->definitionsForSite($site)->get($handle) : null;
    }

    public function availableOnSite(ProviderDefinition $definition, Site $site): bool
    {
        $managed = $this->managed($definition);

        return $managed === null || $managed->availableOnSite($site->handle);
    }

    public function availableInCp(ProviderDefinition $definition): bool
    {
        $managed = $this->managed($definition);

        return $managed === null || $managed->cp;
    }

    /** The CP-managed provider behind a definition, if it came from Plus One. */
    public function managed(ProviderDefinition $definition): ?ManagedProvider
    {
        $this->allProviderDefinitions();

        return isset($this->managedHandles[$definition->handle]) ? $this->registry->get($definition->handle) : null;
    }

    #[\Override]
    public function buildProvider(ProviderDefinition $provider, ?bool $isCpRequest = null): SocialiteProvider
    {
        $isCpRequest ??= $this->isCpContext();
        $driver = parent::buildProvider($provider, $isCpRequest);
        $managed = $this->managed($provider);

        if ($managed === null) {
            return $driver;
        }

        if ($driver instanceof GenericProvider) {
            $driver->withEndpoints($managed->options['authorize'], $managed->options['token'], $managed->options['userinfo']);
        }

        if (DriverCatalogue::isCommunity($managed->driver) && class_exists(CommunityConfig::class) && method_exists($driver, 'setConfig')) {
            $driver->setConfig(new CommunityConfig(
                $provider->clientId,
                $provider->clientSecret,
                $this->callbackPath($provider, $isCpRequest),
                $managed->communityConfig(),
            ));

            if ($provider->scopes !== [] && method_exists($driver, 'scopes')) {
                $driver->scopes($provider->scopes);
            }
        }

        if ($managed->pkce && $driver instanceof AbstractProvider) {
            $driver->enablePKCE();
        }

        return $driver;
    }

    /**
     * Plus One's providers return to `{site}/actions/plusone/callback/{handle}` (which works on
     * sites under a path, unlike core's `oauth/{handle}/callback`). Core uses this for the
     * redirect URI in both the authorize and the token request.
     */
    #[\Override]
    public function callbackPath(ProviderDefinition $provider, bool $isCpRequest = false): string
    {
        if ($this->managed($provider) === null) {
            return parent::callbackPath($provider, $isCpRequest);
        }

        return self::siteActionUrl('callback/'.$provider->handle, $isCpRequest ? ['context' => 'cp'] : null);
    }

    /** Front-end buttons start at Plus One's login action; the CP keeps core's route. */
    #[\Override]
    public function redirectPath(ProviderDefinition $provider, bool $isCpRequest = false): string
    {
        if ($isCpRequest || $this->managed($provider) === null) {
            return parent::redirectPath($provider, $isCpRequest);
        }

        return self::siteActionUrl('login/'.$provider->handle);
    }

    /**
     * A Plus One action URL on a site (default: the current one), e.g. `…/actions/plusone/login/google`.
     *
     * @param  array<string, string>|null  $params
     */
    public static function siteActionUrl(string $path, ?array $params = null, ?int $siteId = null): string
    {
        return Url::siteUrl(trim(Cms::config()->actionTrigger, '/').'/plusone/'.ltrim($path, '/'), $params, null, $siteId);
    }

    #[\Override]
    public function resolveUser(ProviderDefinition $provider, SocialiteUser $socialiteUser, string $identity): ?User
    {
        $user = $provider->userResolver === CoreUserResolver::class
            ? app(SafeUserResolver::class)->handle($provider, $socialiteUser, $identity)
            : parent::resolveUser($provider, $socialiteUser, $identity);

        if ($user?->admin && ! $this->settings->allowAdmins) {
            throw new LoginRefused(LoginRefused::ADMIN, $provider->handle);
        }

        return $user;
    }

    /** @return int[] */
    #[\Override]
    public function resolveGroupIds(ProviderDefinition $provider, SocialiteUser $socialiteUser, User $user, string $identity): array
    {
        $ids = parent::resolveGroupIds($provider, $socialiteUser, $user, $identity);

        // Plus One's own group resolver already adds the default group.
        if ($provider->groupResolver !== GroupResolver::class && $this->settings->defaultUserGroup) {
            $group = $this->groups->getGroupByHandle($this->settings->defaultUserGroup);
            if ($group?->id) {
                $ids[] = $group->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function noteVerifiedEmail(string $email): void
    {
        $this->verifiedEmail = $email;
    }

    /**
     * Core links the identity after saving a new user (or signing in a matched one). If the
     * provider verified the address that account now has, that's proof the user owns it.
     */
    #[\Override]
    public function linkIdentity(User $user, ProviderDefinition $provider, string $identity): void
    {
        parent::linkIdentity($user, $provider, $identity);

        if ($this->verifiedEmail !== null && is_string($user->email) && mb_strtolower(trim($user->email)) === mb_strtolower(trim($this->verifiedEmail))) {
            app(EmailProofs::class)->record($user, $user->email, EmailProofs::METHOD_PROVIDER);
        }

        $this->verifiedEmail = null;
    }

    /** Whether this request is the control panel's (or a CP sign-in's callback). */
    public function isCpContext(): bool
    {
        $request = request();

        return $request->isCpRequest() || $request->query('context') === 'cp';
    }

    /**
     * Core normalizes the provider (validation, group handles, driver checks) through a
     * throwaway OAuth service whose config holds just this provider, so a bad managed row
     * can never break config-file providers, and nothing global is mutated.
     */
    private function normalizeManaged(ManagedProvider $managed): ?ProviderDefinition
    {
        $config = clone $this->config;
        $config->oauthProviders = [$managed->handle => $managed->coreConfig()];

        try {
            return new OAuth($config, $this->projects, $this->groups)->getProviderDefinition($managed->handle);
        } catch (ProviderConfigurationException $e) {
            $this->warnOnce("config:{$managed->handle}", "Plus One provider “{$managed->handle}” is misconfigured: {$e->getMessage()}");
        } catch (Throwable $e) {
            $this->warnOnce("config:{$managed->handle}", "Plus One provider “{$managed->handle}” couldn’t be loaded: ".$e::class);
        }

        return null;
    }

    private function warnOnce(string $key, string $message): void
    {
        if (Cache::add('plusone:warned:'.$key, true, now()->addHour())) {
            Log::warning($message);
        }
    }
}
