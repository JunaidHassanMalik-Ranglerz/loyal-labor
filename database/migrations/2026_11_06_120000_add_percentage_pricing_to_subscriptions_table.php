<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'original_price')) {
                $table->decimal('original_price', 10, 2)->nullable()->after('price_pkr');
            }
            if (!Schema::hasColumn('subscriptions', 'discount_percentage')) {
                $table->decimal('discount_percentage', 5, 2)->default(0)->nullable()->after('original_price');
            }
            if (!Schema::hasColumn('subscriptions', 'payable_price')) {
                $table->decimal('payable_price', 10, 2)->nullable()->after('discount_percentage');
            }
        });

        $rows = DB::table('subscriptions')->get();

        foreach ($rows as $row) {
            $original = (float) (($row->original_price ?? null) ?: ($row->price_pkr ?? 0));
            if ($original < 0) {
                $original = 0;
            }

            $saving = (float) ($row->saving_price ?? 0);
            $existingDiscount = (float) (($row->discount_percentage ?? null) ?: ($row->discount_percent ?? 0));
            $existingPayable = isset($row->payable_price) && $row->payable_price !== null
                ? (float) $row->payable_price
                : null;

            // Preserve what customers currently pay.
            if ($existingPayable !== null && $existingPayable >= 0) {
                $payable = min($original, max(0, $existingPayable));
            } elseif ($saving > 0 && $saving <= $original) {
                $payable = $saving;
            } elseif ($existingDiscount > 0 && $existingDiscount <= 100 && $original > 0) {
                $payable = round($original - ($original * $existingDiscount / 100), 2);
            } else {
                $payable = $original;
            }

            $payable = round(max(0, min($original, $payable)), 2);

            if ($original > 0) {
                $discount = round((($original - $payable) / $original) * 100, 2);
            } else {
                $discount = 0;
            }
            $discount = min(100, max(0, $discount));

            DB::table('subscriptions')->where('id', $row->id)->update([
                'original_price' => $original,
                'discount_percentage' => $discount,
                'payable_price' => $payable,
                // Keep legacy columns in sync for older code paths.
                'price_pkr' => $original,
                'discount_percent' => (int) round($discount),
                'saving_price' => $payable,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'payable_price')) {
                $table->dropColumn('payable_price');
            }
            if (Schema::hasColumn('subscriptions', 'discount_percentage')) {
                $table->dropColumn('discount_percentage');
            }
            if (Schema::hasColumn('subscriptions', 'original_price')) {
                $table->dropColumn('original_price');
            }
        });
    }
};
