<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Always seed the admin. The demo workspace is synthetic PUBLIC data,
        // so it only seeds when demo is explicitly enabled — a private fork
        // (TOWER_DEMO_ENABLED=false) never gets synthetic public rows.
        // Run it directly with `--class=DemoWorkspaceSeeder` when needed.
        $this->call(AdminUserSeeder::class);

        if ((bool) config('tower.demo.enabled', false)) {
            $this->call(DemoWorkspaceSeeder::class);
        }
    }
}
