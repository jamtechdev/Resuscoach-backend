<?php

namespace App\Filament\Widgets;

use App\Models\ExamAttempt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LatestExamAttempts extends \Filament\Widgets\TableWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Latest Exam Attempts';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ExamAttempt::query()
                    ->with('user')
                    ->latest('started_at')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('score')
                    ->label('Score')
                    ->suffix('%')
                    ->color(fn(?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 70 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('correct_count')
                    ->label('Correct')
                    ->formatStateUsing(fn(ExamAttempt $record): string =>
                    "{$record->correct_count}/{$record->total_questions}"),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
