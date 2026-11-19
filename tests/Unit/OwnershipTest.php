<?php

declare(strict_types=1);

use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\PlusOne\Auth\EmailOwnership;
use JustinHoltWeb\PlusOne\Auth\EmailProofs;

function account(array $props = []): User
{
    $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
    foreach (['active' => true, 'pending' => false, 'suspended' => false, 'locked' => false, 'unverifiedEmail' => null, ...$props] as $k => $v) {
        $user->$k = $v;
    }

    return $user;
}

it('only matches accounts with a proof for their current email, in a good state', function (array $props, bool $proof, bool $expected) {
    expect(EmailOwnership::isProven(account($props), $proof))->toBe($expected);
})->with([
    'active and proven' => [[], true, true],
    // Admin/console/import-created, or email changed with verification off: no proof for this address.
    'active but never proven' => [[], false, false],
    'pending (never verified)' => [['pending' => true, 'active' => false], true, false],
    'inactive' => [['active' => false], true, false],
    'suspended' => [['suspended' => true], true, false],
    'locked' => [['locked' => true], true, false],
    'email change awaiting verification' => [['unverifiedEmail' => 'x@example.com'], true, false],
]);

it('ties a proof to one address, case-insensitively', function () {
    expect(EmailProofs::hash(' Jo@Example.com '))->toBe(EmailProofs::hash('jo@example.com'))
        ->and(EmailProofs::hash('jo@example.com'))->not->toBe(EmailProofs::hash('victim@example.com'))
        ->and(strlen(EmailProofs::hash('a@b.c')))->toBe(64);
});
