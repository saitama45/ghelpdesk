<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Mail\NewTicketCreated;
use App\Mail\TicketMergedNotification;
use App\Mail\TicketAssigned;
use App\Mail\TicketCommentAdded;
use App\Mail\TicketStatusChanged;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Models\TicketComment;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Models\Company;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Schedule;
use App\Services\TicketKnowledgeBaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function __construct(
        private \App\Services\OrganizationReferenceService $organizationReferences,
        private TicketKnowledgeBaseService $ticketKnowledgeBaseService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Ticket::with([
            'reporter:id,name,profile_photo', 
            'assignee:id,name,profile_photo', 
            'company:id,name',
            'store:id,name', 
            'item:id,name,priority,category_id,sub_category_id',
            'item.category:id,name',
            'item.subCategory:id,name',
            'slaMetric', 
            'children' => function($q) {
                $q->select('id', 'parent_id', 'ticket_key', 'title', 'assignee_id', 'status')
                  ->with('assignee:id,name,profile_photo');
            }
        ])
            ->where('is_deleted', false)
            ->whereNull('parent_id'); // Only show top-level tickets

        // If user has 'User' role, only show their own reported tickets — no company gate needed
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        } else {
            // Filter by user's company access for all other roles
            $user->load('roles.companies');
            $allowedCompanyIds = collect();

            foreach ($user->roles as $role) {
                if ($role->companies) {
                    $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
                }
            }

            // Also include direct company assignment
            if ($user->company_id) {
                $allowedCompanyIds->push($user->company_id);
            }

            $allowedCompanyIds = $allowedCompanyIds->unique();

            if ($allowedCompanyIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('company_id', $allowedCompanyIds);
            }
        }

        // Apply status filters — User role defaults to 'all' so they see all their own tickets
        $normalizeFilterValues = function ($value) {
            return collect(is_array($value) ? $value : [$value])
                ->filter(fn ($item) => $item !== null && $item !== '')
                ->map(fn ($item) => is_string($item) ? trim($item) : $item)
                ->filter(fn ($item) => $item !== null && $item !== '')
                ->unique(fn ($item) => (string) $item)
                ->values();
        };

        $defaultStatus = $user->hasRole('User') ? 'all' : 'open';
        $statusFilters = $normalizeFilterValues($request->input('status', [$defaultStatus]));

        if ($statusFilters->isEmpty()) {
            $statusFilters = collect([$defaultStatus]);
        }
        
        if (!$statusFilters->contains('all')) {
            $normalStatusFilters = $statusFilters
                ->reject(fn ($status) => in_array($status, ['my_tickets', 'unassigned'], true))
                ->values();

            $query->where(function ($statusQuery) use ($statusFilters, $normalStatusFilters, $user) {
                if ($normalStatusFilters->isNotEmpty()) {
                    $statusQuery->whereIn('status', $normalStatusFilters->all());
                }

                if ($statusFilters->contains('my_tickets')) {
                    $statusQuery->orWhere(function ($q) use ($user) {
                        $q->where('reporter_id', $user->id)
                          ->orWhere('assignee_id', $user->id);
                    });
                }

                if ($statusFilters->contains('unassigned')) {
                    $statusQuery->orWhereNull('assignee_id');
                }
            });
        }

        // Apply Department / Team filter
        $filterDeptId  = $request->filled('department_id')      ? (int) $request->department_id      : null;
        $filterNodeId  = $request->filled('department_node_id') ? (int) $request->department_node_id : null;
        $skipDefaultDepartmentScope = $request->boolean('skip_default_department');

        if (!$skipDefaultDepartmentScope && !$filterDeptId && !$filterNodeId) {
            $filterDeptId = auth()->user()->department_id ? (int) auth()->user()->department_id : null;
        }

        if ($filterNodeId) {
            $descendantIds = \App\Models\DepartmentNode::getAllDescendantIds($filterNodeId);
            $nodeIds = array_merge([$filterNodeId], $descendantIds);
            $query->whereHas('assignee', fn($q) => $q->whereIn('department_node_id', $nodeIds));
        } elseif ($filterDeptId) {
            $query->whereHas('assignee', fn($q) => $q->where('department_id', $filterDeptId));
        }

        // Apply Assignee filter
        $assigneeFilters = $normalizeFilterValues($request->input('assignee_id'));
        if ($assigneeFilters->isNotEmpty()) {
            $query->whereIn('assignee_id', $assigneeFilters->all());
        }

        // Apply Date Range filter
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Carbon\Carbon::parse($request->start_date)->startOfDay();
            $end = \Carbon\Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            if ($request->filled('year')) {
                $query->whereYear('created_at', (int) $request->year);
            }

            if ($request->filled('month')) {
                $query->whereMonth('created_at', (int) $request->month);
            }
        }

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhere('ticket_key', 'like', "%{$request->search}%")
                  ->orWhereHas('assignee', function($aq) use ($request) {
                      $aq->where('name', 'like', "%{$request->search}%");
                  })
                  ->orWhereHas('children', function($cq) use ($request) {
                      $cq->where('title', 'like', "%{$request->search}%")
                        ->orWhere('description', 'like', "%{$request->search}%")
                        ->orWhere('ticket_key', 'like', "%{$request->search}%")
                        ->orWhereHas('assignee', function($aq) use ($request) {
                            $aq->where('name', 'like', "%{$request->search}%");
                        });
                  });
            });
        }
        
        $query->orderBy('created_at', 'desc');
        $tickets = $query->paginate($request->get('per_page', 10))->withQueryString();
        $staff = User::whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name', 'email', 'org_path')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $cannedMessages = \App\Models\CannedMessage::where('is_active', true)->orderBy('title')->get();
        $departments = User::whereNotNull('department')->distinct()->orderBy('department')->pluck('department');

        $vendors = collect([['id' => null, 'name' => 'None']])
            ->concat(Vendor::active()->orderBy('name')->get(['id', 'name']));

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'staff' => $staff,
            'companies' => $companies,
            'stores' => $stores,
            'vendors' => $vendors,
            'cannedMessages' => $cannedMessages,
            'departments' => $departments,
            'hierarchicalDepartments' => $this->organizationReferences->tree(),
            'filters' => [
                'status' => $statusFilters->all(),
                'search' => $request->search,
                'department_id' => $filterDeptId,
                'department_node_id' => $filterNodeId,
                'assignee_id' => $assigneeFilters->all(),
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'year' => $request->year,
                'month' => $request->month,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();

        $query = Ticket::where('is_deleted', false)->whereNull('parent_id');

        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        } else {
            $user->load('roles.companies');
            $allowedCompanyIds = collect();
            foreach ($user->roles as $role) {
                if ($role->companies) {
                    $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
                }
            }
            if ($user->company_id) $allowedCompanyIds->push($user->company_id);
            $allowedCompanyIds = $allowedCompanyIds->unique();
            if ($allowedCompanyIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('company_id', $allowedCompanyIds);
            }
        }

        $normalizeFilterValues = fn ($value) => collect(is_array($value) ? $value : [$value])
            ->filter(fn ($item) => $item !== null && $item !== '')
            ->map(fn ($item) => is_string($item) ? trim($item) : $item)
            ->unique(fn ($item) => (string) $item)
            ->values();

        $defaultStatus = $user->hasRole('User') ? 'all' : 'open';
        $statusFilters = $normalizeFilterValues($request->input('status', [$defaultStatus]));
        if ($statusFilters->isEmpty()) $statusFilters = collect([$defaultStatus]);

        if (!$statusFilters->contains('all')) {
            $normalStatusFilters = $statusFilters->reject(fn ($s) => in_array($s, ['my_tickets', 'unassigned']))->values();
            $query->where(function ($q) use ($statusFilters, $normalStatusFilters, $user) {
                if ($normalStatusFilters->isNotEmpty()) $q->whereIn('status', $normalStatusFilters->all());
                if ($statusFilters->contains('my_tickets')) {
                    $q->orWhere(fn($sq) => $sq->where('reporter_id', $user->id)->orWhere('assignee_id', $user->id));
                }
                if ($statusFilters->contains('unassigned')) $q->orWhereNull('assignee_id');
            });
        }

        $filterDeptId = $request->filled('department_id')      ? (int) $request->department_id      : null;
        $filterNodeId = $request->filled('department_node_id') ? (int) $request->department_node_id : null;
        if (!$filterDeptId && !$filterNodeId) {
            $filterDeptId = auth()->user()->department_id ? (int) auth()->user()->department_id : null;
        }
        if ($filterNodeId) {
            $descendantIds = \App\Models\DepartmentNode::getAllDescendantIds($filterNodeId);
            $query->whereHas('assignee', fn($q) => $q->whereIn('department_node_id', array_merge([$filterNodeId], $descendantIds)));
        } elseif ($filterDeptId) {
            $query->whereHas('assignee', fn($q) => $q->where('department_id', $filterDeptId));
        }

        $assigneeFilters = $normalizeFilterValues($request->input('assignee_id'));
        if ($assigneeFilters->isNotEmpty()) $query->whereIn('assignee_id', $assigneeFilters->all());

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                \Carbon\Carbon::parse($request->start_date)->startOfDay(),
                \Carbon\Carbon::parse($request->end_date)->endOfDay(),
            ]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('ticket_key', 'like', "%{$search}%")
                  ->orWhereHas('assignee', fn($aq) => $aq->where('name', 'like', "%{$search}%"));
            });
        }

        $tickets = $query
            ->with([
                'reporter:id,name',
                'assignee:id,name',
                'company:id,name',
                'store:id,name',
                'item:id,name,priority',
                'category:id,name',
                'subCategory:id,name',
                'vendor:id,name',
                'parent:id,ticket_key',
                'slaMetric',
                'histories' => fn($q) => $q->where('column_changed', 'status')
                    ->with('user:id,name')
                    ->orderBy('changed_at', 'desc'),
            ])
            ->orderBy('created_at', 'desc')
            ->take(2000)
            ->get();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tickets');

        $headers = [
            'Ticket ID', 'Title', 'Type', 'Status', 'Priority', 'Severity',
            'Category', 'Sub-Category', 'Item / Concern',
            'Company', 'Store', 'Vendor', 'Parent Ticket',
            'Reporter (Created By)', 'Assignee', 'Last Updated By',
            'Created At', 'Updated At',
            'First Response At', 'Resolved At', 'Closed At',
            'SLA Response Breached', 'SLA Resolution Breached',
        ];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $h);
        }

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        $tz = new \DateTimeZone('Asia/Manila');
        $fmt = fn ($dt) => $dt ? $dt->setTimezone($tz)->format('Y-m-d H:i:s') : '';

        $row = 2;
        foreach ($tickets as $ticket) {
            $closedAt    = $ticket->histories->firstWhere('new_value', 'closed')?->changed_at;
            $lastUpdater = $ticket->histories->first()?->user?->name;

            $sheet->setCellValue('A' . $row, $ticket->ticket_key);
            $sheet->setCellValue('B' . $row, $ticket->title);
            $sheet->setCellValue('C' . $row, $ticket->type);
            $sheet->setCellValue('D' . $row, str_replace('_', ' ', $ticket->status));
            $sheet->setCellValue('E' . $row, $ticket->priority);
            $sheet->setCellValue('F' . $row, $ticket->severity);
            $sheet->setCellValue('G' . $row, $ticket->category?->name);
            $sheet->setCellValue('H' . $row, $ticket->subCategory?->name);
            $sheet->setCellValue('I' . $row, $ticket->item?->name);
            $sheet->setCellValue('J' . $row, $ticket->company?->name);
            $sheet->setCellValue('K' . $row, $ticket->store?->name);
            $sheet->setCellValue('L' . $row, $ticket->vendor?->name);
            $sheet->setCellValue('M' . $row, $ticket->parent?->ticket_key);
            $sheet->setCellValue('N' . $row, $ticket->reporter?->name);
            $sheet->setCellValue('O' . $row, $ticket->assignee?->name ?? 'Unassigned');
            $sheet->setCellValue('P' . $row, $lastUpdater);
            $sheet->setCellValue('Q' . $row, $fmt($ticket->created_at));
            $sheet->setCellValue('R' . $row, $fmt($ticket->updated_at));
            $sheet->setCellValue('S' . $row, $fmt($ticket->slaMetric?->first_response_at));
            $sheet->setCellValue('T' . $row, $fmt($ticket->slaMetric?->resolved_at));
            $sheet->setCellValue('U' . $row, $fmt($closedAt));
            $sheet->setCellValue('V' . $row, $ticket->slaMetric?->is_response_breached  ? 'Yes' : 'No');
            $sheet->setCellValue('W' . $row, $ticket->slaMetric?->is_resolution_breached ? 'Yes' : 'No');
            $row++;
        }

        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'tickets-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $httpHeaders = [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ];

        return response()->stream(fn() => $writer->save('php://output'), 200, $httpHeaders);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTicketRequest $request)
    {
        $data = $request->validated();
        
        $ticket = DB::transaction(function () use ($data, $request) {
            // Handle requester options
            $isSelfRequester = $request->boolean('is_self_requester', true);
            if ($isSelfRequester) {
                $data['reporter_id'] = auth()->id();
                $data['sender_name'] = null;
                $data['sender_email'] = null;
                $data['department'] = auth()->user()->department;
            } else {
                $data['reporter_id'] = null;
                // sender_name, sender_email, and department are already in $data
            }

            // Ensure Manila Time
            $data['created_at'] = now('Asia/Manila');

            // Default values for removed UI fields
            $data['type'] = $data['type'] ?? 'task';
            $data['severity'] = $data['severity'] ?? 'minor';

            // Set priority, category, and sub_category from item
            if (isset($data['item_id'])) {
                $item = \App\Models\Item::find($data['item_id']);
                if ($item) {
                    $data['priority'] = strtolower($item->priority);
                    $data['category_id'] = $item->category_id;
                    $data['sub_category_id'] = $item->sub_category_id;
                }
            }

            $ticket = Ticket::create($data);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = str_replace('\\', '/', $file->storeAs('ticket-attachments', $fileName, 'public'));
                    
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_storage_path' => $filePath,
                        'file_size_bytes' => $file->getSize(),
                    ]);
                }
            }

            return $ticket;
        });

        $ticket->load(['reporter', 'assignee']);
        $sentTo = [];

        // Notify requester conditionally
        if ($request->boolean('notify_requester', true)) {
            if ($ticket->reporter && $ticket->reporter->email) {
                $pending = Mail::to($ticket->reporter->email);
                $cc = $this->attachTicketCcs($pending, $ticket, [$ticket->reporter->email]);
                $pending->send(new NewTicketCreated($ticket, $ticket->reporter->name));
                $sentTo[] = $ticket->reporter->email;
                $sentTo = array_merge($sentTo, $cc);
            } elseif ($ticket->sender_email) {
                $pending = Mail::to($ticket->sender_email);
                $cc = $this->attachTicketCcs($pending, $ticket, [$ticket->sender_email]);
                $pending->send(new NewTicketCreated($ticket, $ticket->sender_name ?? 'External User'));
                $sentTo[] = $ticket->sender_email;
                $sentTo = array_merge($sentTo, $cc);
            }
        }

        if ($ticket->assignee && $ticket->assignee->email && $ticket->assignee->id !== $ticket->reporter_id) {
            $shouldNotifyAssignee = $ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists();
            if ($shouldNotifyAssignee) {
                Mail::to($ticket->assignee->email)->send(new NewTicketCreated($ticket, $ticket->assignee->name));
                $sentTo[] = $ticket->assignee->email;
            }
        }

        $this->notifyTicketCreationWatchers($ticket, $sentTo);

        if (strtolower($ticket->priority) === 'urgent') {
            $urgentWatchers = User::whereHas('roles', function ($q) {
                $q->where('notify_on_urgent_ticket', true);
            })->get();

            foreach ($urgentWatchers as $watcher) {
                if ($watcher->email && !in_array($watcher->email, $sentTo)) {
                    Mail::to($watcher->email)->send(new TicketAssigned($ticket, $watcher->name));
                    $sentTo[] = $watcher->email;
                }
            }
        }

        return redirect()->back()->with('success', 'Ticket created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Ticket $ticket)
    {
        return $this->edit($ticket);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ticket $ticket)
    {
        $ticket->load([
            'comments' => function($query) {
                $query->with(['user:id,name,profile_photo', 'attachments']);
                if (!auth()->user()->can('tickets.edit')) {
                    $query->where('is_internal', false);
                }
                $query->orderBy('created_at', 'desc');
            },
            'histories' => function($query) {
                $query->with('user:id,name,profile_photo')->orderBy('changed_at', 'desc');
            },
            'attachments', 
            'reporter', 
            'assignee', 
            'company',
            'store',
            'item',
            'parent',
            'scheduleStore.schedule',
            'scheduleStore.store',
            'slaMetric',
            'children' => function($query) {
                $query->with(['scheduleStore.schedule', 'scheduleStore.store', 'schedule', 'store', 'reporter', 'assignee'])->orderBy('created_at', 'asc');
            },
            'ccs' => function($query) {
                $query->with('user:id,name,email')->orderBy('email');
            },
            'parent.ccs' => function($query) {
                $query->with('user:id,name,email')->orderBy('email');
            },
        ]);

        if (!$ticket->slaMetric) {
            $ticket->slaMetric()->create([
                'response_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response'),
                'resolution_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution'),
            ]);
            $ticket->load('slaMetric');
        }

        $staff = User::whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name', 'email', 'org_path')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        $users = User::active()->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $cannedMessages = \App\Models\CannedMessage::where('is_active', true)->orderBy('title')->get();
        $vendors = collect([['id' => null, 'name' => 'None']])
            ->concat(Vendor::active()->orderBy('name')->get(['id', 'name']));

        $subUnit = $ticket->assignee?->org_path;
        $businessHours = [
            'start' => \App\Models\Setting::get($subUnit ? "business_start_time_" . \Illuminate\Support\Str::slug($subUnit, '_') : "business_start_time", \App\Models\Setting::get("business_start_time", "08:00")),
            'end' => \App\Models\Setting::get($subUnit ? "business_end_time_" . \Illuminate\Support\Str::slug($subUnit, '_') : "business_end_time", \App\Models\Setting::get("business_end_time", "17:00")),
            'days' => json_decode(\App\Models\Setting::get($subUnit ? "working_days_" . \Illuminate\Support\Str::slug($subUnit, '_') : "working_days", \App\Models\Setting::get("working_days", "[1,2,3,4,5]")), true),
        ];

        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket,
            'staff' => $staff,
            'companies' => $companies,
            'users' => $users,
            'stores' => $stores,
            'vendors' => $vendors,
            'cannedMessages' => $cannedMessages,
            'businessHours' => $businessHours,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $validated = $request->validated();
        
        // Handle requester options
        if ($request->has('is_self_requester')) {
            $isSelf = $request->boolean('is_self_requester');
            if ($isSelf) {
                $validated['reporter_id'] = auth()->id();
                $validated['sender_name'] = null;
                $validated['sender_email'] = null;
                $validated['department'] = auth()->user()->department;
            } else {
                if (!$ticket->reporter_id || (int) $ticket->reporter_id === (int) auth()->id()) {
                    $validated['reporter_id'] = null;
                    // sender_name, sender_email, and department are already in $validated from request
                } else {
                    unset($validated['reporter_id'], $validated['sender_name'], $validated['sender_email']);
                }
            }
        } elseif ($request->has('department')) {
            $validated['department'] = $request->input('department');
        }

        // Auto-update priority, category, and sub_category if item_id changed
        if (isset($validated['item_id']) && $validated['item_id'] != $ticket->item_id) {
            $item = \App\Models\Item::find($validated['item_id']);
            if ($item) {
                $validated['priority'] = strtolower($item->priority);
                $validated['category_id'] = $item->category_id;
                $validated['sub_category_id'] = $item->sub_category_id;
            }
        }

        $ticket->fill($validated);
        
        if ($ticket->isDirty()) {
            $userId = auth()->id();
            $dirty = $ticket->getDirty();
            $assigneeChanged = false;
            
            foreach ($dirty as $column => $newValue) {
                if ($column === 'assignee_id') $assigneeChanged = true;
                if ($column === 'updated_at') continue;
                
                $oldValue = $ticket->getOriginal($column);
                
                if ($column === 'company_id') {
                    $oldValue = Company::find($oldValue)?->name ?? $oldValue;
                    $newValue = Company::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'store_id') {
                    $oldValue = Store::find($oldValue)?->name ?? $oldValue;
                    $newValue = Store::find($newValue)?->name ?? $newValue;
                } elseif (in_array($column, ['assignee_id', 'reporter_id'])) {
                    $oldValue = User::find($oldValue)?->name ?? $oldValue;
                    $newValue = User::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'category_id') {
                    $oldValue = \App\Models\Category::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\Category::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'sub_category_id') {
                    $oldValue = \App\Models\SubCategory::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\SubCategory::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'item_id') {
                    $oldValue = \App\Models\Item::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\Item::find($newValue)?->name ?? $newValue;
                }
                
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $userId,
                    'column_changed' => $column,
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                    'changed_at' => now('Asia/Manila'),
                ]);
            }
            
            $statusChanged = $ticket->isDirty('status');
            $oldStatus = $ticket->getOriginal('status');
            $newStatus = $ticket->status;
            $ticket->save();

            $alreadyNotified = [];

            // Skip notifications if specifically requested
            if ($request->boolean('notify_requester', true)) {
                if ($assigneeChanged && $ticket->assignee_id) {
                    $ticket->load('assignee');
                    if ($ticket->assignee && $ticket->assignee->email) {
                        if ($ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists()) {
                            $pending = Mail::to($ticket->assignee->email);
                            $cc = $this->attachTicketCcs($pending, $ticket, [$ticket->assignee->email]);
                            $pending->send(new TicketAssigned($ticket, $ticket->assignee->name));
                            $alreadyNotified[] = $ticket->assignee->email;
                            $alreadyNotified = array_merge($alreadyNotified, $cc);
                        }
                    }
                }

                if (strtolower($ticket->priority) === 'urgent') {
                    $urgentWatchers = User::whereHas('roles', function ($q) {
                        $q->where('notify_on_urgent_ticket', true);
                    })->get();

                    foreach ($urgentWatchers as $watcher) {
                        if ($watcher->email && !in_array($watcher->email, $alreadyNotified)) {
                            Mail::to($watcher->email)->send(new TicketAssigned($ticket, $watcher->name));
                            $alreadyNotified[] = $watcher->email;
                        }
                    }
                }

                // Status change notification — primarily for CC list, but also reporter
                if ($statusChanged) {
                    $ticket->loadMissing('reporter');
                    $primary = $ticket->reporter?->email ?: $ticket->sender_email;
                    $primaryName = $ticket->reporter?->name ?: ($ticket->sender_name ?? 'Requester');

                    if ($primary && !in_array($primary, $alreadyNotified, true)) {
                        $pending = Mail::to($primary);
                        $cc = $this->attachTicketCcs($pending, $ticket, array_merge($alreadyNotified, [$primary]));
                        $pending->send(new TicketStatusChanged($ticket, $primaryName, (string) $oldStatus, (string) $newStatus));
                        $alreadyNotified[] = $primary;
                        $alreadyNotified = array_merge($alreadyNotified, $cc);
                    } else {
                        // No primary recipient available — CC list still notified directly
                        $effective = $ticket->effectiveCcs();
                        foreach ($effective as $cc) {
                            if ($cc->email && !in_array($cc->email, $alreadyNotified, true)) {
                                Mail::to($cc->email)->send(new TicketStatusChanged($ticket, $cc->name ?: 'Subscriber', (string) $oldStatus, (string) $newStatus));
                                $alreadyNotified[] = $cc->email;
                            }
                        }
                    }
                }
            }
        }

        return redirect()->back()->with('success', 'Ticket updated successfully.');
    }

    /**
     * Internal helper to sync parent status based on children
     */
    private function syncParentStatus($parentId, $triggeredStatus)
    {
        $parent = Ticket::find($parentId);
        if (!$parent) return;

        $allChildren = Ticket::where('parent_id', $parentId)->get();
        
        if (in_array($triggeredStatus, ['resolved', 'closed'])) {
            // Check if ALL children are terminal (resolved or closed)
            $allDone = $allChildren->every(function($child) {
                return in_array($child->status, ['resolved', 'closed']);
            });

            if ($allDone) {
                // If all are terminal, set parent to the triggered status (resolved or closed)
                $parent->update(['status' => $triggeredStatus]);
            }
        } else {
            // If any child is updated to an active status, parent reflects it
            $parent->update(['status' => $triggeredStatus]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket)
    {
        abort_unless(auth()->user()->can('tickets.delete'), 403);

        $count = $this->archiveTickets(collect([$ticket]));

        return redirect()->route('tickets.index')->with('success', "{$count} ticket(s) archived successfully.");
    }

    public function bulkArchive(Request $request)
    {
        abort_unless($request->user()->can('tickets.delete'), 403);

        $validated = $request->validate([
            'ticket_ids' => 'required|array|min:1',
            'ticket_ids.*' => 'exists:tickets,id',
        ]);

        $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();

        if ($tickets->isEmpty()) {
            return redirect()->back()->withErrors(['archive' => 'No active tickets selected for archive.']);
        }

        $count = $this->archiveTickets($tickets);

        return redirect()->back()->with('success', "{$count} ticket(s) archived successfully.");
    }

    private function archiveTickets($tickets): int
    {
        $rootIds = $tickets->pluck('id');

        return DB::transaction(function () use ($rootIds) {
            $targets = Ticket::whereIn('id', $rootIds)
                ->orWhereIn('parent_id', $rootIds)
                ->get()
                ->unique('id');

            foreach ($targets as $target) {
                if ($target->trashed()) {
                    continue;
                }

                $target->forceFill(['is_deleted' => true])->save();
                $target->delete();
            }

            return $targets->count();
        });
    }

    /**
     * Store a child ticket and link it to a schedule.
     */
    public function storeChild(Request $request, Ticket $ticket)
    {
        // Allow creating multiple child tickets as long as the parent is Open or In Progress
        if (!in_array($ticket->status, ['open', 'in_progress'])) {
            return redirect()->back()->withErrors(['error' => 'Child tickets can only be created for Open or In Progress tickets.']);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'required_unless:status,SL,VL,Restday,Holiday|nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'set_schedule' => 'sometimes|boolean',
            'start_time' => 'required_if:set_schedule,true|nullable|date',
            'end_time' => 'required_if:set_schedule,true|nullable|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ], [
            'store_id.required_unless' => 'Store is required before creating a child ticket.',
        ]);

        $hasSchedule = $request->boolean('set_schedule', true)
            && !empty($validated['start_time'])
            && !empty($validated['end_time']);

        if ($hasSchedule) {
            // Check for an overlapping schedule for the same user (regardless of store)
            $newStart = \Carbon\Carbon::parse($validated['start_time']);
            $newEnd   = \Carbon\Carbon::parse($validated['end_time']);

            // Check against specific schedule segments first
            $conflictStore = \App\Models\ScheduleStore::whereHas('schedule', function($q) use ($validated) {
                    $q->where('user_id', $validated['user_id']);
                })
                ->where('start_time', '<', $newEnd)
                ->where('end_time', '>', $newStart)
                ->first();

            $conflict = null;
            if ($conflictStore) {
                $conflict = $conflictStore;
            } else {
                // Check against schedules that don't have segments
                $conflict = Schedule::where('user_id', $validated['user_id'])
                    ->whereDoesntHave('scheduleStores')
                    ->where('start_time', '<', $newEnd)
                    ->where('end_time', '>', $newStart)
                    ->first();
            }

            if ($conflict) {
                $from = $conflict->start_time->format('M d, Y h:i A');
                $to   = $conflict->end_time->format('M d, Y h:i A');
                return redirect()->back()->withErrors([
                    'schedule_conflict' => "A schedule already exists for this user from {$from} to {$to}. Please choose a different date/time.",
                ]);
            }
        }

        $childTicket = DB::transaction(function () use ($validated, $ticket, $hasSchedule) {
            $company = $ticket->company;
            $companyCode = $company->code;

            $maxNumber = Ticket::withTrashed()
                ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                ->selectRaw(
                    'MAX(TRY_CAST(SUBSTRING(ticket_key, LEN(?) + 2, LEN(ticket_key)) AS INT)) as max_num',
                    [$companyCode]
                )
                ->value('max_num');

            $nextNumber = ($maxNumber ?? 0) + 1;
            
            $childTicket = Ticket::create([
                'ticket_key' => "{$companyCode}-{$nextNumber}",
                'title' => "Child: {$ticket->title}",
                'description' => "Child of {$ticket->ticket_key}. Remarks: " . ($validated['remarks'] ?? ''),
                'type' => $ticket->type,
                'status' => 'for_schedule',
                'priority' => $ticket->priority,
                'severity' => $ticket->severity,
                'reporter_id' => auth()->id(),
                'assignee_id' => $validated['user_id'],
                'company_id' => $ticket->company_id,
                'store_id' => $validated['store_id'] ?? null,
                'category_id' => $ticket->category_id,
                'sub_category_id' => $ticket->sub_category_id,
                'item_id' => $ticket->item_id,
                'department' => $ticket->department,
                'parent_id' => $ticket->id,
                'created_at' => now('Asia/Manila'),
            ]);

            if ($hasSchedule) {
                $schedule = Schedule::create([
                    'user_id' => $validated['user_id'],
                    'status' => $validated['status'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'pickup_start' => $validated['pickup_start'] ?? null,
                    'pickup_end' => $validated['pickup_end'] ?? null,
                    'backlogs_start' => $validated['backlogs_start'] ?? null,
                    'backlogs_end' => $validated['backlogs_end'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'created_at' => now('Asia/Manila'),
                ]);

                // Always create a scheduleStore entry for child tickets so the ticket_id link is preserved
                $schedule->scheduleStores()->create([
                    'store_id' => $validated['store_id'] ?? null,
                    'ticket_id' => $childTicket->id,
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'grace_period_minutes' => 30,
                    'remarks' => $validated['remarks'] ?? null,
                ]);
            }

            // Set parent to For Schedule when a new child is added
            $ticket->update(['status' => 'for_schedule']);

            return $childTicket;
        });

        return redirect()->back()->with('success', 'Child ticket and schedule created successfully.');
    }

    /**
     * Assign a schedule to an existing (child) ticket that was created without one.
     */
    public function assignSchedule(Request $request, Ticket $ticket)
    {
        if (!auth()->user()?->is_manager) {
            abort(403, 'Only managers can assign schedules.');
        }

        $existing = \App\Models\ScheduleStore::where('ticket_id', $ticket->id)->first();
        if ($existing) {
            return redirect()->back()->withErrors(['error' => 'This ticket already has a schedule assigned.']);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'required_unless:status,SL,VL,Restday,Holiday|nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ], [
            'store_id.required_unless' => 'Store is required before assigning a schedule.',
        ]);

        $newStart = \Carbon\Carbon::parse($validated['start_time']);
        $newEnd   = \Carbon\Carbon::parse($validated['end_time']);

        $conflictStore = \App\Models\ScheduleStore::whereHas('schedule', function($q) use ($validated) {
                $q->where('user_id', $validated['user_id']);
            })
            ->where('start_time', '<', $newEnd)
            ->where('end_time', '>', $newStart)
            ->first();

        $conflict = $conflictStore ?: Schedule::where('user_id', $validated['user_id'])
            ->whereDoesntHave('scheduleStores')
            ->where('start_time', '<', $newEnd)
            ->where('end_time', '>', $newStart)
            ->first();

        if ($conflict) {
            $from = $conflict->start_time->format('M d, Y h:i A');
            $to   = $conflict->end_time->format('M d, Y h:i A');
            return redirect()->back()->withErrors([
                'schedule_conflict' => "A schedule already exists for this user from {$from} to {$to}. Please choose a different date/time.",
            ]);
        }

        DB::transaction(function () use ($validated, $ticket) {
            $schedule = Schedule::create([
                'user_id' => $validated['user_id'],
                'status' => $validated['status'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'pickup_start' => $validated['pickup_start'] ?? null,
                'pickup_end' => $validated['pickup_end'] ?? null,
                'backlogs_start' => $validated['backlogs_start'] ?? null,
                'backlogs_end' => $validated['backlogs_end'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'created_at' => now('Asia/Manila'),
            ]);

            $schedule->scheduleStores()->create([
                'store_id' => $validated['store_id'] ?? null,
                'ticket_id' => $ticket->id,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'grace_period_minutes' => 30,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            if ($validated['store_id'] ?? null) {
                $ticket->update(['store_id' => $validated['store_id']]);
            }
        });

        return redirect()->back()->with('success', 'Schedule assigned successfully.');
    }

    /**
     * Update an existing schedule attached to a (child) ticket. Managers only.
     */
    public function updateSchedule(Request $request, Ticket $ticket)
    {
        if (!auth()->user()?->is_manager) {
            abort(403, 'Only managers can edit schedules.');
        }

        $scheduleStore = \App\Models\ScheduleStore::where('ticket_id', $ticket->id)->with('schedule')->first();
        if (!$scheduleStore || !$scheduleStore->schedule) {
            return redirect()->back()->withErrors(['error' => 'No schedule found for this ticket.']);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'required_unless:status,SL,VL,Restday,Holiday|nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ], [
            'store_id.required_unless' => 'Store is required for this schedule status.',
        ]);

        $schedule = $scheduleStore->schedule;
        $newStart = \Carbon\Carbon::parse($validated['start_time']);
        $newEnd   = \Carbon\Carbon::parse($validated['end_time']);

        // Conflict check excluding the current schedule
        $conflictStore = \App\Models\ScheduleStore::whereHas('schedule', function($q) use ($validated, $schedule) {
                $q->where('user_id', $validated['user_id'])
                  ->where('id', '!=', $schedule->id);
            })
            ->where('start_time', '<', $newEnd)
            ->where('end_time', '>', $newStart)
            ->first();

        $conflict = $conflictStore ?: Schedule::where('user_id', $validated['user_id'])
            ->where('id', '!=', $schedule->id)
            ->whereDoesntHave('scheduleStores')
            ->where('start_time', '<', $newEnd)
            ->where('end_time', '>', $newStart)
            ->first();

        if ($conflict) {
            $from = $conflict->start_time->format('M d, Y h:i A');
            $to   = $conflict->end_time->format('M d, Y h:i A');
            return redirect()->back()->withErrors([
                'schedule_conflict' => "A schedule already exists for this user from {$from} to {$to}. Please choose a different date/time.",
            ]);
        }

        DB::transaction(function () use ($validated, $ticket, $schedule, $scheduleStore) {
            $schedule->update([
                'user_id' => $validated['user_id'],
                'status' => $validated['status'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'pickup_start' => $validated['pickup_start'] ?? null,
                'pickup_end' => $validated['pickup_end'] ?? null,
                'backlogs_start' => $validated['backlogs_start'] ?? null,
                'backlogs_end' => $validated['backlogs_end'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            $scheduleStore->update([
                'store_id' => $validated['store_id'] ?? null,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $ticket->update([
                'assignee_id' => $validated['user_id'],
                'store_id' => $validated['store_id'] ?? $ticket->store_id,
            ]);
        });

        return redirect()->back()->with('success', 'Schedule updated successfully.');
    }

    /**
     * Replace the CC list for a (parent) ticket. Child tickets inherit
     * from their parent and cannot be edited directly here.
     */
    public function syncCcs(Request $request, Ticket $ticket)
    {
        if (!auth()->user()->can('tickets.edit')) {
            abort(403);
        }

        if ($ticket->parent_id) {
            return redirect()->back()->withErrors([
                'error' => 'CC list is managed on the parent ticket. Child tickets inherit from the parent.',
            ]);
        }

        $validated = $request->validate([
            'ccs' => 'array',
            'ccs.*.email' => 'required|email:rfc',
            'ccs.*.name' => 'nullable|string|max:255',
            'ccs.*.user_id' => 'nullable|exists:users,id',
        ]);

        $incoming = collect($validated['ccs'] ?? [])
            ->map(fn ($cc) => [
                'email' => strtolower(trim($cc['email'])),
                'name' => $cc['name'] ?? null,
                'user_id' => $cc['user_id'] ?? null,
            ])
            ->unique('email')
            ->values();

        DB::transaction(function () use ($ticket, $incoming) {
            $ticket->ccs()->delete();
            foreach ($incoming as $cc) {
                $ticket->ccs()->create([
                    'email' => $cc['email'],
                    'name' => $cc['name'],
                    'user_id' => $cc['user_id'],
                    'created_by' => auth()->id(),
                ]);
            }
        });

        return redirect()->back()->with('success', 'CC list updated.');
    }

    /**
     * Apply effective CCs to a pending mail, excluding addresses already in $alreadySentTo.
     * Returns the email addresses that were added as CCs.
     */
    private function attachTicketCcs($pendingMail, Ticket $ticket, array $alreadySentTo = []): array
    {
        $effective = $ticket->effectiveCcs();
        $excluded = collect($alreadySentTo)->map(fn ($e) => strtolower((string) $e))->all();
        $ccEmails = $effective
            ->pluck('email')
            ->filter()
            ->map(fn ($e) => strtolower($e))
            ->unique()
            ->reject(fn ($e) => in_array($e, $excluded, true))
            ->values()
            ->all();

        if (!empty($ccEmails)) {
            $pendingMail->cc($ccEmails);
        }

        return $ccEmails;
    }

    /**
     * Duplicate a ticket, copying all fields into a new open ticket.
     */
    public function duplicate(Ticket $ticket)
    {
        $newTicket = DB::transaction(function () use ($ticket) {
            return Ticket::create([
                'title'           => 'Copy of ' . $ticket->title,
                'description'     => $ticket->description,
                'type'            => $ticket->type,
                'status'          => 'open',
                'priority'        => $ticket->priority,
                'severity'        => $ticket->severity,
                'company_id'      => $ticket->company_id,
                'store_id'        => $ticket->store_id,
                'category_id'     => $ticket->category_id,
                'sub_category_id' => $ticket->sub_category_id,
                'item_id'         => $ticket->item_id,
                'assignee_id'     => $ticket->assignee_id,
                'reporter_id'     => $ticket->reporter_id,
                'sender_name'     => $ticket->sender_name,
                'sender_email'    => $ticket->sender_email,
                'department'      => $ticket->department,
                'created_at'      => now('Asia/Manila'),
            ]);
        });

        return redirect()->route('tickets.edit', $newTicket->id)
            ->with('success', "Ticket duplicated successfully as {$newTicket->ticket_key}.");
    }

    /**
     * Store a new comment for the ticket.
     */
    public function storeComment(Request $request, Ticket $ticket)
    {
        // LOCK-OUT LOGIC: If ticket is closed, do not allow new comments via UI
        if ($ticket->status === 'closed') {
            return redirect()->back()->withErrors(['error' => 'This ticket is already closed and cannot accept new comments.']);
        }

        $ticket->loadMissing('item');
        $isTerminalStatusChange = in_array($request->input('status'), ['resolved', 'closed'], true);
        $requiresRcaOnResolve = (bool) $ticket->item?->requires_rca_on_resolve;

        $request->validate([
            'comment_text' => [Rule::requiredIf(!$isTerminalStatusChange), 'nullable', 'string'],
            'is_internal' => 'nullable|boolean',
            'status' => 'nullable|string|in:open,for_schedule,in_progress,resolved,closed,waiting_service_provider,waiting_client_feedback',
            'action_taken' => [Rule::requiredIf($isTerminalStatusChange), 'nullable', 'string'],
            'root_cause_analysis' => [Rule::requiredIf($isTerminalStatusChange && $requiresRcaOnResolve), 'nullable', 'string'],
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:1024000',
        ]);

        $commentText = trim((string) $request->comment_text);
        $actionTaken = trim((string) $request->input('action_taken'));
        $rootCauseAnalysis = trim((string) $request->input('root_cause_analysis'));

        if ($isTerminalStatusChange) {
            $commentText .= ($commentText !== '' ? "\n\n" : '') . "Action Taken:\n" . $actionTaken;

            if ($rootCauseAnalysis !== '') {
                $commentText .= "\n\nRoot Cause Analysis (RCA):\n" . $rootCauseAnalysis;
            }
        }

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => $commentText,
            'is_internal' => $request->boolean('is_internal', false),
            'user_id' => auth()->id(),
            'action_taken' => $actionTaken !== '' ? $actionTaken : null,
            'root_cause_analysis' => $rootCauseAnalysis !== '' ? $rootCauseAnalysis : null,
            'created_at' => now('Asia/Manila'),
        ]);

        // Load user relationship immediately to avoid null errors in emails
        $comment->load('user');

        $kbGenerationStatus = null;

        // HANDLE AUTOMATIC STATUS CHANGE
        if ($request->filled('status')) {
            $oldStatus = $ticket->status;
            $newStatus = $request->status;
            
            if ($oldStatus !== $newStatus) {
                $ticket->update(['status' => $newStatus]);
                
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => auth()->id(),
                    'column_changed' => 'status',
                    'old_value' => $oldStatus,
                    'new_value' => $newStatus,
                    'changed_at' => now('Asia/Manila'),
                ]);

                // SYNC TO PARENT IF APPLICABLE
                if ($ticket->parent_id) {
                    $this->syncParentStatus($ticket->parent_id, $newStatus);
                }

                if ($newStatus === 'closed') {
                    $kbGenerationStatus = $this->ticketKnowledgeBaseService->createDraftFromClosedTicket($ticket, $comment);
                }
            }
        }

        $this->updateFirstResponseMetric($ticket, $comment);

        // --- NOTIFICATIONS ---
        // Skip all notifications if it's an internal note
        if ($comment->is_internal) {
            $this->storeCommentAttachments($request, $ticket, $comment);
            return redirect()->back()->with('success', 'Internal note added.');
        }

        $this->notifyTicketCommentRecipients($ticket, $comment);
        $this->storeCommentAttachments($request, $ticket, $comment);

        return redirect()->back()->with('success', $this->commentSuccessMessage($kbGenerationStatus));
    }

    private function commentSuccessMessage(?string $kbGenerationStatus): string
    {
        return match ($kbGenerationStatus) {
            TicketKnowledgeBaseService::CREATED => 'Comment added, status updated, and KB draft created.',
            TicketKnowledgeBaseService::DUPLICATE => 'Comment added and status updated. KB draft skipped because an existing article already covers this concern.',
            TicketKnowledgeBaseService::SKIPPED_NO_ITEM => 'Comment added and status updated. KB draft skipped because no Item is selected.',
            default => 'Comment added and status updated.',
        };
    }

    public function getCategories()
    {
        return response()->json(\App\Models\Category::where('is_active', true)->orderBy('name')->get());
    }

    public function getSubCategories(Request $request)
    {
        $categoryId = $request->query('category_id');
        if (!$categoryId) return response()->json([]);

        $subCategoryIds = \App\Models\Item::where('category_id', $categoryId)
            ->whereNotNull('sub_category_id')
            ->distinct()
            ->pluck('sub_category_id');
            
        $subCategories = \App\Models\SubCategory::whereIn('id', $subCategoryIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($subCategories);
    }

    public function getItems(Request $request)
    {
        $categoryId = $request->query('category_id') ?? $request->input('category_id');
        $subCategoryId = $request->query('sub_category_id') ?? $request->input('sub_category_id');

        $query = \App\Models\Item::with(['category', 'subCategory'])->where('is_active', true)->orderBy('name');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($subCategoryId) {
            $query->where('sub_category_id', $subCategoryId);
        }

        $items = $query->get()->map(function($item) {
            $cat = $item->category->name ?? 'N/A';
            $sub = $item->subCategory->name ?? 'N/A';
            $item->display_name = "{$cat} | {$sub} | {$item->name}";
            return $item;
        });

        return response()->json($items);
    }
    public function downloadAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_storage_path)) abort(404, 'File not found.');
        return Storage::disk('public')->download($attachment->file_storage_path, $attachment->file_name);
    }

    public function sync(\App\Services\EmailTicketService $service)
    {
        $result = $service->fetchAndProcess();
        return response()->json($result);
    }

    public function bulkUpdate(Request $request)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'ticket_ids'      => 'required|array|min:1',
            'ticket_ids.*'    => 'exists:tickets,id',
            'store_id'        => 'nullable|exists:stores,id',
            'category_id'     => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'item_id'         => 'nullable|exists:items,id',
            'assignee_id'     => 'nullable|exists:users,id',
        ]);

        $fields  = ['store_id', 'category_id', 'sub_category_id', 'item_id', 'assignee_id'];
        $updates = collect($fields)
            ->filter(fn($k) => $request->has($k))
            ->mapWithKeys(fn($k) => [$k => $validated[$k]])
            ->all();

        if (isset($updates['item_id'])) {
            $item = \App\Models\Item::find($updates['item_id']);
            if ($item) {
                $updates['category_id'] = $item->category_id;
                $updates['sub_category_id'] = $item->sub_category_id;
            }
        }

        if (empty($updates)) {
            return redirect()->back()->withErrors(['bulk' => 'No fields selected for update.']);
        }

        if (!empty($updates['item_id'])) {
            $item = \App\Models\Item::find($updates['item_id']);
            if ($item) {
                $updates['priority'] = strtolower($item->priority);
            }
        }

        $count = Ticket::whereIn('id', $validated['ticket_ids'])->update($updates);

        return redirect()->back()->with('success', "{$count} ticket(s) updated successfully.");
    }

    public function bulkResponse(Request $request)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'ticket_ids' => 'required|array|min:1',
            'ticket_ids.*' => 'exists:tickets,id',
            'comment_text' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:1024000',
        ]);

        $commentText = trim((string) ($validated['comment_text'] ?? ''));
        $attachments = $request->file('attachments', []) ?: [];

        if ($commentText === '' && count($attachments) === 0) {
            return redirect()->back()->withErrors(['bulk_response' => 'Response text or at least one attachment is required.']);
        }

        $ticketIds = collect($validated['ticket_ids'])->unique()->values();
        $tickets = Ticket::whereIn('id', $ticketIds)
            ->with(['reporter', 'assignee', 'slaMetric'])
            ->get();

        $closedTickets = $tickets->where('status', 'closed');
        if ($closedTickets->isNotEmpty()) {
            $ticketKeys = $closedTickets->pluck('ticket_key')->filter()->take(5)->implode(', ');
            $suffix = $ticketKeys ? " Closed ticket(s): {$ticketKeys}." : '';

            return redirect()->back()->withErrors([
                'bulk_response' => 'Selected tickets include closed tickets. Please deselect closed tickets before responding.' . $suffix,
            ]);
        }

        $comments = DB::transaction(function () use ($tickets, $commentText, $request) {
            return $tickets->map(function (Ticket $ticket) use ($commentText, $request) {
                $comment = TicketComment::create([
                    'ticket_id' => $ticket->id,
                    'comment_text' => $commentText,
                    'is_internal' => false,
                    'user_id' => auth()->id(),
                    'created_at' => now('Asia/Manila'),
                ]);

                $comment->load('user');
                $this->updateFirstResponseMetric($ticket, $comment);
                $this->storeCommentAttachments($request, $ticket, $comment);

                return [
                    'ticket' => $ticket,
                    'comment' => $comment,
                ];
            });
        });

        foreach ($comments as $entry) {
            $this->notifyTicketCommentRecipients($entry['ticket'], $entry['comment']);
        }

        return redirect()->back()->with('success', "{$comments->count()} ticket response(s) added successfully.");
    }

    /**
     * Bulk create child tickets for multiple parent tickets.
     */
    public function bulkStoreChild(Request $request)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'tickets'                     => 'required|array|min:1',
            'tickets.*.parent_id'         => 'required|exists:tickets,id',
            'tickets.*.user_id'           => 'required|exists:users,id',
            'tickets.*.status'            => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'tickets.*.start_time'        => 'required|date',
            'tickets.*.end_time'          => 'required|date|after_or_equal:tickets.*.start_time',
            'tickets.*.pickup_start'      => 'nullable|string',
            'tickets.*.pickup_end'        => 'nullable|string',
            'tickets.*.backlogs_start'    => 'nullable|string',
            'tickets.*.backlogs_end'      => 'nullable|string',
            'tickets.*.remarks'           => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['tickets'] as $entry) {
                $parentTicket = Ticket::findOrFail($entry['parent_id']);
                
                // Check for schedule conflicts (optional but recommended)
                $newStart = \Carbon\Carbon::parse($entry['start_time']);
                $newEnd   = \Carbon\Carbon::parse($entry['end_time']);
                
                // We'll proceed with creation; conflict check can be added if strictness is needed across all entries

                $schedule = Schedule::create([
                    'user_id'        => $entry['user_id'],
                    'status'         => $entry['status'],
                    'start_time'     => $entry['start_time'],
                    'end_time'       => $entry['end_time'],
                    'pickup_start'   => $entry['pickup_start'] ?? null,
                    'pickup_end'     => $entry['pickup_end'] ?? null,
                    'backlogs_start' => $entry['backlogs_start'] ?? null,
                    'backlogs_end'   => $entry['backlogs_end'] ?? null,
                    'remarks'        => $entry['remarks'] ?? null,
                    'created_at'     => now('Asia/Manila'),
                ]);

                // Generate Ticket Key
                $companyCode = $parentTicket->company->code;
                $maxNumber = Ticket::withTrashed()
                    ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                    ->selectRaw('MAX(TRY_CAST(SUBSTRING(ticket_key, LEN(?) + 2, LEN(ticket_key)) AS INT)) as max_num', [$companyCode])
                    ->value('max_num');
                $nextNumber = ($maxNumber ?? 0) + 1;

                $childTicket = Ticket::create([
                    'ticket_key'      => "{$companyCode}-{$nextNumber}",
                    'title'           => "Child: {$parentTicket->title}",
                    'description'     => "Child of {$parentTicket->ticket_key}. Bulk scheduled. Remarks: " . ($entry['remarks'] ?? ''),
                    'type'            => $parentTicket->type,
                    'status'          => 'for_schedule',
                    'priority'        => $parentTicket->priority,
                    'severity'        => $parentTicket->severity,
                    'reporter_id'     => auth()->id(),
                    'assignee_id'     => $entry['user_id'],
                    'company_id'      => $parentTicket->company_id,
                    'store_id'        => $parentTicket->store_id,
                    'category_id'     => $parentTicket->category_id,
                    'sub_category_id' => $parentTicket->sub_category_id,
                    'item_id'         => $parentTicket->item_id,
                    'department'      => $parentTicket->department,
                    'parent_id'       => $parentTicket->id,
                    'created_at'      => now('Asia/Manila'),
                ]);

                $schedule->scheduleStores()->create([
                    'store_id'             => $parentTicket->store_id,
                    'ticket_id'            => $childTicket->id,
                    'start_time'           => $entry['start_time'],
                    'end_time'             => $entry['end_time'],
                    'grace_period_minutes' => 30,
                    'remarks'              => $entry['remarks'],
                ]);

                // Set parent to For Schedule
                $parentTicket->update(['status' => 'for_schedule']);
            }
        });

        return redirect()->back()->with('success', count($validated['tickets']) . ' child tickets and schedules created successfully.');
    }

    /**
     * Split a ticket into multiple concerns.
     */
    public function split(Request $request, Ticket $ticket)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'original_title' => 'required|string|max:255',
            'new_titles'     => 'required|array',
            'new_titles.*'   => 'required|string|max:255',
        ]);

        $newTicketsCount = 0;

        DB::transaction(function () use ($ticket, $validated, &$newTicketsCount) {
            // 1. Update the original ticket's title
            $oldTitle = $ticket->title;
            $ticket->update(['title' => $validated['original_title']]);

            TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'comment_text' => "Ticket split. Original title was changed from \"{$oldTitle}\" to \"{$validated['original_title']}\".",
                'is_internal' => true,
                'created_at' => now('Asia/Manila'),
            ]);

            // 2. Create new tickets for each additional concern
            foreach ($validated['new_titles'] as $newTitle) {
                $newTicket = $ticket->replicate();
                $newTicket->title = $newTitle;
                $newTicket->status = 'open'; // Reset status for split tickets
                $newTicket->ticket_key = null; // Let observer generate a new key
                $newTicket->created_at = now('Asia/Manila');
                $newTicket->save();

                TicketComment::create([
                    'ticket_id' => $newTicket->id,
                    'user_id' => auth()->id(),
                    'comment_text' => "Split from ticket #{$ticket->ticket_key}.",
                    'is_internal' => true,
                    'created_at' => now('Asia/Manila'),
                ]);

                // Notify requester about the new ticket
                $this->notifyTicketCreated($newTicket);
                
                $newTicketsCount++;
            }
        });

        return redirect()->back()->with('success', "Ticket split into " . ($newTicketsCount + 1) . " tickets.");
    }

    /**
     * Merge multiple tickets into one.
     */
    public function merge(Request $request)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'parent_id'  => 'required|exists:tickets,id',
            'ticket_ids' => 'required|array|min:2',
            'ticket_ids.*' => 'exists:tickets,id',
        ]);

        if (!in_array($validated['parent_id'], $validated['ticket_ids'])) {
            return redirect()->back()->withErrors(['merge' => 'Parent ticket must be one of the selected tickets.']);
        }

        $parent = Ticket::find($validated['parent_id']);
        $childIds = array_diff($validated['ticket_ids'], [$parent->id]);
        $children = Ticket::whereIn('id', $childIds)->get();

        DB::transaction(function () use ($parent, $children) {
            $childKeys = $children->pluck('ticket_key')->implode(', ');

            // 1. Update children
            foreach ($children as $child) {
                $child->update([
                    'status' => 'closed',
                    'parent_id' => $parent->id,
                ]);

                TicketComment::create([
                    'ticket_id' => $child->id,
                    'user_id' => auth()->id(),
                    'comment_text' => "Ticket merged into #{$parent->ticket_key}.",
                    'is_internal' => true,
                    'created_at' => now('Asia/Manila'),
                ]);
            }

            // 2. Add comment to parent
            TicketComment::create([
                'ticket_id' => $parent->id,
                'user_id' => auth()->id(),
                'comment_text' => "Tickets merged into this: {$childKeys}.",
                'is_internal' => true,
                'created_at' => now('Asia/Manila'),
            ]);

            // 3. Notify all unique requesters
            $this->notifyMerge($parent, $children);
        });

        return redirect()->back()->with('success', "Tickets merged successfully into #{$parent->ticket_key}.");
    }

    private function updateFirstResponseMetric(Ticket $ticket, TicketComment $comment): void
    {
        if ($comment->is_internal) {
            return;
        }

        $ticket->loadMissing('slaMetric');
        $metric = $ticket->slaMetric;

        if ($metric && !$metric->first_response_at && auth()->id() !== $ticket->reporter_id) {
            $now = now('Asia/Manila');
            $metric->update([
                'first_response_at' => $now,
                'is_response_breached' => $metric->response_target_at && $now->gt($metric->response_target_at),
            ]);
        }
    }

    private function storeCommentAttachments(Request $request, Ticket $ticket, TicketComment $comment): void
    {
        if (!$request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $fileName = now('Asia/Manila')->format('YmdHis') . '_' . Str::uuid() . '_' . $file->getClientOriginalName();
            $filePath = str_replace('\\', '/', $file->storeAs('ticket-attachments', $fileName, 'public'));

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'comment_id' => $comment->id,
                'file_name' => $file->getClientOriginalName(),
                'file_storage_path' => $filePath,
                'file_size_bytes' => $file->getSize(),
            ]);
        }
    }

    private function notifyTicketCommentRecipients(Ticket $ticket, TicketComment $comment): void
    {
        $ticket->loadMissing(['reporter', 'assignee']);
        $comment->loadMissing('user');
        $commenterId = auth()->id();
        $recipients = collect();

        if ($ticket->assignee && $ticket->assignee->email) {
            $recipients->push([
                'email' => strtolower($ticket->assignee->email),
                'name' => $ticket->assignee->name,
                'id' => $ticket->assignee->id,
            ]);
        }

        if ($ticket->reporter && $ticket->reporter->email) {
            $recipients->push([
                'email' => strtolower($ticket->reporter->email),
                'name' => $ticket->reporter->name,
                'id' => $ticket->reporter->id,
            ]);
        }

        if ($ticket->sender_email) {
            $recipients->push([
                'email' => strtolower($ticket->sender_email),
                'name' => $ticket->sender_name ?? 'External User',
                'id' => null,
            ]);
        }

        $recipients = $recipients->filter(function ($recipient) use ($commenterId) {
            return ($recipient['id'] != $commenterId) && !empty($recipient['email']);
        })->unique('email');

        $supportEmail = \App\Models\Setting::get('imap_username');

        \Illuminate\Support\Facades\Log::info("Notifying recipients for comment on ticket {$ticket->ticket_key}: " . $recipients->pluck('email')->implode(', '));

        $recipientList = $recipients->values()->all();
        $primaryEmails = array_column($recipientList, 'email');

        foreach ($recipientList as $index => $recipient) {
            $mail = new TicketCommentAdded($ticket, $comment, $recipient['name']);

            if ($supportEmail) {
                $mail->replyTo($supportEmail);
            }

            $pending = Mail::to($recipient['email']);

            // Only attach the CC list to the first email to avoid CCs
            // receiving duplicate copies for each primary recipient.
            if ($index === 0) {
                $this->attachTicketCcs($pending, $ticket, $primaryEmails);
            }

            $pending->send($mail);
        }
    }

    /**
     * Helper to send merge notification
     */
    private function notifyMerge($parent, $children)
    {
        $allTickets = collect([$parent])->concat($children);
        $recipients = collect();

        foreach ($allTickets as $t) {
            if ($t->reporter && $t->reporter->email) {
                $recipients->push(['email' => strtolower($t->reporter->email), 'name' => $t->reporter->name]);
            } elseif ($t->sender_email) {
                $recipients->push(['email' => strtolower($t->sender_email), 'name' => $t->sender_name ?? 'External User']);
            }
        }

        $recipients = $recipients->unique('email');

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->send(new TicketMergedNotification($parent, $children, $recipient['name']));
        }
    }

    /**
     * Helper to send new ticket notification (reused logic from store)
     */
    private function notifyTicketCreated(Ticket $ticket)
    {
        $sentTo = [];

        // Notify requester
        if ($ticket->reporter && $ticket->reporter->email) {
            Mail::to($ticket->reporter->email)->send(new NewTicketCreated($ticket, $ticket->reporter->name));
            $sentTo[] = $ticket->reporter->email;
        } elseif ($ticket->sender_email) {
            Mail::to($ticket->sender_email)->send(new NewTicketCreated($ticket, $ticket->sender_name ?? 'External User'));
            $sentTo[] = $ticket->sender_email;
        }

        // Notify assignee
        if ($ticket->assignee && $ticket->assignee->email && !in_array($ticket->assignee->email, $sentTo)) {
            $shouldNotifyAssignee = $ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists();
            if ($shouldNotifyAssignee) {
                Mail::to($ticket->assignee->email)->send(new NewTicketCreated($ticket, $ticket->assignee->name));
                $sentTo[] = $ticket->assignee->email;
            }
        }

        // Notify admins/others
        $this->notifyTicketCreationWatchers($ticket, $sentTo);
    }

    private function notifyTicketCreationWatchers(Ticket $ticket, array &$sentTo): void
    {
        $ticket->loadMissing('company');

        $usersToNotify = User::active()
            ->whereHas('roles', function ($q) {
                $q->where('notify_on_ticket_create', true);
            })
            ->with('roles.companies')
            ->get();

        foreach ($usersToNotify as $userToNotify) {
            $email = strtolower((string) $userToNotify->email);

            if (!$email || in_array($email, $sentTo, true)) {
                continue;
            }

            if (!$this->userCanReceiveTicketCreationNotification($userToNotify, $ticket)) {
                continue;
            }

            Mail::to($email)->send(new NewTicketCreated($ticket, $userToNotify->name));
            $sentTo[] = $email;
        }
    }

    private function userCanReceiveTicketCreationNotification(User $user, Ticket $ticket): bool
    {
        if (!$user->email || !$user->is_active) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ($user->id === $ticket->reporter_id || $user->id === $ticket->assignee_id) {
            return false;
        }

        $allowedCompanyIds = collect();

        foreach ($user->roles as $role) {
            if ($role->companies) {
                $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
            }
        }

        if ($user->company_id) {
            $allowedCompanyIds->push($user->company_id);
        }

        return $allowedCompanyIds->unique()->contains($ticket->company_id);
    }
}
