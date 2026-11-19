#!/bin/bash
# Plus One live checks against the Craft 6 harness (~/Sites/plugin-testing-craft6, Craft Pro).
#
# There are no real provider credentials, so every sign-in runs end to end against a fake
# OAuth 2 / OpenID Connect provider (tests/Harness/fake-idp.php, copied to the harness as
# routes/plusone-fake-idp.php): login → authorize (state + PKCE) → Craft's callback → token
# (checks code, redirect_uri, client secret, PKCE verifier) → userinfo. The identity it
# returns is chosen per check with query parameters (sub, email, verified, name).
#
# Needs in the harness: laravel/socialite (via this plugin), socialiteproviders/keycloak, the
# fake IdP route include in routes/web.php, and PLUSONE_FAKE_SECRET=plusone-secret in .env.
# Plus One's settings for the checks live in config/craft/plusone.php (written under
# bin/harness-lock, removed at the end). Fixtures are prefixed `plusone` and removed at the end.
# Multisite checks run with PLUSONE_MULTISITE=1 (adds and removes the plusonefr site).
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
SRC=$(cd "$(dirname "$0")" && pwd)
U=https://plugin-testing-craft6.ddev.site
IDP=$U/plusone-fake-idp
cd "$H" || exit 2
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v -e WARNING -e DEPRECATED | tail -1; }
run() { local out i; for i in 1 2 3 4 5 6; do out=$("$H"/bin/harness-lock ddev artisan tinker --execute "require 'storage/$1';" 2>/dev/null | grep -v -e WARNING -e DEPRECATED | tail -1); case "$out" in *Stale*|*Busy*|'') sleep 3;; *) break;; esac; done; echo "$out"; }

mkdir -p resources/views/plusone
cp "$SRC"/templates/account.twig "$SRC"/templates/account-blade.blade.php resources/views/plusone/
for f in fixtures cleanup gql-fixtures multisite-fixtures; do cp "$SRC/$f.php" "storage/plusone-$f.php"; done
cmp -s "$SRC/fake-idp.php" routes/plusone-fake-idp.php || "$H"/bin/harness-lock cp "$SRC/fake-idp.php" routes/plusone-fake-idp.php
grep -q 'plusone-fake-idp.php' routes/web.php || { echo "routes/web.php doesn't include routes/plusone-fake-idp.php — see the header of this script"; exit 2; }
grep -q '^PLUSONE_FAKE_SECRET=plusone-secret' .env || { echo ".env needs PLUSONE_FAKE_SECRET=plusone-secret"; exit 2; }

# ---- settings: config/craft/plusone.php, from a JSON object of overrides on top of BASE ----
FAKE="authorize=$IDP/authorize; token=$IDP/token; userinfo=$IDP/userinfo"
BASE=$(python3 -I -c 'import json,sys; print(json.dumps({
  "providers": [
    {"enabled": True, "handle": "plusone-fake", "driver": "generic", "name": "Fake ID", "clientId": "plusone-client", "clientSecret": "$PLUSONE_FAKE_SECRET",
     "sites": "default", "cp": True, "newUsers": "allow", "linkByEmail": True, "pkce": True, "options": sys.argv[1]},
    {"enabled": True, "handle": "plusone-kc", "driver": "socialiteproviders/keycloak", "name": "Fake Keycloak", "clientId": "plusone-client", "clientSecret": "$PLUSONE_FAKE_SECRET",
     "sites": "*", "cp": False, "newUsers": "allow", "linkByEmail": False, "pkce": True, "options": "base_url="+sys.argv[2]+"; realms=plusone"},
  ],
  "defaultUserGroup": "plusoneMembers", "requireVerifiedEmail": True, "activateNewUsers": True, "allowAdmins": False, "allowedEmailDomains": None,
  "frontEndLinking": True, "sites": [{"site": "default", "loginPath": "plusone/account", "accountPath": "plusone/account"}],
}))' "$FAKE" "$IDP")
conf() {
  local over=${1:-}; [ -n "$over" ] || over='{}'
  python3 -I - "$BASE" "$over" "$H/config/craft/plusone.php.tmp" <<'PY'
import json,sys
base=json.loads(sys.argv[1]); over=json.loads(sys.argv[2])
for k,v in over.items():
    if k.startswith('fake.'): base['providers'][0][k[5:]]=v
    elif k.startswith('kc.'): base['providers'][1][k[3:]]=v
    else: base[k]=v
def php(v):
    if v is None: return 'null'
    if v is True: return 'true'
    if v is False: return 'false'
    if isinstance(v,(int,float)): return str(v)
    if isinstance(v,list): return '['+', '.join(php(x) for x in v)+']'
    if isinstance(v,dict): return '['+', '.join(php(k)+' => '+php(x) for k,x in v.items())+']'
    return "'"+str(v).replace('\\','\\\\').replace("'","\\'")+"'"
open(sys.argv[3],'w').write("<?php\n\n// Written by craft-plusone/tests/Harness/check.sh; removed when it finishes.\nreturn "+php(base)+";\n")
PY
  "$H"/bin/harness-lock mv "$H/config/craft/plusone.php.tmp" "$H/config/craft/plusone.php" || return 1
  sleep 3 # PHP-FPM's opcache revalidates files every 2 s
}

