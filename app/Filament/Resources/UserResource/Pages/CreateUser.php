<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Api\IwacsRoClient;
use App\Services\Api\IwacsRoException;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->record;
        $record->syncRoles(['ro']);

        try {
            $result = app(IwacsRoClient::class)->createUser([
                'name' => (string) $record->name,
                'email' => (string) $record->email,
                'password' => (string) ($this->data['password'] ?? ''),
                'role' => 'ro',
            ], 'ro-user-'.(string) $record->getKey().'-'.Str::uuid());
            $record->update([
                'sso_subject' => (string) $result['sso_subject'],
                'profile_version' => (int) ($result['profile_version'] ?? 1),
            ]);
        } catch (Throwable $exception) {
            $record->forceDelete();
            report($exception);
            $message = $exception instanceof IwacsRoException
                ? $exception->getMessage()
                : 'Akun RO gagal disinkronkan ke IWACS. Tidak ada akun baru yang disimpan.';
            Notification::make()->title('Pembuatan akun dibatalkan')->body($message)->danger()->persistent()->send();

            throw ValidationException::withMessages(['email' => $message]);
        }

        Notification::make()->title('Akun RO berhasil dibuat')->body('Akun yang sama telah dibuat di RO, IWACS, dan Keycloak.')->success()->send();
    }
    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}

}
