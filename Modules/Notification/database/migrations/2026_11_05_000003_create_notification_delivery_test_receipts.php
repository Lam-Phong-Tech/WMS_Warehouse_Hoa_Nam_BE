<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        $pg=DB::getDriverName()==='pgsql';
        Schema::create('notification_delivery_test_receipts',function(Blueprint $t)use($pg):void{
            $t->uuid('id')->primary();$t->foreignUuid('user_id')->references($pg?'id':'user_id')->on($pg?'app_users':'app_user')->cascadeOnDelete();
            $t->uuid('auth_session_id');$t->foreignUuid('registration_id')->constrained('notification_delivery_bindings')->cascadeOnDelete();
            $t->unsignedBigInteger('binding_version');$t->foreignUuid('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();$t->timestampTz('created_at');
        });
    }
    public function down():void{Schema::dropIfExists('notification_delivery_test_receipts');}
};