# ---- browser helpers (one cookie jar per "browser") ----
jar() { mktemp; }
# flow JAR START_URL IDP_PARAMS → "<code> <location>" of Craft's callback response (or of the first step that didn't redirect)
flow() {
  local j=$1 start=$2 params=$3 a c
  a=$(curl -sk -c "$j" -b "$j" -o /dev/null -w '%{http_code} %{redirect_url}' "$start")
  case "$a" in 302\ "$IDP"*) ;; *) echo "start:$a"; return;; esac
  c=$(curl -sk -c "$j" -b "$j" -o /dev/null -w '%{http_code} %{redirect_url}' "${a#302 }&$params")
  case "$c" in 302\ *) ;; *) echo "idp:$c"; return;; esac
  curl -sk -c "$j" -b "$j" -o /dev/null -w '%{http_code} %{redirect_url}' "${c#302 }"
}
login() { flow "$1" "$U/${4:-}actions/plusone/login/${3:-plusone-fake}?return=/plusone/account" "$2"; }
who() { curl -sk -b "$1" "$U/${2:-}plusone/account" | grep -o '<p id="user">[^<]*' | sed 's/<p id="user">//'; }
page() { curl -sk -b "$1" "$U/${3:-}plusone/$2"; }
field() { grep -o "<[a-z]* id=\"$1\">[^<]*" | sed "s/<[a-z]* id=\"$1\">//"; }
xsrf() { awk '$6=="XSRF-TOKEN"{print $7}' "$1" | tail -1 | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
post() { local j=$1; shift; curl -sk -c "$j" -b "$j" -X POST -H "X-XSRF-TOKEN: $(xsrf "$j")" -o /dev/null -w '%{http_code} %{redirect_url}' "$@"; }
idlink() { tk "echo Illuminate\\Support\\Facades\\DB::table('sso_identities')->where('provider','$1')->where('identityId','$2')->value('userId') ?? 'none';"; }
uid() { tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->${2:-username}('$1')->status(null)->one()?->id ?? 'none';"; }
last() { tk "echo json_encode(Illuminate\\Support\\Facades\\Cache::get('plusone-idp:last'));"; }
usercount() { tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->email('plusone_alice*')->status(null)->count();"; }
# Forget that this browser's session recently confirmed a password (signing in counts as confirming),
# as if the sign-in was more than auth.password_timeout ago.
age() { local c; c=$(awk '$6=="craft-cms-session"{print $7}' "$1" | tail -1); tk "\$id=Illuminate\\Cookie\\CookieValuePrefix::remove(Illuminate\\Support\\Facades\\Crypt::decryptString(urldecode('$c'))); \$h=app('session')->driver()->getHandler(); \$d=json_decode(\$h->read(\$id), true); unset(\$d['auth']['password_confirmed_at']); \$h->write(\$id, json_encode(\$d)); echo 1;" >/dev/null; }

# One run at a time: a second run's cleanup would delete this run's fixtures mid-flight.
RUNLOCK=$H/storage/plusone-check.lock
mkdir "$RUNLOCK" 2>/dev/null || { echo "another Plus One check is running ($RUNLOCK)"; exit 2; }
trap 'rmdir "$RUNLOCK" 2>/dev/null' EXIT
# Start clean: an interrupted run can leave users and identities behind.
ok "leftovers from earlier runs removed" "$(run plusone-cleanup.php)" "ok"

CPLOGIN="$U/admin/login"
ACCOUNT="$U/plusone/account"
FIX=$(run plusone-fixtures.php)
ok "fixtures" "$(echo "$FIX" | grep -c plusone_bob)" "1"
BOB=$(uid plusone_bob); ADMIN=$(uid plusone_admin); EDITOR=$(uid plusone_editor)
conf

# ---------------------------------------------------------------- sign-in + registration
J=$(jar)
start=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-fake?return=/plusone/account")
ok "login start → the provider's authorize URL" "${start%%\?*}" "$IDP/authorize"
ok "authorize request carries state" "$(echo "$start" | grep -cE '[?&]state=[A-Za-z0-9]{40}')" "1"
ok "authorize request carries a PKCE S256 challenge" "$(echo "$start" | grep -oE 'code_challenge_method=S256')" "code_challenge_method=S256"
ok "redirect_uri is Plus One's callback action on this site" "$(python3 -I -c 'import sys,urllib.parse as u; print(u.parse_qs(u.urlparse(sys.argv[1]).query)["redirect_uri"][0])' "$start")" "$U/actions/plusone/callback/plusone-fake"
ok "no client secret in the browser redirect" "$(echo "$start" | grep -c 'plusone-secret')" "0"

J=$(jar)
ok "new verified user: registered, signed in, sent to the return path" "$(login "$J" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1&name=Alice')" "302 $ACCOUNT"
ok "token request sent the PKCE verifier, and it matched" "$(last)" '{"challengeSent":true,"method":"S256","verifierSent":true,"pkceOk":true}'
ok "twig: signed in as the new user" "$(who "$J")" "plusone_alice@plusone.test"
ALICE=$(uid plusone_alice@plusone.test email)
ok "identity linked to the new user" "$(idlink plusone-fake plusone-alice)" "$ALICE"
ok "new user is active and in the default group" "$(tk "\$u=CraftCms\\Cms\\User\\Elements\\User::find()->id($ALICE)->status(null)->one(); echo \$u->getStatus().'/'.implode(',', array_map(fn(\$g)=>\$g->handle, \$u->getGroups()));")" "active/plusoneMembers"
ok "new user has no password and isn't an admin" "$(tk "echo json_encode([Illuminate\\Support\\Facades\\DB::table('users')->where('id',$ALICE)->value('password'), (bool) Illuminate\\Support\\Facades\\DB::table('users')->where('id',$ALICE)->value('admin')]);")" "[null,false]"
J2=$(jar)
login "$J2" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1' >/dev/null
ok "same identity again → same user, no duplicate" "$(who "$J2")/$(usercount)" "plusone_alice@plusone.test/1"

# ---------------------------------------------------------------- state, codes, PKCE
J=$(jar)
a=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-fake")
c=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$a&sub=plusone-mallory&email=plusone_mallory@plusone.test&verified=1")
tampered=$(echo "$c" | sed -E 's/state=[^&]+/state=forged/')
ok "callback with a forged state → refused" "$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{http_code} %{redirect_url}' "$tampered")" "302 $ACCOUNT"
ok "…and nobody signed in, no user created" "$(who "$J")/$(uid plusone_mallory@plusone.test email)" "guest/none"
V=$(jar)
ok "login CSRF: someone else's callback URL in a fresh browser → refused" "$(curl -sk -c "$V" -b "$V" -o /dev/null -w '%{http_code}' "$c")/$(who "$V")" "302/guest"
J=$(jar)
a=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-fake")
c=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$a&sub=plusone-alice&email=plusone_alice@plusone.test&verified=1")
curl -sk -c "$J" -b "$J" -o /dev/null "$c"
K=$(jar); curl -sk -c "$K" -b "$K" -o /dev/null "$U/actions/plusone/login/plusone-fake"
code=$(echo "$c" | grep -oE 'code=[^&]+'); st=$(curl -sk -c "$K" -b "$K" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-fake" | grep -oE 'state=[^&]+')
ok "replayed authorization code (valid state) → refused" "$(curl -sk -c "$K" -b "$K" -o /dev/null -w '%{http_code}' "$U/actions/plusone/callback/plusone-fake?$code&$st")/$(who "$K")" "302/guest"
ok "provider error (access_denied) → refused cleanly" "$(flow "$(jar)" "$U/actions/plusone/login/plusone-fake" 'deny=1')" "302 $ACCOUNT"
conf '{"fake.pkce": false}'
J=$(jar); login "$J" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1' >/dev/null
ok "PKCE off for a provider → no challenge sent (still signs in)" "$(last | python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(d["challengeSent"], d["verifierSent"])')/$(who "$J")" "False False/plusone_alice@plusone.test"
conf

# ---------------------------------------------------------------- account takeover guards
J=$(jar)
ok "existing email, provider sends email_verified=false → refused" "$(login "$J" 'sub=plusone-evil1&email=plusone_bob@plusone.test&verified=0')/$(who "$J")" "302 $ACCOUNT/guest"
ok "…not linked" "$(idlink plusone-fake plusone-evil1)" "none"
J=$(jar)
ok "existing email, no verification claim at all → refused" "$(login "$J" 'sub=plusone-evil2&email=plusone_bob@plusone.test')/$(who "$J")" "302 $ACCOUNT/guest"
ok "…and no second account with that email" "$(tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->email('plusone_bob@plusone.test')->status(null)->count();")" "1"
conf '{"fake.linkByEmail": false}'
J=$(jar)
ok "existing email, verified, but provider not set to match by email → refused" "$(login "$J" 'sub=plusone-bob-g&email=plusone_bob@plusone.test&verified=1')/$(who "$J")" "302 $ACCOUNT/guest"
conf
J=$(jar)
ok "existing email, verified, provider matches by email → signs in as that user" "$(login "$J" 'sub=plusone-bob-g&email=plusone_bob@plusone.test&verified=1')/$(who "$J")" "302 $ACCOUNT/plusone_bob"
ok "…and links the identity" "$(idlink plusone-fake plusone-bob-g)" "$BOB"
J=$(jar)
ok "matched user's email isn't changed by the provider (case-variant email)" "$(login "$J" 'sub=plusone-bob-g&email=PLUSONE_BOB@plusone.test&verified=1' >/dev/null; tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->id($BOB)->status(null)->one()->email;")" "plusone_bob@plusone.test"
J=$(jar)
ok "admin's email, verified → refused (admins off)" "$(login "$J" 'sub=plusone-adm&email=plusone_admin@plusone.test&verified=1')/$(who "$J")" "302 $ACCOUNT/guest"
ok "…not linked" "$(idlink plusone-fake plusone-adm)" "none"
tk "Illuminate\\Support\\Facades\\DB::table('sso_identities')->insert(['provider'=>'plusone-fake','identityId'=>'plusone-adm-linked','userId'=>$ADMIN,'dateCreated'=>now(),'dateUpdated'=>now()]); echo 1;" >/dev/null
J=$(jar)
ok "admin with an already-linked identity → still refused" "$(login "$J" 'sub=plusone-adm-linked&email=x_plusone@plusone.test&verified=1')/$(who "$J")" "302 $ACCOUNT/guest"
conf '{"allowAdmins": true}'
J=$(jar)
ok "allowAdmins on → the linked admin signs in" "$(login "$J" 'sub=plusone-adm-linked&email=x_plusone@plusone.test&verified=1' >/dev/null; who "$J")" "plusone_admin"
conf
tk "Illuminate\\Support\\Facades\\DB::table('sso_identities')->where('userId',$ADMIN)->delete(); echo 1;" >/dev/null
tk "Illuminate\\Support\\Facades\\DB::table('users')->where('id',$BOB)->update(['suspended'=>true]); echo 1;" >/dev/null
J=$(jar)
ok "suspended user with a linked identity → refused, stays suspended" "$(login "$J" 'sub=plusone-bob-g&email=plusone_bob@plusone.test&verified=1' >/dev/null; who "$J")/$(tk "echo (int) Illuminate\\Support\\Facades\\DB::table('users')->where('id',$BOB)->value('suspended');")" "guest/1"
tk "Illuminate\\Support\\Facades\\DB::table('users')->where('id',$BOB)->update(['suspended'=>false]); echo 1;" >/dev/null

# ---------------------------------------------------------------- registration rules
J=$(jar)
ok "new user with an unverified email → refused, no account" "$(login "$J" 'sub=plusone-u1&email=plusone_u1@plusone.test&verified=0' >/dev/null; who "$J")/$(uid plusone_u1@plusone.test email)" "guest/none"
J=$(jar)
ok "new user with no email → refused" "$(login "$J" 'sub=plusone-u2' >/dev/null; who "$J")/$(idlink plusone-fake plusone-u2)" "guest/none"
conf '{"allowedEmailDomains": "example.org, @plusone.example"}'
J=$(jar)
ok "email domain not allowed → refused" "$(login "$J" 'sub=plusone-u3&email=plusone_u3@plusone.test&verified=1' >/dev/null; who "$J")/$(uid plusone_u3@plusone.test email)" "guest/none"
J=$(jar)
ok "allowed domain → registered" "$(login "$J" 'sub=plusone-u4&email=plusone_u4@plusone.example&verified=1' >/dev/null; who "$J")" "plusone_u4@plusone.example"
conf '{"fake.newUsers": "deny"}'
J=$(jar)
ok "provider set to never register → refused" "$(login "$J" 'sub=plusone-u5&email=plusone_u5@plusone.test&verified=1' >/dev/null; who "$J")/$(uid plusone_u5@plusone.test email)" "guest/none"
conf '{"fake.newUsers": "default"}'
pubreg=$(tk "echo (int) CraftCms\\Cms\\Support\\Facades\\ProjectConfig::get('users.allowPublicRegistration');")
J=$(jar)
if [ "$pubreg" = 0 ]; then
  ok "“if public registration is on” with it off → refused" "$(login "$J" 'sub=plusone-u6&email=plusone_u6@plusone.test&verified=1' >/dev/null; who "$J")" "guest"
fi
conf '{"requireVerifiedEmail": false}'
J=$(jar)
ok "verification not required: unverified new user is created inactive (not signed in, nothing activated)" "$(login "$J" 'sub=plusone-u7&email=plusone_u7@plusone.test&verified=0' >/dev/null; who "$J")/$(tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->email('plusone_u7@plusone.test')->status(null)->one()?->getStatus();")" "guest/inactive"
conf

# ---------------------------------------------------------------- return URLs (open redirect)
for r in 'https://evil.test/x' '//evil.test/x' '/\evil.test' 'javascript:alert(1)' 'https://plugin-testing-craft6.ddev.site.evil.test/' '%2F%2Fevil.test'; do
  J=$(jar)
  out=$(flow "$J" "$U/actions/plusone/login/plusone-fake?return=$(python3 -I -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1],safe="%"))' "$r")" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1')
  ok "return=$r → stays on the site" "$(echo "$out" | grep -c 'evil')/${out%% *}" "0/302"
done
J=$(jar)
ok "absolute on-site return URL is kept" "$(flow "$J" "$U/actions/plusone/login/plusone-fake?return=$U/plusone/account-blade" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1')" "302 $U/plusone/account-blade"
ok "already signed in: login link just returns (off-site return dropped)" "$(curl -sk -b "$J" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-fake?return=https://evil.test/")" "$U/"
conf '{"sites": []}'
J=$(jar)
ok "failed front-end sign-in without a site sign-in page → Craft's default (CP login)" "$(login "$J" 'sub=plusone-u1&email=plusone_u1@plusone.test&verified=0')" "302 $CPLOGIN"
conf
ok "unknown provider → 404" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/actions/plusone/login/nope")/$(curl -sk -o /dev/null -w '%{http_code}' "$U/oauth/nope/redirect")" "404/404"
conf '{"fake.enabled": false}'
ok "disabled provider → login and callback 404" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/actions/plusone/login/plusone-fake")/$(curl -sk -o /dev/null -w '%{http_code}' "$U/actions/plusone/callback/plusone-fake?code=x&state=y")" "404/404"
conf '{"fake.clientSecret": "$PLUSONE_NOT_SET"}'
ok "provider whose secret env var isn't set → unavailable, site still up" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/actions/plusone/login/plusone-fake")/$(curl -sk -o /dev/null -w '%{http_code}' "$ACCOUNT")" "404/200"
conf

# ---------------------------------------------------------------- SocialiteProviders (Keycloak package)
J=$(jar)
kc=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$U/actions/plusone/login/plusone-kc")
ok "keycloak package: authorize URL from base_url + realms" "${kc%%\?*}" "$IDP/realms/plusone/protocol/openid-connect/auth"
ok "keycloak package: full sign-in (new user)" "$(login "$J" 'sub=plusone-kc-carol&email=plusone_carol@plusone.test&verified=1' plusone-kc >/dev/null; who "$J")" "plusone_carol@plusone.test"
ok "keycloak package: PKCE verified by the provider" "$(last | python3 -I -c 'import json,sys; print(json.load(sys.stdin)["pkceOk"])')" "True"

# ---------------------------------------------------------------- templates: Twig ↔ Blade
J=$(jar); login "$J" 'sub=plusone-alice&email=plusone_alice@plusone.test&verified=1' >/dev/null
strip() { sed -E 's/name="_token" value="[^"]+"/name="_token" value="T"/'; }
ok "Twig and Blade render the same (signed in)" "$(page "$J" account | strip | md5)" "$(page "$J" account-blade | strip | md5)"
ok "Twig and Blade render the same (guest)" "$(page "$(jar)" account | strip | md5)" "$(page "$(jar)" account-blade | strip | md5)"
ok "providers() on the default site" "$(page "$J" account | field providers)" "plusone-fake=Fake ID,plusone-kc=Fake Keycloak"
ok "buttons() escapes and links to the login route" "$(page "$J" account-blade | grep -o 'class="plusone-button plusone-button--plusone-fake"[^>]*>' | grep -c "href=\"$U/actions/plusone/login/plusone-fake?return=%2Fplusone%2Faccount\"")" "1"
ok "accounts(): connected, but it's alice's only way in" "$(page "$J" account | grep -o '<li>plusone-fake[^<]*')" "<li>plusone-fake:connected:cannot"
ok "no secrets or identity ids in the page" "$(page "$J" account | grep -cE 'plusone-secret|plusone-alice|PLUSONE_FAKE')" "0"

