<?php

namespace Tests\Feature\Ingest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * All four plugins are free and updated through wordpress.org, so the
 * licensing and updater modules are out of scope. The namespaces are still
 * claimed: an SDK configured with licensing enabled would otherwise get
 * our HTML 404 page, which tells whoever is debugging it nothing.
 */
class ReservedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_licensing_answers_not_implemented(): void
    {
        $this->post('/public/license/'.fake()->uuid().'/check')
            ->assertStatus(501)
            ->assertJson(['success' => false]);
    }

    public function test_updates_answer_not_implemented(): void
    {
        $this->post('/v2/update/'.fake()->uuid().'/check')
            ->assertStatus(501)
            ->assertJson(['success' => false]);
    }
}
