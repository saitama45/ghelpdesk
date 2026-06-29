<?php

namespace App\Console\Commands;

use App\Models\ProjectTask;
use App\Models\TaskCard;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily sweep that notifies stakeholders about task-board cards and project
 * tasks that just became due or are about to be due. Runs once a day so each
 * item produces at most one due-date notification per day.
 */
class SendDueDateNotifications extends Command
{
    protected $signature = 'notifications:due-soon';

    protected $description = 'Notify assignees/members about cards and project tasks due soon or newly overdue';

    public function handle(NotificationService $notifications): int
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now()->addDay();
        $count = 0;

        TaskCard::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('due_complete')->orWhere('due_complete', false))
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->with(['assignees:id', 'watchers:id', 'board.members:id', 'board.watchers:id'])
            ->chunk(100, function ($cards) use ($notifications, &$count) {
                foreach ($cards as $card) {
                    $overdue = Carbon::parse($card->due_at)->isPast();
                    $notifications->notifyTaskCard(
                        $card,
                        'due',
                        $overdue ? 'Card overdue' : 'Card due soon',
                        \Illuminate\Support\Str::limit($card->title, 50) . ' is due ' . Carbon::parse($card->due_at)->diffForHumans(),
                        null,
                        [],
                        $overdue ? 'warning' : 'info'
                    );
                    $count++;
                }
            });

        ProjectTask::query()
            ->whereNotNull('end_date')
            ->where('status', '!=', 'Completed')
            ->whereBetween('end_date', [$from->toDateString(), $to->toDateString()])
            ->with(['project.teamMembers:id,project_id,user_id'])
            ->chunk(100, function ($tasks) use ($notifications, &$count) {
                foreach ($tasks as $task) {
                    $overdue = Carbon::parse($task->end_date)->endOfDay()->isPast();
                    $notifications->notifyProjectTask(
                        $task,
                        'due',
                        $overdue ? 'Task overdue' : 'Task due soon',
                        \Illuminate\Support\Str::limit($task->name, 50) . ' is due ' . Carbon::parse($task->end_date)->diffForHumans(),
                        null,
                        [],
                        $overdue ? 'warning' : 'info'
                    );
                    $count++;
                }
            });

        $this->info("Dispatched due-date notifications for {$count} item(s).");

        return self::SUCCESS;
    }
}
