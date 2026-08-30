<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\ProjectTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The "Apply Activity Template" modal offered none of the named templates because
 * the project name had to match the template's `project_name` exactly: templates
 * are named for the product ("LINK HUB") while projects carry a qualifier as well
 * ("NN LINK HUB"). These pin the whole-word containment rule that replaced it.
 *
 * No database: coversProjectName() is pure string logic, deliberately so — the
 * whole-word test has no portable SQL form across SQL Server and the SQLite test
 * connection.
 */
class ProjectTemplateApplicabilityTest extends TestCase
{
    private function template(?string $projectName): ProjectTemplate
    {
        return new ProjectTemplate(['project_name' => $projectName]);
    }

    public static function matchingNames(): array
    {
        return [
            'the reported case' => ['LINK HUB', 'NN LINK HUB'],
            'other qualifier' => ['LINK HUB', 'TGI LINK HUB'],
            'exact match still matches' => ['LINK HUB', 'LINK HUB'],
            'qualifier after the name' => ['DAVID', 'DAVID Phase 2'],
            'qualifier on both sides' => ['DIWA', 'NN DIWA Rollout'],
            'case is ignored' => ['link hub', 'NN LINK HUB'],
            'surrounding whitespace' => ['  LINK HUB  ', ' NN LINK HUB '],
            'punctuation is a boundary' => ['LINK HUB', 'NN (LINK HUB)'],
            'apostrophe in the needle' => ["NONO'S", "NN NONO'S ROLLOUT"],
        ];
    }

    #[DataProvider('matchingNames')]
    public function test_it_covers_a_project_whose_name_contains_the_template_name(string $templateName, string $projectName): void
    {
        $this->assertTrue(
            $this->template($templateName)->coversProjectName($projectName),
            "[$templateName] should cover [$projectName]"
        );
    }

    public static function nonMatchingNames(): array
    {
        return [
            'different product' => ['LINK HUB', 'LINK PORTAL'],
            'longer word, not a match' => ['LINK HUB', 'NN LINK HUBBING'],
            'prefix of a longer word' => ['DAVID', 'DAVIDSON PROJECT'],
            'only part of the name' => ['LINK HUB PRO', 'NN LINK HUB'],
            'unrelated' => ['DIWA', 'NN LINK HUB'],
            'empty project name' => ['LINK HUB', ''],
        ];
    }

    #[DataProvider('nonMatchingNames')]
    public function test_it_does_not_cover_an_unrelated_project(string $templateName, string $projectName): void
    {
        $this->assertFalse(
            $this->template($templateName)->coversProjectName($projectName),
            "[$templateName] must not cover [$projectName]"
        );
    }

    public function test_a_template_with_no_project_name_applies_anywhere(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $this->assertTrue($this->template($blank)->coversProjectName('NN LINK HUB'));
            $this->assertTrue($this->template($blank)->coversProjectName('Anything At All'));
        }
    }

    public function test_a_regex_metacharacter_in_the_name_is_treated_literally(): void
    {
        // An unescaped needle would make this a wildcard that matches everything.
        $this->assertTrue($this->template('C++ ROLLOUT')->coversProjectName('NN C++ ROLLOUT'));
        $this->assertFalse($this->template('A.C')->coversProjectName('NN ABC'));
    }

    /*
     * applicabilityErrorFor() is what the apply endpoint enforces AND what the
     * modal's list is built from. They were once two separate copies of this rule
     * and drifted apart, so the list offered a template the endpoint then refused
     * with an error nothing displayed — "Apply Template" did nothing at all.
     */

    private function project(array $attributes = []): Project
    {
        return new Project(array_merge([
            'name' => 'NN LINK HUB',
            'company_id' => 6,
            'brand_company_id' => 8,
            'project_type' => 'Full Service Group: Customer Brand',
        ], $attributes));
    }

    private function fullTemplate(array $attributes = []): ProjectTemplate
    {
        return new ProjectTemplate(array_merge([
            'project_name' => 'LINK HUB',
            'entity_company_id' => 6,
            'brand_company_id' => 8,
            'project_type' => 'Full Service Group: Customer Brand',
        ], $attributes));
    }

    public function test_the_reported_template_may_be_applied_to_the_reported_project(): void
    {
        $this->assertNull($this->fullTemplate()->applicabilityErrorFor($this->project()));
    }

    public function test_a_template_with_no_restrictions_applies_to_any_project(): void
    {
        $bare = new ProjectTemplate(['project_type' => 'Full Service Group: Customer Brand']);

        $this->assertNull($bare->applicabilityErrorFor($this->project()));
        $this->assertNull($bare->applicabilityErrorFor($this->project(['name' => 'Something Else'])));
    }

    public function test_it_refuses_another_entitys_template(): void
    {
        $this->assertStringContainsString(
            'different entity',
            $this->fullTemplate(['entity_company_id' => 10])->applicabilityErrorFor($this->project())
        );
    }

    public function test_it_refuses_another_brands_template(): void
    {
        $this->assertStringContainsString(
            'different brand',
            $this->fullTemplate(['brand_company_id' => 7])->applicabilityErrorFor($this->project())
        );
    }

    public function test_it_refuses_a_template_for_a_different_project_type(): void
    {
        $this->assertStringContainsString(
            'Store Opening',
            $this->fullTemplate(['project_type' => 'Store Opening'])->applicabilityErrorFor($this->project())
        );
    }

    public function test_it_refuses_a_template_named_for_a_different_product(): void
    {
        $this->assertStringContainsString(
            'LINK PORTAL',
            $this->fullTemplate(['project_name' => 'LINK PORTAL'])->applicabilityErrorFor($this->project())
        );
    }

    public function test_the_guard_and_the_listing_agree_on_the_name_rule(): void
    {
        // The exact regression: the listing accepted "LINK HUB" for "NN LINK HUB"
        // while the guard demanded equality, so applying silently failed.
        $template = $this->fullTemplate();
        $project = $this->project();

        $this->assertTrue($template->coversProjectName($project->name));
        $this->assertNull($template->applicabilityErrorFor($project));
    }
}
