<?php

namespace App\Filament\Widgets;

use App\Models\CoachingSession;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalQuestions = Question::count();
        $activeQuestions = Question::where('is_active', true)->count();
        $totalUsers = User::where('is_admin', false)->count();
        $totalExams = ExamAttempt::count();
        $completedExams = ExamAttempt::where('status', 'completed')->count();
        $avgScore = ExamAttempt::where('status', 'completed')->avg('score');
        $totalCoachingSessions = CoachingSession::count();
        $completedCoachingSessions = CoachingSession::where('status', 'completed')->count();

        return [
            Stat::make('Total Questions', $totalQuestions)
                ->description($activeQuestions . ' active')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($totalQuestions >= 250 ? 'success' : 'warning')
                ->chart([7, 3, 4, 5, 6, 3, 5]),

            Stat::make('Registered Users', $totalUsers)
                ->description('Non-admin users')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Total Exams', $totalExams)
                ->description($completedExams . ' completed')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('success'),

            Stat::make('Average Score', $avgScore ? number_format($avgScore, 1) . '%' : 'N/A')
                ->description('Across all completed exams')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($avgScore >= 70 ? 'success' : ($avgScore >= 50 ? 'warning' : 'danger')),

            Stat::make('Coaching Sessions', $totalCoachingSessions)
                ->description($completedCoachingSessions . ' completed')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('info'),
        ];
    }
}
