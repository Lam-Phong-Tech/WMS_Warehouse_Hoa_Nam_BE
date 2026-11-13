<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Retention\PruneNotificationRecipients;
use Modules\Notification\Services\NotificationService;
use Tests\TestCase;

class NotificationHistoryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(): User
    {
        return User::create(['username' => 'history_'.Str::random(12), 'email' => Str::random(12).'@example.test',
            'display_name' => 'History test', 'password_hash' => Hash::make('test-only'), 'status' => 'ACTIVE', 'version' => 1]);
    }

    public function test_a_thousand_warehouse_events_keep_every_recipient_despite_legacy_cap(): void
    {
        Event::fake([NotificationPushed::class]);
        $this->travelTo(now()->startOfMinute());
        config(['notification_delivery.max_in_app_per_minute' => 1]);
        $user = $this->recipient();
        $service = app(NotificationService::class);
        for ($i = 0; $i < 1000; $i++) {
            $service->notify('inbound.created', 'Same title', 'Same body', [$user->user_id],
                sourceModule: 'warehouse', subjectType: 'stock_document', subjectId: (string) Str::uuid(),
                sourceSnapshot: [], dispatchKey: 'history-event-'.$i);
        }
        $this->assertSame(1000, Notification::count());
        $this->assertSame(1000, NotificationRecipient::where('user_id', $user->user_id)->whereNull('read_at')->count());
    }

    public function test_display_opt_out_keeps_warehouse_history_but_other_categories_keep_preferences(): void
    {
        $user = $this->recipient();
        DB::transaction(fn () => ProfilePreferences::put($user->user_id, ['notifications' => [
            'stock_documents' => ['in_app' => false, 'email' => false],
            'inventory' => ['in_app' => false, 'email' => false],
        ]], 1));
        $service = app(NotificationService::class);
        $n = $service->notify('inbound.created', 'Title', 'Body', [$user->user_id], sourceModule: 'warehouse', sourceSnapshot: []);
        $this->assertNotNull($n);
        $this->assertDatabaseHas('notification_recipients', ['notification_id' => $n->id, 'user_id' => $user->user_id]);
        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    public function test_identical_text_does_not_merge_distinct_warehouse_events_but_dispatch_retry_is_idempotent(): void
    {
        $user = $this->recipient();
        $service = app(NotificationService::class);
        $subject = (string) Str::uuid();
        foreach (range(1, 2) as $i) {
            $this->assertNotNull($service->notify('stock.shortage_detected', 'Same', 'Same', [$user->user_id],
                sourceModule: 'inventory', subjectType: 'stock_document', subjectId: $subject, sourceSnapshot: []));
        }
        foreach (range(1, 2) as $i) {
            $service->notify('inbound.created', 'Same', 'Same', [$user->user_id], sourceModule: 'warehouse',
                sourceSnapshot: [], dispatchKey: 'one-real-dispatch');
        }
        $this->assertSame(3, Notification::count());
        $this->assertSame(3, NotificationRecipient::count());
    }

    public function test_retention_never_prunes_warehouse_history_even_when_read_long_ago(): void
    {
        $user = $this->recipient();
        foreach ([['inbound.created', 'notification'], ['custom.event', 'warehouse'], ['warranty.created', 'component']] as [$event, $module]) {
            $n = Notification::create(['event_key' => $event, 'title' => 'Old history', 'body' => 'Old body',
                'severity' => 'info', 'source_module' => $module, 'created_at' => now()->subDays(2000)]);
            NotificationRecipient::create(['notification_id' => $n->id, 'user_id' => $user->user_id,
                'read_at' => now()->subDays(1000), 'created_at' => now()->subDays(2000)]);
        }
        $this->assertSame(0, app(PruneNotificationRecipients::class)->prune()['deleted_total']);
        $this->assertSame(3, NotificationRecipient::count());
    }
}
