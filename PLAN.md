# Plus One — plan

Social sign-in for Craft 6, managed in the CP — every SocialiteProviders provider, front-end connected-accounts, on top of Craft 6's own OAuth.

- Package: `justinholtweb/craft-plusone` · handle `plusone` · namespace `JustinHoltWeb\PlusOne`
- Editions: single edition · pricing: Free (Craft License)
- Laravel integration: laravel/socialite + socialiteproviders/* — Craft 6 core already integrates Socialite for OAuth login (Pro edition, config-file providers). Plus One extends it.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Craft 6 is in beta: use caution on production.

## Rescoped against Craft 6 core (2026-11-17)
Craft 6 core ships OAuth login through Socialite (`CraftCms\Cms\Auth\OAuth`): providers come from
`GeneralConfig::oauthProviders()`, identities are linked in `SsoIdentity`, groups resolved, buttons rendered —
**Pro edition only** (`Edition::supportsOAuth()`). Plus One must extend that, never replace or bypass the gate.

## How it extends core (2026-11-18)
- `Auth\PlusOneOAuth extends OAuth`, bound in place of core's scoped `OAuth` service, so core's
  `OAuthController`, CP login buttons, “Sign-in providers” screen and `sso_identities` table are all reused.
- CP-managed providers are normalized **by core** (a throwaway `OAuth` with a cloned `GeneralConfig` holding
  just that provider), so they behave exactly like config-file providers and a bad row can't break the others.
- Core's strategy contracts carry Plus One's rules: `SafeUserResolver` (`ResolvesOAuthUser`), `UserPopulator`,
  `GroupResolver`. Providers on core's default resolver (config-file ones too) get `SafeUserResolver`.
- `callbackPath()` / `redirectPath()` overridden: Plus One providers use action routes
  (`actions/plusone/callback/{handle}`), because core's `oauth/{provider}/callback` is a plain web route that
  404s on sites under a path. The callback action runs core's `OAuthController::callback`.
- `Http\Middleware\GuardOAuthRoutes` (pushed on `craft.web` + `craft.cp`) wraps core's callback and the CP
  “Sign-in providers” connect/destroy routes: admin rule, last-login-method rule, front-end return URLs.

## Phases
1. **Providers in the CP.** ✅ Table in settings (handle, driver, name, client id, secret `$ENV` only, scopes,
   sites, CP login, new users, match by verified email, PKCE, options). Status list with per-site callback URLs.
2. **Community providers.** ✅ Installed `socialiteproviders/*` OAuth 2 packages discovered from Composer
   metadata (PSR-4 namespace + `Provider`), extra keys from `additionalConfigKeys()`, configured via
   `setConfig()`; no SocialiteWasCalled listener needed. Generic OpenID Connect provider. Catalogue in docs/providers.md.
3. **Front-end accounts.** ✅ Twig/Blade `plusone`: buttons, providers, accounts, connect/disconnect forms,
   messages. Connect needs the password when the user has one. Avatar/profile sync: name only (core's
   populator); avatar → asset not done (remote fetch into a volume; see questions).
4. **Security.** ✅ No link by unverified email; email match also needs Craft to vouch for the account's email (pre-hijack guard, 2026-11-19, from a security review) and ends the account's other sessions; opt-in match by verified email; admins off by default; state
   always, PKCE default; on-site returns only; no lockout on disconnect (front end and core's CP screen);
   existing users never activated/unsuspended; matched users' email never changed.
5. **Multisite + translations.** ✅ Per-site providers, per-site callback URLs (incl. subpath sites), per-site
   sign-in/account pages; en + fr.
6. **GraphQL.** ✅ `plusOneProviders(site:)` — handle, name, label, loginUrl; permission “List sign-in providers”.

## Status
- **2026-11-18/19 — 6.0.0-beta.1 feature-complete.** 41 Pest tests; `tests/Harness/check.sh` 116 checks
  (with `PLUSONE_MULTISITE=1`; one run at a time, it cleans up leftovers first) against a local fake OAuth 2 / OIDC provider (`tests/Harness/fake-idp.php`,
  also mimicking Keycloak's URLs for `socialiteproviders/keycloak`). Pint, Larastan 6, port-check green.
- Unverified (no credentials): real Google/GitHub/etc. Built-in Socialite drivers go through core's named-driver
  path unchanged; only the generic OIDC provider and the Keycloak package ran end to end.
- Harness additions (documented in check.sh): `socialiteproviders/keycloak` in composer.json,
  `routes/plusone-fake-idp.php` + a guarded `require` in `routes/web.php`, `PLUSONE_FAKE_SECRET` in `.env`.

## Core issues found (Craft 6.0.0-beta.1)
- `OAuth::normalizeProvider()` never runs `Env::parse()` (from reading the source) on `clientId`/`clientSecret`, though the
  `oauthProviders` docs show `'$GOOGLE_CLIENT_ID'` (Plus One resolves its own).
- `oauthProviders.activatesUsers` also activates **existing** users on sign-in, via `Users::activateUser()`,
  which clears `suspended` and `locked`: a suspended user with a linked identity unsuspends themselves.
- Core's `oauth/{provider}/callback` / `redirect` are plain web routes: on a site under a path
  (`https://x.test/fr/`) the callback URL core generates 404s.
- OAuth sign-in skips two-step verification (`finalizeLogin(..., skipTwoFactor: true)`), for admins too.
- “Sign-in providers” disconnect doesn't check that another login method remains.

## Questions for Justin
1. Avatars: copy the provider's picture into a user photo (needs a remote fetch into an asset volume, so an
   SSRF-safe fetcher + a volume setting)? Left out of beta.1.
2. GraphQL is deliberately thin (`plusOneProviders` only). Should headless sites also get a *read* of the
   current user's connected providers (would need a user-authenticated GraphQL token, not a schema token)? I'd say no.
3. Admins are blocked from social sign-in by default (core skips 2FA for OAuth). Keep that default?
4. Should I report the core issues above upstream (craftcms/cms), or will you?
5. Promos: done in the Bloodhound style? (see promos/README.md) — and the site accent #3D99DD (icon blue) OK?

## Definition of done (every phase)
`bin/port-check craft-plusone` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