# ---------------------------------------------------------------- front-end linking
ok "disconnect alice's only sign-in method → refused" "$(post "$J" -d return=/plusone/account "$U/actions/plusone/disconnect/plusone-fake")/$(idlink plusone-fake plusone-alice)" "302 $ACCOUNT/$ALICE"
ok "…with an explanation" "$(page "$J" account | field message | grep -c '^error:Set a password or connect another provider')" "1"
ok "connect without the CSRF token → 419" "$(curl -sk -b "$J" -X POST -o /dev/null -w '%{http_code}' "$U/actions/plusone/connect/plusone-kc")" "419"
c=$(post "$J" -d return=/plusone/account "$U/actions/plusone/connect/plusone-kc")
ok "alice (no password) connects Keycloak: off to the provider" "${c%%\?*}" "302 $IDP/realms/plusone/protocol/openid-connect/auth"
cb=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "${c#302 }&sub=plusone-kc-alice&email=other@plusone.test&verified=1")
ok "…and back to the on-site return page, not the CP" "$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{http_code} %{redirect_url}' "$cb")" "302 $ACCOUNT"
ok "…linked to alice" "$(idlink plusone-kc plusone-kc-alice)" "$ALICE"
ok "…with core's success message" "$(page "$J" account | field message)" "success:Fake Keycloak connected."
ok "now alice can disconnect either provider" "$(page "$J" account-blade | grep -o '<li>[^<]*' | tr '\n' ' ')" "<li>plusone-fake:connected:can <li>plusone-kc:connected:can "
ok "disconnect Keycloak" "$(post "$J" -d return=/plusone/account "$U/actions/plusone/disconnect/plusone-kc")/$(idlink plusone-kc plusone-kc-alice)" "302 $ACCOUNT/none"
# bob has a password: connecting needs it
B=$(jar); login "$B" 'sub=plusone-bob-g&email=plusone_bob@plusone.test&verified=1' >/dev/null
ok "bob signed in" "$(who "$B")" "plusone_bob"
ok "bob needs his password to connect (Twig = Blade)" "$(page "$B" account | field needsPassword)/$(page "$B" account-blade | field needsPassword)" "yes/yes"
ok "…and the connect form asks for it (Twig = Blade)" "$(page "$B" account | grep -c 'name="password"')/$(page "$B" account-blade | grep -c 'name="password"')" "1/1"
age "$B"
ok "connect without the password → refused" "$(post "$B" "$U/actions/plusone/connect/plusone-kc")" "302 $ACCOUNT"
ok "connect with a wrong password → refused" "$(post "$B" -d password=wrong "$U/actions/plusone/connect/plusone-kc")" "302 $ACCOUNT"
c=$(post "$B" -d password=plusone-Pass-123 -d 'return=https://evil.test/' "$U/actions/plusone/connect/plusone-kc")
ok "connect with the password → off to the provider" "${c%%\?*}" "302 $IDP/realms/plusone/protocol/openid-connect/auth"
cb=$(curl -sk -c "$B" -b "$B" -o /dev/null -w '%{redirect_url}' "${c#302 }&sub=plusone-kc-alice2&email=x@plusone.test&verified=1")
ok "…off-site return dropped for the site's account page" "$(curl -sk -c "$B" -b "$B" -o /dev/null -w '%{redirect_url}' "$cb")" "$ACCOUNT"
ok "…linked to bob" "$(idlink plusone-kc plusone-kc-alice2)" "$BOB"
# an identity already linked to bob can't be connected to alice
c=$(post "$J" -d return=/plusone/account "$U/actions/plusone/connect/plusone-kc")
cb=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "${c#302 }&sub=plusone-kc-alice2&email=x@plusone.test&verified=1")
curl -sk -c "$J" -b "$J" -o /dev/null "$cb"
ok "connecting an identity that belongs to someone else → refused" "$(idlink plusone-kc plusone-kc-alice2)/$(page "$J" account | field message)" "$BOB/error:Fake Keycloak is already connected to another user."
ok "bob (has a password) can disconnect his last provider" "$(post "$B" "$U/actions/plusone/disconnect/plusone-kc")/$(idlink plusone-kc plusone-kc-alice2)" "302 $ACCOUNT/none"
ok "disconnect a provider you're not connected to → refused" "$(post "$B" "$U/actions/plusone/disconnect/plusone-kc")" "302 $ACCOUNT"
G=$(jar); curl -sk -c "$G" -b "$G" -o /dev/null "$U/actions/plusone/login/plusone-fake"
ok "connect/disconnect when signed out → 403" "$(post "$G" "$U/actions/plusone/connect/plusone-fake" | cut -c1-3)/$(post "$G" "$U/actions/plusone/disconnect/plusone-fake" | cut -c1-3)" "403/403"
conf '{"frontEndLinking": false}'
ok "front-end linking off → 404, accounts() empty" "$(post "$J" "$U/actions/plusone/connect/plusone-kc" | cut -c1-3)/$(page "$J" account | grep -c '<li>')" "404/0"
conf

