<?php

namespace App\Services;

use App\Models\AcctDocumentReview;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\PaymentRecord;
use App\Models\PaymentRecordApproval;
use App\Models\PosRequest;
use App\Models\PosRequestApproval;
use App\Models\QatCycle;
use App\Models\SapRequest;
use App\Models\SapRequestApproval;
use App\Models\ScheduleChangeRequest;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\ServiceVehicleTrip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tells which "needs approval" bell notifications (domain `approval`, event
 * `pending`) no longer need the recipient: the request has left its pending
 * state (approved, rejected, cancelled, deleted), the level the ping was for
 * already has an approval row — someone at that level acted — or, for requests
 * resubmitted under the same id, the ping predates the latest submission. The
 * bell hides those and leaves them out of the unread count; nothing is deleted.
 *
 * The request is identified by the notification's `subject` ("pos_request:12")
 * and the level by `data.level`, or for rows written before that key existed,
 * the "(Stage N)" / "(Level N)" the POS, SAP and form messages carry. Without
 * a level only the request's own state counts.
 *
 * Most request models here are entity-scoped, so the lookups drop
 * ActiveEntityScope: an approver viewing another entity must not see a live
 * approval vanish as if its request were gone.
 */
class ApprovalNotificationResolver
{
    /**
     * `pending` everywhere; `received` only on legacy accounting review pings.
     */
    private const PENDING_EVENTS = ['pending', 'received'];

    /**
     * Accounting review pings written before they carried an id subject used
     * event `received` and subject "Document {reference no}"; their link is the
     * only place the review id survives.
     */
    private const LEGACY_ACCOUNTING_URL = '~^/accounting-documents/(\d+)(?:[/?#]|$)~';

    /**
     * A ping sent before the request's latest (re)submission belongs to an
     * earlier round. Both timestamps come from the same request's clock, so
     * the margin only absorbs SQL Server datetime rounding.
     */
    private const RESUBMISSION_MARGIN_SECONDS = 5;

    /**
     * @param  Collection<int, \Illuminate\Notifications\DatabaseNotification>  $notifications
     * @return Collection<int, \Illuminate\Notifications\DatabaseNotification> the resolved ones
     */
    public function resolved(Collection $notifications): Collection
    {
        $workflows = $this->workflows();

        $pending = $notifications
            ->filter(fn ($n) => ($n->data['domain'] ?? null) === 'approval'
                && in_array($n->data['event'] ?? null, self::PENDING_EVENTS, true))
            ->map(fn ($n) => ['notification' => $n, 'ref' => $this->reference($n)])
            ->filter(fn ($row) => $row['ref'] !== null && isset($workflows[$row['ref']['workflow']]));

        $resolved = collect();

        foreach ($pending->groupBy(fn ($row) => $row['ref']['workflow']) as $workflow => $rows) {
            $definition = $workflows[$workflow];
            $requestIds = $rows->pluck('ref.id')->unique()->values()->all();
            $since = $definition['since'] ?? null;

            $stillPending = $definition['pending']($requestIds)
                ->keyBy(fn ($request) => (int) $request->id);

            $doneLevels = $definition['approvals']
                ? $definition['approvals']()
                    ->whereIn($definition['foreignKey'], $requestIds)
                    ->get([$definition['foreignKey'], 'level'])
                    ->map(fn ($approval) => (int) $approval->{$definition['foreignKey']} . '|' . (int) $approval->level)
                    ->flip()
                : collect();

            foreach ($rows as $row) {
                ['id' => $requestId, 'level' => $level] = $row['ref'];
                $request = $stillPending->get($requestId);

                if (
                    ! $request
                    || ($level !== null && $doneLevels->has($requestId . '|' . $level))
                    || ($since && $this->predates($row['notification'], $request->{$since}))
                ) {
                    $resolved->push($row['notification']);
                }
            }
        }

        return $resolved;
    }

    private function predates($notification, $submittedAt): bool
    {
        return $submittedAt !== null && $notification->created_at !== null
            && $notification->created_at->lt(
                Carbon::parse($submittedAt)->subSeconds(self::RESUBMISSION_MARGIN_SECONDS)
            );
    }

