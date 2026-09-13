<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create tax_rates table for GST configuration
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('e.g., GST 5%, GST 18%');
            $table->text('description')->nullable();
            $table->decimal('rate', 5, 2)->comment('Total GST rate (e.g., 18)');
            $table->decimal('cgst_rate', 5, 2)->comment('CGST rate (50% of total for intra-state)');
            $table->decimal('sgst_rate', 5, 2)->comment('SGST rate (50% of total for intra-state)');
            $table->decimal('igst_rate', 5, 2)->comment('IGST rate (100% of total for inter-state)');
            $table->enum('category', ['standard', 'reduced', 'zero', 'exempt', 'special'])->default('standard')->comment('GST category');
            $table->string('hsn_code', 8)->nullable()->comment('Applicable HSN code');
            $table->boolean('is_active')->default(true)->comment('Active/Inactive flag');
            $table->boolean('is_default')->default(false)->comment('Default rate for new products');
            $table->timestamps();
            $table->index('is_active');
            $table->index('category');
        });

        // Seed default tax rates
        \DB::table('tax_rates')->insertOrIgnore([
            [
                'name' => 'GST 0%',
                'description' => 'Zero-rated items (essential goods)',
                'rate' => 0,
                'cgst_rate' => 0,
                'sgst_rate' => 0,
                'igst_rate' => 0,
                'category' => 'zero',
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'GST 5%',
                'description' => 'Basic necessities (food, medicines)',
                'rate' => 5,
                'cgst_rate' => 2.50,
                'sgst_rate' => 2.50,
                'igst_rate' => 5,
                'category' => 'reduced',
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'GST 12%',
                'description' => 'Processed foods, computers',
                'rate' => 12,
                'cgst_rate' => 6,
                'sgst_rate' => 6,
                'igst_rate' => 12,
                'category' => 'standard',
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'GST 18%',
                'description' => 'Standard rate (majority of goods)',
                'rate' => 18,
                'cgst_rate' => 9,
                'sgst_rate' => 9,
                'igst_rate' => 18,
                'category' => 'standard',
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'GST 28%',
                'description' => 'Luxury items (cars, AC)',
                'rate' => 28,
                'cgst_rate' => 14,
                'sgst_rate' => 14,
                'igst_rate' => 28,
                'category' => 'special',
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};