# ---------------------------------------------------------------- control panel
E=$(jar)
cpstart=$(curl -sk -c "$E" -b "$E" -o /dev/null -w '%{redirect_url}' "$U/admin/oauth/plusone-fake/redirect")
ok "CP sign-in (provider with CP login on): callback carries context=cp" "$(python3 -I -c 'import sys,urllib.parse as u; print(u.parse_qs(u.urlparse(sys.argv[1]).query)["redirect_uri"][0])' "$cpstart")" "$U/actions/plusone/callback/plusone-fake?context=cp"
tk "Illuminate\\Support\\Facades\\DB::table('sso_identities')->insert(['provider'=>'plusone-fake','identityId'=>'plusone-editor','userId'=>$EDITOR,'dateCreated'=>now(),'dateUpdated'=>now()]); echo 1;" >/dev/null
cb=$(curl -sk -c "$E" -b "$E" -o /dev/null -w '%{redirect_url}' "$cpstart&sub=plusone-editor&email=plusone_editor@plusone.test&verified=1")
ok "CP sign-in as a social-only editor → the CP" "$(curl -sk -c "$E" -b "$E" -o /dev/null -w '%{http_code} %{redirect_url}' "$cb" | cut -c1-$((${#U}+10)))" "302 $U/admin"
ok "CP: the editor can't disconnect their only sign-in method (core screen, guarded)" "$(curl -sk -c "$E" -b "$E" -X DELETE -H "X-XSRF-TOKEN: $(xsrf "$E")" -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$U/admin/myaccount/sign-in-providers/plusone-fake")/$(idlink plusone-fake plusone-editor)" "422/$EDITOR"
ok "Keycloak isn't offered for CP sign-in (CP login off)" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/admin/oauth/plusone-kc/redirect")" "404"
A=""; for i in 1 2 3 4 5 6; do A=$("$H/bin/cp-session" 2>/dev/null) && break; sleep 15; done
if [ -n "$A" ]; then
  ok "CP: an admin can't start connecting a provider" "$(curl -sk -b "$A" -c "$A" -X POST -H "X-XSRF-TOKEN: $(xsrf "$A")" -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$U/admin/myaccount/sign-in-providers/plusone-fake/connect")" "403"
  ok "CP: Plus One settings page loads" "$(curl -sk -b "$A" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/plusone")" "200"
  ok "CP: settings page shows env var names, never secret values" "$(curl -sk -b "$A" "$U/admin/settings/plugins/plusone" | grep -c 'plusone-secret')/$(curl -sk -b "$A" "$U/admin/settings/plugins/plusone" | grep -c 'PLUSONE_FAKE_SECRET' | tr -d ' ' | sed 's/[1-9][0-9]*/yes/')" "0/yes"
  ok "CP: settings page lists the callback URL to register" "$(curl -sk -b "$A" "$U/admin/settings/plugins/plusone" | grep -c 'actions\\/plusone\\/callback\\/plusone-fake')" "1"
