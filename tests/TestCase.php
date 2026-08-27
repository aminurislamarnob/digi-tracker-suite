<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may talk to the internet.
     *
     * This is not tidiness. Half of this application reads wordpress.org,
     * and a test that reaches the real API passes for the wrong reason: it
     * asserts against whatever a public server happened to return, so it
     * goes green on a value nobody wrote down, then fails months later when
     * a stranger's plugin gets a download. It also makes the suite slow,
     * offline-hostile, and quietly rude to an API we are a guest on.
     *
     * preventStrayRequests turns that from a silent pass into a loud error
     * naming the URL, so the only way to exercise a network path is to
     * state what the response is.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
