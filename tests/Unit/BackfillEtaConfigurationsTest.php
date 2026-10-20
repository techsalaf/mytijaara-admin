<?php

namespace Tests\Unit;

use App\Models\EtaConfiguration;
use App\Models\Zone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BackfillEtaConfigurationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_dry_run_writes_nothing(): void
    {
        $before = EtaConfiguration::count();

        $this->artisan('eta:backfill')->assertSuccessful();

        $this->assertSame($before, EtaConfiguration::count());
    }

    public function test_running_it_twice_creates_nothing_the_second_time(): void
    {
        $this->artisan('eta:backfill --apply')->assertSuccessful();
        $afterFirst = EtaConfiguration::count();

        $this->artisan('eta:backfill --apply')->assertSuccessful();
        $afterSecond = EtaConfiguration::count();

        $this->assertSame($afterFirst, $afterSecond);
    }
}
