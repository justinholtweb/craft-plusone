---
title: Security
slug: security
order: 30
summary: How Plus One stops account takeover — email matching, state and PKCE, redirects, admins, lockouts.
---

Social sign-in's big risk is **account takeover**: someone signing in to your account because a provider
handed over your email address. Plus One closes the usual holes.

## Never links by an unverified email

When an identity isn't connected to anyone yet and its email matches an existing account, Plus One signs
in to that account **only if** all of these hold:

1. the provider has **Match by verified email** on,
2. the provider says it **verified** that address (`email_verified` / `verified_email` claim; GitHub only
   returns verified addresses), and
3. the account isn't an admin's (unless **Allow admins** is on).

Otherwise the sign-in is refused — it never links the account and never creates a second account with
the same email. The visitor sees Craft's generic “Authentication failed.”, so the page doesn't reveal
whether an email has an account. The reason is in the log.

A matched account's email is never changed by the provider.

## State and PKCE

Every Plus One provider uses Socialite's `state` check (no stateless providers), which stops login CSRF:
a callback URL from someone else's sign-in fails in your browser. PKCE (S256) is on by default, so an
intercepted authorization code is useless without the verifier kept in the session.

## Only on-site redirects

`return=` on sign-in, connect and disconnect accepts a path on the current site (`/account`) or an
absolute URL that starts with the site's own base URL. Anything else — other hosts, `//evil.test`,
`/\evil.test`, `javascript:` — falls back to the default.

## Admins

Off by default, admin accounts can't sign in with, connect or be matched to a social account, because
Craft skips two-step verification for OAuth sign-ins. This covers the front end, the control panel's
login page and Craft's own **My Account → Sign-in providers** screen. Turn on **Allow admins** if you
want it (and secure the provider accounts accordingly).

## No lockouts

Disconnecting a provider is refused when it's someone's last way to sign in (no password, no passkey,
no other connected provider) — on the front end and on Craft's **Sign-in providers** screen.

## Connecting needs a password

On the front end, people who have a password must enter it (or have confirmed it in the last
`auth.password_timeout` seconds) to connect a provider, as Craft's control panel requires an elevated
session. A stolen session cookie alone can't attach an attacker's social account.

## Suspended accounts stay suspended

Craft's `activatesUsers` option would also unsuspend and unlock existing accounts on sign-in. Plus One's
providers never set it; they activate only people they register, and only when the provider verified the email.

## Secrets

Client secrets must be environment variable references. They're resolved only when a provider is built
and are never written to project config, logs, templates, GraphQL or the settings page.
