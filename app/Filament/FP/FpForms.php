<?php

declare(strict_types=1);

namespace App\Filament\FP;

use App\Models\FpTeam;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class FpForms
{
    public static function biodata(): array
    {
        $fields = [];
        foreach (['full_name' => 'Nama', 'phone' => 'Telepon', 'email' => 'Email', 'bank_account' => 'Rekening BCA', 'nik' => 'NIK', 'birth_place' => 'Tempat Lahir', 'education_level' => 'Pendidikan', 'education_institution' => 'Institusi', 'graduation_year' => 'Tahun Lulus'] as $key => $label) {
            $input = TextInput::make($key)->label($label)->required()->maxLength(255);
            if ($key === 'email' && ! config('fp.annual_nip_email_enabled')) {
                continue;
            }
            if ($key === 'email') {
                $input->email()->maxLength(254)->required(false)->extraInputAttributes(['x-on:input' => '$event.target.value = $event.target.value.toLowerCase()'])->dehydrateStateUsing(fn ($state) => is_string($state) ? \Illuminate\Support\Str::lower($state) : $state);
            }
            if ($key === 'bank_account' && \App\Application\FP\OnboardingRevision::enabled()) {
                $input->required(false)->maxLength(10)->regex('/\A[0-9]{6,10}\z/')->helperText('Opsional; dapat dilengkapi kemudian.');
            }
            $fields[] = $input;
        }

        return [...$fields, DatePicker::make('birth_date')->native(false)->locale('id')->displayFormat('d/m/Y')->format('Y-m-d')->label('Tanggal Lahir')->required(), Textarea::make('address_ktp')->label('Alamat KTP')->required(), Textarea::make('address_current')->label('Alamat Tinggal')->required(), Select::make('team_id')->label('Team')->options(fn () => FpTeam::where('is_active', true)->limit(200)->pluck('display_name', 'team_id'))->searchable()->required()];
    }

    public static function source(): array
    {
        return ApprovalSourceForm::fields();
    }
}
