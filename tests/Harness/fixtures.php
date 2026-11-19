<?php

// Plus One harness fixtures (idempotent), all prefixed `plusone`:
//   groups  plusoneMembers (default group for new users), plusoneEditors (accessCp)
//   users   plusone_bob (password), plusone_admin (admin, password), plusone_editor (no password, plusoneEditors)
// Run with: ddev artisan tinker --execute "require 'storage/plusone-fixtures.php';"

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\User\Data\UserGroup;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\UserPermissions;
use CraftCms\Cms\User\Users;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$groups = [];
foreach (['plusoneMembers' => 'Plus One Members', 'plusoneEditors' => 'Plus One Editors'] as $handle => $name) {
    $group = UserGroups::getGroupByHandle($handle) ?? new UserGroup;
    $group->name = $name;
    $group->handle = $handle;
    UserGroups::saveGroup($group) || throw new RuntimeException("group $handle: ".json_encode($group->errors()->all()));
    $groups[$handle] = UserGroups::getGroupByHandle($handle);
}
app(UserPermissions::class)->saveGroupPermissions($groups['plusoneEditors']->id, ['accessCp']);

$people = [
    'plusone_bob' => ['admin' => false, 'password' => 'plusone-Pass-123', 'groups' => []],
    'plusone_admin' => ['admin' => true, 'password' => 'plusone-Pass-123', 'groups' => []],
    'plusone_editor' => ['admin' => false, 'password' => null, 'groups' => [$groups['plusoneEditors']->id]],
];

$ids = [];
foreach ($people as $username => $p) {
    $user = User::find()->username($username)->status(null)->one() ?? new User;
    $user->username = $username;
    $user->email = "$username@plusone.test";
    $user->fullName = ucfirst(substr($username, 8));
    $user->admin = $p['admin'];
    $user->active = true;
    $user->pending = false;
    Elements::saveElement($user, false) || throw new RuntimeException("user $username: ".json_encode($user->errors()->all()));
    DB::table('users')->where('id', $user->id)->update(['password' => $p['password'] ? Hash::make($p['password']) : null, 'suspended' => false, 'locked' => false]);
    app(Users::class)->assignUserToGroups($user->id, $p['groups']);
    $ids[$username] = $user->id;
}

DB::table('sso_identities')->whereIn('userId', array_values($ids))->delete();

echo json_encode($ids);