    /**
     * @return array{workflow: string, id: int, level: int|null}|null
     */
    private function reference($notification): ?array
    {
        if (preg_match('/^([a-z_]+):(\d+)$/', (string) ($notification->data['subject'] ?? ''), $subject)) {
            $workflow = $subject[1];
            $id = (int) $subject[2];
        } elseif (preg_match(self::LEGACY_ACCOUNTING_URL, (string) ($notification->data['url'] ?? ''), $url)) {
            $workflow = 'acct_document_review';
            $id = (int) $url[1];
        } else {
            return null;
        }

        // `received` is only the legacy accounting review event, never another's.
        if (($notification->data['event'] ?? null) === 'received' && $workflow !== 'acct_document_review') {
            return null;
        }

        $level = $notification->data['level'] ?? null;

        if ($level === null && preg_match('/\((?:Stage|Level) (\d+)\)/', (string) ($notification->data['message'] ?? ''), $match)) {
            $level = $match[1];
        }

        return ['workflow' => $workflow, 'id' => $id, 'level' => $level === null ? null : (int) $level];
    }

    /**
     * Per subject prefix: the requests still awaiting approval; for multi-level
     * workflows the approval rows (any decision) marking a level done; and for
     * workflows resubmitted under the same id, the column holding the latest
     * submission time (`since`).
     */
    private function workflows(): array
    {
        $unscoped = fn (string $model): Builder => $model::withoutGlobalScope(ActiveEntityScope::class);
        $terminal = ['Approved', 'Rejected', 'Cancelled'];

        return [
            'schedule_change_request' => [
                'pending' => fn (array $ids) => ScheduleChangeRequest::whereIn('id', $ids)
                    ->where('status', 'pending')->get(['id']),
                'approvals' => null,
            ],
            'pos_request' => [
                'pending' => fn (array $ids) => $unscoped(PosRequest::class)->whereIn('id', $ids)
                    ->where('current_approval_level', '>', 0)->whereNotIn('status', $terminal)->get(['id']),
                'approvals' => fn () => PosRequestApproval::query(),
                'foreignKey' => 'pos_request_id',
            ],
            'sap_request' => [
                'pending' => fn (array $ids) => $unscoped(SapRequest::class)->whereIn('id', $ids)
                    ->where('current_approval_level', '>', 0)->whereNotIn('status', $terminal)->get(['id']),
                'approvals' => fn () => SapRequestApproval::query(),
                'foreignKey' => 'sap_request_id',
            ],
            'form_record' => [
                'pending' => fn (array $ids) => $unscoped(FormRecord::class)->whereIn('id', $ids)
                    ->where('current_approval_level', '>', 0)->whereNotIn('status', $terminal)->get(['id']),
                'approvals' => fn () => FormRecordApproval::query(),
                'foreignKey' => 'form_record_id',
            ],
            'payment_record' => [
                'pending' => fn (array $ids) => $unscoped(PaymentRecord::class)->whereIn('id', $ids)
                    ->where('status', 'pending')->get(['id']),
                'approvals' => fn () => PaymentRecordApproval::query(),
                'foreignKey' => 'payment_record_id',
            ],
            'service_vehicle_trip' => [
                'pending' => fn (array $ids) => $unscoped(ServiceVehicleTrip::class)->whereIn('id', $ids)
                    ->where('status', 'Pending Approval')->get(['id']),
                'approvals' => null,
            ],
            // Awaiting approval = Google sign-up with no role yet, the same test
            // UserController::isPendingGoogleRegistration() uses. An archived
            // (soft-deleted) registration drops out as gone.
            'user_registration' => [
                'pending' => fn (array $ids) => User::whereIn('id', $ids)
                    ->whereNotNull('google_id')->whereDoesntHave('roles')->get(['id']),
                'approvals' => null,
            ],
            // A returned or withdrawn cycle is resubmitted under the same id.
            'qat_cycle' => [
                'pending' => fn (array $ids) => QatCycle::whereIn('id', $ids)
                    ->where('status', QatCycle::STATUS_FOR_APPROVAL)->get(['id', 'submitted_at']),
                'approvals' => null,
                'since' => 'submitted_at',
            ],
            // A linkportal resubmission resets the same review to pending.
            'acct_document_review' => [
                'pending' => fn (array $ids) => AcctDocumentReview::whereIn('id', $ids)
                    ->whereIn('status', [AcctDocumentReview::STATUS_PENDING, AcctDocumentReview::STATUS_IN_REVIEW])
                    ->get(['id', 'received_at']),
                'approvals' => null,
                'since' => 'received_at',
            ],
        ];
    }
}
