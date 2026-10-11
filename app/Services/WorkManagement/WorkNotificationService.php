<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use Illuminate\Support\Facades\DB;

/**
 * In-app notifications for collaborators (task assignments, reviews and observations) and the header inbox.
 *
 * @phpstan-type InboxNotification array{id: int, type: string, title: string, body: string|null, href: string, read: bool, created_at: string}
 * @phpstan-type InboxComment array{id: int, task_id: int, task_title: string, company_name: string|null, author: string|null, message: string, type: string, href: string, created_at: string}
 */
class WorkNotificationService
{
    /**
     * Records a notification unless the recipient is missing or is the person who caused it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function notify(int $organizationId, ?int $recipientId, ?int $actorId, string $type, string $title, ?string $body = null, ?string $relatedType = null, ?int $relatedId = null, array $metadata = []): void
    {
        if ($recipientId === null || $recipientId === $actorId) {
            return;
        }

        $isActiveMember = DB::table('organization_user')
            ->where('organization_user.organization_id', $organizationId)
            ->where('user_id', $recipientId)
            ->where('status', 'active')
            ->exists();
        if (! $isActiveMember) {
            return;
        }

        DB::table('work_notifications')->insert([
            'organization_id' => $organizationId,
            'user_id' => $recipientId,
            'type' => $type,
            'title' => mb_substr($title, 0, 180),
            'body' => $body,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The office's own tools (chat channel, mail) get the same notice; a slow tool never delays the request.
        dispatch(fn () => app(IntegrationConnectionService::class)->broadcast($organizationId, $recipientId, $title, $body))->afterResponse();
    }

    /**
     * Unread count, latest notifications and recent comments on the user's tasks for the header inbox.
     *
     * @return array{unread: int, notifications: list<InboxNotification>, comments: list<InboxComment>}
     */
    public function inbox(int $organizationId, User $user): array
    {
        $base = DB::table('work_notifications')
            ->where('work_notifications.organization_id', $organizationId)
            ->where('user_id', $user->id);

        $notifications = (clone $base)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'type', 'title', 'body', 'related_type', 'related_id', 'read_at', 'created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'title' => (string) $row->title,
                'body' => $row->body === null ? null : (string) $row->body,
                'href' => $this->href($row->related_type, $row->related_id === null ? null : (int) $row->related_id),
                'read' => $row->read_at !== null,
                'created_at' => (string) $row->created_at,
            ])
            ->values()
            ->all();

        $comments = DB::table('task_comments as comment')
            ->join('work_tasks as task', 'task.id', '=', 'comment.work_task_id')
            ->leftJoin('companies', 'companies.id', '=', 'task.company_id')
            ->leftJoin('users as author', 'author.id', '=', 'comment.user_id')
            ->where('comment.organization_id', $organizationId)
            ->whereNull('task.deleted_at')
            ->where('comment.user_id', '!=', $user->id)
            ->where(fn ($query) => $query
                ->where('task.assigned_to', $user->id)
                ->orWhere('task.reviewer_id', $user->id)
                ->orWhere('task.created_by', $user->id))
            ->orderByDesc('comment.id')
            ->limit(6)
            ->get(['comment.id', 'comment.work_task_id', 'comment.type', 'comment.message', 'comment.created_at', 'task.title', 'companies.name as company_name', 'author.name as author_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'task_id' => (int) $row->work_task_id,
                'task_title' => (string) $row->title,
                'company_name' => $row->company_name === null ? null : (string) $row->company_name,
                'author' => $row->author_name === null ? null : (string) $row->author_name,
                'message' => mb_strimwidth((string) $row->message, 0, 160, '…'),
                'type' => (string) $row->type,
                'href' => '/work/tasks/'.$row->work_task_id,
                'created_at' => (string) $row->created_at,
            ])
            ->values()
            ->all();

        return [
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'notifications' => array_values($notifications),
            'comments' => array_values($comments),
        ];
    }

    /** Marks one of the user's own notifications as read and returns where it points to. */
    public function markRead(int $organizationId, User $user, int $notificationId): string
    {
        $notification = DB::table('work_notifications')
            ->where('work_notifications.organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('id', $notificationId)
            ->firstOrFail(['id', 'related_type', 'related_id', 'read_at']);

        if ($notification->read_at === null) {
            DB::table('work_notifications')->where('id', $notification->id)->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return $this->href($notification->related_type, $notification->related_id === null ? null : (int) $notification->related_id);
    }

    public function markAllRead(int $organizationId, User $user): int
    {
        return DB::table('work_notifications')
            ->where('work_notifications.organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    private function href(?string $relatedType, ?int $relatedId): string
    {
        if ($relatedId === null) {
            return '/work/dashboard';
        }

        return match ($relatedType) {
            'work_tasks' => '/work/tasks/'.$relatedId,
            'company_period_reviews' => '/work/reviews/'.$relatedId,
            'users' => '/office/users?user='.$relatedId,
            'office_staffing' => '/office/assignments',
            'operational_incidents' => '/operations/incidents?incident='.$relatedId,
            'communications' => '/communication?message='.$relatedId,
            'review_observations' => '/work/observations/'.$relatedId,
            'fiscal_obligations' => '/tax/obligations/'.$relatedId,
            'luzma_reminders' => (string) (DB::table('luzma_reminders')->where('id', $relatedId)->value('destination') ?: '/luzma?tab=reminders'),
            'luzma_insights' => '/luzma?tab=insights',
            'audit_engagements' => '/work/audit/engagements/'.$relatedId,
            'webhook_endpoints' => '/office/integrations?tab=webhooks',
            'client_requests' => '/client-open/request/'.$relatedId,
            'client_approval_requests' => '/client-open/approval/'.$relatedId,
            'client_documents' => '/client-open/document/'.$relatedId,
            'published_reports' => '/client-open/report/'.$relatedId,
            'client_conversations' => '/client-open/conversation/'.$relatedId,
            'company_data_change_requests' => '/client-open/change/'.$relatedId,
            'audit_evidence_requests' => '/work/audit-requests/open/evidence/'.$relatedId,
            'audit_findings' => '/work/audit-requests/open/finding/'.$relatedId,
            default => '/work/dashboard',
        };
    }
}
