<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('product_type', 20)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 20);
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('model', 120)->nullable();
            $table->string('sku', 60)->nullable()->unique();
            $table->json('specification')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_type', 'is_active']);
            $table->index('brand_id');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('storage', 40)->nullable();
            $table->string('ram', 40)->nullable();
            $table->string('color', 60)->nullable();
            $table->string('condition_grade', 20)->nullable();
            $table->json('attributes')->nullable();
            $table->decimal('default_purchase_price', 18, 2)->nullable();
            $table->decimal('default_selling_price', 18, 2)->nullable();
            $table->unsignedInteger('reorder_point')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
