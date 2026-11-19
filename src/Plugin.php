<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne;

use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\Auth\OAuth\OAuth;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Gql\GqlQueries;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\OAuth as OAuthFacade;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Routing\Router;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;
use JustinHoltWeb\PlusOne\Http\Middleware\GuardOAuthRoutes;
use JustinHoltWeb\PlusOne\Providers\DriverCatalogue;
use JustinHoltWeb\PlusOne\Providers\ManagedProvider;
use JustinHoltWeb\PlusOne\Providers\ProviderRegistry;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Plus One — social sign-in for Craft 6, managed in the control panel, on top of Craft's
 * own OAuth (Socialite) support: CP-managed providers, every SocialiteProviders package,
 * front-end sign-in and connected accounts, and account-takeover guards.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    protected array $events = [
        TemplateGlobalsResolving::class => Listeners\RegisterTemplateApi::class,
        GqlSchemaComponentsResolving::class => Listeners\RegisterGqlComponents::class,
    ];

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());
        $this->app->scoped(ProviderRegistry::class);
        $this->app->scoped(PlusOneOAuth::class);

        // Craft's OAuth service, everywhere (controllers, login page, “Sign-in providers”), is Plus One's.
        $this->app->scoped(OAuth::class, fn ($app) => $app->make(PlusOneOAuth::class));
        $this->app->forgetInstance(OAuth::class);
        OAuthFacade::clearResolvedInstance(OAuth::class);

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->pushMiddlewareToGroup('craft.web', GuardOAuthRoutes::class);
        $router->pushMiddlewareToGroup('craft.cp', GuardOAuthRoutes::class);

        // GraphQL `plusOneProviders`, for schemas given “List sign-in providers”.
        $this->app->make(GqlQueries::class)->register(Gql\ProvidersQuery::class);
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $drivers = [];
        foreach (DriverCatalogue::options() as $value => $label) {
            $drivers[] = ['label' => $label, 'value' => $value];
        }

        $sites = [];
        foreach (Sites::getAllSites(true) as $site) {
            $sites[] = ['label' => $site->name, 'value' => $site->handle];
        }

        return Ui::make([
            ...(PlusOneOAuth::isAvailable() ? [] : [
                MarkdownContent::make('plusone-unavailable', t('**Craft’s OAuth sign-in isn’t available**, so Plus One’s providers are inactive. It needs Craft Pro and the `laravel/socialite` package.', category: 'plusone')),
            ]),
            Heading::make('plusone-providers', t('Providers', category: 'plusone'))
                ->description(t('Providers in `oauthProviders` (config/general.php) keep working alongside these; Plus One’s sign-in rules apply to them too.', category: 'plusone')),
            Field::make(t('Providers', category: 'plusone'), Table::make('providers')
                ->columns([
                    'enabled' => ['heading' => t('On', category: 'plusone'), 'type' => 'lightswitch'],
                    'handle' => ['heading' => t('Handle', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'google'],
                    'driver' => ['heading' => t('Provider', category: 'plusone'), 'type' => 'select', 'options' => $drivers],
                    'name' => ['heading' => t('Name', category: 'plusone'), 'type' => 'singleline', 'placeholder' => 'Google'],
                    'clientId' => ['heading' => t('Client ID', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => '$GOOGLE_CLIENT_ID'],
                    'clientSecret' => ['heading' => t('Client secret (env var)', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => '$GOOGLE_CLIENT_SECRET'],
                    'scopes' => ['heading' => t('Extra scopes', category: 'plusone'), 'type' => 'singleline', 'code' => true],
                    'sites' => ['heading' => t('Sites', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => '*'],
                    'cp' => ['heading' => t('CP login', category: 'plusone'), 'type' => 'lightswitch'],
                    'newUsers' => ['heading' => t('New users', category: 'plusone'), 'type' => 'select', 'options' => [
                        ['label' => t('If public registration is on', category: 'plusone'), 'value' => ManagedProvider::NEW_USERS_DEFAULT],
                        ['label' => t('Always register', category: 'plusone'), 'value' => ManagedProvider::NEW_USERS_ALLOW],
                        ['label' => t('Never register', category: 'plusone'), 'value' => ManagedProvider::NEW_USERS_DENY],
                    ]],
                    'linkByEmail' => ['heading' => t('Match by verified email', category: 'plusone'), 'type' => 'lightswitch'],
                    'pkce' => ['heading' => 'PKCE', 'type' => 'lightswitch'],
                    'options' => ['heading' => t('Options', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'param.hd=example.com'],
                ])
                ->defaultValues(['enabled' => true, 'sites' => '*', 'pkce' => true, 'newUsers' => ManagedProvider::NEW_USERS_DEFAULT])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add a provider', category: 'plusone')))
                ->instructions(t('Client secrets must be environment variable references (`$GOOGLE_CLIENT_SECRET`): Plus One never stores or shows a secret. **Sites**: `*` or site handles; **CP login** adds the button to the control panel’s login page. **Match by verified email** lets someone sign in to an existing account with the same email, but only when the provider says it verified that address (never for admins). **Options**, `key=value; …`: `groups=handle` (groups for new users), `param.name=value` (extra authorization parameters, e.g. Google’s `param.hd`), OpenID Connect’s `authorize=`, `token=`, `userinfo=` URLs, and a SocialiteProviders package’s own keys (Keycloak: `base_url=…; realms=…`).', category: 'plusone')),
            MarkdownContent::make('plusone-status', $this->statusMarkdown()),
            Heading::make('plusone-registration', t('Registration rules', category: 'plusone'))
                ->description(t('Applied to every provider, including those in config/general.php. Whether new users can register at all follows each provider’s “New users” and Craft’s public registration setting.', category: 'plusone')),
            Field::make(t('Require a verified email', category: 'plusone'), Lightswitch::make('requireVerifiedEmail'))
                ->instructions(t('Only register someone when the provider confirms it verified their email address.', category: 'plusone')),
            Field::make(t('Allowed email domains', category: 'plusone'), Textarea::make('allowedEmailDomains')->rows(2)->placeholder('example.com'))
                ->instructions(t('Only register people whose email is at one of these domains (comma or line separated). Blank allows any domain.', category: 'plusone')),
            Field::make(t('Default user group', category: 'plusone'), Text::make('defaultUserGroup')->placeholder('members'))
                ->instructions(t('Handle of a user group new users join.', category: 'plusone')),
            Field::make(t('Activate new users', category: 'plusone'), Lightswitch::make('activateNewUsers'))
                ->instructions(t('Activate people Plus One registers when their email is verified by the provider, instead of leaving them pending.', category: 'plusone')),
            Field::make(t('Allow admins', category: 'plusone'), Lightswitch::make('allowAdmins'))
                ->instructions(t('Let admin accounts sign in with, connect or be matched to a social account. Off by default, because Craft skips two-step verification for social sign-in.', category: 'plusone')),
            Heading::make('plusone-front-end', t('Front end', category: 'plusone')),
            Field::make(t('Front-end account linking', category: 'plusone'), Lightswitch::make('frontEndLinking'))
                ->instructions(t('Let logged-in users connect and disconnect providers from the front end (`plusone.accounts()`).', category: 'plusone')),
            Field::make(t('Sites', category: 'plusone'), Table::make('sites')
                ->columns([
                    'site' => ['heading' => t('Site', category: 'plusone'), 'type' => 'select', 'options' => $sites],
                    'loginPath' => ['heading' => t('Sign-in page', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'login'],
                    'accountPath' => ['heading' => t('Account page', category: 'plusone'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'account'],
                ])
                ->allowAdd()->allowDelete()
                ->addRowLabel(t('Add a site', category: 'plusone')))
                ->instructions(t('Per site: where a failed front-end sign-in lands (instead of the control panel’s login page), and where connecting or disconnecting a provider returns to when the form doesn’t say. Paths on that site only.', category: 'plusone')),
        ]);
    }

    /** Each provider's state and the callback URL to register with it, per site. Never a secret. */
    private function statusMarkdown(): string
    {
        try {
            $oauth = app(PlusOneOAuth::class);
            $registry = app(ProviderRegistry::class);
            $definitions = $oauth->allProviderDefinitions();
        } catch (Throwable) {
            return t('Provider status is unavailable: check the providers above and the log.', category: 'plusone');
        }

        $lines = [];

        foreach ($registry->all() as $handle => $managed) {
            $state = match (true) {
                ! $managed->enabled => t('off', category: 'plusone'),
                ! $managed->hasCredentials() => t('**not ready**: its client ID or secret environment variable isn’t set', category: 'plusone'),
                ! $definitions->has($handle) => t('**not ready**: see the log', category: 'plusone'),
                default => t('ready', category: 'plusone'),
            };
            $lines[] = "- **".e($managed->name)."** (`{$handle}`, ".DriverCatalogue::label($managed->driver)."): {$state}";

            $definition = $definitions->get($handle);
            if ($definition instanceof ProviderDefinition) {
                foreach (Sites::getAllSites() as $site) {
                    if ($site->hasUrls && $managed->availableOnSite($site->handle)) {
                        $lines[] = '  - '.t('Callback on {site}', ['site' => $site->name], category: 'plusone').': `'.PlusOneOAuth::siteActionUrl("callback/{$handle}", null, $site->id).'`';
                    }
                }
            }
        }

        foreach ($registry->configFileHandles() as $handle => $_) {
            $lines[] = "- `{$handle}`: ".t('from config/general.php', category: 'plusone');
        }

        $community = array_keys(DriverCatalogue::community());
        $lines[] = '';
        $lines[] = $community === []
            ? t('No SocialiteProviders packages are installed. Add one with `composer require socialiteproviders/<name>` and it appears under Provider.', category: 'plusone')
            : t('SocialiteProviders packages installed: {list}.', ['list' => implode(', ', array_map(fn ($p) => "`{$p}`", $community))], category: 'plusone');

        return ($lines === [''] ? '' : t('**Status**', category: 'plusone')."\n\n").implode("\n", $lines);
    }
}