else
  echo "… skipped admin CP checks (CP login throttled)"
fi
ok "settings: a literal client secret is rejected" "$(tk "\$s=new JustinHoltWeb\\PlusOne\\Settings; \$s->providers=[['handle'=>'x','driver'=>'google','clientId'=>'id','clientSecret'=>'literal-secret']]; echo \$s->validate() ? 'valid' : 'invalid';")" "invalid"
ok "settings: an unknown driver / bad handle / http endpoint is rejected" "$(tk "\$s=new JustinHoltWeb\\PlusOne\\Settings; foreach ([['handle'=>'x','driver'=>'nope','clientId'=>'i','clientSecret'=>'\$S'],['handle'=>'X Y','driver'=>'google','clientId'=>'i','clientSecret'=>'\$S'],['handle'=>'g','driver'=>'generic','clientId'=>'i','clientSecret'=>'\$S','options'=>'authorize=http://idp.test/a; token=https://idp.test/t; userinfo=https://idp.test/u']] as \$r) { \$s->providers=[\$r]; echo \$s->validate() ? 'v' : 'i'; }")" "iii"
ok "settings: a valid provider passes" "$(tk "\$s=new JustinHoltWeb\\PlusOne\\Settings; \$s->providers=[['handle'=>'google','driver'=>'google','clientId'=>'\$G_ID','clientSecret'=>'\$G_SECRET']]; echo \$s->validate() ? 'valid' : json_encode(\$s->errors()->all());")" "valid"
ok "settings: unknown default group / site path off-site rejected" "$(tk "\$s=new JustinHoltWeb\\PlusOne\\Settings; \$s->defaultUserGroup='nope'; echo \$s->validate() ? 'v' : 'i'; \$s=new JustinHoltWeb\\PlusOne\\Settings; \$s->sites=[['site'=>'default','loginPath'=>'//evil.test']]; echo \$s->validate() ? 'v' : 'i';")" "ii"

