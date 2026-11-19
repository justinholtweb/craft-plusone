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
3. **the account has proved owning its current address**, and is active (not pending), not
   suspended or locked, with no email change awaiting verification, and
4. the account isn't an admin's (unless **Allow admins** is on).

Point 3 stops **account pre-hijacking**: someone registering an account with *your* email (or
changing an account's email to yours) before you ever sign in with Google, then waiting for your
social login to land in an account whose password they know. A provider verifying an address proves
the *visitor* owns it, not who created the account or last changed its email.

### Email proofs

Plus One keeps its own per-user record of which address an account has proved owning
(`plusone_email_proofs`, a hash of the address). A proof is recorded when:

- someone follows a link Craft emailed — activation, email verification or password reset (core's
  `EmailVerified` event). Core uses one kind of code for all of these and doesn't say which link it
  was, so Plus One records only the account's current email, and only when there's no pending change
  to a different address (a reset link goes to the current address, a verification link to the
  pending one, and Plus One can't tell them apart). A pending change is proven by the next link
  that comes back after it's applied; or
- Plus One registers a user, or links one by email, with an address verified by a provider that is
  set to *Match by verified email*. The proof names that provider and stops counting if the provider
  is removed or no longer trusted, so a provider with a weak `email_verified` can't mint proofs that
  a trusted one then relies on.

The proof is for one address. **Any** change to the account's email voids it, however it was made
(front end with verification off, the control panel, the console, an import), until a new link
comes back. Craft's own settings (public registration, *Verify email addresses*, edition) don't
come into it.

**Residual risk, plainly:**

- Accounts that have never followed an emailed link — created in the control panel, the console or
  an import, and existing accounts from before Plus One was installed — can't be matched by email
  until they do (a password reset is enough). They can still sign in with their password and connect
  a provider from their account. This fails closed, never open.
- An admin can copy an account's activation or password-reset URL in the control panel and open it
  without the email ever being sent; that counts as a proof. Admins are trusted with accounts anyway.
- There is no "email last changed" date in Craft, so a proof is tied to the address (by hash) rather
  than to a time: an account that proved an address, moved away from it and later moved back is
  still proven for it.
- *Match by verified email* trusts the provider's `email_verified` claim. It's **off** by default for
  every provider; turn it on only for providers whose verification you trust.

When an email match links an account, the account's other sessions end and its remember-me token
is cycled.

Otherwise the sign-in is refused — it never links the account and never creates a second account with
the same email. When the provider verified the email (so the visitor owns it), they're told an account
already exists and to sign in with their password and connect the provider from their account.
In every other case they see Craft's generic “Authentication failed.”, so the page doesn't reveal
whether an email has an account. The reason is always in the log.

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
