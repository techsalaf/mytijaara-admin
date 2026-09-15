<?php
namespace Tests\Architecture;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
final class RegistrationSchema {
    public static function create(): void {
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary(); $t->text('value'); $t->integer('expiration');
        });
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->tinyInteger('status')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('logo')->default('def.png');
            $table->string('cover_photo')->default('def.png');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->text('address')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('store_business_model')->default('commission');
            $table->string('minimum_delivery_time')->default('30');
            $table->string('maximum_delivery_time')->default('40');
            $table->string('delivery_time_type')->default('min');
            $table->string('tin')->nullable();
            $table->date('tin_expire_date')->nullable();
            $table->string('tin_certificate_image')->nullable();
            $table->string('delivery_time')->default('30-40 min');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->text('pickup_zone_id')->nullable();
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->geometry('coordinates', subtype: 'polygon', srid: 4326)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('restaurant_wise_topic')->nullable();
            $table->string('customer_wise_topic')->nullable();
            $table->string('deliveryman_wise_topic')->nullable();
            $table->boolean('cash_on_delivery')->default(true);
            $table->boolean('digital_payment')->default(true);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_name');
            $table->string('module_type');
            $table->string('thumbnail')->nullable();
            $table->string('slug')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->integer('stores_count')->default(0);
            $table->boolean('all_zone_service')->default(false);
            $table->timestamps();
        });

        Schema::create('module_zone', function (Blueprint $table) {
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('zone_id');
            $table->double('per_km_shipping_charge')->nullable();
            $table->double('minimum_shipping_charge')->nullable();
            $table->double('maximum_shipping_charge')->nullable();
            $table->double('maximum_cod_order_amount')->nullable();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('store_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->integer('day');
            $table->time('opening_time');
            $table->time('closing_time');
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->string('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('admins', function (Blueprint $t) {
            $t->id(); $t->integer('role_id'); $t->string('email'); $t->timestamps();
        });
        Schema::create('notification_settings', function (Blueprint $t) {
            $t->id(); $t->string('type'); $t->string('key'); $t->string('module_type')->nullable(); $t->string('mail_status')->default('inactive'); $t->timestamps();
        });
    }
}
