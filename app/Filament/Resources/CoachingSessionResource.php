<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CoachingSessionResource\Pages;
use App\Models\CoachingSession;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CoachingSessionResource extends Resource
{
    protected static ?string $model = CoachingSession::class;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getNavigationLabel(): string
    {
        return 'Coaching Sessions';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Exam Management';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Session Details')
                    ->schema([
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('attempt_id')
                            ->relationship('attempt', 'id')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'in_progress' => 'In Progress',
                                'completed' => 'Completed',
                                'expired' => 'Expired',
                                'paused' => 'Paused',
                            ])
                            ->required(),
                        Select::make('current_step')
                            ->options([
                                'initial_reasoning' => 'Initial Reasoning',
                                'guideline_reveal' => 'Guideline Reveal',
                                'corrected_reasoning' => 'Corrected Reasoning',
                                'follow_up' => 'Follow Up',
                                'complete' => 'Complete',
                            ]),
                        TextInput::make('questions_reviewed')
                            ->numeric()
                            ->default(0),
                        DateTimePicker::make('started_at'),
                        DateTimePicker::make('ended_at'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attempt_id')
                    ->label('Exam ID')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        'expired' => 'danger',
                        'paused' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('current_step')
                    ->label('Current Step')
                    ->badge()
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'initial_reasoning' => 'Initial Reasoning',
                        'guideline_reveal' => 'Guideline Reveal',
                        'corrected_reasoning' => 'Corrected Reasoning',
                        'follow_up' => 'Follow Up',
                        'complete' => 'Complete',
                        default => '-',
                    }),
                TextColumn::make('questions_reviewed')
                    ->label('Questions Reviewed')
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label('Ended')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'expired' => 'Expired',
                        'paused' => 'Paused',
                    ]),
                SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoachingSessions::route('/'),
            'view' => Pages\ViewCoachingSession::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereIn('status', ['in_progress', 'paused'])->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
