<?php

namespace Tests;

use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CompanyContext memoizes the active entity per user id in a static array.
        // RefreshDatabase resets auto-increment ids each test, so without flushing,
        // a prior test's active entity leaks into the next test that reuses user id 1.
        CompanyContext::flushMemo();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }
}
