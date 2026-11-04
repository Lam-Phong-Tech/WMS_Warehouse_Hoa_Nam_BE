<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Mail\ProfileNotificationMail;
use Modules\Notification\Services\NotificationService;
use RuntimeException;
use Tests\TestCase;

/** Real commit boundaries in a disposable database; no external mail delivery. */
class ProfileNotificationDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    private function recipient(array $changes): User
    {
        $user = User::create(['username' => 'mail_'.Str::random(12), 'email' => Str::random(12).'@example.test',
            'display_name' => 'Mail test', 'password_hash' => Hash::make(Str::random(32)), 'status' => 'ACTIVE', 'version' => 1]);
        DB::transaction(function () use ($user, $changes): void {
            User::whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            ProfilePreferences::put((string) $user->user_id, $changes, 1);
        });

        return $user;
    }

    public function test_preferences_control_real_recipients_and_mail_only_after_commit(): void
    {
        Mail::fake();
        $user = $this->recipient(['notifications' => ['inventory' => ['in_app' => false, 'email' => true]]]);
        DB::transaction(function () use ($user): void {
            app(NotificationService::class)->notify('stock.shortage_detected', 'Stock notice', 'Stock details', [$user->user_id], sourceModule: 'inventory');
            Mail::assertNothingQueued();
            $this->assertSame(0, DB::table('notification_recipients')->where('user_id', $user->user_id)->count());
        });
        Mail::assertQueued(ProfileNotificationMail::class, 1);
        Mail::assertQueued(ProfileNotificationMail::class,
            fn ($mail) => $mail->hasTo($user->email) && str_contains($mail->render(), 'Stock details'));
        app(NotificationService::class)->notify('security.alert_raised', 'Security notice', 'Security details', [$user->user_id], sourceModule: 'auth');
        $this->assertSame(1, DB::table('notification_recipients')->where('user_id', $user->user_id)->count());
        Mail::assertQueued(ProfileNotificationMail::class, 1);
    }

    public function test_enqueue_failure_is_logged_and_does_not_fail_committed_notification(): void
    {
        $user = $this->recipient(['notifications' => ['inventory' => ['email' => true]]]);
        Log::spy();
        Mail::shouldReceive('to')->once()->with($user->email)->andThrow(new RuntimeException('Synthetic queue outage'));
        $notification = DB::transaction(fn () => app(NotificationService::class)->notify(
            'stock.shortage_detected', 'Stock notice', 'Stock details', [$user->user_id], sourceModule: 'inventory'));
        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notification_recipients', ['notification_id' => $notification->id, 'user_id' => $user->user_id]);
        Log::shouldHaveReceived('warning')->once()->with('Notification email enqueue failed',
            ['notification_id' => (string) $notification->id, 'exception' => RuntimeException::class]);
    }

    public function test_rolled_back_notification_never_enqueues_email(): void
    {
        Mail::fake();
        $user = $this->recipient(['notifications' => ['inventory' => ['email' => true]]]);
        try {
            DB::transaction(function () use ($user): void {
                app(NotificationService::class)->notify('stock.shortage_detected', 'Stock notice', 'Stock details', [$user->user_id], sourceModule: 'inventory');
                throw new RuntimeException('Synthetic rollback');
            });
        } catch (RuntimeException) {
        }
        Mail::assertNothingQueued();
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('notification_recipients')->count());
    }
}
