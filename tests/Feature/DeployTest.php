<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'heimdeye.runner.nightly_at' => '02:00']);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_can_deploy_with_an_empty_queue_during_the_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:00'));

        $this->artisan('heimdeye:can-deploy')->assertExitCode(0);
    }

    public function test_cannot_deploy_with_jobs_in_the_queue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:00'));
        DB::table('jobs')->insert(['queue' => 'checks', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

        $this->artisan('heimdeye:can-deploy')->assertExitCode(1);
    }

    public function test_cannot_deploy_just_before_the_nightly_run(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 01:50'));

        $this->artisan('heimdeye:can-deploy')->assertExitCode(1);
    }

    public function test_a_trusted_proxy_can_say_the_request_was_https(): void
    {
        config(['heimdeye.trusted_proxies' => '192.168.20.7']);
        (new AppServiceProvider($this->app))->boot();
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'];

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.20.7'])->get('/', $headers)
            ->assertRedirect('https://localhost/admin');
    }

    public function test_an_untrusted_client_cannot_say_the_request_was_https(): void
    {
        config(['heimdeye.trusted_proxies' => '192.168.20.7']);
        (new AppServiceProvider($this->app))->boot();
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'];

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.20.99'])->get('/', $headers)
            ->assertRedirect('http://localhost/admin');
    }
}
