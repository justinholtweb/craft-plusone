---
title: Installation
slug: installation
order: 10
summary: Requirements, the Craft 6 beta caution, installing, and your first provider.
---

> **Craft 6 only, and still in beta.** Plus One is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Plus One (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## Requirements

- Craft CMS 6.0.0-beta.1 or later, **Pro edition** — Craft's OAuth sign-in (which Plus One builds on) is a Pro feature
- PHP 8.5
- `laravel/socialite` (installed with Plus One)

## Install

```bash
composer require justinholtweb/craft-plusone
php artisan craft:plugin:install plusone
```

## Your first provider (Google)

1. In the Google Cloud console, create an OAuth client (type *Web application*).
2. In Craft, open **Settings → Plugins → Plus One**. Add a provider: handle `google`, provider *Google*,
   client ID `$GOOGLE_CLIENT_ID`, client secret `$GOOGLE_CLIENT_SECRET`. Save.
3. The **Status** list under the providers table now shows the **callback URL** for each site, e.g.
   `https://example.com/actions/plusone/callback/google`. Add each one to the Google client's
   *Authorized redirect URIs*.
4. Put the credentials in `.env`:

   ```dotenv
   GOOGLE_CLIENT_ID=1234-abc.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-…
   ```

5. Add a button to your login template:

   ```twig
   {{ plusone.buttons('/account') }}
   ```

That's it: visitors can sign in with Google, and — if you allow it — register. Turn on **CP login** for
the provider to put the button on the control panel's login page too.

The client secret must be an environment variable reference. Plus One refuses a pasted secret, so secrets
never land in project config (and are never shown back in the control panel).
