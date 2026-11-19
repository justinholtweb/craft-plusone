---
title: GraphQL
slug: graphql
order: 60
summary: plusOneProviders — the sign-in buttons for a headless front end. Nothing else is exposed.
---

For headless front ends, Plus One adds one read-only query, enabled per schema with **Plus One → List
sign-in providers**:

```graphql
{
  plusOneProviders(site: "default") {
    handle
    name
    label
    loginUrl
  }
}
```

It returns the providers enabled on that site (default: the schema's primary site) — what a public login
page shows anyway. Send visitors to `loginUrl` (add `?return=/path` for an on-site page to come back to);
the OAuth flow itself runs in the browser with Craft's session, not through GraphQL.

Deliberately **not** exposed: client IDs or secrets, tokens, who is connected to which provider, identity
ids, and any mutation. A site the schema can't read is an error.
