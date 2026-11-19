<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Listeners;

use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use JustinHoltWeb\PlusOne\Template\PlusOneVariable;

/**
 * `plusone` for Twig and `$plusone` for Blade (Craft feeds template globals to both).
 */
class RegisterTemplateApi
{
    public function handle(TemplateGlobalsResolving $event): void
    {
        $event->globals['plusone'] = app(PlusOneVariable::class);
    }
}
