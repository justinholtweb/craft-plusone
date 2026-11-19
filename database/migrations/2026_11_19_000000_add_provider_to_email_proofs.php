<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-release installs created `plusone_email_proofs` without `provider`. Provider proofs
 * recorded before then name no provider, so they never count (fail closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plusone_email_proofs') && ! Schema::hasColumn('plusone_email_proofs', 'provider')) {
            Schema::table('plusone_email_proofs', function (Blueprint $table) {
                $table->string('provider', 64)->nullable()->after('method');
            });
        }
    }

    public function down(): void {}
};
