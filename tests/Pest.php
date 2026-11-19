<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\I18N;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

uses()->group('unit')->in('Unit');

// Unit tests run without a Craft app: translations echo the source string with its params filled in.
uses()->beforeEach(function () {
    $app = new Container;
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
    I18N::shouldReceive('translate')->andReturnUsing(
        fn (string $id, array $params = []) => strtr($id, collect($params)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all()),
    );
})->in('Unit');
