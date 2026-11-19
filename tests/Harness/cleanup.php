<?php

// Removes every Plus One harness fixture: users whose username or email starts with `plusone_`
// (and their sign-in identities), the plusone* user groups, plusone_* GraphQL schemas/tokens and
// the plusonefr site. Never touches anything else.
// Run with: ddev artisan tinker --execute "require 'storage/plusone-cleanup.php';"

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;

$users = User::find()->status(null)->all();
foreach ($users as $user) {
    if (str_starts_with((string) $user->username, 'plusone_') || str_starts_with((string) $user->email, 'plusone_')) {
        DB::table('sso_identities')->where('userId', $user->id)->delete();
        Elements::deleteElement($user, true);
    }
}
DB::table('sso_identities')->where('provider', 'like', 'plusone-%')->delete();

foreach (['plusoneMembers', 'plusoneEditors'] as $handle) {
    if ($group = UserGroups::getGroupByHandle($handle)) {
        UserGroups::deleteGroup($group);
    }
}

foreach (Gql::getTokens() as $token) {
    if (str_starts_with((string) $token->name, 'plusone_')) {
        Gql::deleteTokenById($token->id);
    }
}
foreach (Gql::getSchemas() as $schema) {
    if (str_starts_with((string) $schema->name, 'plusone_')) {
        Gql::deleteSchema($schema);
    }
}

if ($fr = Sites::getSiteByHandle('plusonefr', true)) {
    Sites::deleteSite($fr);
}

echo 'ok';
