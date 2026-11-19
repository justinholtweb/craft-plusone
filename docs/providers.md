---
title: Providers
slug: providers
order: 20
summary: Every provider type, its settings, the callback URL, and a catalogue of popular providers.
---

Providers live in **Settings → Plugins → Plus One** (stored in project config), or in
`config/craft/plusone.php` under `providers`. Each row:

| Column | What it does |
|---|---|
| **On** | Switch the provider off without deleting it. |
| **Handle** | Lower-case letters, numbers and dashes. It's in the URLs, so don't change it once people use it. |
| **Provider** | A Socialite driver, *OpenID Connect (any provider)*, or an installed SocialiteProviders package. |
| **Name** | Shown on buttons: “Sign in with {name}”. Defaults to the provider's name. |
| **Client ID** | Literal or `$ENV_VAR`. |
| **Client secret** | **Must** be `$ENV_VAR`. |
| **Extra scopes** | Added to the driver's defaults. |
| **Sites** | `*` for every site, or site handles. See [Multisite](multisite). |
| **CP login** | Offer it on the control panel's login page. |
| **New users** | *If public registration is on* (Craft's setting), *Always register* or *Never register*. |
| **Match by verified email** | Let someone sign in to an existing account with the same email — only when the provider says it verified the address, and never for admins. See [Security](security). |
| **PKCE** | On by default. Turn off only for a provider that rejects PKCE parameters. |
| **Options** | `key=value; key=value` — see below. |

## Options

- `groups=handle, handle` — extra user groups for people this provider registers (on top of the default group).
- `param.name=value` — extra parameters for the authorization request, e.g. Google's `param.hd=example.com`
  (a hint only: use **Allowed email domains** to enforce it) or `param.prompt=select_account`.
- OpenID Connect: `authorize=https://…; token=https://…; userinfo=https://…` (all three required, https).
- SocialiteProviders: the package's own keys, e.g. Keycloak `base_url=https://sso.example.com; realms=staff`.
  Values may be `$ENV_VAR` references.

## Callback URLs

Every Plus One provider returns to `{site URL}/actions/plusone/callback/{handle}`, one per site the
provider is enabled on. The settings page lists them; register each with the provider. (Plus One uses an
action URL rather than Craft's `oauth/{handle}/callback` because action URLs work on sites that live
under a path, like `https://example.com/fr/`.)

Providers from `oauthProviders` in `config/general.php` keep Craft's own callback URL.

## Built-in providers

Socialite's OAuth 2 drivers: **Google**, **GitHub**, **GitLab**, **Bitbucket**, **Facebook**,
**LinkedIn** (OpenID), **Slack** (OpenID), **Twitch** and **X**. (Twitter's OAuth 1 driver isn't supported
by Craft's OAuth.)

## OpenID Connect (any provider)

For Authentik, Keycloak, Zitadel, Auth0, Okta, Microsoft Entra, a company SSO… anything that speaks OpenID
Connect. Use its discovery document (`/.well-known/openid-configuration`) to find the three endpoints:

```
authorize=https://sso.example.com/application/o/authorize/; token=https://sso.example.com/application/o/token/; userinfo=https://sso.example.com/application/o/userinfo/
```

Scopes default to `openid email profile`. The provider's `email_verified` claim is what
[Match by verified email](security) and **Require a verified email** rely on.

## SocialiteProviders packages

[SocialiteProviders](https://socialiteproviders.com) has 100+ community providers. Install one with Composer
and it appears in the **Provider** menu as `socialiteproviders/<name>` — no service provider or event
listener to register:

```bash
composer require socialiteproviders/keycloak
```

Its extra settings go in **Options** (the settings page lists each installed package). OAuth 1 packages aren't
supported.

## Catalogue

| Provider | Type | Where to create the client | Notes |
|---|---|---|---|
| Google | built-in `google` | console.cloud.google.com → APIs & Services → Credentials | Sends `email_verified`. `param.hd=` hints a Workspace domain. |
| GitHub | built-in `github` | github.com/settings/developers → OAuth Apps | Only returns verified emails. |
| GitLab | built-in `gitlab` | gitlab.com/-/user_settings/applications | Scopes `read_user`. Self-hosted: use OpenID Connect. |
| Microsoft / Entra ID | `socialiteproviders/microsoft` or OpenID Connect | portal.azure.com → App registrations | Entra's `email` is not always verified: leave *Match by verified email* off. |
| Apple | `socialiteproviders/apple` | developer.apple.com → Identifiers → Services IDs | Needs a generated client secret (JWT); see the package's README. |
| Facebook | built-in `facebook` | developers.facebook.com → Apps | No `email_verified` claim: treated as unverified. |
| LinkedIn | built-in `linkedin-openid` | linkedin.com/developers → Apps | Sends `email_verified`. |
| Slack | built-in `slack-openid` | api.slack.com/apps | |
| Discord | `socialiteproviders/discord` | discord.com/developers/applications | Sends `verified`; not mapped — treated as unverified. |
| Keycloak | `socialiteproviders/keycloak` | Keycloak admin → Clients | Options `base_url=…; realms=…`. |
| Authentik, Zitadel, Auth0, Okta | OpenID Connect | the provider's admin | Sends `email_verified`. |

A provider that doesn't say an email is verified is treated as unverified: it can still sign people in to
accounts they've connected, but can't register them while **Require a verified email** is on, and can't
match existing accounts by email.

## Providers in config/general.php

Craft's own `oauthProviders` config keeps working next to Plus One's. Plus One's sign-in rules (see
[Security](security)) apply to those providers too, as long as they use Craft's default user resolver.
A Plus One provider can't reuse a handle from that config.
