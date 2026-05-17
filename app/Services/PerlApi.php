<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client over the Mojolicious Perl API.
 *
 * Endpoints live under {PERL_API_URL}/api/perl/ — on production
 * that's the nginx-proxied path on localhost; on the laptop dev
 * box it's an SSH tunnel back to production's 127.0.0.1:8082.
 *
 * Every method returns either the decoded JSON body on success or
 * null on any kind of failure (HTTP error, connection refused,
 * timeout). Callers can decide whether to fall back to a PHP-side
 * implementation or surface an error.
 */
class PerlApi
{
    /**
     * Parse one of the three Ancestry URL/triple shapes into a
     * (atreeid, ancestryid) pair. Single source of truth for the
     * regex — UpsertPersonRequest can delegate here once we trust
     * the service.
     *
     * @return array{atreeid:int, ancestryid:int}|null
     */
    public function parseUrl(string $url): ?array
    {
        try {
            $resp = $this->client()->post('parse-url', ['url' => $url]);
        } catch (\Throwable $e) {
            Log::warning('PerlApi parseUrl failed', ['error' => $e->getMessage()]);
            return null;
        }

        if ($resp->successful()) {
            $data = $resp->json();
            return isset($data['atreeid'], $data['ancestryid'])
                ? ['atreeid' => (int) $data['atreeid'], 'ancestryid' => (int) $data['ancestryid']]
                : null;
        }
        return null;
    }

    /** Cheap liveness probe — returns true when the service answers /healthz. */
    public function healthy(): bool
    {
        try {
            return $this->client()->get('healthz')->ok();
        } catch (\Throwable $e) {
            Log::warning('PerlApi healthz failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function client(): PendingRequest
    {
        $base = rtrim((string) config('services.perl_api.url'), '/') . '/api/perl/';
        return Http::baseUrl($base)
            ->acceptJson()
            ->timeout((int) config('services.perl_api.timeout', 15));
    }
}
