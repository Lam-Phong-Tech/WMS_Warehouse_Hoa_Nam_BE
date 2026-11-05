<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications',function(Blueprint $t):void{$t->char('delivery_dispatch_key',64)->nullable()->unique();});
        Schema::create('notification_deliveries',function(Blueprint $t):void{
            $t->uuid('id')->primary();$t->foreignUuid('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $t->foreignUuid('registration_id')->constrained('notification_delivery_bindings')->cascadeOnDelete();
            $t->unsignedBigInteger('binding_version');$t->string('state',32);$t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('available_at');$t->timestampTz('expires_at');$t->uuid('lease_token')->nullable();$t->timestampTz('lease_expires_at')->nullable();
            $t->string('last_code',80)->nullable();$t->string('provider_id',512)->nullable();$t->timestampTz('provider_accepted_at')->nullable();
            $t->timestampTz('created_at');$t->timestampTz('updated_at');
            $t->unique(['recipient_id','registration_id','binding_version'],'notification_delivery_target');$t->index(['state','available_at'],'notification_delivery_due');
        });
    }
    public function down(): void {Schema::dropIfExists('notification_deliveries');Schema::table('notifications',function(Blueprint $t):void{$t->dropUnique(['delivery_dispatch_key']);$t->dropColumn('delivery_dispatch_key');});}
};
