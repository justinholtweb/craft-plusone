<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * Plus One — Social login, managed in the CP — every SocialiteProviders provider, front-end connected-accounts, on top of Craft 6's own OAuth.
 *
 * See PLAN.md for the build phases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Front-end account linking', category: 'plusone'), Lightswitch::make('frontEndLinking'))
                ->instructions(t('Let logged-in users connect and disconnect providers from the front end.', category: 'plusone')),
        ]);
    }
}
