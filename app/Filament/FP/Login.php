<?php

declare(strict_types=1);

namespace App\Filament\FP;

final class Login extends \Filament\Pages\Auth\Login
{
    protected function getEmailFormComponent(): \Filament\Forms\Components\Component
    {
        return parent::getEmailFormComponent()->rules(['lowercase'])
            ->validationMessages(['lowercase' => 'Gunakan email dengan huruf kecil untuk login lokal.']);
    }
}
