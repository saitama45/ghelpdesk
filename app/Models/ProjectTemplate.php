<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProjectTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'project_type',
        'store_class',
        'entity_company_id',
        'brand_company_id',
        'project_name',
    ];

    protected $casts = [
        'entity_company_id' => 'integer',
        'brand_company_id' => 'integer',
    ];

    public function entityCompany()
    {
        return $this->belongsTo(Company::class, 'entity_company_id');
    }

    public function brandCompany()
    {
        return $this->belongsTo(Company::class, 'brand_company_id');
    }

    public function activities()
    {
        return $this->hasMany(ActivityTemplate::class)
            ->orderBy('milestone_order')
            ->orderBy('parent_activity_template_id')
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The templates offered on a project's "Apply Activity Template" modal.
     *
     * A template narrows itself with four optional applicability fields; each one
     * left empty means "any". Entity, brand and project type are plain equality and
     * stay in SQL. The project NAME is matched in PHP — see coversProjectName() for
     * why it cannot be an exact comparison, and note that the whole-word test has no
     * portable SQL form (SQL Server has CHARINDEX, the test connection is SQLite).
     * The table holds a handful of rows, so filtering the result set costs nothing.
     *
     * Store class is deliberately NOT a filter (user decision, 2026-08-31): a
     * template marked Regular or Kitchen still shows on any project.
     */
    public static function applicableTo(Project $project): Collection
    {
        return static::query()
            ->withCount('activities')
            ->orderBy('name')
            ->get()
            ->filter(fn (self $template) => $template->applicabilityErrorFor($project) === null)
            ->values();
    }

    /**
     * Why this template may NOT be applied to this project, or null if it may.
     *
     * The single source for applicability. It was previously written twice — once
     * as the listing query and once as a guard in ProjectTaskController — and the
     * two drifted: the list started offering a template that the guard then
     * silently refused, so "Apply Template" appeared to do nothing.
     *
     * Filtering happens in PHP rather than SQL on purpose. The whole-word name test
     * has no portable SQL form (SQL Server has CHARINDEX, the test connection is
     * SQLite), and splitting the rule between a WHERE clause and a PHP check is
     * exactly the drift described above. The table holds a handful of hand-authored
     * rows, so evaluating them all costs nothing.
     */
    public function applicabilityErrorFor(Project $project): ?string
    {
        if ($this->entity_company_id && (int) $this->entity_company_id !== (int) $project->company_id) {
            return 'This template belongs to a different entity.';
        }

        if ($this->brand_company_id && (int) $this->brand_company_id !== (int) $project->brand_company_id) {
            return 'This template belongs to a different brand.';
        }

        if (filled($this->project_type) && $this->project_type !== $project->project_type) {
            return 'This template is for "'.$this->project_type.'" projects, and this one is "'.$project->project_type.'".';
        }

        if (! $this->coversProjectName($project->name)) {
            return 'This template is for projects named after "'.$this->project_name.'", which "'.$project->name.'" is not.';
        }

        return null;
    }

    /**
     * Does this template's `project_name` cover the given project?
     *
     * Whole-word containment, not equality. Templates are named for the product
     * being delivered ("LINK HUB"), while projects carry a qualifier as well
     * ("NN LINK HUB", "TGI LINK HUB"); an exact comparison matched neither, which
     * is why every named template was missing from the modal.
     *
     * Whole words specifically, so "LINK HUB" never claims "LINK PORTAL" or
     * "LINK HUBBING". An empty project_name means the template applies anywhere.
     */
    public function coversProjectName(?string $projectName): bool
    {
        $needle = trim((string) $this->project_name);

        if ($needle === '') {
            return true;
        }

        $haystack = trim((string) $projectName);

        if ($haystack === '') {
            return false;
        }

        // Boundaries are "not a letter or digit" rather than \b: a needle may begin
        // or end with punctuation (NONO'S), where \b does not mean what it looks like.
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/iu';

        return preg_match($pattern, $haystack) === 1;
    }
}
