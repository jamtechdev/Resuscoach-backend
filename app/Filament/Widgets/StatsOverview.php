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

    // Auto-refresh widget every 10 seconds
    protected ?string $pollingInterval = '10s';

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
            Stat::make('Total Questions', number_format($totalQuestions))
                ->description($activeQuestions . ' active questions')
                ->descriptionIcon('heroicon-o-academic-cap')
                ->icon('heroicon-o-book-open')
                ->color($totalQuestions >= 250 ? 'success' : ($totalQuestions >= 100 ? 'info' : 'warning'))
                ->chart($this->getQuestionChartData()),

            Stat::make('Registered Users', number_format($totalUsers))
                ->description('Active learners')
                ->descriptionIcon('heroicon-o-users')
                ->icon('heroicon-o-user-group')
                ->color('primary')
                ->chart($this->getUserChartData()),

            Stat::make('Total Exams', number_format($totalExams))
                ->description($completedExams . ' completed • ' . ($totalExams - $completedExams) . ' in progress')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('success')
                ->chart($this->getExamChartData()),

            Stat::make('Average Score', $avgScore ? number_format($avgScore, 1) . '%' : 'N/A')
                ->description('Performance across all exams')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->icon('heroicon-o-trophy')
                ->color($avgScore >= 70 ? 'success' : ($avgScore >= 50 ? 'warning' : ($avgScore ? 'danger' : 'gray')))
                ->chart($avgScore ? $this->getScoreChartData() : []),

            Stat::make('Coaching Sessions', number_format($totalCoachingSessions))
                ->description($completedCoachingSessions . ' completed • ' . ($totalCoachingSessions - $completedCoachingSessions) . ' active')
                ->descriptionIcon('heroicon-o-chat-bubble-left-right')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->chart($this->getCoachingChartData()),
        ];
    }

    protected function getQuestionChartData(): array
    {
        // Return last 7 days of question additions (mock data for now)
        return [2, 5, 3, 8, 4, 6, 7];
    }

    protected function getUserChartData(): array
    {
        // Return last 7 days of user registrations (mock data for now)
        return [1, 2, 1, 3, 2, 1, 2];
    }

    protected function getExamChartData(): array
    {
        // Return last 7 days of exam attempts (mock data for now)
        return [3, 5, 4, 7, 6, 5, 8];
    }

    protected function getScoreChartData(): array
    {
        // Return last 7 days of average scores (mock data for now)
        return [65, 68, 72, 70, 75, 73, 74];
    }

    protected function getCoachingChartData(): array
    {
        // Return last 7 days of coaching sessions (mock data for now)
        return [2, 3, 2, 4, 3, 3, 5];
    }
}
