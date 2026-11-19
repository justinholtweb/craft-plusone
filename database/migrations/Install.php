<?php

declare(strict_types=1);

namespace JustinHoltWeb\PlusOne\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `plusone_email_proofs`: which address each user has proved owning, and how. Stored as a
 * SHA-256 of the lower-cased address, so a change of email (by anyone, any route) silently
 * voids the proof without Plus One having to watch for it.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plusone_email_proofs')) {
            return;
        }

        Schema::create('plusone_email_proofs', function (Blueprint $table) {
            $table->id();
            // Craft's users.id is a signed int, so no foreignId().
            $table->integer('userId')->unique();
            $table->foreign('userId')->references('id')->on(Table::USERS)->cascadeOnDelete();
            $table->char('emailHash', 64);
            $table->string('method', 16); // email-link | provider
            // For `provider` proofs: the provider that verified the address. Only counts while that
            // provider still exists and is trusted for email.
            $table->string('provider', 64)->nullable();
            $table->timestamp('dateProven');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plusone_email_proofs');
    }
}
