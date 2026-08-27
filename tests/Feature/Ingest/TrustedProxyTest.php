<?php

namespace Tests\Feature\Ingest;

use App\Models\Account;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * The production host sits behind Cloudflare, which makes the client IP a
 * question rather than a fact.
 *
 * Two things depend on getting it right, and both fail silently rather
 * than loudly: the per-IP throttle -- the only control on a protocol with
 * no authentication -- and the country column. If every heartbeat in the
 * fleet reports the same edge address, the throttle is tripped by ordinary
 * traffic while a genuine flood hides inside it.
 *
 * So the trust list is Cloudflare's published ranges, not '*'. The origin
 * is still reachable by its bare IP, and a forwarded-for header is a field
 * the sender chooses for itself. These tests pin both directions: honour
 * the header from an edge, ignore it from anyone else.
 */
class TrustedProxyTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->for(Account::factory())->create();
    }

    protected function trackFrom(string $peer, ?string $forwardedFor = null): void
    {
        $payload = $this->payload($this->project);

        $server = [
            'REMOTE_ADDR' => $peer,
            'HTTP_USER_AGENT' => 'DigiTracker/'.md5($payload['url']).';',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        $this->call('POST', '/track', $payload, server: $server)->assertOk();
    }

    protected function recordedIp(): ?string
    {
        return Site::acrossAccounts()->latest('id')->first()?->ip;
    }

    /**
     * 162.158.0.0/15 is a Cloudflare range, so the address it forwards is
     * the site that actually sent the heartbeat.
     */
    public function test_the_real_address_is_recovered_from_behind_cloudflare(): void
    {
        $this->trackFrom(peer: '162.158.4.9', forwardedFor: '203.0.113.77');

        $this->assertSame('203.0.113.77', $this->recordedIp());
    }

    /**
     * The one that matters. The origin answers on its bare IP, so a sender
     * who skips the proxy can put anything in this header. Believing it
     * would hand every forger a fresh throttle bucket and a country of
     * their choosing.
     */
    public function test_a_forwarded_header_from_an_untrusted_peer_is_ignored(): void
    {
        $this->trackFrom(peer: '198.51.100.20', forwardedFor: '203.0.113.77');

        $this->assertSame('198.51.100.20', $this->recordedIp());
    }

    /** A chain records the client, not the intermediate hops. */
    public function test_the_client_is_taken_from_the_head_of_a_forwarded_chain(): void
    {
        $this->trackFrom(peer: '172.64.9.9', forwardedFor: '203.0.113.77, 162.158.4.9');

        $this->assertSame('203.0.113.77', $this->recordedIp());
    }

    /** Nothing forwarded, nothing invented: the peer is the client. */
    public function test_a_direct_request_records_its_own_peer(): void
    {
        $this->trackFrom(peer: '198.51.100.20');

        $this->assertSame('198.51.100.20', $this->recordedIp());
    }

    /**
     * The throttle keys on the resolved address. Were it keyed on the edge,
     * the whole fleet would share one bucket -- which is the failure this
     * config exists to prevent, so it is worth asserting rather than
     * assuming it follows.
     */
    public function test_two_sites_behind_one_edge_get_separate_throttle_buckets(): void
    {
        $edge = '162.158.4.9';

        for ($i = 0; $i < 40; $i++) {
            $this->trackFrom(peer: $edge, forwardedFor: '203.0.113.10');
        }

        // A different site, same edge. If the buckets were shared this
        // request would already be inside a spent allowance.
        $this->trackFrom(peer: $edge, forwardedFor: '203.0.113.11');

        $this->assertSame('203.0.113.11', $this->recordedIp());
    }
}
