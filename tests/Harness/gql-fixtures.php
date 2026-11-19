<?php

// Plus One harness: GraphQL schemas + tokens (idempotent). Token = "plusone{name}" padded to 32 chars.
//   full   the primary site (+ plusonefr when it exists) and plusone.providers:read
//   none   the same sites, without plusone.providers:read
// Run with: ddev artisan tinker --execute "require 'storage/plusone-gql-fixtures.php';"

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Data\GqlToken;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;

$sites = Sites::getAllSites(true)->filter(fn ($s) => $s->primary || $s->handle === 'plusonefr')->map(fn ($s) => "sites.$s->uid:read")->values()->all();

foreach (['full' => [...$sites, 'plusone.providers:read'], 'none' => $sites] as $name => $scope) {
    $schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === "plusone_{$name}") ?? new GqlSchema;
    $schema->name = "plusone_{$name}";
    $schema->scope = $scope;
    Gql::saveSchema($schema) || throw new RuntimeException(json_encode($schema->errors()->all()));

    $token = collect(Gql::getTokens())->first(fn ($t) => $t->name === "plusone_{$name}") ?? new GqlToken;
    $token->name = "plusone_{$name}";
    $token->schemaId = $schema->id;
    $token->accessToken = str_pad("plusone{$name}", 32, '0');
    Gql::saveToken($token) || throw new RuntimeException(json_encode($token->errors()->all()));
}

Gql::invalidateCaches();
echo 'ok';
