<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Api\IwacsRoClient;
use App\Services\Api\IwacsRoException;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            return parent::handleRecordUpdate($record, $data);
        }

        $client = app(IwacsRoClient::class);
        $sourceEmail = (string) $record->email;
        $createdFromLegacy = false;
        if (blank($record->sso_subject)) {
            try {
                $mapping = $client->lookupByEmail($sourceEmail);
                $record->sso_subject = (string) $mapping['sso_subject'];
                $record->profile_version = (int) ($mapping['profile_version'] ?? 1);
                $record->save();
            } catch (IwacsRoException $exception) {
                $bootstrapPassword = (string) ($data['password'] ?? '');
                if ($bootstrapPassword === '') {
                    throw ValidationException::withMessages([
                        'data.password' => 'Akun RO lama belum terhubung ke IWACS. Masukkan password baru untuk membuat identitas IWACS dan Keycloak sebelum menyimpan perubahan.',
                    ]);
                }
                try {
                    $mapping = $client->createUser([
                        'name' => (string) ($data['name'] ?? $record->name),
                        'email' => (string) ($data['email'] ?? $record->email),
                        'password' => $bootstrapPassword,
                        'role' => 'ro',
                    ], 'ro-legacy-link-'.Str::uuid());
                    $record->sso_subject = (string) $mapping['sso_subject'];
                    $record->profile_version = (int) ($mapping['profile_version'] ?? 1);
                    $record->save();
                    $sourceEmail = (string) ($data['email'] ?? $record->email);
                    $createdFromLegacy = true;
                } catch (IwacsRoException $creationException) {
                    throw ValidationException::withMessages(['data.email' => $creationException->getMessage()]);
                }
            }
        }

        $changes = [];
        if (! $createdFromLegacy) {
            foreach (['name', 'email'] as $field) {
                if (array_key_exists($field, $data) && (string) $record->getAttribute($field) !== (string) $data[$field]) {
                    $changes[$field] = $data[$field];
                }
            }
        }
        $password = (string) ($data['password'] ?? '');
        $expectedVersion = (int) ($record->profile_version ?? 1);
        $record->update($data);

        try {
            if ($changes !== []) {
                $result = $client->updateUser((string) $record->sso_subject, $expectedVersion, $changes, $sourceEmail);
                $expectedVersion = (int) ($result['profile_version'] ?? $expectedVersion);
                $record->update(['profile_version' => $expectedVersion]);
            }
            if ($password !== '' && ! $createdFromLegacy) {
                $result = $client->updatePassword((string) $record->sso_subject, $expectedVersion, $password, (string) $record->email);
                $expectedVersion = (int) ($result['profile_version'] ?? $expectedVersion);
                $record->update(['profile_version' => $expectedVersion]);
            }
        } catch (IwacsRoException $exception) {
            throw ValidationException::withMessages(['data.email' => $exception->getMessage()]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, User $record): void {
                    if (blank($record->sso_subject)) {
                        \Filament\Notifications\Notification::make()
                            ->title('Penghapusan dibatalkan')
                            ->body('Akun RO belum memiliki sso_subject dan tidak dapat dicocokkan secara aman dengan IWACS.')
                            ->danger()
                            ->persistent()
                            ->send();
                        $action->halt();

                        return;
                    }

                    try {
                        app(IwacsRoClient::class)->deleteUser(
                            (string) $record->sso_subject,
                            (int) ($record->profile_version ?? 1),
                            (string) $record->email,
                        );
                    } catch (IwacsRoException $exception) {
                        \Filament\Notifications\Notification::make()
                            ->title('Penghapusan dibatalkan')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                        $action->halt();
                    }
                })
                ->successNotificationTitle('Akun RO dihapus permanen dari RO dan IWACS'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
