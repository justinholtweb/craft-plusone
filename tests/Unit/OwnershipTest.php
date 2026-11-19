<?php

declare(strict_types=1);

use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\PlusOne\Auth\EmailOwnership;

function account(array $props = []): User
{
    $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
    foreach (['active' => true, 'pending' => false, 'suspended' => false, 'locked' => false, 'unverifiedEmail' => null, ...$props] as $k => $v) {
        $user->$k = $v;
    }

    return $user;
}

it('only vouches for accounts whose email Craft has verified', function (array $props, bool $publicReg, bool $verify, bool $expected) {
    expect(EmailOwnership::isProven(account($props), $publicReg, $verify))->toBe($expected);
})->with([
    'active, registration verifies emails' => [[], true, true, true],
    'active, no public registration' => [[], false, false, true],
    'pending (never verified)' => [['pending' => true, 'active' => false], true, true, false],
    'inactive' => [['active' => false], false, true, false],
    'suspended' => [['suspended' => true], false, true, false],
    'locked' => [['locked' => true], false, true, false],
    'email change awaiting verification' => [['unverifiedEmail' => 'x@example.com'], false, true, false],
    'public registration without email verification' => [[], true, false, false],
]);
