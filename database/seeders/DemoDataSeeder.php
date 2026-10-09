<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Services\DemoDataService;
use RuntimeException;
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) throw new RuntimeException('DemoDataSeeder is for local/demo environments.');
        $this->call([PlatformRolesSeeder::class, CandidateMasterDataSeeder::class]);
        app(DemoDataService::class)->generate();
        $this->command?->info('Demo data added. Credentials: Admin > Data management > Login credentials (CSV download).');
    }
}
