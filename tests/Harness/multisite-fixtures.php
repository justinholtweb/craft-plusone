<?php

// Plus One harness: its own second site, `plusonefr` (French), at $APP_URL/plusonefr/ (an absolute
// base URL: `@web` isn't resolved in Craft 6). Never touches a site Plus One didn't create.
// cleanup.php removes it. Run under bin/harness-lock:
//   ddev artisan tinker --execute "require 'storage/plusone-multisite-fixtures.php';"

use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;

$fr = Sites::getSiteByHandle('plusonefr', true);

if (! $fr) {
    $primary = Sites::getPrimarySite();
    $fr = new Site;
    $fr->groupId = $primary->groupId;
    $fr->name = 'Plus One Français';
    $fr->handle = 'plusonefr';
    $fr->language = 'fr-FR';
    $fr->hasUrls = true;
    $fr->baseUrl = rtrim((string) config('app.url'), '/').'/plusonefr/';
    $fr->enabled = true;
    Sites::saveSite($fr) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
}

echo 'ok';
