# Release Notes for Plus One

## 6.0.0-beta.1 - Unreleased

> Craft 6 only. Craft 6 is in beta: use caution on production.

### Added
- Social sign-in providers managed in Settings → Plus One (or `config/craft/plusone.php`), added to Craft's own OAuth providers. Client secrets must be `$ENV_VAR` references.
- Socialite's built-in OAuth 2 drivers, a generic OpenID Connect provider, and every installed `socialiteproviders/*` OAuth 2 package (discovered from Composer, with its extra config keys).
- PKCE (S256) by default; providers are never stateless.
- Registration rules: require a verified email, allowed email domains, a default user group, per-provider “new users” (follow public registration / always / never), activation of verified new users.
- Account pre-hijacking guard: matching by email also requires the account to have proved owning its current address (a per-user proof Plus One records when an emailed link comes back, for the current address only when no change to another address is pending, or when a provider trusted for email verifies the address on registration; provider proofs stop counting if that provider is removed or untrusted) and to be active with no email change pending. Any email change voids the proof; a link made by email match ends the account's other sessions; the visitor is told to sign in with their password and connect instead.
- Account-takeover guards: an unverified email never links to an existing account; matching by a verified email is opt-in per provider; admins can't use social sign-in unless allowed; a matched account's email isn't changed; existing accounts are never activated or unsuspended by a sign-in. They apply to providers in `config/general.php` too.
- Front-end sign-in (`actions/plusone/login/{handle}?return=`) with on-site-only return URLs and a per-site sign-in page for failures.
- Front-end connected accounts: connect (password required when the user has one) and disconnect (refused for the last way to sign in), returning to on-site pages.
- Craft's “Sign-in providers” screen: admins can't connect, and the last way to sign in can't be disconnected.
- Per-site providers and a callback URL per site (`actions/plusone/callback/{handle}`), which also works for sites under a path.
- Twig `plusone` / Blade `$plusone`: `buttons()`, `providers()`, `loginUrl()`, `accounts()`, `connectForm()`, `disconnectForm()`, `needsPassword()`, `message()`.
- GraphQL `plusOneProviders(site:)` (schema permission “List sign-in providers”): handle, name, label and login URL only.
- English and French translations.
