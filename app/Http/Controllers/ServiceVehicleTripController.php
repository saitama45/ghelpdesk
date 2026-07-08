<?php

namespace App\Http\Controllers;

use App\Models\ServiceVehicle;
use App\Models\ServiceVehicleTrip;
use App\Models\ServiceVehicleTripAttachment;
use App\Models\User;
use App\Services\ServiceVehicleTripService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ServiceVehicleTripController extends Controller implements HasMiddleware
{
    public function __construct(private ServiceVehicleTripService $tripService) {}

    /** Memoized approve-permission holders for the current request. */
    private ?array $permissionHolders = null;

    /** Per-requester cache of eligible approver ids for the current request. */
    private array $approverCache = [];

    public static function middleware(): array
    {
        return [
            new Middleware('can:service_vehicle_trips.view',    only: ['index', 'show', 'detectConflict']),
            new Middleware('can:service_vehicle_trips.create',  only: ['store']),
            // start/complete are authorized by driver identity (below), not the
            // edit permission — only the assigned driver executes their trip.
            new Middleware('can:service_vehicle_trips.edit',    only: ['update', 'cancel']),
            new Middleware('can:service_vehicle_trips.delete',  only: ['destroy']),
            new Middleware('can:service_vehicle_trips.approve', only: ['approve', 'reject']),
        ];
    }

    /**
     * Active user ids holding the approve permission (cached per request).
     *
     * @return array<int>
     */
    private function approvePermissionHolderIds(): array
    {
        return $this->permissionHolders ??= app(\App\Services\NotificationService::class)
            ->usersWithPermission('service_vehicle_trips.approve');
    }

    /**
     * Who may approve/reject a trip requested by $requesterId: the requester's
     * direct managers (their "Report To") plus the next immediate head above
     * (those managers' managers), limited to holders of the approve permission.
     *
     * Falls back to every permitted approver when the requester has no manager
     * chain (or none of them hold the permission) so bookings never get stuck.
     *
     * @return array<int>
     */
    private function eligibleApproverIds(?int $requesterId): array
    {
        $key = (int) $requesterId;

        if (array_key_exists($key, $this->approverCache)) {
            return $this->approverCache[$key];
        }

        $permitted = $this->approvePermissionHolderIds();

        $requester = $requesterId ? User::find($requesterId) : null;
        if (! $requester) {
            return $this->approverCache[$key] = $permitted;
        }

        $directManagerIds = $requester->managers()->pluck('users.id');

        $headIds = $directManagerIds->isEmpty()
            ? collect()
            : User::whereIn('id', $directManagerIds)
                ->with('managers:id')
                ->get()
                ->flatMap(fn (User $manager) => $manager->managers->pluck('id'));

        $chain = $directManagerIds->merge($headIds)
            ->map(fn ($id) => (int) $id)
            ->unique();

        $eligible = $chain->intersect($permitted)->values()->all();

        return $this->approverCache[$key] = ! empty($eligible) ? $eligible : $permitted;
    }

    /**
     * Whether $user sits in the requester's approver chain for this trip
     * (requester's manager / next head above). This gates approve, reject and
     * edit — the requester themselves is never their own approver.
     */
    private function tripApproverFlag(ServiceVehicleTrip $trip, ?User $user): bool
    {
        return $user
            ? in_array((int) $user->id, $this->eligibleApproverIds($trip->created_by), true)
            : false;
    }

    /**
     * Whether $user may approve/reject the given pending trip — i.e. holds the
     * approve permission AND sits in the requester's manager chain.
     */
    private function tripCanBeApprovedBy(ServiceVehicleTrip $trip, ?User $user): bool
    {
        if (! $user || ! $user->can('service_vehicle_trips.approve')) {
            return false;
        }

        if ($trip->status !== 'Pending Approval') {
            return false;
        }

        return $this->tripApproverFlag($trip, $user);
    }

    /**
     * Whether $user may edit the given trip — the requester's approver chain plus
     * the edit permission, while the trip is still Pending/Scheduled.
     */
    private function tripCanBeEditedBy(ServiceVehicleTrip $trip, ?User $user): bool
    {
        if (! $user || ! $user->can('service_vehicle_trips.edit')) {
            return false;
        }

        if (! in_array($trip->status, ['Pending Approval', 'Scheduled'], true)) {
            return false;
        }

        return $this->tripApproverFlag($trip, $user);
    }

    public function index(Request $request)
    {
        $search    = trim((string) $request->input('search', ''));
        $perPage   = max(1, min(200, (int) $request->input('per_page', 10)));
        $statuses  = array_values(array_filter((array) $request->input('statuses', [])));
        $vehicleId = $request->input('vehicle_id');
        $driverId  = $request->input('driver_id');
        $month     = $request->input('month'); // YYYY-MM for calendar fetch

        $monthStart = $month
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : Carbon::now()->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();

        // Table query (paginated)
        $tableQuery = ServiceVehicleTrip::with(['vehicle', 'driver:id,name', 'approver:id,name']);

        if ($search !== '') {
            $tableQuery->where(function ($q) use ($search) {
                $q->where('purpose_of_travel', 'like', "%{$search}%")
                  ->orWhere('start_point', 'like', "%{$search}%")
                  ->orWhere('end_point', 'like', "%{$search}%")
                  ->orWhereHas('driver', fn ($dq) => $dq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('vehicle', fn ($vq) => $vq->where('plate_no', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            });
        }
        if (! empty($statuses)) {
            $tableQuery->whereIn('status', $statuses);
        }
        if ($vehicleId) {
            $tableQuery->where('service_vehicle_id', $vehicleId);
        }
        if ($driverId) {
            $tableQuery->where('driver_id', $driverId);
        }

        $trips = $tableQuery->latest('date_used')->latest('id')->paginate($perPage)->withQueryString();

        // Calendar query (current month, all trips, no pagination)
        $calendarQuery = ServiceVehicleTrip::with(['vehicle:id,name,plate_no', 'driver:id,name'])
            ->whereBetween('date_used', [$monthStart->toDateString(), $monthEnd->toDateString()]);

        if ($vehicleId) {
            $calendarQuery->where('service_vehicle_id', $vehicleId);
        }
        if ($driverId) {
            $calendarQuery->where('driver_id', $driverId);
        }
        if (! empty($statuses)) {
            $calendarQuery->whereIn('status', $statuses);
        }

        $calendarTrips = $calendarQuery->orderBy('date_used')->orderBy('planned_departure_time')->get();

        // Flag each trip with the viewer's relationship to the requester so the
        // Approve/Reject and Edit buttons only show for the requester's manager
        // chain (never the requester themselves).
        $viewer = $request->user();
        $annotate = function (ServiceVehicleTrip $t) use ($viewer) {
            $t->is_approver = $this->tripApproverFlag($t, $viewer);
            $t->can_approve = $this->tripCanBeApprovedBy($t, $viewer);
            $t->can_edit    = $this->tripCanBeEditedBy($t, $viewer);
            $t->is_driver   = $viewer && (int) $t->driver_id === (int) $viewer->id;
        };
        $trips->getCollection()->each($annotate);
        $calendarTrips->each($annotate);

        // Summary stat cards
        $today = Carbon::today();
        $summary = [
            'pending_approval'   => ServiceVehicleTrip::where('status', 'Pending Approval')->count(),
            'scheduled_next_7d'  => ServiceVehicleTrip::where('status', 'Scheduled')
                                    ->whereBetween('date_used', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                                    ->count(),
            'in_progress'        => ServiceVehicleTrip::where('status', 'In Progress')->count(),
            'trips_this_month'   => ServiceVehicleTrip::whereBetween('date_used', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
        ];

        return Inertia::render('ServiceVehicleTrips/Index', [
            'trips'         => $trips,
            'calendarTrips' => $calendarTrips,
            'month'         => $monthStart->format('Y-m'),
            'vehicles'      => ServiceVehicle::where('status', '!=', 'retired')->orderBy('name')->get(),
            'allVehicles'   => ServiceVehicle::orderBy('name')->get(),
            'drivers'       => User::orderBy('name')->get(['id', 'name', 'email']),
            'summary'       => $summary,
            'filters'       => $request->only(['statuses', 'vehicle_id', 'driver_id', 'search', 'month']),
        ]);
    }

    public function show(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        $serviceVehicleTrip->load(['vehicle', 'driver:id,name,email', 'approver:id,name', 'attachments.uploader:id,name', 'creator:id,name', 'updater:id,name']);
        $serviceVehicleTrip->is_approver = $this->tripApproverFlag($serviceVehicleTrip, $request->user());
        $serviceVehicleTrip->can_approve = $this->tripCanBeApprovedBy($serviceVehicleTrip, $request->user());
        $serviceVehicleTrip->can_edit    = $this->tripCanBeEditedBy($serviceVehicleTrip, $request->user());
        $serviceVehicleTrip->is_driver   = (int) $serviceVehicleTrip->driver_id === (int) $request->user()->id;

        return response()->json($serviceVehicleTrip);
    }

    public function detectConflict(Request $request)
    {
        $validated = $request->validate([
            'service_vehicle_id' => 'required|exists:service_vehicles,id',
            'date_used' => 'required|date',
            'planned_departure_time' => 'required',
            'planned_arrival_time' => 'required',
            'exclude_trip_id' => 'nullable|integer',
        ]);

        $conflict = $this->tripService->detectConflict(
            (int) $validated['service_vehicle_id'],
            Carbon::parse($validated['date_used'])->toDateString(),
            $validated['planned_departure_time'],
            $validated['planned_arrival_time'],
            $validated['exclude_trip_id'] ?? null,
        );

        return response()->json([
            'conflict' => $conflict ? [
                'id' => $conflict->id,
                'driver_name' => $conflict->driver?->name,
                'planned_departure_time' => $conflict->planned_departure_time,
                'planned_arrival_time' => $conflict->planned_arrival_time,
                'status' => $conflict->status,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateBooking($request);
        $this->ensureTimesValid($validated);
        $this->ensureNoConflict($validated);

        $validated['status'] = 'Pending Approval';
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $trip = ServiceVehicleTrip::create($validated);

        $notifications = app(\App\Services\NotificationService::class);
        $notifications->notifyApproval(
            // Only the requester's manager chain (direct managers + next head above).
            $this->eligibleApproverIds($trip->created_by),
            $request->user()->id,
            'pending',
            'Trip approval needed',
            trim(($request->user()->name ?? 'A user') . ' booked a service vehicle trip awaiting your approval.'),
            route('service-vehicle-trips.index', [], false),
            'service_vehicle_trip:' . $trip->id,
            'warning'
        );

        return redirect()->back()->with('success', 'Trip booked. Waiting for approval.');
    }

    public function update(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if(! in_array($serviceVehicleTrip->status, ['Pending Approval', 'Scheduled']), 422, 'Only Pending or Scheduled trips can be edited.');
        abort_unless(
            $this->tripApproverFlag($serviceVehicleTrip, $request->user()),
            403,
            'Only the requester\'s manager can edit this trip.'
        );

        $validated = $this->validateBooking($request);
        $this->ensureTimesValid($validated);
        $this->ensureNoConflict($validated, $serviceVehicleTrip->id);

        $validated['updated_by'] = $request->user()->id;
        $serviceVehicleTrip->update($validated);

        return redirect()->back()->with('success', 'Trip updated.');
    }

    public function approve(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if($serviceVehicleTrip->status !== 'Pending Approval', 422, 'Only Pending trips can be approved.');
        abort_unless(
            in_array((int) $request->user()->id, $this->eligibleApproverIds($serviceVehicleTrip->created_by), true),
            403,
            'Only the requester\'s manager can approve this trip.'
        );

        $serviceVehicleTrip->update([
            'status'      => 'Scheduled',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'updated_by'  => $request->user()->id,
        ]);

        $this->notifyTripRequester($serviceVehicleTrip, 'approved', $request->user()->id);

        return redirect()->back()->with('success', 'Trip approved.');
    }

    public function reject(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if($serviceVehicleTrip->status !== 'Pending Approval', 422, 'Only Pending trips can be rejected.');
        abort_unless(
            in_array((int) $request->user()->id, $this->eligibleApproverIds($serviceVehicleTrip->created_by), true),
            403,
            'Only the requester\'s manager can reject this trip.'
        );

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $serviceVehicleTrip->update([
            'status'           => 'Rejected',
            'approved_by'      => $request->user()->id,
            'approved_at'      => now(),
            'rejection_reason' => $validated['rejection_reason'],
            'updated_by'       => $request->user()->id,
        ]);

        $this->notifyTripRequester($serviceVehicleTrip, 'rejected', $request->user()->id);

        return redirect()->back()->with('success', 'Trip rejected.');
    }

    /**
     * Bell the trip requester (booker) with the final decision.
     */
    private function notifyTripRequester(ServiceVehicleTrip $trip, string $decision, int $actorId): void
    {
        if (!$trip->created_by) {
            return;
        }

        app(\App\Services\NotificationService::class)->notifyApproval(
            [$trip->created_by],
            $actorId,
            $decision,
            'Trip ' . $decision,
            "Your service vehicle trip booking has been {$decision}.",
            route('service-vehicle-trips.index', [], false),
            'service_vehicle_trip:' . $trip->id,
            $decision === 'approved' ? 'success' : 'warning'
        );
    }

    public function start(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if($serviceVehicleTrip->status !== 'Scheduled', 422, 'Only Scheduled trips can be started.');
        abort_unless(
            (int) $request->user()->id === (int) $serviceVehicleTrip->driver_id,
            403,
            'Only the assigned driver can start this trip.'
        );

        $serviceVehicleTrip->update([
            'status'                 => 'In Progress',
            'actual_departure_time'  => now()->format('H:i:s'),
            'updated_by'             => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Trip started.');
    }

    public function complete(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if(! in_array($serviceVehicleTrip->status, ['Scheduled', 'In Progress']), 422, 'Trip cannot be completed in its current status.');
        abort_unless(
            (int) $request->user()->id === (int) $serviceVehicleTrip->driver_id,
            403,
            'Only the assigned driver can log completion for this trip.'
        );

        $validated = $request->validate([
            'actual_departure_time'    => 'required',
            'actual_arrival_time'      => 'required|after:actual_departure_time',
            'odometer_before'          => 'required|integer|min:0',
            'odometer_after'           => 'required|integer|gte:odometer_before',
            'remarks'                  => 'nullable|string',
            'acknowledgement_accepted' => 'required|accepted',
            'attachments'              => 'nullable|array|max:10',
            'attachments.*'            => 'file|max:10240', // 10 MB per file
        ]);

        DB::transaction(function () use ($serviceVehicleTrip, $validated, $request) {
            $serviceVehicleTrip->update([
                'status'                  => 'Completed',
                'actual_departure_time'   => $validated['actual_departure_time'],
                'actual_arrival_time'     => $validated['actual_arrival_time'],
                'odometer_before'         => $validated['odometer_before'],
                'odometer_after'          => $validated['odometer_after'],
                'remarks'                 => $validated['remarks'] ?? $serviceVehicleTrip->remarks,
                'acknowledgement_accepted' => true,
                'acknowledged_at'         => now(),
                'updated_by'              => $request->user()->id,
            ]);

            foreach ((array) $request->file('attachments', []) as $file) {
                $path = $file->store('service-vehicle-trip-attachments', 'public');
                ServiceVehicleTripAttachment::create([
                    'service_vehicle_trip_id' => $serviceVehicleTrip->id,
                    'file_name'               => $file->getClientOriginalName(),
                    'file_storage_path'       => $path,
                    'file_size_bytes'         => $file->getSize(),
                    'uploaded_by'             => $request->user()->id,
                    'uploaded_date'           => now(),
                ]);
            }
        });

        return redirect()->back()->with('success', 'Trip completed.');
    }

    public function cancel(Request $request, ServiceVehicleTrip $serviceVehicleTrip)
    {
        abort_if(! in_array($serviceVehicleTrip->status, ['Pending Approval', 'Scheduled']), 422, 'Only Pending or Scheduled trips can be cancelled.');

        $serviceVehicleTrip->update([
            'status'     => 'Cancelled',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Trip cancelled.');
    }

    public function destroy(ServiceVehicleTrip $serviceVehicleTrip)
    {
        foreach ($serviceVehicleTrip->attachments as $att) {
            Storage::disk('public')->delete($att->file_storage_path);
        }
        $serviceVehicleTrip->delete();

        return redirect()->back()->with('success', 'Trip record deleted.');
    }

    protected function validateBooking(Request $request): array
    {
        return $request->validate([
            'service_vehicle_id'     => 'required|exists:service_vehicles,id',
            'driver_id'              => 'required|exists:users,id',
            'date_used'              => 'required|date',
            'purpose_of_travel'      => 'required|string|max:500',
            'passengers'             => 'nullable|string',
            'start_point'            => 'required|string|max:255',
            'end_point'              => 'required|string|max:255',
            'waypoints'              => 'nullable|array',
            'waypoints.*'            => 'required|string|max:255',
            'planned_departure_time' => 'required',
            'planned_arrival_time'   => 'required',
            'remarks'                => 'nullable|string',
        ]);
    }

    protected function ensureTimesValid(array $data): void
    {
        $dep = Carbon::parse($data['planned_departure_time']);
        $arr = Carbon::parse($data['planned_arrival_time']);

        if ($arr->lessThanOrEqualTo($dep)) {
            throw ValidationException::withMessages([
                'planned_arrival_time' => 'Planned arrival must be after planned departure.',
            ]);
        }
    }

    protected function ensureNoConflict(array $data, ?int $excludeTripId = null): void
    {
        $conflict = $this->tripService->detectConflict(
            (int) $data['service_vehicle_id'],
            Carbon::parse($data['date_used'])->toDateString(),
            $data['planned_departure_time'],
            $data['planned_arrival_time'],
            $excludeTripId,
        );

        if ($conflict) {
            throw ValidationException::withMessages([
                'service_vehicle_id' => 'This vehicle is already booked from ' . $conflict->planned_departure_time . ' to ' . $conflict->planned_arrival_time . ' on the same date (driver: ' . ($conflict->driver?->name ?? 'unknown') . ').',
            ]);
        }
    }
}
