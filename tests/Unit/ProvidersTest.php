<?php

declare(strict_types=1);

use JustinHoltWeb\PlusOne\Auth\GroupResolver;
use JustinHoltWeb\PlusOne\Auth\SafeUserResolver;
use JustinHoltWeb\PlusOne\Auth\UserPopulator;
use JustinHoltWeb\PlusOne\Providers\DriverCatalogue;
use JustinHoltWeb\PlusOne\Providers\GenericProvider;
use JustinHoltWeb\PlusOne\Providers\ManagedProvider;

function row(array $overrides = []): array
{
    return [
        'enabled' => true, 'handle' => 'google', 'driver' => 'google', 'name' => '', 'clientId' => '$G_ID',
        'clientSecret' => '$G_SECRET', 'scopes' => '', 'sites' => '*', 'cp' => false, 'newUsers' => 'default',
        'linkByEmail' => false, 'pkce' => true, 'options' => '', ...$overrides,
    ];
}

it('builds a provider from a valid row', function () {
    $p = ManagedProvider::fromRow(row(['scopes' => 'openid, email', 'sites' => 'default fr', 'options' => 'param.hd=example.com; groups=members, staff']));

    expect($p)->toBeInstanceOf(ManagedProvider::class)
        ->and($p->name)->toBe('Google')
        ->and($p->scopes)->toBe(['openid', 'email'])
        ->and($p->sites)->toBe(['default', 'fr'])
        ->and($p->availableOnSite('fr'))->toBeTrue()
        ->and($p->availableOnSite('de'))->toBeFalse()
        ->and($p->authParams())->toBe(['hd' => 'example.com'])
        ->and($p->groupHandles())->toBe(['members', 'staff'])
        ->and(ManagedProvider::fromRow(row(['sites' => '']))->sites)->toBeNull();
});

it('refuses literal secrets and malformed rows', function (array $overrides, string $error) {
    $errors = ManagedProvider::fromRow(row($overrides));

    expect($errors)->toBeArray()
        ->and(implode(' ', $errors))->toContain($error);
})->with([
    'literal secret' => [['clientSecret' => 'abc123'], 'environment variable reference'],
    'secret with text around the var' => [['clientSecret' => 'x$G_SECRET'], 'environment variable reference'],
    'bad handle' => [['handle' => 'Google Login'], 'handles are lower-case'],
    'unknown driver' => [['driver' => 'myspace'], 'unknown provider type'],
    'no client id' => [['clientId' => ''], 'client ID is required'],
    'bad new-users value' => [['newUsers' => 'sometimes'], '“New users”'],
    'unreadable option' => [['options' => 'justakey'], 'options are `key=value`'],
    'generic without endpoints' => [['driver' => 'generic'], 'need `authorize=https://…`'],
    'generic over http' => [['driver' => 'generic', 'options' => 'authorize=http://idp.test/a; token=https://idp.test/t; userinfo=https://idp.test/u'], '`authorize` must be an https:// URL'],
]);

it('accepts http endpoints only for localhost', function () {
    expect(ManagedProvider::isEndpoint('https://idp.example.com/authorize'))->toBeTrue()
        ->and(ManagedProvider::isEndpoint('http://localhost:8080/token'))->toBeTrue()
        ->and(ManagedProvider::isEndpoint('http://127.0.0.1/token'))->toBeTrue()
        ->and(ManagedProvider::isEndpoint('http://idp.example.com/token'))->toBeFalse()
        ->and(ManagedProvider::isEndpoint('https://user:pass@idp.example.com/'))->toBeFalse()
        ->and(ManagedProvider::isEndpoint('javascript:alert(1)'))->toBeFalse();
});

it('hands core a config that keeps state checks and Plus One’s guards', function () {
    putenv('G_ID=client-1');
    putenv('G_SECRET=s3cret');
    $_ENV['G_ID'] = 'client-1';
    $_ENV['G_SECRET'] = 's3cret';

    $config = ManagedProvider::fromRow(row(['newUsers' => 'deny', 'linkByEmail' => true]))->coreConfig();

    expect($config['stateless'])->toBeFalse()
        ->and($config['activatesUsers'])->toBeFalse()
        ->and($config['trustsEmail'])->toBeTrue()
        ->and($config['createsUsers'])->toBeFalse()
        ->and($config['clientId'])->toBe('client-1')
        ->and($config['clientSecret'])->toBe('s3cret')
        ->and($config['userResolver'])->toBe(SafeUserResolver::class)
        ->and($config['userPopulator'])->toBe(UserPopulator::class)
        ->and($config['groupResolver'])->toBe(GroupResolver::class)
        ->and(ManagedProvider::fromRow(row(['newUsers' => 'allow']))->coreConfig()['createsUsers'])->toBeTrue()
        ->and(ManagedProvider::fromRow(row())->coreConfig()['createsUsers'])->toBeNull();
});

it('treats an unset secret variable as missing, never as the literal reference', function () {
    $p = ManagedProvider::fromRow(row(['clientSecret' => '$PLUSONE_DEFINITELY_UNSET']));

    expect($p->clientSecret())->toBeNull()
        ->and($p->hasCredentials())->toBeFalse();
});

it('maps drivers to what core expects', function () {
    expect(ManagedProvider::fromRow(row())->coreDriver())->toBe('google')
        ->and(ManagedProvider::fromRow(row(['driver' => 'generic', 'options' => 'authorize=https://i.test/a; token=https://i.test/t; userinfo=https://i.test/u']))->coreDriver())->toBe(GenericProvider::class)
        ->and(DriverCatalogue::exists('github'))->toBeTrue()
        ->and(DriverCatalogue::exists('twitter'))->toBeFalse()
        ->and(DriverCatalogue::exists('socialiteproviders/not-installed'))->toBeFalse();
});
