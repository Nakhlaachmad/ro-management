<?php

declare(strict_types=1);

namespace App\Filament\Resources\VictoryUserResource\Pages;

final class ListVictoryUsers extends \Filament\Resources\Pages\ListRecords
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\VictoryUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('createLocal')->label('Tambah User HRD')->form([
                ...\App\Filament\Resources\VictoryUserResource::fields(),
                \Filament\Forms\Components\Select::make('role')->label('Role Pengguna')->options(fn () => \App\Application\FP\VictoryUsers::creationRoles(auth()->user()))->default('hrd')->required()->in(fn () => array_keys(\App\Application\FP\VictoryUsers::creationRoles(auth()->user())))->helperText('Role harus dipilih secara eksplisit. Hanya Admin dapat membuat Admin.'),
                \Filament\Forms\Components\TextInput::make('password')->password()->required()->minLength(12)->maxLength(128)->autocomplete('new-password'),
                \Filament\Forms\Components\TextInput::make('password_confirmation')->password()->required()->same('password'),
            ])->requiresConfirmation()->visible(fn () => self::$resource::canCreate())->authorize(fn () => self::$resource::canCreate())->action(function (array $data, \Filament\Forms\Form $form) {
                try {
                    app(\App\Application\FP\VictoryUsers::class)->command(auth()->user(), 'CREATE', null, $data);
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $field = in_array($field, ['name', 'email', 'password', 'password_confirmation', 'role'], true) ? $field : 'name';
                        $errors[$form->getStatePath().'.'.$field] = $messages;
                    }
                    throw \Illuminate\Validation\ValidationException::withMessages($errors);
                }
                // Clear stale filters/pagination so a successful create is immediately visible.
                $this->removeTableFilters();
                $this->tableSearch = \Illuminate\Support\Str::lower($data['email']);
                $this->updatedTableSearch();
                \Filament\Notifications\Notification::make()->title('User berhasil dibuat')->success()->send();
            }),
        ];
    }
}
