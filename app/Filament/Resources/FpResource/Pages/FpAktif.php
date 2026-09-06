<?php

namespace App\Filament\Resources\FpResource\Pages;
use App\Filament\Widgets\FpAktifStatsWidget;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class FpAktif extends ListRecords
{
    protected static string $resource = FpResource::class;
    protected static ?string $navigationLabel = 'FP Aktif';
    protected static ?int $navigationSort = 2;

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('fp.aktif.view'), 403);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->where('status', 'aktif');
    }

    protected static ?string $title = 'FP Aktif';
    protected function getHeaderWidgets(): array
    {
        return [
            FpAktifStatsWidget::class,
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
                                $batas = \Carbon\Carbon::parse($record->tanggal_masuk)->addMonths(2);

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
                                // 'comment' => $record->comment,
                            ];
                        })
                        ->form([
                            Forms\Components\Grid::make(3)
                                ->schema([

                                    Forms\Components\TextInput::make('name')
                                        ->columnSpan(3)
                                        ->disabled(),
                                        
                                    Forms\Components\TextInput::make('team')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('jalur')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('grade')
                                        ->disabled(),

                                    Forms\Components\DatePicker::make('tanggal_masuk')
                                        ->disabled(),

                                    Forms\Components\TextInput::make('tanggal_keluar')
                                        ->label('Tanggal Keluar')
                                        ->disabled()
                                        ->formatStateUsing(function ($state) {
                                            return $state
                                                ? \Carbon\Carbon::parse($state)->format('d/m/Y')
                                                : '-';
                                        }),
                                    
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
                                        ->disabled()
                                        ->formatStateUsing(fn ($state) => filled($state) ? $state : '-'),
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

                Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->color(fn ($state) => $state === 'aktif' ? 'success' : 'gray')
                ->action(
                    Tables\Actions\Action::make('nonaktifkan')
                        ->label('Nonaktifkan FP')
                        ->visible(fn ($record) => $record->status === 'aktif')
                        ->authorize('fp.comment')
                        ->modalHeading('Nonaktifkan FP')
                        ->modalSubmitActionLabel('Submit')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\TextInput::make('nama_fp')
                                ->label('Nama FP')
                                ->default(fn ($record) => $record->name)
                                ->disabled(),

                            Forms\Components\TextInput::make('team')
                                ->label('Team')
                                ->default(fn ($record) =>
                                    $record->team
                                        ? $record->team->name . ' > ' . $record->team->team
                                        : '-'
                                )
                                ->disabled(),

                            Forms\Components\DatePicker::make('tanggal_keluar')
                                ->required()
                                ->rule(function ($record) {
                                    return function ($attribute, $value, $fail) use ($record) {
                                        if ($value < $record->tanggal_masuk) {
                                            $fail('Tanggal keluar tidak boleh sebelum tanggal masuk.');
                                        }
                                    };
                                }),

                            Forms\Components\Textarea::make('comment')
                                ->label('Comment')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function ($record, array $data) {
                            $record->update([
                                'status' => 'nonaktif',
                                'tanggal_keluar' => $data['tanggal_keluar'],
                                'comment' => $data['comment'],
                            ]);
                        })
                    ),

                Tables\Columns\TextColumn::make('tanggal_masuk')
                    ->date()
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
            ]);
            
    }
}
