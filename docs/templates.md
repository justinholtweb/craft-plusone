---
title: Twig and Blade
slug: templates
order: 40
summary: Sign-in buttons, connected accounts, connect/disconnect forms — the same API in Twig and Blade.
---

Plus One adds a `plusone` global for Twig and `$plusone` for Blade. Every method works the same in both.

## Sign-in buttons

```twig
{{ plusone.buttons('/account') }}
```

```blade
{{ $plusone->buttons('/account') }}
```

Renders `<div class="plusone-buttons">` with one `<a class="plusone-button plusone-button--{handle}">` per
provider enabled on the current site. Style them however you like, or build your own:

```twig
{% for p in plusone.providers('/account') %}
  <a href="{{ p.loginUrl }}" class="btn btn-{{ p.handle }}">{{ p.label }}</a>
{% endfor %}
```

`providers()` returns `handle`, `name`, `label`, `icon` and `loginUrl`. `loginUrl(handle, return)` builds one
link. The `return` path must be on the current site (see [Security](security)); without it, people land on
Craft's `postLoginRedirect`.

A failed sign-in goes to the site's **Sign-in page** (Settings → Plus One → Sites), or Craft's login page.
Show the message with:

```twig
{% set m = plusone.message() %}
{% if m %}<p class="{{ m.type }}">{{ m.message }}</p>{% endif %}
```

## Connected accounts

```twig
{% for a in plusone.accounts() %}
  {{ a.name }}:
  {% if a.connected %}
    {{ a.canDisconnect ? plusone.disconnectForm(a.handle) : 'your only way to sign in' }}
  {% else %}
    {{ plusone.connectForm(a.handle) }}
  {% endif %}
{% endfor %}
```

```blade
@foreach ($plusone->accounts() as $a)
  {{ $a['name'] }}:
  @if ($a['connected'])
    {{ $a['canDisconnect'] ? $plusone->disconnectForm($a['handle']) : 'your only way to sign in' }}
  @else
    {{ $plusone->connectForm($a['handle']) }}
  @endif
@endforeach
```

`accounts()` lists the providers on this site for the logged-in user (`handle`, `name`, `connected`,
`canDisconnect`, `connectUrl`, `disconnectUrl`), plus any provider they're connected to that's no longer
offered here, so it can still be disconnected.

`connectForm(handle, return, label)` and `disconnectForm(handle, return, label)` render POST forms with the
CSRF token. The connect form includes a password field when the user has a password (`needsPassword()`).
`disconnectForm()` renders nothing when it's the user's last way to sign in.

Writing your own forms: POST to `connectUrl` / `disconnectUrl` with `_token` (Twig `csrfInput()`, Blade
`@csrf`), an optional `return`, and `password` when needed. Send `Accept: application/json` to get
`{success, message}` (connect: `{url}` to send the browser to).

Front-end linking can be switched off with **Front-end account linking**.
