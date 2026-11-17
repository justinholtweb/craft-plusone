# Plus One — plan

Social login, managed in the CP — every SocialiteProviders provider, front-end connected-accounts, on top of Craft 6's own OAuth.

- Package: `justinholtweb/craft-plusone` · handle `plusone` · namespace `JustinHoltWeb\PlusOne`
- Editions: single edition · pricing: Free (provisional)
- Laravel integration: laravel/socialite + socialiteproviders/* — Craft 6 core already integrates Socialite for OAuth login (Pro edition, config-file providers). Plus One extends it.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

## Rescoped against Craft 6 core (2026-11-17)
Craft 6 core ships OAuth login through Socialite (`CraftCms\Cms\Auth\OAuth`): providers come from
`GeneralConfig::oauthProviders()`, identities are linked in `SsoIdentity`, groups resolved, buttons rendered —
**Pro edition only** (`Edition::supportsOAuth()`). Plus One must extend that, never replace or bypass the gate.

## Phases
1. **Providers in the CP.** Manage providers (client id/secret as env references, scopes, group mapping) in
   project config and feed them into core's provider definitions.
2. **Community providers.** Register any installed `socialiteproviders/*` package (100+ providers) with core's
   Socialite manager; provider catalogue with setup instructions per provider.
3. **Front-end accounts.** Twig components: login buttons, connected-accounts list, link/unlink, avatar/profile
   sync from the provider.

## Risks / open questions
- Can core's provider list be extended at runtime (event/contract), or only via general config?
- If core grows a CP UI before GA, Plus One shrinks to phase 2–3.

## Definition of done (every phase)
`bin/port-check craft-plusone` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
