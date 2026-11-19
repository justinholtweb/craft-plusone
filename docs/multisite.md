---
title: Multisite
slug: multisite
order: 50
summary: Providers per site, a callback URL per site, per-site sign-in and account pages, translations.
---

- **Providers per site:** a provider's **Sites** column is `*` or a list of site handles. Elsewhere it's not
  in `providers()`, `buttons()` or GraphQL, and its sign-in and callback URLs answer 404.
- **Callback URL per site:** each site has its own `{site URL}/actions/plusone/callback/{handle}`, which
  works for sites on their own domain and for sites under a path (`https://example.com/fr/`). Register every
  one the settings page lists.
- **Pages per site:** Settings → Plus One → **Sites** sets, per site, the **Sign-in page** a failed sign-in
  returns to and the **Account page** connect/disconnect return to by default.
- **Control panel:** the CP login offers providers with **CP login** on, whatever their sites.
- **Translations:** every message and label goes through Craft's translator (category `plusone`); English and
  French ship in `lang/`. Provider names are yours to translate in your site's `plusone` translations.

An unknown site handle never matches anything: GraphQL returns an error for a site the schema can't read,
and settings refuse site handles that don't exist.
