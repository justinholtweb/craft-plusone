---
title: Troubleshooting
slug: troubleshooting
order: 80
summary: Buttons missing, “Authentication failed.”, redirect URI mismatches.
---

**No buttons / the settings page says Craft's OAuth isn't available.** Craft's OAuth sign-in needs Craft Pro
and `laravel/socialite`.

**A provider is “not ready”.** Its client ID or secret environment variable isn't set in this environment.

**“Authentication failed.”** On purpose, the visitor sees the same message for every refusal. Check
`storage/logs`: Plus One logs `Plus One refused an OAuth sign-in via “handle”: reason.` — `email-in-use`,
`unverified-email`, `no-email`, `domain-not-allowed` or `admin`. Other reasons (a provider error, an expired
`state`) are logged by Craft.

**`redirect_uri_mismatch`.** Register the exact callback URL the settings page lists for that site,
`…/actions/plusone/callback/{handle}`.

**Signed in, but landed on the control panel's login page after a failure.** Set the site's **Sign-in page**
under Settings → Plus One → Sites.

**Can't disconnect a provider.** It's the account's last way to sign in. Set a password (or connect another
provider) first.

**An admin can't sign in with Google.** Admins are off by default; see [Security](security).
