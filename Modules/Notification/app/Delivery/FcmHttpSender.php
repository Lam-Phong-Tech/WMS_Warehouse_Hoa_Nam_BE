<?php

namespace Modules\Notification\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class FcmHttpSender implements DeliverySender
{
    public function __construct(private readonly FcmAccessTokenSource $tokens) {}

    public function send(string $token, array $data): array
    {
        return $this->transmit($token, $data, false);
    }

    /** Validates at Google without delivering; never touches an outbox row. */
    public function validate(string $token, array $data): array
    {
        return $this->transmit($token, $data, true);
    }

    private function transmit(string $token, array $data, bool $validation): array
    {
        if (! app(DeliveryConfig::class)->transportReady()) {
            return $this->out('CONFIGURATION_ERROR');
        }
        $allowed = ['contract', 'delivery_id', 'recipient_id', 'registration_id', 'binding_version', 'expires_at'];
        if (array_diff(array_keys($data), $allowed) !== [] || array_diff($allowed, array_keys($data)) !== [] || ($data['contract'] ?? null) !== DeliveryConfig::CONTRACT) {
            return $this->out('REJECTED');
        }
        foreach (['delivery_id', 'recipient_id', 'registration_id'] as $k) {
            if (! is_string($data[$k]) || ! Str::isUuid($data[$k])) {
                return $this->out('REJECTED');
            }
        }
        if (! is_string($data['binding_version']) || ! preg_match('/^[1-9][0-9]*$/D', $data['binding_version']) || strlen($data['binding_version']) > 16 || (int) $data['binding_version'] > 9007199254740991) {
            return $this->out('REJECTED');
        }
        if (! is_string($data['expires_at']) || ! preg_match('/T.*(?:Z|[+-]\d\d:\d\d)$/D', $data['expires_at'])) {
            return $this->out('REJECTED');
        }
        try {
            $expiry = CarbonImmutable::parse($data['expires_at']);
        } catch (Throwable) {
            return $this->out('REJECTED');
        }
        $project = (string) config('notification_delivery.project_id');
        if (! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $project) || $token === '' || strlen($token) > 4096) {
            return $this->out('CONFIGURATION_ERROR');
        }
        $canary = app(AndroidTestCanary::class);
        $lease = null;
        if ($canary->configured()) {
            if ($validation || ($lease = $canary->claimProvider($token, $data)) === null) {
                return $this->out('REJECTED');
            }
        }
        try {
            $access = $this->tokens->accessToken();
        } catch (Throwable) {
            return $this->out('CONFIGURATION_ERROR');
        }
        $ttl = min(3600, $expiry->getTimestamp() - now()->getTimestamp());
        if ($ttl <= 0) {
            return $this->out('EXPIRED');
        }
        if ($canary->configured() && ! $canary->providerAllowed($token, $data, 1, $lease)) {
            return $this->out('REJECTED');
        }
        try {
            $r = Http::withToken($access)->acceptJson()->connectTimeout(5)->timeout(15)->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', [
                'validateOnly' => $validation, 'message' => ['token' => $token, 'data' => $data, 'android' => ['priority' => 'HIGH', 'ttl' => $ttl.'s'], 'webpush' => ['headers' => ['TTL' => (string) $ttl, 'Urgency' => 'high']]],
            ]);
        } catch (Throwable) {
            return $this->out('UNKNOWN');
        }
        $json = $r->json();
        $name = is_array($json) ? ($json['name'] ?? null) : null;
        if ($r->successful() && is_string($name) && str_starts_with($name, 'projects/'.$project.'/messages/')) {
            return $validation ? $this->out('VALIDATED') : $this->out('PROVIDER_ACCEPTED', $name);
        }
        foreach ((array) ($json['error']['details'] ?? []) as $detail) {
            if (is_array($detail) && in_array($detail['errorCode'] ?? null, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true)) {
                return $this->out('UNREGISTERED');
            }
        }
        if (in_array($r->status(), [401, 403], true)) {
            return $this->out('CONFIGURATION_ERROR');
        }
        if ($r->status() === 429 || $r->serverError()) {
            $retry = $r->header('Retry-After');

            return $this->out('RETRYABLE', null, is_string($retry) && ctype_digit($retry) ? min(3600, (int) $retry) : null);
        }

        return $this->out('REJECTED');
    }

    private function out(string $state, ?string $id = null, ?int $retry = null): array
    {
        return ['state' => $state, 'provider_id' => $id, 'retry_after' => $retry];
    }
}
