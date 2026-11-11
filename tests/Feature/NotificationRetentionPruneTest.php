<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Retention\PruneNotificationRecipients;
use Tests\TestCase;

/**
 * Retention của chuông thông báo: xóa theo từng recipient, giữ ledger
 * `notifications`, và không bao giờ tự xóa critical/security/user/role.
 */
class NotificationRetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
    }

    public function test_prunes_read_info_after_90_days_and_unread_info_after_180_days(): void
    {
        $user = $this->user();
        $deletedRead = $this->recipient($user, 'inbound.completed', 'info', readDaysAgo: 91, createdDaysAgo: 200);
        $keptRead = $this->recipient($user, 'inbound.completed', 'info', readDaysAgo: 89, createdDaysAgo: 200);
        $deletedUnread = $this->recipient($user, 'inbound.created', 'info', readDaysAgo: null, createdDaysAgo: 181);
        $keptUnread = $this->recipient($user, 'inbound.created', 'info', readDaysAgo: null, createdDaysAgo: 179);

        $result = $this->pruner()->prune(5_000);

        $this->assertSame(2, $result['deleted_total']);
        $this->assertNull($this->freshRecipient($deletedRead));
        $this->assertNull($this->freshRecipient($deletedUnread));
        $this->assertNotNull($this->freshRecipient($keptRead));
        $this->assertNotNull($this->freshRecipient($keptUnread));
    }

    public function test_prunes_warning_on_longer_windows(): void
    {
        $user = $this->user();
        $deletedRead = $this->recipient($user, 'stock.shortage_detected', 'warning', readDaysAgo: 366, createdDaysAgo: 500);
        $keptRead = $this->recipient($user, 'stock.shortage_detected', 'warning', readDaysAgo: 364, createdDaysAgo: 500);
        $deletedUnread = $this->recipient($user, 'stock.shortage_detected', 'warning', readDaysAgo: null, createdDaysAgo: 541);
        $keptUnread = $this->recipient($user, 'stock.shortage_detected', 'warning', readDaysAgo: null, createdDaysAgo: 539);

        $result = $this->pruner()->prune(5_000);

        $this->assertSame(2, $result['deleted_total']);
        $this->assertNull($this->freshRecipient($deletedRead));
        $this->assertNull($this->freshRecipient($deletedUnread));
        $this->assertNotNull($this->freshRecipient($keptRead));
        $this->assertNotNull($this->freshRecipient($keptUnread));
    }

    public function test_keeps_critical_security_account_and_unknown_severity_rows(): void
    {
        $user = $this->user();
        $critical = $this->recipient($user, 'stock.shortage_detected', 'critical', readDaysAgo: 1_000, createdDaysAgo: 2_000);
        $security = $this->recipient($user, 'security.alert_raised', 'warning', readDaysAgo: 1_000, createdDaysAgo: 2_000);
        $account = $this->recipient($user, 'user.account_changed', 'info', readDaysAgo: 1_000, createdDaysAgo: 2_000);
        $role = $this->recipient($user, 'role.permissions_changed', 'info', readDaysAgo: null, createdDaysAgo: 2_000);
        $unknown = $this->recipient($user, 'custom.audit_event', 'audit', readDaysAgo: 1_000, createdDaysAgo: 2_000);

        $result = $this->pruner()->prune(5_000);

        $this->assertSame(0, $result['deleted_total']);
        $this->assertNotNull($this->freshRecipient($critical));
        $this->assertNotNull($this->freshRecipient($security));
        $this->assertNotNull($this->freshRecipient($account));
        $this->assertNotNull($this->freshRecipient($role));
        $this->assertNotNull($this->freshRecipient($unknown));
    }

    public function test_prunes_per_recipient_and_keeps_notification_ledger(): void
    {
        $oldUser = $this->user();
        $activeUser = $this->user();
        $notification = $this->notification('inbound.completed', 'info', now()->subDays(200));
        $old = $this->recipientFor($oldUser, $notification, readDaysAgo: 100);
        $active = $this->recipientFor($activeUser, $notification, readDaysAgo: 10);

        $this->pruner()->prune(5_000);

        $this->assertNull($this->freshRecipient($old));
        $this->assertNotNull($this->freshRecipient($active));
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $user = $this->user();
        $old = $this->recipient($user, 'inbound.completed', 'info', readDaysAgo: 100, createdDaysAgo: 200);

        $preview = $this->pruner()->prune(5_000, dryRun: true);

        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['matched']['info_read']);
        $this->assertSame(0, $preview['deleted_total']);
        $this->assertNotNull($this->freshRecipient($old));

        $result = $this->pruner()->prune(5_000);

        $this->assertSame(1, $result['deleted']['info_read']);
        $this->assertNull($this->freshRecipient($old));
    }

    public function test_limit_is_applied_per_tier_and_must_be_positive(): void
    {
        $user = $this->user();
        foreach (range(1, 3) as $index) {
            $this->recipient($user, 'inbound.completed', 'info', readDaysAgo: 100 + $index, createdDaysAgo: 200);
        }

        $this->assertSame(2, $this->pruner()->prune(2)['deleted_total']);
        $this->assertSame(1, $this->pruner()->prune(2)['deleted_total']);

        $this->expectException(\InvalidArgumentException::class);
        $this->pruner()->prune(0);
    }

    public function test_command_supports_dry_run_and_reports_json(): void
    {
        $user = $this->user();
        $this->recipient($user, 'inbound.completed', 'info', readDaysAgo: 100, createdDaysAgo: 200);

        $this->artisan('notification-retention:prune', ['--dry-run' => true])
            ->expectsOutputToContain('"dry_run":true')
            ->assertSuccessful();

        $this->assertSame(1, NotificationRecipient::query()->count());
    }

    private function pruner(): PruneNotificationRecipients
    {
        return app(PruneNotificationRecipients::class);
    }

    private function user(): User
    {
        $suffix = Str::lower(Str::random(10));

        return User::create([
            'username' => 'retention_'.$suffix,
            'display_name' => 'Retention test',
            'email' => 'retention_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    private function notification(string $eventKey, string $severity, Carbon $createdAt): Notification
    {
        return Notification::create([
            'event_key' => $eventKey,
            'title' => 'Retention test',
            'body' => 'Retention test body',
            'severity' => $severity,
            'source_module' => 'notification',
            'created_at' => $createdAt,
        ]);
    }

    private function recipient(
        User $user,
        string $eventKey,
        string $severity,
        ?int $readDaysAgo,
        int $createdDaysAgo,
    ): NotificationRecipient {
        return $this->recipientFor(
            $user,
            $this->notification($eventKey, $severity, now()->subDays($createdDaysAgo)),
            $readDaysAgo,
            $createdDaysAgo,
        );
    }

    private function recipientFor(
        User $user,
        Notification $notification,
        ?int $readDaysAgo,
        ?int $createdDaysAgo = null,
    ): NotificationRecipient {
        return NotificationRecipient::create([
            'notification_id' => $notification->id,
            'user_id' => $user->user_id,
            'read_at' => $readDaysAgo === null ? null : now()->subDays($readDaysAgo),
            'created_at' => now()->subDays($createdDaysAgo ?? 0),
        ]);
    }

    private function freshRecipient(NotificationRecipient $recipient): ?NotificationRecipient
    {
        return NotificationRecipient::query()->whereKey($recipient->id)->first();
    }
}
