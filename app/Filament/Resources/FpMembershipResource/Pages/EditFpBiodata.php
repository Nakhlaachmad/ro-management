<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

use App\Application\FP\AmendFpBiodata;
use App\Filament\Resources\FpMembershipResource;
use App\Models\FpMembership;
use Filament\Forms\Form;

final class EditFpBiodata extends \Filament\Resources\Pages\Page
{
    protected static string $resource = FpMembershipResource::class;

    protected static string $view = 'filament.fp.edit-biodata';

    protected static ?string $title = 'Edit Biodata Financial Planner';

    public int $membershipId;

    public array $data = [];

    public function mount(int $record): void
    {
        $member = FpMembership::findOrFail($record);
        abort_unless(FpMembershipResource::canEdit($member), 403);
        $this->membershipId = $record;
        $data = [];
        foreach (AmendFpBiodata::FIELDS as $field) {
            $data[$field] = $field === 'bank_account' ? $member->bank_account_encrypted : ($field === 'birth_date' ? $member->birth_date?->format('Y-m-d') : $member->$field);
        }
        $this->form->fill($data + ['expected_revision' => AmendFpBiodata::revision($record)]);
    }

    public function form(Form $form): Form
    {
        $fields = array_values(array_filter(\App\Filament\FP\FpForms::biodata(), fn ($field) => in_array($field->getName(), AmendFpBiodata::FIELDS, true)));

        return $form->schema([\Filament\Forms\Components\Hidden::make('expected_revision')->required(), ...$fields])->columns(2)->statePath('data');
    }

    public function submit(): void
    {
        $member = FpMembership::findOrFail($this->membershipId);
        abort_unless(FpMembershipResource::canEdit($member), 403);
        try {
            app(AmendFpBiodata::class)->execute(auth()->user(), $this->membershipId, $this->form->getState());
        } catch (\Illuminate\Validation\ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }
            \Filament\Notifications\Notification::make()->danger()->title('Perubahan ditolak. Periksa data atau muat ulang revisi terbaru.')->send();
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        \Filament\Notifications\Notification::make()->success()->title('Biodata diperbarui; riwayat asli tetap utuh')->send();
        $this->redirect(FpMembershipResource::getUrl('view', ['record' => $this->membershipId], panel: 'fp-dev'));
    }
}
