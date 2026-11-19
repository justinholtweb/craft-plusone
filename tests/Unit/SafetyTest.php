<?php

declare(strict_types=1);

use CraftCms\Cms\Auth\OAuth\Data\ProviderDefinition;
use JustinHoltWeb\PlusOne\Auth\EmailVerification;
use JustinHoltWeb\PlusOne\Auth\ReturnUrl;
use JustinHoltWeb\PlusOne\Settings;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User;

function definition(string $driver = 'generic', ?string $class = null): ProviderDefinition
{
    return new ProviderDefinition(handle: 'p', driver: $driver, providerClass: $class, name: 'P', label: 'Sign in with P', clientId: 'id', clientSecret: 'secret');
}

function socialite(array $raw, ?string $email = 'a@example.com'): User
{
    return (new User)->setRaw($raw)->map(['id' => '1', 'email' => $email]);
}

it('only treats an explicit verification claim as a verified email', function () {
    expect(EmailVerification::isVerified(definition(), socialite(['email_verified' => true])))->toBeTrue()
        ->and(EmailVerification::isVerified(definition(), socialite(['email_verified' => 'true'])))->toBeTrue()
        ->and(EmailVerification::isVerified(definition(), socialite(['verified_email' => true])))->toBeTrue()
        ->and(EmailVerification::isVerified(definition(), socialite(['email_verified' => false])))->toBeFalse()
        ->and(EmailVerification::isVerified(definition(), socialite(['email_verified' => 'yes please'])))->toBeFalse()
        ->and(EmailVerification::isVerified(definition(), socialite([])))->toBeFalse()
        ->and(EmailVerification::isVerified(definition(), socialite(['email_verified' => true], 'not-an-email')))->toBeFalse()
        ->and(EmailVerification::isVerified(definition(), socialite(['email_verified' => true], null)))->toBeFalse();
});

it('trusts GitHub’s emails (it only returns verified ones)', function () {
    expect(EmailVerification::isVerified(definition('github'), socialite([])))->toBeTrue()
        ->and(EmailVerification::isVerified(definition(GithubProvider::class, GithubProvider::class), socialite([])))->toBeTrue()
        // An explicit false claim still wins.
        ->and(EmailVerification::isVerified(definition('github'), socialite(['email_verified' => false])))->toBeFalse();
});

it('extracts email domains', function () {
    expect(EmailVerification::domain('Jo@Example.COM'))->toBe('example.com')
        ->and(EmailVerification::domain('weird@name@example.org'))->toBe('example.org')
        ->and(EmailVerification::domain('nope'))->toBeNull()
        ->and(EmailVerification::domain(null))->toBeNull();
});

it('accepts only on-site return paths', function (string $value, ?string $expected) {
    expect(ReturnUrl::normalizePath($value))->toBe($expected);
})->with([
    ['account', 'account'],
    ['/account', 'account'],
    ['/account?tab=social#linked', 'account?tab=social#linked'],
    ['/fr/compte', 'fr/compte'],
    ['https://evil.test/', null],
    ['//evil.test/x', null],
    ['/\\evil.test', null],
    ['\\\\evil.test', null],
    ['javascript:alert(1)', null],
    ['JaVaScRiPt:alert(1)', null],
    ['data:text/html,x', null],
    ["/account\nSet-Cookie: x", null],
    ['/account x', null],
    ['', null],
    ['/{siteUrl}', null],
]);

it('reads registration and per-site settings', function () {
    $settings = new Settings;
    $settings->allowedEmailDomains = "Example.com, @staff.example.org\n example.com";
    $settings->sites = [['site' => 'fr', 'loginPath' => ' connexion ', 'accountPath' => '']];

    expect($settings->allowedDomains())->toBe(['example.com', 'staff.example.org'])
        ->and($settings->siteSettings('fr'))->toBe(['loginPath' => 'connexion', 'accountPath' => null])
        ->and($settings->siteSettings('default'))->toBe(['loginPath' => null, 'accountPath' => null])
        ->and((new Settings)->requireVerifiedEmail)->toBeTrue()
        ->and((new Settings)->allowAdmins)->toBeFalse();
});
