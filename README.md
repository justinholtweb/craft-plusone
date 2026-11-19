# Plus One

Social sign-in for Craft 6, managed in the control panel — Google, GitHub, any OpenID Connect provider and
every SocialiteProviders package, with front-end sign-in and connected accounts, built on Craft's own OAuth.

> [!WARNING]
> **Craft 6 only, and in beta.** Plus One is a native **Craft CMS 6** plugin. Craft 6 is still in beta,
> and so is Plus One (`6.0.0-beta.1`). Try it on development and staging sites first and **use caution on
> production**: keep backups, and expect changes between betas. It does not run on Craft 5.

Docs: [justinholt.com/plugins/craft-plusone](https://justinholt.com/plugins/craft-plusone/docs/) and [docs/](docs/). See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later, **Pro edition** (Craft's OAuth sign-in is a Pro feature)
- PHP 8.5
- `laravel/socialite` (installed with Plus One); optionally any `socialiteproviders/*` OAuth 2 package

## What it adds to Craft's OAuth

Craft 6 signs people in through Laravel Socialite, with providers from `oauthProviders` in
`config/general.php`. Plus One extends that service — Craft's controller, login page, “Sign-in providers”
screen and identity table do the work — and adds:

- **Providers in the control panel** (Settings → Plus One), with client secrets kept as `$ENV_VAR`
  references: never stored, never shown.
- **Every SocialiteProviders package**: `composer require socialiteproviders/keycloak` and it's in the menu,
  with its extra settings. Plus a generic **OpenID Connect** provider (Authentik, Zitadel, Auth0, Okta…).
- **Front-end sign-in and connected accounts** for Twig and Blade: buttons, connect/disconnect forms.
- **Registration rules:** require a verified email, allowed email domains, a default user group, per-provider “new users”.
- **Per-site providers** and a callback URL per site (including sites under a path, which Craft's own
  OAuth routes don't reach).
- **GraphQL** `plusOneProviders` for headless login pages.

## Account-takeover guards

- Never links to an existing account by an **unverified** email — only when the provider verified it and
  the provider is set to match by email; otherwise refused, never a duplicate account.
- `state` on every provider (no stateless) and **PKCE** by default.
- Only **on-site** return URLs.
- **Admins** can't sign in with, connect or be matched to a social account unless you allow it (Craft
  skips two-step verification for OAuth sign-ins).
- Disconnecting someone's **last way to sign in** is refused, on the front end and on Craft's own screen.
- Connecting a provider on the front end needs the user's **password** when they have one.
- Existing accounts are never activated or unsuspended by a sign-in.

These rules also apply to providers configured in `config/general.php`. See [docs/security.md](docs/security.md).

## Quick start

1. Settings → Plugins → Plus One → add a provider: handle `google`, provider *Google*, client ID
   `$GOOGLE_CLIENT_ID`, secret `$GOOGLE_CLIENT_SECRET`. Register the callback URL it lists with Google.
2. Put the credentials in `.env`.
3. In your login template:

```twig
{{ plusone.buttons('/account') }}
```

```blade
{{ $plusone->buttons('/account') }}
```

Connected accounts:

```twig
{% for a in plusone.accounts() %}
  {{ a.name }} {{ a.connected ? plusone.disconnectForm(a.handle) : plusone.connectForm(a.handle) }}
{% endfor %}
```

## Editions and pricing

Free, under the Craft License.

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
tests/Harness/check.sh                      # live checks in the Craft 6 harness (fake OAuth provider)
PLUSONE_MULTISITE=1 tests/Harness/check.sh  # …plus a temporary second site
```

No real provider accounts are needed to test: `tests/Harness/fake-idp.php` is a local OAuth 2 / OpenID
Connect provider (authorize, token with PKCE checks, userinfo; also Keycloak's URL layout), so every sign-in
runs the full redirect → callback → token → userinfo flow.
