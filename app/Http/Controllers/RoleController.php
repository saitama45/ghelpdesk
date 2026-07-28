<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Company;
use App\Http\Services\RoleService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RoleController extends Controller
{
    /** Export/import sheet columns, in order. */
    private const EXPORT_HEADERS = [
        'name', 'landing_page', 'companies', 'is_assignable',
        'notify_on_ticket_create', 'notify_on_ticket_assign',
        'notify_on_urgent_ticket', 'notify_on_user_registration', 'permissions',
    ];

    protected function bumpPermissionsVersion(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (!Cache::has('permissions_version')) {
            Cache::forever('permissions_version', 1);
            return;
        }

        Cache::increment('permissions_version');
    }

    public function index(Request $request)
    {
        $query = Role::with('permissions:id,name', 'companies:id,name');
        
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        
        $roles = $query->paginate($request->get('per_page', 10))->withQueryString();
        $permissions = RoleService::getPermissionsByCategory();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'companies' => $companies,
            'dynamicForms' => \App\Models\FormDefinition::where('is_active', true)->get(['name', 'slug']),
        ]);
    }

    public function editorData(Request $request, Role $role)
    {
        abort_unless($request->user()->can('roles.edit'), 403);

        $role->load('permissions:id,name', 'companies:id,name');

        return response()->json([
            'role' => $role,
            'permissions' => RoleService::getPermissionsByCategory(),
            'companies' => Company::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'dynamic_forms' => \App\Models\FormDefinition::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['name', 'slug']),
        ]);
    }

    /**
     * Export the current (searched) role list to Excel. The sheet is the exact
     * shape the importer expects, so an export can be edited and re-imported.
     */
    public function export(Request $request)
    {
        $query = Role::with('permissions:id,name', 'companies:id,name');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $roles = $query->orderBy('name')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Roles');

        foreach (self::EXPORT_HEADERS as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count(self::EXPORT_HEADERS));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        $row = 2;
        foreach ($roles as $role) {
            $values = [
                $role->name,
                $role->landing_page ?: 'dashboard',
                $role->companies->pluck('name')->implode('; '),
                $role->is_assignable ? 'Yes' : 'No',
                $role->notify_on_ticket_create ? 'Yes' : 'No',
                $role->notify_on_ticket_assign ? 'Yes' : 'No',
                $role->notify_on_urgent_ticket ? 'Yes' : 'No',
                $role->notify_on_user_registration ? 'Yes' : 'No',
                $role->permissions->pluck('name')->sort()->implode('; '),
            ];

            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1) . $row,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            $row++;
        }

        foreach (range(1, count(self::EXPORT_HEADERS)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }
        // The permissions column is long — cap it so the sheet stays readable.
        $sheet->getColumnDimension($lastColumn)->setAutoSize(false);
        $sheet->getColumnDimension($lastColumn)->setWidth(80);

        $writer = new Xlsx($spreadsheet);
        $filename = 'roles-export-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * Import roles from an exported sheet. New role names are created; existing
     * ones are only touched when "update_existing" is requested. Unknown
     * permissions/companies are reported instead of silently created.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt|max:5120',
            'update_existing' => 'boolean',
        ]);

        $updateExisting = $request->boolean('update_existing');

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $headerRow = array_shift($rows);
        if (!$headerRow) {
            return response()->json(['created' => 0, 'updated' => 0, 'errors' => ['The file is empty.']]);
        }
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headerRow);

        // Known permission catalogue — everything the Roles UI can offer.
        $knownPermissions = [];
        foreach (RoleService::getPermissionsByCategory() as $categoryPermissions) {
            foreach ($categoryPermissions as $permission) {
                $knownPermissions[mb_strtolower($permission->name)] = $permission->name;
            }
        }

        $companyMap = [];
        foreach (Company::get(['id', 'name']) as $company) {
            $companyMap[mb_strtolower(trim($company->name))] = $company->id;
        }

        $existingRoles = Role::get(['id', 'name'])
            ->mapWithKeys(fn ($role) => [mb_strtolower(trim($role->name)) => $role->id])
            ->all();

        $created = 0;
        $updated = 0;
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
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = "Row {$rowNum}: role name is missing or too long — skipped.";
                continue;
            }

            $nameKey = mb_strtolower($name);
            $existingId = $existingRoles[$nameKey] ?? null;

            if ($existingId && !$updateExisting) {
                $errors[] = "Row {$rowNum}: role '{$name}' already exists — skipped.";
                continue;
            }

            // Companies (semicolon-separated names). At least one must resolve.
            $companyIds = [];
            foreach (explode(';', $data['companies'] ?? '') as $token) {
                $key = mb_strtolower(trim($token));
                if ($key === '') {
                    continue;
                }
                if (isset($companyMap[$key])) {
                    $companyIds[] = $companyMap[$key];
                } else {
                    $errors[] = "Row {$rowNum}: company '" . trim($token) . "' not found — skipped for this role.";
                }
            }
            if (!$companyIds) {
                $errors[] = "Row {$rowNum}: role '{$name}' has no valid company — skipped.";
                continue;
            }

            // Permissions (semicolon-separated). Unknown names are reported.
            $permissionNames = [];
            foreach (explode(';', $data['permissions'] ?? '') as $token) {
                $key = mb_strtolower(trim($token));
                if ($key === '') {
                    continue;
                }
                if (isset($knownPermissions[$key])) {
                    $permissionNames[] = $knownPermissions[$key];
                } else {
                    $errors[] = "Row {$rowNum}: permission '" . trim($token) . "' is unknown — skipped for this role.";
                }
            }

            $attributes = [
                'landing_page' => ($data['landing_page'] ?? '') !== '' ? mb_substr($data['landing_page'], 0, 255) : 'dashboard',
                'is_assignable' => $this->parseBool($data['is_assignable'] ?? '', false),
                'notify_on_ticket_create' => $this->parseBool($data['notify_on_ticket_create'] ?? '', false),
                'notify_on_ticket_assign' => $this->parseBool($data['notify_on_ticket_assign'] ?? '', false),
                'notify_on_urgent_ticket' => $this->parseBool($data['notify_on_urgent_ticket'] ?? '', false),
                'notify_on_user_registration' => $this->parseBool($data['notify_on_user_registration'] ?? '', false),
            ];

            if ($existingId) {
                $role = Role::find($existingId);
                $role->forceFill($attributes)->save();
                $updated++;
            } else {
                $role = Role::create(['name' => $name] + $attributes);
                $existingRoles[$nameKey] = $role->id;
                $created++;
            }

            RoleService::updateRolePermissions($role->id, array_unique($permissionNames));
            $role->companies()->sync(array_unique($companyIds));
        }

        $this->bumpPermissionsVersion();

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    private function parseBool($value, bool $default): bool
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'yes', 'y', 'true'], true);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles',
            'landing_page' => 'nullable|string|max:255',
            'permissions' => 'array',
            'companies' => 'required|array|min:1',
            'is_assignable' => 'boolean',
            'notify_on_ticket_create' => 'boolean',
            'notify_on_ticket_assign' => 'boolean',
            'notify_on_urgent_ticket' => 'boolean',
            'notify_on_user_registration' => 'boolean',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'landing_page' => $request->landing_page,
            'is_assignable' => $request->boolean('is_assignable'),
            'notify_on_ticket_create' => $request->boolean('notify_on_ticket_create'),
            'notify_on_ticket_assign' => $request->boolean('notify_on_ticket_assign'),
            'notify_on_urgent_ticket' => $request->boolean('notify_on_urgent_ticket'),
            'notify_on_user_registration' => $request->boolean('notify_on_user_registration'),
        ]);
        
        if ($request->permissions) {
            RoleService::updateRolePermissions($role->id, $request->permissions);
        }
        
        if ($request->companies) {
            $role->companies()->sync($request->companies);
        }

        $this->bumpPermissionsVersion();

        return redirect()->back()->with('success', 'Role created successfully');
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'landing_page' => 'nullable|string|max:255',
            'permissions' => 'array',
            'companies' => 'required|array|min:1',
            'is_assignable' => 'boolean',
            'notify_on_ticket_create' => 'boolean',
            'notify_on_ticket_assign' => 'boolean',
            'notify_on_urgent_ticket' => 'boolean',
            'notify_on_user_registration' => 'boolean',
        ]);

        $role->name = $request->name;
        $role->landing_page = $request->landing_page;
        $role->is_assignable = $request->boolean('is_assignable');
        $role->notify_on_ticket_create = $request->boolean('notify_on_ticket_create');
        $role->notify_on_ticket_assign = $request->boolean('notify_on_ticket_assign');
        $role->notify_on_urgent_ticket = $request->boolean('notify_on_urgent_ticket');
        $role->notify_on_user_registration = $request->boolean('notify_on_user_registration');
        $role->save();
        
        RoleService::updateRolePermissions($role->id, $request->permissions ?? []);
        
        $role->companies()->sync($request->companies ?? []);

        $this->bumpPermissionsVersion();

        return redirect()->back()->with('success', 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete role with assigned users');
        }

        $role->delete();
        $this->bumpPermissionsVersion();
        return redirect()->back()->with('success', 'Role deleted successfully');
    }
}
