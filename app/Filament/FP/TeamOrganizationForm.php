<?php

declare(strict_types=1);

namespace App\Filament\FP;

use App\Application\FP\FeatureAccess;
use App\Models\FpMembership;
use App\Models\FpTeam;
use Filament\Forms\Components as C;
use Filament\Forms\Get;
use Filament\Forms\Set;

final class TeamOrganizationForm
{
    public static function activeFps(string $search = ''): array
    {
        return FpMembership::where('lifecycle_state', 'ACTIVE')->when($search !== '', fn ($q) => $q->whereLike('full_name', '%'.$search.'%'))->orderBy('full_name')->orderBy('id')->limit(100)->get(['id', 'full_name', 'nip'])->mapWithKeys(fn ($fp) => [$fp->id => $fp->full_name.' — '.($fp->nip ?? 'FP #'.$fp->id)])->all();
    }

    public static function createFields(): array
    {
        return [C\TextInput::make('display_name')->label('Nama Team')->required()->maxLength(255),
            C\Select::make('mm_fp_id')->label('Nama MM')->required()->searchable()->options(fn () => self::activeFps())->getSearchResultsUsing(fn (string $search) => self::activeFps($search))
                ->getOptionLabelUsing(fn ($id) => ($fp = FpMembership::where('lifecycle_state', 'ACTIVE')->find($id)) ? $fp->full_name.' — '.($fp->nip ?? 'FP #'.$fp->id) : null),
            C\Placeholder::make('active_notice')->label('Status setelah disimpan')->content('AKTIF — jabatan MM tercatat tanpa memindahkan keanggotaan FP.')];
    }

    public static function leaderOptions(int $team): array
    {
        return FpMembership::where('lifecycle_state', 'ACTIVE')->whereNotIn('id', \Illuminate\Support\Facades\DB::table('fp_team_offices')->select('membership_id')->where('office', 'LEADER')->whereNull('ends_at')->where('team_id', '<>', $team))
            ->orderBy('full_name')->orderBy('id')->limit(200)->get(['id', 'full_name', 'nip'])->mapWithKeys(fn ($fp) => [$fp->id => $fp->full_name.' — '.($fp->nip ?? 'FP #'.$fp->id)])->all();
    }

    public static function dissolutionFields(): array
    {
        return [C\Wizard::make([
            C\Wizard\Step::make('Tujuan')->schema([
                C\Repeater::make('new_teams')->label('Buat Team tujuan baru (opsional)')->addActionLabel('Tambah Team tujuan baru')->live()->defaultItems(0)->schema([
                    C\TextInput::make('key')->label('Kode tujuan sementara')->required()->maxLength(50)->helperText('Gunakan kode unik untuk pilihan tujuan di bawah.'), ...self::createFields(),
                ]),
                C\Select::make('bulk_destination')->label('Tujuan anggota terpilih')->options(fn (Get $get) => self::destinations($get('new_teams') ?? []))->searchable(),
                C\Actions::make([
                    C\Actions\Action::make('selectMembers')->label('Pilih Semua')->action(fn (Get $get, Set $set) => self::select($get, $set, true)),
                    C\Actions\Action::make('clearMembers')->label('Batalkan Pilihan')->action(fn (Get $get, Set $set) => self::select($get, $set, false)),
                    C\Actions\Action::make('applyDestination')->label('Terapkan ke anggota terpilih')->action(function (Get $get, Set $set): void {
                        FeatureAccess::require(auth()->user(), 'f2.teams');
                        $moves = $get('moves') ?? [];
                        foreach ($moves as &$move) {
                            if ($move['selected'] ?? false) {
                                $move['destination'] = $get('bulk_destination');
                            }
                        }
                        $set('moves', $moves);
                    }),
                ]),
                C\Repeater::make('moves')->label('Seluruh anggota ACTIVE wajib memiliki tujuan')->addable(false)->deletable(false)->reorderable(false)->schema([
                    C\Hidden::make('membership_id')->required(), C\Checkbox::make('selected')->label('Pilih anggota'),
                    C\Placeholder::make('member')->label('Financial Planner')->content(fn (Get $get) => FpMembership::find($get('membership_id'))?->full_name ?? 'Tidak tersedia'),
                    C\Select::make('destination')->label('Team tujuan')->required()->searchable()->options(fn (Get $get) => self::destinations($get('../../new_teams') ?? [])),
                ]),
            ]),
            C\Wizard\Step::make('Preview')->schema([C\Placeholder::make('preview')->label('Rencana perpindahan')->content(fn (Get $get) => collect($get('moves') ?? [])->map(fn ($row) => 'FP #'.($row['membership_id'] ?? '?').' → '.(self::destinations($get('new_teams') ?? [])[$row['destination'] ?? ''] ?? 'Tujuan belum dipilih'))->join(' | '))]),
            C\Wizard\Step::make('Konfirmasi')->schema([C\Checkbox::make('confirmed')->label('Saya mengonfirmasi seluruh anggota dipindahkan dan Team asal dinonaktifkan.')->accepted()->required(), C\Placeholder::make('history')->content('Transaksi atomik, NIP dan snapshot asal tetap utuh. Jabatan Team asal diakhiri pada waktu eksekusi.')]),
        ])->skippable(false)];
    }

    public static function destinations(array $newTeams): array
    {
        $values = FpTeam::where('is_active', true)->orderBy('display_name')->pluck('display_name', 'team_id')->mapWithKeys(fn ($name, $id) => ['team:'.$id => $name])->all();
        foreach ($newTeams as $team) {
            if (! empty($team['key']) && ! empty($team['display_name'])) {
                $values['new:'.$team['key']] = 'Baru: '.$team['display_name'];
            }
        }

        return $values;
    }

    private static function select(Get $get, Set $set, bool $selected): void
    {
        FeatureAccess::require(auth()->user(), 'f2.teams');
        $moves = $get('moves') ?? [];
        foreach ($moves as &$move) {
            $move['selected'] = $selected;
        }
        $set('moves', $moves);
    }

    public static function moves(array $data): array
    {
        return array_map(function ($row): array {
            $destination = (string) ($row['destination'] ?? '');
            $result = ['membership_id' => $row['membership_id']];
            if (preg_match('/\Ateam:([1-9][0-9]*)\z/', $destination, $match)) {
                $result['destination_team_id'] = (int) $match[1];
            } elseif (str_starts_with($destination, 'new:')) {
                $result['new_team_key'] = substr($destination, 4);
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages(['moves' => 'Setiap anggota wajib memiliki tujuan valid.']);
            }

            return $result;
        }, array_values($data['moves'] ?? []));
    }
}
