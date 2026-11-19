<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Auth;

use CraftCms\Cms\Support\Url;

/**
 * Where to send someone after signing in or (dis)connecting: only ever a page on
 * the current site. Anything else — another host, a protocol-relative `//evil`,
 * a `javascript:` URL, back-slash tricks — is dropped for the default.
 */
final class ReturnUrl
{
    /**
     * A site-relative path (no leading slash) if `$value` is a safe on-site path, else null.
     * Accepts `account`, `/account`, `/account?tab=x#y`.
     */
    public static function normalizePath(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\{}<>"]/', $value) || str_starts_with($value, '//')) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        // A colon before the first slash reads as a scheme to some browsers (`javascript:…`).
        $beforeSlash = explode('/', ltrim($value, '/'), 2)[0];
        if (str_contains(explode('?', $beforeSlash, 2)[0], ':')) {
            return null;
        }

        return ltrim($value, '/');
    }

    /**
     * An absolute URL on the current site for `$value`, or `$default` (also checked) when it isn't one.
     * Absolute URLs are accepted only when they start with the current site's base URL.
     */
    public static function resolve(?string $value, ?string $default = null): string
    {
        foreach ([$value, $default] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if ($url = self::onSite($candidate)) {
                return $url;
            }
        }

        return Url::siteUrl();
    }

    private static function onSite(string $value): ?string
    {
        $base = Url::siteUrl();

        if (Url::isAbsoluteUrl($value)) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
                return null;
            }

            $prefix = rtrim($base, '/').'/';

            return ($value === rtrim($base, '/') || str_starts_with($value, $prefix)) && self::sameOrigin($value, $base) ? $value : null;
        }

        $path = self::normalizePath($value);

        return $path === null ? null : Url::siteUrl($path);
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);

        if (! is_array($pa) || ! is_array($pb) || isset($pa['user'])) {
            return false;
        }

        return strtolower($pa['scheme'] ?? '') === strtolower($pb['scheme'] ?? '')
            && strtolower($pa['host'] ?? '') === strtolower($pb['host'] ?? '')
            && ($pa['port'] ?? null) === ($pb['port'] ?? null);
    }
}
