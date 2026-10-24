<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Support\ExplicitReadFields as Fields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class ExplicitReadFieldsTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_known_false_zero_empty_lists_and_owner_redaction_are_preserved_without_fake_values(): void
    {
        $body = json_decode('{"success":true,"data":[{"id":"original","qty":0,"allowed":false,"items":[],"posted_at":null,"status":"POSTED","partner":{"display_name":null,"availability":"WITHHELD_IN_AGGREGATE"}}],"meta":{"has_more":false,"next_cursor":null,"query":{"date_from":null}}}');
        $result = Fields::project($body);
        $this->assertSame('original', $result->data[0]->id);
        $this->assertSame(0, $result->data[0]->qty);
        $this->assertFalse($result->data[0]->allowed);
        $this->assertSame([], $result->data[0]->items);
        $this->assertObjectNotHasProperty('posted_at', $result->data[0]);
        $reasons = collect($result->meta->read_fields->missing_fields)->keyBy('path');
        $this->assertSame('NOT_RECORDED', $reasons['/data/0/posted_at']['status']);
        $this->assertSame('WITHHELD_IN_AGGREGATE', $reasons['/data/0/partner/display_name']['status']);
        $this->assertSame('END_OF_RESULTS', $reasons['/meta/next_cursor']['status']);
        $this->assertSame('FILTER_NOT_APPLIED', $reasons['/meta/query/date_from']['status']);
        $this->noNull($result);
        $this->assertNull($body->data[0]->posted_at); // Original owner representation is not mutated.
    }

    public function test_partial_total_and_container_availability_do_not_become_zero_or_available_total(): void
    {
        $body = json_decode('{"success":true,"data":[{"contained_qty":8,"available_qty":null,"reserved_qty":null,"quantity_semantics":"CONTAINER_CONTENTS_NOT_WAREHOUSE_BALANCE_OR_ISSUE_ELIGIBILITY","capacity":null,"capacity_status":"NOT_CONFIGURED"}],"meta":{"total":null,"availableTotal":2,"coverage":"PARTIAL_SOURCE"}}');
        $result = Fields::project($body);
        $this->assertObjectNotHasProperty('total', $result->meta);
        $this->assertSame(2, $result->meta->availableTotal);
        $this->assertSame(8, $result->data[0]->contained_qty);
        $this->assertObjectNotHasProperty('available_qty', $result->data[0]);
        $reasons = collect($result->meta->read_fields->missing_fields)->keyBy('path');
        $this->assertSame('PARTIAL_SOURCE', $reasons['/meta/total']['status']);
        $this->assertSame('NO_AUTHORITATIVE_CONTAINER_AVAILABILITY_MODEL', $reasons['/data/0/available_qty']['status']);
        $this->assertSame('NOT_CONFIGURED', $reasons['/data/0/capacity']['status']);
        $this->noNull($result);
    }

    public function test_null_list_element_is_fail_closed_not_reindexed_or_converted_to_an_empty_record(): void
    {
        try {
            Fields::project(json_decode('{"success":true,"data":[{"id":"one"},null,{"id":"three"}]}'));
            $this->fail('A missing list element must not be hidden.');
        } catch (HttpResponseException $error) {
            $this->assertSame(503, $error->getResponse()->getStatusCode());
        }
    }

    public function test_opt_in_read_explains_missing_values_and_preserves_exact_owner_write_precondition(): void
    {
        [$actor, $token] = $this->actor();
        $legacy = $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $etag = $legacy->headers->get('ETag');
        $projected = $this->getJson('/api/v1/auth/me', $this->headers($token) + [Fields::HEADER => Fields::CONTRACT])->assertOk()
            ->assertJsonPath('meta.contract', Fields::CONTRACT)->assertJsonPath('data.user_id', $actor->user_id)
            ->assertJsonPath('meta.read_fields.owner_precondition.etag', $etag);
        $this->assertFalse($projected->headers->has('ETag'));
        $this->assertStringContainsString(strtolower(Fields::HEADER), strtolower($projected->headers->get('Vary')));
        $this->assertStringContainsString(strtolower(Fields::HEADER), strtolower($projected->headers->get('Access-Control-Expose-Headers')));
        $this->noNull($projected->json());
        if (getenv('WMS_CAPTURE_READ_SCHEMA') === '1') {
            $example = $projected->json();
            $example['data']['email'] = 'redacted-test@example.test';
            fwrite(STDOUT, "\nEXPLICIT_READ_SCHEMA=".json_encode(['scope' => 'ISOLATED_TEST_NOT_LIVE',
                'status' => $projected->getStatusCode(), 'request_id' => $projected->headers->get('X-Request-ID'),
                'body' => $example], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->patchJson('/api/v1/auth/me', ['display_name' => 'Original write owner'], $this->headers($token) + ['If-Match' => $etag])
            ->assertOk()->assertJsonPath('data.display_name', 'Original write owner');
        $this->assertDatabaseHas('app_user', ['user_id' => $actor->user_id, 'display_name' => 'Original write owner']);
    }

    public function test_read_header_cannot_execute_commands_or_hide_authentication_and_cache_errors(): void
    {
        [, $token] = $this->actor();
        $this->getJson('/api/v1/auth/me', $this->headers('') + [Fields::HEADER => Fields::CONTRACT])->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout', [], $this->headers($token) + [Fields::HEADER => Fields::CONTRACT])
            ->assertStatus(422)->assertHeader('X-Request-ID');
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($token) + [Fields::HEADER => Fields::CONTRACT, 'If-None-Match' => '"1"'])
            ->assertStatus(422)->assertJsonPath('error.code', 'READ_FIELDS_CONDITIONAL_CACHE_UNSUPPORTED');
        $this->getJson('/api/v1/auth/sessions', $this->headers($token) + [Fields::HEADER => Fields::CONTRACT])->assertStatus(422)
            ->assertJsonPath('error.code', 'READ_FIELDS_ROUTE_UNSUPPORTED');
    }

    public function test_representation_namespace_cannot_overwrite_owner_metadata(): void
    {
        try {
            Fields::project(json_decode('{"success":true,"data":[],"meta":{"read_fields":{"owner":"must survive"}}}'));
            $this->fail('A namespace collision must not silently replace owner metadata.');
        } catch (HttpResponseException $error) {
            $this->assertSame(503, $error->getResponse()->getStatusCode());
        }
    }

    public function test_declared_redaction_wins_over_timestamp_and_quantity_fallback_reasons(): void
    {
        $result = Fields::project(json_decode('{"success":true,"data":{"posted_at":null,"posted_at_status":"REDACTED","available_qty":null,"available_qty_status":"FORBIDDEN","quantity_semantics":"CONTAINER_CONTENTS_NOT_WAREHOUSE_BALANCE_OR_ISSUE_ELIGIBILITY"},"meta":{"contract":"owner-v1"}}'));
        $missing = collect($result->meta->read_fields->missing_fields)->keyBy('path');
        $this->assertSame('REDACTED', $missing['/data/posted_at']['status']);
        $this->assertSame('FORBIDDEN', $missing['/data/available_qty']['status']);
        $this->assertSame('owner-v1', $result->meta->read_fields->owner_contract);
        $this->assertSame(Fields::CONTRACT, $result->meta->contract);
        $this->noNull($result);
    }

    public function test_unexplained_null_owner_root_is_not_hidden_as_a_successful_empty_result(): void
    {
        [, $token] = $this->actor();
        $this->app->instance(AuthController::class, new class extends Controller
        {
            public function me(): JsonResponse
            {
                return response()->json(['success' => true, 'data' => null, 'meta' => (object) []]);
            }
        });
        $this->getJson('/api/v1/auth/me', $this->headers($token) + [Fields::HEADER => Fields::CONTRACT])->assertStatus(503)
            ->assertJsonPath('error.code', 'READ_FIELDS_ROOT_UNAVAILABLE');
    }

    private function actor(): array
    {
        $password = bin2hex(random_bytes(18));
        $suffix = substr((string) Str::uuid(), 0, 8);
        $actor = User::create(['username' => 'fields_'.$suffix, 'email' => 'fields_'.$suffix.'@wms-hoanam.test',
            'display_name' => 'Isolated field contract', 'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1]);
        $token = $this->browserLogin($actor->email, $password)->assertOk()->json('data.access_token');

        return [$actor, $token];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token];
    }

    private function noNull(mixed $value): void
    {
        $this->assertNotNull($value);
        if (is_array($value) || is_object($value)) {
            foreach ($value as $child) {
                $this->noNull($child);
            }
        }
    }
}
