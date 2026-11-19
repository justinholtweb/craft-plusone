---
title: FAQ
slug: faq
order: 90
summary: Craft 6 core OAuth vs Plus One, editions, Craft 5.
---

**Craft 6 already has OAuth sign-in. Why Plus One?** Craft 6's OAuth (Pro) reads providers from
`config/general.php`. Plus One adds providers managed in the control panel, every SocialiteProviders
package, a generic OpenID Connect provider, front-end sign-in and connected accounts for Twig and Blade,
registration rules, per-site providers, and account-takeover guards — and it uses Craft's own OAuth
service, controller and identity table to do it, so nothing is duplicated.

**Does it work with Craft 5?** No. Plus One is Craft 6 only, and Craft 6 is in beta: use caution on production.

**Does it need Craft Pro?** Yes — Craft's OAuth sign-in is a Pro feature, and Plus One builds on it.

**Is it free?** Yes, under the Craft License.

**Does it store access tokens?** No. Plus One only keeps the link between a provider identity and a Craft
user (Craft's `sso_identities` table). Tokens are used once to read the profile, then dropped.

**Does it sync avatars?** Not yet: copying a remote image into an asset volume is on the roadmap.
