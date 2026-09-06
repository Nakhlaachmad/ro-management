<?php

namespace Tests\Unit;

use App\Models\User;
use Filament\Panel;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    public function test_all_official_ro_roles_can_enter_the_panel(): void
    {
        $user = new class extends User {
            /** @var array<int, array<int, string>> */
            public array $requestedRoles = [];

            public function hasAnyRole(...$roles): bool
            {
                $this->requestedRoles = $roles;

                return true;
            }
        };
        $user->is_active = true;

        $this->assertTrue($user->canAccessPanel(Panel::make()));
        $this->assertSame([['admin', 'hrd', 'bm', 'ro']], $user->requestedRoles);
    }
}
