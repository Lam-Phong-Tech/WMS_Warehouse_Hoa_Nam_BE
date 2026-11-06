<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Notification\Delivery\DeliverySender;
use Modules\Notification\Delivery\FcmAccessTokenSource;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\NotificationOutbox;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Services\NotificationService;
use Tests\Feature\Concerns\PushDeliveryTestCase;

class NotificationAndroidCanaryTest extends PushDeliveryTestCase
{
    use DatabaseMigrations;

    private const CANARY = 'notification-delivery-android-canary-v1';

    private string $manifestFile;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
        $this->afterRefreshingDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->manifestFile = sys_get_temp_dir().'/hn-fcm-canary-'.Str::uuid().'.json';
        file_put_contents($this->manifestFile, '{"enabled":false}');
        config()->set('notification_delivery.canary_file', $this->manifestFile);
        config()->set('notification_delivery.enabled', false);
        config()->set('notification_delivery.self_test_enabled', false);
        config()->set('notification_delivery.tier', 'dev-test');
        config()->set('app.url', 'https://khohoanamdev.lptech.info.vn');
    }

    protected function tearDown(): void
    {
        if (isset($this->manifestFile) && is_file($this->manifestFile)) {
            unlink($this->manifestFile);
        }
        parent::tearDown();
    }

    private function target(): array
    {
        [$user, $token] = $this->actor();
        $family = DB::table('refresh_tokens')->where('user_id', $user->user_id)->value('auth_session_id');
        $id = (string) Str::uuid();
        $proof = $this->proof();

        return compact('user', 'token', 'family', 'id', 'proof');
    }

    private function headers(array $target): array
    {
        return $this->headersFor($target['token'], $target['proof']) + [
            'X-Notification-App-Package' => 'vn.info.lptech.wmshoanam',
            'X-Notification-Installation-ID' => $target['id'],
        ];
    }

    private function activate(array $t, array $overrides = []): array
    {
        $fingerprint = hash_hmac('sha256', implode("\0", [self::CANARY, (string) $t['user']->user_id,
            $t['family'], $t['id'], hash('sha256', "notification-installation-v1\0".$t['proof'])]), (string) config('app.key'));
        $manifest = array_replace([
            'contract' => self::CANARY, 'enabled' => true, 'run_id' => (string) Str::uuid(),
            'user_id' => (string) $t['user']->user_id, 'auth_session_id' => $t['family'],
            'installation_id' => $t['id'], 'proof_fingerprint' => $fingerprint,
            'base_version' => 0, 'binding_version' => 1, 'registration_id' => null,
            'starts_at' => now()->utc()->subSecond()->toIso8601String(),
            'expires_at' => now()->utc()->addSeconds(1199)->toIso8601String(),
            'source' => 'system.notification.test', 'max_messages' => 1, 'max_provider_attempts' => 1,
        ], $overrides);
        file_put_contents($this->manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $manifest;
    }

    private function registerTarget(array $t): string
    {
        return $this->putJson(self::BASE.'/installations/'.$t['id'],
            array_replace($this->body(), ['platform' => 'ANDROID']), $this->headers($t))
            ->assertCreated()->assertHeader('ETag', '"1"')->json('data.registration_id');
    }

    private function selfTestBody(string $registration, ?string $key = null): array
    {
        return ['registration_id' => $registration, 'binding_version' => 1, 'request_key' => $key ?? (string) Str::uuid()];
    }

    private function sender(string $outcome = 'PROVIDER_ACCEPTED'): DeliverySender
    {
        return new class($outcome) implements DeliverySender
        {
            public array $seen = [];

            public function __construct(private string $outcome) {}

            public function send(string $token, array $data): array
            {
                $this->seen[] = $data;

                return ['state' => $this->outcome, 'provider_id' => null, 'retry_after' => null];
            }
        };
    }

    public function test_off_diagnostic_is_read_only_and_does_not_claim_unbound_identity_is_registered(): void
    {
        $t = $this->target();
        $h = $this->headers($t) + ['X-Notification-Canary-Diagnostic' => self::CANARY];
        $r = $this->getJson(self::BASE.'/installations/'.$t['id'], $h)->assertOk()->assertHeader('ETag', '"0"')
            ->assertJsonPath('data.canary_diagnostic.contract', self::CANARY)
            ->assertJsonPath('data.canary_diagnostic.actor_id', (string) $t['user']->user_id)
            ->assertJsonPath('data.canary_diagnostic.auth_session_id', $t['family'])
            ->assertJsonPath('data.canary_diagnostic.proof_status', 'UNBOUND_CLIENT_PROPOSED')
            ->assertJsonPath('data.canary_diagnostic.canary_active', false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $r->json('data.canary_diagnostic.proof_fingerprint'));
        $this->assertStringNotContainsString($t['proof'], $r->getContent());
        $this->assertSame(0, DB::table('notification_delivery_installations')->count());
        $this->assertSame(0, DB::table('notification_delivery_bindings')->count());
        $this->assertSame(0, DB::table('notification_delivery_test_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_capabilities_admit_only_exact_target_without_enabling_global_business(): void
    {
        $t = $this->target();
        $this->activate($t);
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.configuration_status', 'CONFIGURED')
            ->assertJsonPath('data.self_test_enabled', true)->assertJsonPath('data.platforms', ['ANDROID'])
            ->assertJsonPath('data.canary.mode', 'ANDROID_ONE_TEST')->assertJsonPath('data.canary.business_delivery_enabled', false);
        $this->getJson(self::BASE.'/capabilities', $this->headersFor($t['token']))->assertOk()->assertJsonPath('data.enabled', false);
        $wrong = $t;
        $wrong['id'] = (string) Str::uuid();
        $this->getJson(self::BASE.'/capabilities', $this->headers($wrong))->assertOk()->assertJsonPath('data.enabled', false);
        $wrong = $t;
        $wrong['proof'] = $this->proof();
        $this->getJson(self::BASE.'/capabilities', $this->headers($wrong))->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse(config('notification_delivery.enabled'));
        $this->assertFalse(config('notification_delivery.self_test_enabled'));
    }

    public function test_registration_fences_wrong_owner_installation_platform_and_proof(): void
    {
        $t = $this->target();
        $this->activate($t);
        $other = $this->target();
        $body = array_replace($this->body(), ['platform' => 'ANDROID']);
        $h = $this->headers($other);
        $h['X-Installation-Proof'] = $t['proof'];
        $this->putJson(self::BASE.'/installations/'.$t['id'], $body, $h)->assertForbidden();
        $wrong = $t;
        $wrong['id'] = (string) Str::uuid();
        $this->putJson(self::BASE.'/installations/'.$wrong['id'], $body, $this->headers($wrong))->assertForbidden();
        $wrong = $t;
        $wrong['proof'] = $this->proof();
        $this->putJson(self::BASE.'/installations/'.$t['id'], $body, $this->headers($wrong))->assertForbidden();
        $this->putJson(self::BASE.'/installations/'.$t['id'], $this->body(), $this->headers($t))->assertForbidden();
        $this->assertSame(0, DB::table('notification_delivery_installations')->count());
        $this->registerTarget($t);
    }

    public function test_registration_cannot_rotate_target_to_another_token_or_version(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $before = DB::table('notification_delivery_bindings')->where('id', $reg)->first();
        $this->putJson(self::BASE.'/installations/'.$t['id'],
            array_replace($this->body('TEST-OTHER-DEVICE-TOKEN-ROTATION', 1), ['platform' => 'ANDROID']), $this->headers($t))->assertStatus(409);
        $this->assertEquals($before, DB::table('notification_delivery_bindings')->where('id', $reg)->first());
        $this->putJson(self::BASE.'/installations/'.$t['id'],
            array_replace($this->body(), ['platform' => 'ANDROID']), $this->headers($t))->assertStatus(412);
    }

    public function test_manifest_closed_malformed_overlong_wrong_tier_and_expiry_fail_closed(): void
    {
        $t = $this->target();
        foreach ([['enabled' => false], ['expires_at' => now()->utc()->addHour()->toIso8601String()],
            ['expires_at' => now()->utc()->subSecond()->toIso8601String()], ['max_messages' => 2]] as $override) {
            $this->activate($t, $override);
            $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        }
        $this->activate($t);
        config()->set('notification_delivery.tier', 'production');
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        config()->set('notification_delivery.tier', 'dev-test');
        file_put_contents($this->manifestFile, '{broken');
        config()->set('notification_delivery.enabled', true);
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        Http::assertNothingSent();
    }

    public function test_one_key_is_durable_same_key_replays_but_second_key_cannot_create_another_test(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $body = $this->selfTestBody($reg);
        $first = $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertCreated()->assertJsonPath('data.state', 'PENDING');
        $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertOk()->assertJsonPath('data.recipient_id', $first->json('data.recipient_id'));
        $this->travel(2)->minutes();
        $this->postJson(self::BASE.'/test-self', $this->selfTestBody($reg), $this->headers($t))->assertStatus(409);
        $this->assertSame(1, DB::table('notification_delivery_test_receipts')->count());
        $this->assertSame(1, DB::table('notification_deliveries')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_canary_ignores_old_outbox_and_only_sends_selected_receipt_once(): void
    {
        config()->set('notification_delivery.canary_file', null);
        config()->set('notification_delivery.enabled', true);
        $t = $this->target();
        $web = $this->putJson(self::BASE.'/installations/'.Str::uuid(), $this->body('TEST-OLD-WEB-DEVICE-TOKEN'), $this->headersFor($t['token'], $this->proof()))->assertCreated()->json('data.registration_id');
        app(NotificationService::class)->notify('system.notification.test', 'OLD', 'OLD', [(string) $t['user']->user_id], dispatchKey: 'old-web-test');
        $old = DB::table('notification_deliveries')->where('registration_id', $web)->first();
        config()->set('notification_delivery.canary_file', $this->manifestFile);
        config()->set('notification_delivery.enabled', false);
        $this->activate($t);
        $reg = $this->registerTarget($t);
        app(NotificationService::class)->notify('user.account_changed', 'NOT IN SCOPE', 'NOT IN SCOPE', [(string) $t['user']->user_id], dispatchKey: 'business-not-in-scope');
        $this->postJson(self::BASE.'/test-self', $this->selfTestBody($reg), $this->headers($t))->assertCreated();
        $sender = $this->sender();
        $this->assertSame(1, app(NotificationOutbox::class)->drain($sender));
        $this->assertSame([$reg], array_column($sender->seen, 'registration_id'));
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->assertEquals($old, DB::table('notification_deliveries')->where('id', $old->id)->first());
        $this->assertSame(2, DB::table('notification_deliveries')->count());
    }

    public function test_unknown_and_retryable_are_never_automatically_retried(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $body = $this->selfTestBody($reg);
        $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertCreated();
        $sender = $this->sender('UNKNOWN');
        $this->assertSame(1, app(NotificationOutbox::class)->drain($sender));
        $this->travel(2)->minutes();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->getJson(self::BASE.'/test-self/'.$body['request_key'], $this->headers($t))->assertOk()->assertJsonPath('data.state', 'UNKNOWN');
        DB::table('notification_deliveries')->update(['state' => 'RETRYABLE', 'available_at' => now()->utc()->subMinute()->toIso8601String()]);
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->assertCount(1, $sender->seen);
    }

    public function test_closure_keeps_owner_get_and_exact_optout_available_but_stops_send(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $body = $this->selfTestBody($reg);
        $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertCreated();
        file_put_contents($this->manifestFile, '{"enabled":false}');
        $sender = $this->sender();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->getJson(self::BASE.'/test-self/'.$body['request_key'], $this->headers($t))->assertOk()->assertJsonPath('data.state', 'PENDING');
        $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertNotFound();
        $this->delete(self::BASE.'/installations/'.$t['id'], [], $this->headers($t) + ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.active', false);
        $this->assertSame([], $sender->seen);
    }

    public function test_direct_real_sender_cannot_send_old_or_fabricated_delivery_under_canary(): void
    {
        $t = $this->target();
        $this->activate($t);
        $tokens = new class implements FcmAccessTokenSource
        {
            public int $calls = 0;

            public function accessToken(): string
            {
                $this->calls++;

                return 'TEST-ONLY-OAUTH';
            }
        };
        $sender = new FcmHttpSender($tokens);
        $sender->send('TEST-OPAQUE-DEVICE-TOKEN', ['contract' => 'notification-delivery-v1', 'delivery_id' => (string) Str::uuid(),
            'recipient_id' => (string) Str::uuid(), 'registration_id' => (string) Str::uuid(), 'binding_version' => '1', 'expires_at' => now()->utc()->addMinute()->toIso8601String()]);
        $this->assertSame(0, $tokens->calls);
        Http::assertNothingSent();
    }

    public function test_expiry_during_oauth_prevents_fcm_request(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $this->postJson(self::BASE.'/test-self', $this->selfTestBody($reg), $this->headers($t))->assertCreated();
        $file = $this->manifestFile;
        $tokens = new class($file) implements FcmAccessTokenSource
        {
            public int $calls = 0;

            public function __construct(private string $file) {}

            public function accessToken(): string
            {
                $this->calls++;
                file_put_contents($this->file, '{"enabled":false}');

                return 'TEST-ONLY-OAUTH';
            }
        };
        app(NotificationOutbox::class)->drain(new FcmHttpSender($tokens));
        $this->assertSame(1, $tokens->calls);
        Http::assertNothingSent();
    }

    public function test_another_live_family_of_same_account_cannot_register_or_claim_capability(): void
    {
        config()->set('refresh_token.single_active_session.enabled', false);
        $t = $this->target();
        $this->activate($t);
        $t['token'] = $this->browserLogin($t['user']->email, 'Push-Test-Only123!')->assertOk()->json('data.access_token');
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson(self::BASE.'/installations/'.$t['id'], array_replace($this->body(), ['platform' => 'ANDROID']), $this->headers($t))->assertForbidden();
        $this->assertSame(0, DB::table('notification_delivery_installations')->count());
    }

    public function test_changed_binding_version_and_expiry_revoke_capability_and_send_admission(): void
    {
        $t = $this->target();
        $this->activate($t);
        $this->registerTarget($t);
        DB::table('notification_delivery_installations')->where('id', $t['id'])->update(['binding_version' => 2]);
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        $this->travel(21)->minutes();
        $this->getJson(self::BASE.'/capabilities', $this->headers($t))->assertOk()->assertJsonPath('data.enabled', false);
        Http::assertNothingSent();
    }

    public function test_expired_lease_readback_is_unknown_even_after_canary_closes(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $body = $this->selfTestBody($reg);
        $this->postJson(self::BASE.'/test-self', $body, $this->headers($t))->assertCreated();
        DB::table('notification_deliveries')->update(['state' => 'SENDING', 'attempts' => 1,
            'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->utc()->subMinute()->toIso8601String()]);
        file_put_contents($this->manifestFile, '{"enabled":false}');
        $this->getJson(self::BASE.'/test-self/'.$body['request_key'], $this->headers($t))->assertOk()->assertJsonPath('data.state', 'UNKNOWN');
        $this->assertSame('SENDING', DB::table('notification_deliveries')->value('state'));
        $s = $this->sender();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($s));
        Http::assertNothingSent();
    }

    public function test_real_sender_consumes_one_attempt_before_provider_and_cannot_replay_same_lease(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        $this->postJson(self::BASE.'/test-self', $this->selfTestBody($reg), $this->headers($t))->assertCreated();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/hoa-nam-scanner-devtest/messages/test-only'], 200)]);
        $tokens = new class implements FcmAccessTokenSource
        {
            public function accessToken(): string
            {
                return 'TEST-ONLY-OAUTH';
            }
        };
        $sender = new FcmHttpSender($tokens);
        $row = DB::table('notification_deliveries')->first();
        DB::table('notification_deliveries')->where('id', $row->id)->update(['state' => 'SENDING',
            'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->utc()->addMinute()->toIso8601String()]);
        $data = ['contract' => 'notification-delivery-v1', 'delivery_id' => $row->id, 'recipient_id' => $row->recipient_id,
            'registration_id' => $reg, 'binding_version' => '1', 'expires_at' => CarbonImmutable::parse($row->expires_at)->utc()->toIso8601String()];
        $this->assertSame('PROVIDER_ACCEPTED', $sender->send('TEST-FCM-OPAQUE-DEVICE-TOKEN-A', $data)['state']);
        $this->assertSame('REJECTED', $sender->send('TEST-FCM-OPAQUE-DEVICE-TOKEN-A', $data)['state']);
        Http::assertSentCount(1);
        $this->assertSame(1, (int) DB::table('notification_deliveries')->value('attempts'));
    }

    public function test_canary_test_does_not_broadcast_to_other_sessions_or_send_email(): void
    {
        $t = $this->target();
        $this->activate($t);
        $reg = $this->registerTarget($t);
        Event::fake([NotificationPushed::class]);
        Mail::fake();
        $this->postJson(self::BASE.'/test-self', $this->selfTestBody($reg), $this->headers($t))->assertCreated();
        Event::assertNotDispatched(NotificationPushed::class);
        Mail::assertNothingOutgoing();
        $this->assertSame(1, DB::table('notification_recipients')->count());
        $this->assertSame(1, DB::table('notification_deliveries')->count());
        $this->assertNull(DB::table('notification_recipients')->value('read_at'));
    }
}
