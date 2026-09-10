<?php

namespace App\Http\Controllers;

use App\Mail\GoogleRegistrationApproved;
use App\Models\DepartmentNode;
use App\Models\Store;
use App\Models\User;
use App\Services\AccountArchiveService;
use App\Services\OrganizationReferenceService;
use App\Support\UserDeletionBlockers;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Models\Role;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class UserController extends Controller
{
    /** Default login password assigned to every new user created via import. */
    private const DEFAULT_IMPORT_PASSWORD = 'Password@123';

    public function __construct(private OrganizationReferenceService $organizationReferences)
    {
    }

    public function index(Request $request)
    {
        $query = User::query()
            ->select([
                'id', 'name', 'employee_id_no', 'email', 'department', 'org_path', 'department_id',
                'department_node_id', 'position', 'date_hired', 'is_active',
                'is_manager', 'google_id',
            ])
            ->with([
                'roles:id,name',
                'managers:id,name',
            ])
            ->withCount('stores');

        $this->applyIndexFilters($query, $request);

        $perPage = max(10, min(100, $request->integer('per_page', 10)));
        $users = $query->orderBy('name')->paginate($perPage)->withQueryString();
        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => fn () => Role::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
                'role' => $request->input('role', ''),
            ],
        ]);
    }

    /**
     * Search / status / role filters shared by the index listing and the export
     * so a download always mirrors what the page is showing.
     */
    private function applyIndexFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('employee_id_no', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('department', 'like', "%{$request->search}%")
                  ->orWhere('org_path', 'like', "%{$request->search}%")
                  ->orWhere('position', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false)
                    ->where(fn ($q) => $q->whereNull('google_id')->orWhereHas('roles')),
                'pending_approval' => $query->whereNotNull('google_id')
                    ->where('is_active', false)
                    ->whereDoesntHave('roles'),
                default => null,
            };
        }

        if ($request->filled('role')) {
            $role = $request->input('role');
            if ($role === 'none') {
                $query->whereDoesntHave('roles');
            } else {
                $query->whereHas('roles', fn ($q) => $q->where('name', $role));
            }
        }
    }

    /**
     * Export the current (filtered) user list to Excel. Columns match the
     * import template so an export can be edited and fed back in.
     */
    public function export(Request $request)
    {
        $query = User::query()
            ->select([
                'id', 'name', 'employee_id_no', 'email', 'department', 'org_path',
                'department_node_id', 'position', 'date_hired', 'is_active', 'is_manager', 'google_id',
            ])
            ->with(['roles:id,name', 'managers:id,name,email', 'stores:id,code,name']);

        $this->applyIndexFilters($query, $request);

        $users = $query->orderBy('name')->get();

        // Placement labels straight from the live tree, so the exported value is
        // one the importer can match back (the denormalized columns can be stale).
        $nodeLabels = collect($this->departmentNodeOptions())->pluck('label', 'node_id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Users');

        $headers = [
            'name', 'employee_id_no', 'email', 'role', 'department', 'position',
            'date_hired', 'is_manager', 'is_active', 'assigned_stores', 'reports_to',
        ];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        $row = 2;
        foreach ($users as $user) {
            $values = [
                $user->name,
                $user->employee_id_no,
                $user->email,
                $user->roles->pluck('name')->implode('; '),
                // Same "Department > Node > Sub-node" label the importer matches on.
                $nodeLabels[$user->department_node_id]
                    ?? collect([$user->department, $user->org_path])->filter()->implode(' > '),
                $user->position,
                $user->date_hired ? Carbon::parse($user->date_hired)->format('Y-m-d') : '',
                $user->is_manager ? 'Yes' : 'No',
                $user->is_active ? 'Yes' : 'No',
                $user->stores->pluck('code')->implode('; '),
                $user->managers->pluck('email')->implode('; '),
            ];

            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1) . $row,
                    (string) ($value ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            $row++;
        }

        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'users-export-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function formOptions(Request $request)
    {
        abort_unless(
            $request->user()->can('users.create') || $request->user()->can('users.edit'),
            403
        );

        return response()->json([
            'stores' => Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name]),
            'managers' => User::query()
                ->where('is_manager', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'department_tree' => $this->organizationReferences->tree(activeOnly: true),
        ]);
    }

    public function details(Request $request, User $user)
    {
        abort_unless(
            $request->user()->can('users.view') || $request->user()->can('users.edit'),
            403
        );

        $user->load([
            'stores:id,name,code',
            'managers:id,name',
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'store_ids' => $user->stores->pluck('id')->values(),
                'manager_ids' => $user->managers->pluck('id')->values(),
                'stores' => $user->stores->map(fn (Store $store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                ])->values(),
                'creator' => $user->creator,
                'updater' => $user->updater,
                'created_by' => $user->created_by,
                'updated_by' => $user->updated_by,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    public function store(Request $request)
    {
        // Runs before validation on purpose: `unique:users` queries the table
        // directly, so an ARCHIVED account still owns its email and employee ID,
        // and its "already been taken" would point at a row that is nowhere to be
        // seen on this page. Name the archive instead.
        $this->rejectArchivedIdentityClash($request->input('email'), $request->input('employee_id_no'));

        $request->validate([
            'name' => 'required|string|max:255',
            'employee_id_no' => 'required|string|max:255|unique:users,employee_id_no',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department_node_id' => 'nullable|integer|exists:department_nodes,id',
            'position' => 'nullable|string|max:255',
            'date_hired' => 'nullable|date',
            'is_active' => 'boolean',
            'is_manager' => 'boolean',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'manager_ids' => 'nullable|array',
            'manager_ids.*' => 'exists:users,id',
        ]);

        $organizationPayload = $this->organizationPayloadFromRequest($request);

        $user = User::create([
            'name' => $request->name,
            'employee_id_no' => $request->employee_id_no,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            ...$organizationPayload,
            'position' => $request->position,
            'date_hired' => $request->date_hired,
            'is_active' => $request->input('is_active', true),
            'is_manager' => $request->input('is_manager', false),
            'email_verified_at' => now(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $user->assignRole($request->role);

        if ($request->has('store_ids')) {
            $user->stores()->sync($request->store_ids);
        }

        if ($request->has('manager_ids')) {
            $user->managers()->sync($request->manager_ids);
        }

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'employee_id_no' => 'required|string|max:255|unique:users,employee_id_no,' . $user->id,
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|exists:roles,name',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department_node_id' => 'nullable|integer|exists:department_nodes,id',
            'position' => 'nullable|string|max:255',
            'date_hired' => 'nullable|date',
            'is_active' => 'boolean',
            'is_manager' => 'boolean',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'manager_ids' => 'nullable|array',
            'manager_ids.*' => 'exists:users,id',
            'notify_user_approval' => 'boolean',
        ]);

        $organizationPayload = $this->organizationPayloadFromRequest($request);
        $wasPendingGoogleRegistration = $this->isPendingGoogleRegistration($user);

        $user->name = $request->name;
        $user->employee_id_no = $request->employee_id_no;
        $user->email = $request->email;
        $user->forceFill($organizationPayload);
        $user->position = $request->position;
        $user->date_hired = $request->date_hired;
        $user->is_active = $request->boolean('is_active');
        $user->is_manager = $request->boolean('is_manager');
        $user->updated_by = auth()->id();
        $user->save();

        $user->syncRoles([$request->role]);
        Cache::forget('user_permissions_' . $user->id . '_' . ($user->updated_at?->timestamp ?? 0));

        // Update stores assignment
        if ($request->has('store_ids')) {
            $user->stores()->sync($request->store_ids);
        } else {
            $user->stores()->detach();
        }

        // Update managers assignment
        if ($request->has('manager_ids')) {
            $user->managers()->sync($request->manager_ids);
        } else {
            $user->managers()->detach();
        }

        if (
            $wasPendingGoogleRegistration
            && $this->isApprovedGoogleRegistration($user)
            && $request->boolean('notify_user_approval')
        ) {
            $this->notifyGoogleRegistrationApproved($user);
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    /**
     * Archive, not destroy. The row is soft-deleted and recoverable from
     * Settings → Account Archive; when the account is a mobile-app member their
     * paired `customers` record is archived in the same transaction, so /users
     * and /stamps → Customers never disagree about who exists.
     *
     * The reference cleanup this method used to perform now runs at purge time
     * in AccountArchiveService — clearing tickets and attendance up front would
     * have made the archive unrecoverable.
     */
    public function destroy(User $user, AccountArchiveService $archive)
    {
        if ($user->id === auth()->id()) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete the account you are signed in with.',
            ]);
        }

        $archived = $archive->archiveUser($user, auth()->id());

        $message = $archived['customer']
            ? "User archived. Their loyalty customer record \"{$archived['customer']}\" was archived too."
            : 'User archived. Restore it from Settings → Account Archive.';

        return redirect()->back()->with('success', $message);
    }

    /**
     * Refuse a new account whose email or employee ID is still held by an
     * archived one, and say where that row is so the admin can restore or purge
     * it instead of guessing at a phantom duplicate.
     */
    private function rejectArchivedIdentityClash(?string $email, ?string $employeeId): void
    {
        $archived = User::onlyTrashed()
            ->where(function ($query) use ($email, $employeeId) {
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($employeeId) {
                    $query->orWhere('employee_id_no', $employeeId);
                }
            })
            ->when(!$email && !$employeeId, fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if (!$archived) {
            return;
        }

        $field = ($email && $archived->email === $email) ? 'email' : 'employee_id_no';
        $label = $field === 'email' ? 'email address' : 'employee ID';

        throw ValidationException::withMessages([
            $field => "That {$label} belongs to \"{$archived->name}\", an archived account. Restore or purge it from Settings → Account Archive first.",
        ]);
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Password reset successfully.');
    }

    private function isPendingGoogleRegistration(User $user): bool
    {
        return filled($user->google_id) && ! $user->roles()->exists();
    }

    private function isApprovedGoogleRegistration(User $user): bool
    {
        return filled($user->google_id) && (bool) $user->is_active && $user->roles()->exists();
    }

    private function organizationPayloadFromRequest(Request $request): array
    {
        $nodeId = $request->input('department_node_id');
        $deptId = $request->input('department_id');

        if (!$nodeId && !$deptId) {
            return $this->organizationReferences->clearPayload();
        }

        $payload = $this->organizationReferences->payloadFromNodeId(
            $nodeId ? (int) $nodeId : null
        );

        if (!$nodeId && $deptId) {
            $dept = \App\Models\Department::find($deptId);
            if ($dept && $dept->is_active) {
                $payload['department'] = $dept->name;
                $payload['department_id'] = $dept->id;
            }
        }

        if ($request->filled('department_id') && is_null($payload['department_id'])) {
            throw ValidationException::withMessages([
                'department_id' => 'Selected department is invalid or inactive.',
            ]);
        }

        return $payload;
    }

    private function notifyGoogleRegistrationApproved(User $user): void
    {
        app(\App\Services\NotificationService::class)->notifyApproval(
            [$user->id],
            auth()->id(),
            'approved',
            'Account approved',
            'Your account registration has been approved. Welcome aboard!',
            route('dashboard', [], false),
            'user_registration:' . $user->id,
            'success'
        );

        try {
            Mail::to($user->email)->send(new GoogleRegistrationApproved($user));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Download the Excel user-import template. Columns that map to known
     * records (role, department placement, stores, reports-to) carry in-cell
     * dropdowns sourced from a hidden "Lists" sheet.
     */
    public function template()
    {
        $roles = Role::orderBy('name')->pluck('name')->values();
        $departments = collect($this->departmentNodeOptions())->pluck('label')->values();
        $stores = Store::where('is_active', true)->orderBy('code')->get(['code', 'name']);
        $managers = User::where('is_manager', true)->where('is_active', true)
            ->orderBy('name')->get(['name', 'email']);

        $spreadsheet = new Spreadsheet();

        // ── Hidden Lists sheet (dropdown sources) ───────────────────────
        $lists = $spreadsheet->createSheet(1);
        $lists->setTitle('Lists');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $lists->setCellValue('A1', 'Roles');
        foreach ($roles as $i => $r) {
            $lists->setCellValue('A' . ($i + 2), $r);
        }
        $lists->setCellValue('B1', 'Departments');
        foreach ($departments as $i => $d) {
            $lists->setCellValue('B' . ($i + 2), $d);
        }
        $lists->setCellValue('C1', 'Stores');
        foreach ($stores as $i => $s) {
            $lists->setCellValue('C' . ($i + 2), $s->code);
        }
        $lists->setCellValue('D1', 'Managers');
        foreach ($managers as $i => $m) {
            $lists->setCellValue('D' . ($i + 2), $m->email);
        }
        $lists->setCellValue('E1', 'YesNo');
        $lists->setCellValue('E2', 'Yes');
        $lists->setCellValue('E3', 'No');

        // ── Import Template sheet ───────────────────────────────────────
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        $headers = [
            'name', 'employee_id_no', 'email', 'role', 'department', 'position',
            'date_hired', 'is_manager', 'is_active', 'assigned_stores', 'reports_to',
        ];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
        }

        // Example row
        $sheet->setCellValue('A2', 'Juan Dela Cruz');
        $sheet->setCellValue('B2', 'EMP-0001');
        $sheet->setCellValue('C2', 'juan.delacruz@example.com');
        $sheet->setCellValue('D2', $roles->first() ?? 'Agent');
        $sheet->setCellValue('E2', $departments->first() ?? '');
        $sheet->setCellValue('F2', 'IT Technician');
        $sheet->setCellValue('G2', Carbon::now()->format('Y-m-d'));
        $sheet->setCellValue('H2', 'No');
        $sheet->setCellValue('I2', 'Yes');
        $sheet->setCellValue('J2', $stores->first()?->code ?? '');
        $sheet->setCellValue('K2', $managers->first()?->email ?? '');

        // Header styling
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        foreach (range(1, 11) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        // Cell comments explaining the multi-value (semicolon) columns.
        $storeComment = $sheet->getComment('J1');
        $storeComment->getText()->createTextRun(
            "Pick a store code from the dropdown.\nTo assign several stores to one user, separate the codes with a semicolon ( ; ).\nExample:  STR-001; STR-002; STR-005"
        );
        $storeComment->setWidth('260pt')->setHeight('90pt');

        $reportsComment = $sheet->getComment('K1');
        $reportsComment->getText()->createTextRun(
            "Pick a manager's email from the dropdown.\nTo report to several managers, separate the emails with a semicolon ( ; ).\nExample:  ana@example.com; ben@example.com"
        );
        $reportsComment->setWidth('260pt')->setHeight('90pt');

        // Dropdowns (soft validation — server re-validates, and multi-value cells
        // may legitimately contain semicolon-separated lists).
        $maxRow = 1001;
        $addList = function (string $formula, string $sqref, bool $allowBlank = true) use ($sheet) {
            $anchor = explode(':', $sqref)[0];
            // NOTE: PhpSpreadsheet's writer inverts this flag (writes showDropDown="1"
            // when false), and in OOXML showDropDown="1" *hides* the in-cell arrow.
            // So setShowDropDown(true) is what actually shows the dropdown in Excel.
            $sheet->getCell($anchor)->getDataValidation()
                ->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank($allowBlank)
                ->setShowDropDown(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setFormula1($formula)
                ->setSqref($sqref);
        };

        if ($roles->isNotEmpty()) {
            $addList(sprintf('Lists!$A$2:$A$%d', $roles->count() + 1), "D2:D{$maxRow}", false);
        }
        if ($departments->isNotEmpty()) {
            $addList(sprintf('Lists!$B$2:$B$%d', $departments->count() + 1), "E2:E{$maxRow}");
        }
        $addList('Lists!$E$2:$E$3', "H2:H{$maxRow}");
        $addList('Lists!$E$2:$E$3', "I2:I{$maxRow}");
        if ($stores->isNotEmpty()) {
            $addList(sprintf('Lists!$C$2:$C$%d', $stores->count() + 1), "J2:J{$maxRow}");
        }
        if ($managers->isNotEmpty()) {
            $addList(sprintf('Lists!$D$2:$D$%d', $managers->count() + 1), "K2:K{$maxRow}");
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'users-import-template.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * Import users from the Excel template. New emails only — existing emails
     * are skipped and reported. Every created user gets the default password.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows));

        // Lookups (all case-insensitive).
        $roleMap = Role::pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n])->all();
        $deptMap = collect($this->departmentNodeOptions())
            ->mapWithKeys(fn ($o) => [$this->normalizeKey($o['label']) => $o['node_id']])->all();
        $storeMap = [];
        foreach (Store::get(['id', 'code', 'name']) as $s) {
            $storeMap[mb_strtolower(trim($s->code))] = $s->id;
            $storeMap[mb_strtolower(trim($s->name))] = $s->id;
        }
        $managerMap = [];
        foreach (User::where('is_manager', true)->get(['id', 'email']) as $m) {
            $managerMap[mb_strtolower(trim($m->email))] = $m->id;
        }
        $existingEmails = [];
        foreach (User::pluck('email') as $e) {
            $existingEmails[mb_strtolower(trim($e))] = true;
        }
        $existingEmployeeIds = [];
        foreach (User::whereNotNull('employee_id_no')->pluck('employee_id_no') as $employeeIdNo) {
            $existingEmployeeIds[mb_strtolower(trim($employeeIdNo))] = true;
        }

        $imported = 0;
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $data = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $data[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }

            $name = $data['name'] ?? '';
            $employeeIdNo = $data['employee_id_no'] ?? '';
            $email = $data['email'] ?? '';

            $validator = Validator::make(
                ['name' => $name ?: null, 'employee_id_no' => $employeeIdNo ?: null, 'email' => $email ?: null],
                ['name' => 'required|string|max:255', 'employee_id_no' => 'nullable|string|max:255', 'email' => 'required|email|max:255']
            );
            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            $emailKey = mb_strtolower($email);
            if (isset($existingEmails[$emailKey])) {
                $errors[] = "Row {$rowNum}: email '{$email}' already exists — skipped.";
                continue;
            }

            $employeeIdKey = mb_strtolower($employeeIdNo);
            if ($employeeIdNo !== '' && isset($existingEmployeeIds[$employeeIdKey])) {
                $errors[] = "Row {$rowNum}: employee_id_no '{$employeeIdNo}' already exists — skipped.";
                continue;
            }

            $roleKey = mb_strtolower($data['role'] ?? '');
            if ($roleKey === '' || !isset($roleMap[$roleKey])) {
                $errors[] = "Row {$rowNum}: role '" . ($data['role'] ?? '') . "' is invalid — skipped.";
                continue;
            }
            $roleName = $roleMap[$roleKey];

            // Department placement (optional).
            $orgPayload = $this->organizationReferences->clearPayload();
            if (!empty($data['department'])) {
                $deptKey = $this->normalizeKey($data['department']);
                if (isset($deptMap[$deptKey])) {
                    $orgPayload = $this->organizationReferences->payloadFromNodeId((int) $deptMap[$deptKey]);
                } else {
                    $errors[] = "Row {$rowNum}: department '{$data['department']}' not found — user created without placement.";
                }
            }

            // Date hired (optional).
            $dateHired = null;
            if (!empty($data['date_hired'])) {
                try {
                    $dateHired = Carbon::parse($data['date_hired'])->format('Y-m-d');
                } catch (Throwable $e) {
                    $errors[] = "Row {$rowNum}: invalid date_hired '{$data['date_hired']}' — left blank.";
                }
            }

            $isActive = $this->parseBool($data['is_active'] ?? '', true);
            $isManager = $this->parseBool($data['is_manager'] ?? '', false);

            // Stores (semicolon-separated; matched by code or name).
            $storeIds = [];
            if (!empty($data['assigned_stores'])) {
                foreach (explode(';', $data['assigned_stores']) as $token) {
                    $key = mb_strtolower(trim($token));
                    if ($key === '') {
                        continue;
                    }
                    if (isset($storeMap[$key])) {
                        $storeIds[] = $storeMap[$key];
                    } else {
                        $errors[] = "Row {$rowNum}: store '" . trim($token) . "' not found — skipped for this user.";
                    }
                }
            }

            // Reports-to managers (semicolon-separated; matched by email).
            $managerIds = [];
            if (!empty($data['reports_to'])) {
                foreach (explode(';', $data['reports_to']) as $token) {
                    $key = mb_strtolower(trim($token));
                    if ($key === '') {
                        continue;
                    }
                    if (isset($managerMap[$key])) {
                        $managerIds[] = $managerMap[$key];
                    } else {
                        $errors[] = "Row {$rowNum}: reports-to '" . trim($token) . "' is not an active manager — skipped for this user.";
                    }
                }
            }

            $user = User::create([
                'name' => $name,
                'employee_id_no' => $employeeIdNo ?: null,
                'email' => $email,
                'password' => Hash::make(self::DEFAULT_IMPORT_PASSWORD),
                ...$orgPayload,
                'position' => $data['position'] ?: null,
                'date_hired' => $dateHired,
                'is_active' => $isActive,
                'is_manager' => $isManager,
                'email_verified_at' => now(),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $user->assignRole($roleName);

            if ($storeIds) {
                $user->stores()->sync(array_unique($storeIds));
            }
            if ($managerIds) {
                $user->managers()->sync(array_unique($managerIds));
            }

            // Guard against duplicate emails within the same file.
            $existingEmails[$emailKey] = true;
            if ($employeeIdNo !== '') {
                $existingEmployeeIds[$employeeIdKey] = true;
            }
            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'errors' => $errors,
            'default_password' => self::DEFAULT_IMPORT_PASSWORD,
        ]);
    }

    /**
     * Flat, full-path labels for every active department node, e.g.
     * "Technology And Solutions > Corporate Technology > Sector 1".
     *
     * @return array<int, array{label: string, node_id: int}>
     */
    private function departmentNodeOptions(): array
    {
        $nodes = DepartmentNode::with('department')->get()->keyBy('id');
        $options = [];

        foreach ($nodes as $node) {
            if (!$node->is_active || !$node->department || !$node->department->is_active) {
                continue;
            }

            $parts = [];
            $current = $node;
            $guard = 0;
            while ($current && $guard++ < 25) {
                array_unshift($parts, $current->name);
                $current = $current->parent_id ? ($nodes[$current->parent_id] ?? null) : null;
            }

            $options[] = [
                'label' => $node->department->name . ' > ' . implode(' > ', $parts),
                'node_id' => $node->id,
            ];
        }

        usort($options, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    private function parseBool($value, bool $default): bool
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'yes', 'y', 'true', 'active'], true);
    }

    private function normalizeKey(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value)));
    }
}
