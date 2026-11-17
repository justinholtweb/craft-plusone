<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** Front-end account linking. Let logged-in users connect and disconnect providers from the front end. */
    public bool $frontEndLinking = true;
}
