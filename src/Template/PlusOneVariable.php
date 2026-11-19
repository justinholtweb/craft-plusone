<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Template;

use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use JustinHoltWeb\PlusOne\Auth\LoginMethods;
use JustinHoltWeb\PlusOne\Auth\PlusOneOAuth;
use JustinHoltWeb\PlusOne\Settings;

use function CraftCms\Cms\t;

/**
 * `plusone` in Twig and `$plusone` in Blade: sign-in buttons and connected accounts for
 * the current site. Never exposes tokens, identity ids or credentials.
 *
 *   {{ plusone.buttons('/account') }}              {!! $plusone->buttons('/account') !!}
 *   {% for p in plusone.providers() %}…            @foreach ($plusone->providers() as $p) …
 *   {% for a in plusone.accounts() %}…{{ plusone.connectForm(a.handle) }}
 */
class PlusOneVariable
{
    public function __construct(
        private readonly PlusOneOAuth $oauth,
        private readonly LoginMethods $loginMethods,
        private readonly Settings $settings,
    ) {}

    public function isAvailable(): bool
    {
        return PlusOneOAuth::isAvailable();
    }

    /**
     * Providers that sign in on the current site.
     *
     * @return list<array{handle: string, name: string, label: string, icon: ?string, loginUrl: string}>
     */
    public function providers(?string $return = null): array
    {
        return $this->siteDefinitions()
            ->map(fn (ProviderDefinition $d) => [
                'handle' => $d->handle,
                'name' => $d->name,
                'label' => $d->label,
                'icon' => $d->icon,
                'loginUrl' => $this->loginUrl($d->handle, $return),
            ])
            ->values()
            ->all();
    }

    /** `{site}/actions/plusone/login/{handle}`, with an on-site `return` path (checked again when used). */
    public function loginUrl(string $handle, ?string $return = null): string
    {
        return PlusOneOAuth::siteActionUrl('login/'.rawurlencode($handle), $return !== null && $return !== '' ? ['return' => $return] : null);
    }

    /** One link per provider, `<a class="plusone-button plusone-button--{handle}">Sign in with …</a>`. */
    public function buttons(?string $return = null): HtmlString
    {
        $html = '';

        foreach ($this->providers($return) as $provider) {
            $html .= Html::a(e($provider['label']), $provider['loginUrl'], [
                'class' => ['plusone-button', 'plusone-button--'.$provider['handle']],
                'data-provider' => $provider['handle'],
            ]);
        }

        return new HtmlString($html === '' ? '' : Html::tag('div', $html, ['class' => 'plusone-buttons']));
    }

    /**
     * The current user's sign-in providers on this site: whether each is connected and whether
     * it can be disconnected (not their last way to sign in). Empty when nobody is logged in.
     *
     * @return list<array{handle: string, name: string, connected: bool, canDisconnect: bool, connectUrl: string, disconnectUrl: string}>
     */
    public function accounts(): array
    {
        $user = $this->currentUser();

        if (! $user || ! $this->settings->frontEndLinking) {
            return [];
        }

        $connected = $this->loginMethods->connectedHandles($user);
        $definitions = $this->siteDefinitions();
        $rows = [];

        foreach ($definitions as $definition) {
            $isConnected = in_array($definition->handle, $connected, true);
            $rows[] = $this->accountRow($user, $definition->handle, $definition->name, $isConnected);
        }

        // Connected elsewhere (a provider since disabled on this site) — still disconnectable.
        $all = $this->oauth->allProviderDefinitions();
        foreach ($connected as $handle) {
            if (! $definitions->has($handle)) {
                $rows[] = $this->accountRow($user, $handle, $all->get($handle)->name ?? $handle, true);
            }
        }

        return $rows;
    }

    /** Whether connecting a provider will ask the current user for their password. */
    public function needsPassword(): bool
    {
        $user = $this->currentUser();

        return $user !== null && DB::table(Table::USERS)->where('id', $user->id)->value('password') !== null;
    }

    public function connectUrl(string $handle): string
    {
        return PlusOneOAuth::siteActionUrl('connect/'.rawurlencode($handle));
    }

    public function disconnectUrl(string $handle): string
    {
        return PlusOneOAuth::siteActionUrl('disconnect/'.rawurlencode($handle));
    }

    /** A POST form (with CSRF token, and a password field when needed) that connects a provider. */
    public function connectForm(string $handle, ?string $return = null, ?string $label = null): HtmlString
    {
        $definition = $this->oauth->siteDefinition($handle);
        $user = $this->currentUser();

        if (! $definition || ! $user || ! $this->settings->frontEndLinking || in_array($handle, $this->loginMethods->connectedHandles($user), true)) {
            return new HtmlString('');
        }

        $password = $this->needsPassword()
            ? Html::tag('label', e(t('Current password', category: 'plusone')).' '.'<input type="password" name="password" autocomplete="current-password" required>')
            : '';

        return $this->form($this->connectUrl($handle), $return, $password, $label ?? t('Connect {provider}', ['provider' => $definition->name], category: 'plusone'), 'plusone-connect');
    }

    /** A POST form that disconnects a provider. Renders nothing if it's the user's last way to sign in. */
    public function disconnectForm(string $handle, ?string $return = null, ?string $label = null): HtmlString
    {
        $user = $this->currentUser();

        if (! $user || ! $this->settings->frontEndLinking || ! in_array($handle, $this->loginMethods->connectedHandles($user), true) || ! $this->loginMethods->canDisconnect($user, $handle)) {
            return new HtmlString('');
        }

        $name = $this->oauth->allProviderDefinitions()->get($handle)->name ?? $handle;

        return $this->form($this->disconnectUrl($handle), $return, '', $label ?? t('Disconnect {provider}', ['provider' => $name], category: 'plusone'), 'plusone-disconnect');
    }

    /**
     * The latest Plus One / OAuth message for this visitor (`{type: 'success'|'error'|'notice', message}`), or null.
     *
     * @return array{type: string, message: string}|null
     */
    public function message(): ?array
    {
        $messages = Flash::all();
        $last = end($messages);

        return is_array($last) ? ['type' => (string) $last['type'], 'message' => (string) $last['message']] : null;
    }

    /** @return array{handle: string, name: string, connected: bool, canDisconnect: bool, connectUrl: string, disconnectUrl: string} */
    private function accountRow(User $user, string $handle, string $name, bool $connected): array
    {
        return [
            'handle' => $handle,
            'name' => $name,
            'connected' => $connected,
            'canDisconnect' => $connected && $this->loginMethods->canDisconnect($user, $handle),
            'connectUrl' => $this->connectUrl($handle),
            'disconnectUrl' => $this->disconnectUrl($handle),
        ];
    }

    private function form(string $action, ?string $return, string $fields, string $label, string $class): HtmlString
    {
        $site = Sites::getCurrentSite();
        $return ??= $this->settings->siteSettings($site->handle)['accountPath'];

        return new HtmlString(Html::tag('form',
            Html::hiddenInput('_token', csrf_token())->render()
            .($return !== null ? Html::hiddenInput('return', $return)->render() : '')
            .$fields
            .Html::tag('button', e($label), ['type' => 'submit']),
            ['method' => 'post', 'action' => $action, 'class' => $class],
        ));
    }

    /** @return Collection<string, ProviderDefinition> */
    private function siteDefinitions(): Collection
    {
        $site = rescue(fn () => Sites::getCurrentSite(), null, false);

        return $site ? $this->oauth->definitionsForSite($site) : collect();
    }

    private function currentUser(): ?User
    {
        $user = request()->craftUser()?->asElement();

        return $user instanceof User ? $user : null;
    }
}
