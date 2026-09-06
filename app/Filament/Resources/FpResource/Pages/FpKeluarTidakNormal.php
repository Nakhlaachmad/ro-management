<?php

namespace App\Filament\Resources\FpResource\Pages;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Filament\Forms;
use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class FpKeluarTidakNormal extends ListRecords
{
    protected static string $resource = FpResource::class;
    protected static ?string $navigationLabel = 'FP Keluar Tidak Normal';
    protected static ?int $navigationSort = 4;
    protected static ?string $title = 'FP Keluar Tidak Normal';

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('fp.keluar.tidak.normal.view'), 403);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->keluarTidakNormal();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label('Nama FP')
                ->searchable(),

            Tables\Columns\TextColumn::make('team.name')
                ->label('Team'),

            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->color(fn ($state) => $state === 'nonaktif' ? 'warning' : 'success'),

            Tables\Columns\TextColumn::make('tanggal_keluar')
                ->date(),

            Tables\Columns\TextColumn::make('comment')
                ->wrap(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->recordUrl(null)
            ->defaultSort('id', 'desc')
            ->columns([
            Tables\Columns\TextColumn::make('name')
                ->label('Nama FP')
                ->searchable()
                ->action(
                    Tables\Actions\Action::make('view')
                        ->modalHeading(fn ($record) => 'Detail FP - ' . $record->name)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->fillForm(function ($record) {

                            $team = $record->team
                                ? $record->team->name . ' > ' . $record->team->team
                                : '-';

                            if (!$record->tanggal_keluar) {
                                $status = 'FP Aktif';
                            } else {
                                $batas = \Carbon\Carbon::parse($record->tanggal_masuk)->addMonths(3);

                                $status = $record->tanggal_keluar >= $batas
                                    ? 'FP Keluar Normal'
                                    : 'FP Keluar Tidak Normal';
                            }

                            return [
                                'name' => $record->name,
                                'team' => $team,
                                'status' => $status,
                                'no_tlfn' => $record->no_tlfn,
                                'grade' => $record->grade,
                                'jalur' => $record->jalur,
                                'tanggal_masuk' => $record->tanggal_masuk,
                                'tanggal_keluar' => $record->tanggal_keluar,
                                'no_rekening' => $record->no_rekening,
                                'no_ktp' => $record->no_ktp,
                                'alamat_ktp' => $record->alamat_ktp,
                                'alamat' => $record->alamat,
                                'tempat_lahir' => $record->tempat_lahir,
                                'tanggal_lahir' => $record->tanggal_lahir,
                                'lulusan' => $record->lulusan,
                                'comment' => $record->comment,
                            ];
                        })
                        ->form([
                            Forms\Components\Grid::make(3) // 2 kolom utama
                                ->schema([

                                    Forms\Components\TextInput::make('name')
                                        ->columnSpan(3)
                                        ->disabled(),

                                    // ====== BAGIAN DATA RINGKAS ======
                                    Forms\Components\TextInput::make('team')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('jalur')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('grade')
                                        ->disabled(),

                                    Forms\Components\DatePicker::make('tanggal_masuk')
                                        ->disabled(),

                                    Forms\Components\DatePicker::make('tanggal_keluar')
                                        ->disabled(),
                                    
                                    Forms\Components\TextInput::make('status')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('no_tlfn')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('no_rekening')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('no_ktp')
                                        ->disabled(),

                                    Forms\Components\Textarea::make('alamat_ktp')
                                        ->columnSpan(3)
                                        ->rows(2)
                                        ->disabled(),

                                    Forms\Components\Textarea::make('alamat')
                                        ->columnSpan(3)
                                        ->rows(2)
                                        ->disabled(),

                                    Forms\Components\TextInput::make('tempat_lahir')
                                        ->disabled(),

                                    Forms\Components\DatePicker::make('tanggal_lahir')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('lulusan')
                                        ->disabled(),

                                    Forms\Components\Textarea::make('comment')
                                        ->columnSpan(3)
                                        ->rows(2)
                                        ->disabled(),
                                ])
                        ])
                ),


                Tables\Columns\TextColumn::make('team')
                    ->label('Team')
                    ->getStateUsing(function ($record) {
                        return $record->team
                            ? $record->team->name . ' > ' . $record->team->team
                            : '-';
                    })
                    ->sortable(query: function ($query, $direction) {
                        $query->orderBy('team_id', $direction);
                    }),

                Tables\Columns\TextColumn::make('grade')
                    ->sortable(),

                Tables\Columns\TextColumn::make('jalur')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'nonaktif',
                    ]),

                Tables\Columns\TextColumn::make('tanggal_keluar')
                    ->label('Tanggal Keluar')
                    ->date(),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Comment')
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('grade')
                    ->options([
                        'A' => 'A',
                        'B' => 'B',
                    ]),

                Tables\Filters\SelectFilter::make('jalur')
                    ->options([
                        'RO' => 'RO',
                        'MGM' => 'MGM',
                    ]),

                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Team')
                    ->relationship('team', 'team'),
            ])
            ->actions([

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make(),

            ])
            ->actionsAlignment('start');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\FpKeluarTidakNormalStats::class,
        ];
    }
}
