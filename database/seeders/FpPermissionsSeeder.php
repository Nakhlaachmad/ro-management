<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class FpPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (\App\Application\FP\FeatureAccess::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