# ---------------------------------------------------------------- GraphQL
ok "gql fixtures" "$(run plusone-gql-fixtures.php)" "ok"
gq() { local i out; for i in 1 2 3 4 5; do out=$(curl -sk -H "Authorization: Bearer $(printf 'plusone%s' "$1" | awk '{ s=$0; while (length(s) < 32) s=s "0"; print s }')" -H 'Content-Type: application/json' \
  -X POST "$U/actions/graphql/api" -d "$(python3 -I -c 'import json,sys; print(json.dumps({"query": sys.argv[1]}))' "$2")" -w '\n%{http_code}'); [ "${out##*$'\n'}" = 429 ] && { sleep 10; continue; }; break; done
  echo "${out%$'\n'*}" | python3 -I -c "
import json,sys
r=json.load(sys.stdin)
if r.get('errors'): print('ERR:'+r['errors'][0]['message'])
else:
  d=r['data']; print($3)"; }
ok "gql: providers for the primary site" "$(gq full '{ plusOneProviders { handle name label loginUrl } }' 'json.dumps(d["plusOneProviders"], separators=(",",":"))')" "[{\"handle\":\"plusone-fake\",\"name\":\"Fake ID\",\"label\":\"Sign in with Fake ID\",\"loginUrl\":\"$U/actions/plusone/login/plusone-fake\"},{\"handle\":\"plusone-kc\",\"name\":\"Fake Keycloak\",\"label\":\"Sign in with Fake Keycloak\",\"loginUrl\":\"$U/actions/plusone/login/plusone-kc\"}]"
ok "gql: no such field without the schema permission" "$(gq none '{ plusOneProviders { handle } }' 'd' | grep -c 'Cannot query field "plusOneProviders"')" "1"
ok "gql: a site the schema can't read → error" "$(gq full '{ plusOneProviders(site: "nope") { handle } }' 'd')" "ERR:This schema can’t read the “nope” site."
ok "gql: the type has no credential/identity fields" "$(gq full '{ __type(name: "PlusOneProvider") { fields { name } } }' '",".join(f["name"] for f in d["__type"]["fields"])')" "handle,name,label,loginUrl"

# ---------------------------------------------------------------- multisite
if [ "${PLUSONE_MULTISITE:-0}" = 1 ]; then
  ok "multisite: add plusonefr" "$(run plusone-multisite-fixtures.php)" "ok"
  sleep 2
  ok "multisite: gql fixtures with plusonefr" "$(run plusone-gql-fixtures.php)" "ok"
  ok "multisite: provider limited to default → not on plusonefr (Twig = Blade)" "$(page "$(jar)" account plusonefr/ | field providers)/$(page "$(jar)" account-blade plusonefr/ | field providers)" "plusone-kc=Fake Keycloak/plusone-kc=Fake Keycloak"
  ok "multisite: …its login and callback 404 there" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/plusonefr/actions/plusone/login/plusone-fake")/$(curl -sk -o /dev/null -w '%{http_code}' "$U/plusonefr/actions/plusone/callback/plusone-fake?code=x&state=y")" "404/404"
  ok "multisite: …even with ?context=cp (the CP-login switch doesn't widen a site)" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/plusonefr/actions/plusone/login/plusone-fake?context=cp")" "404"
  J=$(jar)
  frstart=$(curl -sk -c "$J" -b "$J" -o /dev/null -w '%{redirect_url}' "$U/plusonefr/actions/plusone/login/plusone-kc?return=/plusone/account")
  ok "multisite: per-site callback URL" "$(python3 -I -c 'import sys,urllib.parse as u; print(u.parse_qs(u.urlparse(sys.argv[1]).query)["redirect_uri"][0])' "$frstart")" "$U/plusonefr/actions/plusone/callback/plusone-kc"
  ok "multisite: sign in on plusonefr, back to its own page" "$(login "$J" 'sub=plusone-kc-carol&email=plusone_carol@plusone.test&verified=1' plusone-kc plusonefr/)" "302 $U/plusonefr/plusone/account"
  ok "multisite: signed in on plusonefr" "$(who "$J" plusonefr/)" "plusone_carol@plusone.test"
  conf '{"fake.sites": "plusonefr"}'
  ok "multisite: move the provider to plusonefr only" "$(page "$(jar)" account plusonefr/ | field providers)/$(page "$(jar)" account | field providers)" "plusone-fake=Fake ID,plusone-kc=Fake Keycloak/plusone-kc=Fake Keycloak"
  ok "multisite: gql per site" "$(gq full '{ plusOneProviders(site: "plusonefr") { handle loginUrl } }' '",".join(p["handle"]+"@"+p["loginUrl"] for p in d["plusOneProviders"])')" "plusone-fake@$U/plusonefr/actions/plusone/login/plusone-fake,plusone-kc@$U/plusonefr/actions/plusone/login/plusone-kc"
  conf
  ok "multisite: French translations" "$(tk "app()->setLocale('fr'); echo CraftCms\\Cms\\t('Sign in with {name}', ['name' => 'Google'], category: 'plusone');")" "Se connecter avec Google"
fi

# ---------------------------------------------------------------- cleanup
"$H"/bin/harness-lock rm -f "$H/config/craft/plusone.php"
ok "cleanup" "$(run plusone-cleanup.php)" "ok"
ok "no plusone fixtures left" "$(tk "echo CraftCms\\Cms\\User\\Elements\\User::find()->username('plusone_*')->status(null)->count() + CraftCms\\Cms\\User\\Elements\\User::find()->email('plusone_*')->status(null)->count() + Illuminate\\Support\\Facades\\DB::table('sso_identities')->where('provider','like','plusone-%')->count() + (CraftCms\\Cms\\Support\\Facades\\Sites::getSiteByHandle('plusonefr', true) ? 1 : 0) + (CraftCms\\Cms\\Support\\Facades\\UserGroups::getGroupByHandle('plusoneMembers') ? 1 : 0);")" "0"
rm -f resources/views/plusone/account.twig resources/views/plusone/account-blade.blade.php storage/plusone-*.php
rmdir resources/views/plusone 2>/dev/null

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
