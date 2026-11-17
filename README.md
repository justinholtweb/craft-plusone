# Plus One

Social login, managed in the CP — every SocialiteProviders provider, front-end connected-accounts, on top of Craft 6's own OAuth.

> **Status:** scaffold — nothing user-facing yet. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: laravel/socialite + socialiteproviders/*. Craft 6 core already integrates Socialite for OAuth login (Pro edition, config-file providers). Plus One extends it.

## Editions and pricing

single edition — Free (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-plusone --install`).
