---
title: Configuration
slug: configuration
order: 70
summary: Every setting, and setting them from config/craft/plusone.php.
---

Everything on the settings page can be set in `config/craft/plusone.php` (values there override the page):

```php
<?php

return [
    'providers' => [
        [
            'enabled' => true,
            'handle' => 'google',
            'driver' => 'google',
            'clientId' => '$GOOGLE_CLIENT_ID',
            'clientSecret' => '$GOOGLE_CLIENT_SECRET',
            'sites' => '*',
            'cp' => false,
            'newUsers' => 'default',   // default | allow | deny
            'linkByEmail' => false,
            'pkce' => true,
            'options' => 'param.prompt=select_account',
        ],
    ],
    'requireVerifiedEmail' => true,
    'allowedEmailDomains' => 'example.com',
    'defaultUserGroup' => 'members',
    'activateNewUsers' => true,
    'allowAdmins' => false,
    'frontEndLinking' => true,
    'sites' => [
        ['site' => 'default', 'loginPath' => 'login', 'accountPath' => 'account'],
    ],
];
```

| Setting | Default | |
|---|---|---|
| `requireVerifiedEmail` | `true` | Register someone only when the provider verified their email. |
| `allowedEmailDomains` | blank | Register only these domains. Blank: any. |
| `defaultUserGroup` | blank | Group handle new users join. |
| `activateNewUsers` | `true` | Activate people Plus One registers (verified email only); otherwise they're left inactive for an admin. |
| `allowAdmins` | `false` | Let admins use social sign-in. |
| `frontEndLinking` | `true` | Front-end connect/disconnect. |

Registration rules apply to every OAuth provider, including `oauthProviders` in `config/general.php`.
Whether someone may register at all is the provider's **New users** plus Craft's **Allow public
registration** (Settings → Users).
