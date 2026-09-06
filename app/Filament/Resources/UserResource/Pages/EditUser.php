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
                $client->updatePassword((string) $record->sso_subject, $expectedVersion, $password, (string) $record->email);
            }
        } catch (IwacsRoException $exception) {
            throw ValidationException::withMessages(['data.email' => $exception->getMessage()]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}

}
