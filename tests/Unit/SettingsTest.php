<?php

declare(strict_types=1);

use JustinHoltWeb\PlusOne\Settings;

it('has sensible defaults', function () {
    $settings = new Settings;

    expect($settings->frontEndLinking)->toBe(true);
});
