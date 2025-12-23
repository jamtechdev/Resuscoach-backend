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

    protected static ?string $description = 'Recent exam attempts by users';

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
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-user')
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'expired' => 'Expired',
                        default => ucfirst($state),
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        'expired' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn(string $state): string => match ($state) {
                        'in_progress' => 'heroicon-o-clock',
                        'completed' => 'heroicon-o-check-circle',
                        'expired' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    }),
                TextColumn::make('score')
                    ->label('Score')
                    ->suffix('%')
                    ->sortable()
                    ->color(fn(?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 70 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->weight('bold')
                    ->icon(fn(?float $state): string => match (true) {
                        $state === null => 'heroicon-o-minus',
                        $state >= 70 => 'heroicon-o-trophy',
                        $state >= 50 => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-x-circle',
                    }),
                TextColumn::make('correct_count')
                    ->label('Correct Answers')
                    ->formatStateUsing(fn(ExamAttempt $record): string =>
                    "{$record->correct_count} / {$record->total_questions}")
                    ->sortable()
                    ->icon('heroicon-o-check-badge'),
                TextColumn::make('started_at')
                    ->label('Started At')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->icon('heroicon-o-clock')
                    ->since(),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No exam attempts yet')
            ->emptyStateDescription('Exam attempts will appear here once users start taking exams.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
