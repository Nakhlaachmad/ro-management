<?php

declare(strict_types=1);

namespace App\Models;

final class VictoryUser extends User
{
    protected $table = 'users';

    public function getMorphClass()
    {
        return User::class;
    }

    public function getCasts(): array
    {
        return parent::getCasts() + ['fp_app_managed' => 'boolean'];
    }
}
