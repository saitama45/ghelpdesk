<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HybridSdlcWorkbookService
{
    private const VALIDATION_LAST_ROW = 5000;

    public const FULL_SERVICE = 'Full Service Group: Customer Brand';
    public const CORPORATE = 'Corporate Group: Servicing Brand';

    private const COLLABORATION_DEPARTMENTS = [
        'BD' => 'Business Development',
        'PD' => 'Project Development',
        'FM' => 'Facilities Management',
        'Marketing' => 'Marketing',
        'P&O' => 'People and Organization',
        'OWD' => 'Organizational Wellness & Development',
        'LD' => 'Leadership Development',
        'SCM' => 'Supply Chain Management',
        'F&A' => 'Finance and Accounting',
        'CBI / DBS' => 'CBI / DBS',
        'TAS' => 'Technology and Solutions',
    ];

    public const HEADERS = [
        'Template Name', 'Project Type', 'Entity Code', 'Brand Code', 'Project Name',
        'Store Class', 'Row Key', 'Parent Row Key', 'Activity', 'Activity Mode',
        'Milestone', 'Milestone Order', 'Milestone Weight %', 'Activity Weight %',
        'Sub-Task Weight %', 'Acceptance Criteria', 'Asset Item', 'Model Specs',
        'Quantity', 'Responsible', 'Department', 'Sub Unit', 'Duration Days', 'Order',
        'Requisite Row Key', 'Can Run Parallel',
    ];

    public function write(string $path): array
    {
        return $this->writeTemplates($path, $this->templates());
    }

    public function writeSeparate(string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $results = [];
        foreach ($this->templates() as $template) {
            $filename = preg_replace('/[<>:"\/\\\\|?*]+/', '-', $template['name']).'.xlsx';
            $results[] = $this->writeTemplates($directory.DIRECTORY_SEPARATOR.$filename, [$template]);
        }

        return $results;
    }

    private function writeTemplates(string $path, array $templates): array
    {
        $rows = [];
        $indexRows = [];

        foreach ($templates as $template) {
            $templateRows = $this->templateRows($template);
            array_push($rows, ...$templateRows);
            $indexRows[] = [
                $template['name'], $template['project_type'], $template['entity'],
                $template['brand'] ?: '—', $template['project_name'],
                count($template['milestones']), count($templateRows),
            ];
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activity Templates');
        $sheet->fromArray([self::HEADERS, ...$rows], null, 'A1');
        $this->styleDataSheet($sheet, count($rows));

        $index = $spreadsheet->createSheet();
        $index->setTitle('Template Index');
        $index->fromArray([
            ['Included Hybrid SDLC / Agile Templates'],
            ['Template Name', 'Project Type', 'Entity', 'Brand', 'Project Name', 'Milestones', 'Import Rows'],
            ...$indexRows,
        ], null, 'A1');
        $index->mergeCells('A1:G1');
        $this->styleGuideSheet($index, 'G');

        if ($this->includesAllDepartmentsImplementation($templates)) {
            $matrix = $spreadsheet->createSheet();
            $matrix->setTitle('Collaboration Matrix');
            $matrix->fromArray($this->collaborationMatrixRows(), null, 'A1');
            $this->styleCollaborationMatrix($matrix, count($this->collaborationProcesses()));
        }

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Hybrid SDLC / Agile Activity Template Instructions'],
            ['Purpose', 'Recommended starting structures for the listed application projects. Review owners, durations, dependencies, weights, and acceptance criteria before importing.'],
            ['Import', 'Open Activity Templates, choose Import, and upload this XLSX. The application groups rows by Template Name, Project Type, and Store Class.'],
            ['Project Name', 'DAVID, LINK HUB, DIWA, and LINK PORTAL remain plain-text Project Name values; there is no Solution dropdown.'],
            ['Hierarchy', 'Milestone = business capability or release; Activity = SDLC phase, sprint, or store rollout; Sub-Task = assignable accepted deliverable.'],
            ['All Departments', "All Departments' Process Implementation in LINK HUB contains one Activity per business process. Its Sub-Tasks cover department readiness plus the hybrid SDLC / Agile work needed to implement, test, release, and adopt that process in LINK HUB."],
            ['Collaboration Matrix', 'Use the matrix as the cross-department reporting view. Milestone marks the primary owning department and Waiting is the recommended initial state; replace these with current status or target week as planning progresses. Imported Sub-Tasks remain the source of progress percentages.'],
            ['Task Board', 'Keep detailed user stories, bugs, and developer tasks on the Task Board. The Gantt should track accepted sprint/release outcomes.'],
            ['Weights', 'Milestones total 100% per template, activities total 100% per milestone, and sub-tasks total 100% per activity.'],
            ['Per Store', 'A Per Store activity is cloned after target stores are selected. Do not manually create 35 placeholder store rows.'],
            ['NONOS DIWA', '35 is the recommended target-store count. The workbook does not create store assignments.'],
            ['CBTL DIWA', 'Target-store count remains to be confirmed.'],
            ['Safety', 'Import into a test environment first. Re-importing an identical template is skipped rather than overwriting it.'],
        ], null, 'A1');
        $this->styleGuideSheet($instructions, 'B');
        $instructions->getColumnDimension('A')->setWidth(22);
        $instructions->getColumnDimension('B')->setWidth(115);

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('WBS Guide');
        $guide->fromArray([
            ['Work-Breakdown Guide'],
            ['Level', 'Use', 'Example', 'Progress rule'],
            ['Project', 'One application in one entity/brand context', 'DAVID · TGI · CBTL', 'Weighted milestone roll-up'],
            ['Milestone', 'Business capability, release, or rollout outcome', 'Inventory Scanning with Barcode Scanner', 'Weighted activity roll-up'],
            ['Activity', 'SDLC phase, Agile sprint, or per-store deployment', 'Sprint 1 – Core Workflow', 'Weighted sub-task roll-up'],
            ['Sub-Task', 'Concrete deliverable with acceptance criteria', 'Product owner accepts sprint demo', 'Owner updates 0–100%'],
            ['Task Board', 'Stories, bugs, coding tasks, and daily execution', 'API validation bug', 'Feeds its linked Gantt deliverable'],
        ], null, 'A1');
        $guide->mergeCells('A1:D1');
        $this->styleGuideSheet($guide, 'D');

        $lists = $spreadsheet->createSheet();
        $lists->setTitle('Lists');
        $lists->fromArray([
            ['Project Types', 'Entity Codes', 'Brand Codes', 'Store Classes', 'Activity Modes', 'Yes / No'],
            [self::FULL_SERVICE, 'TGI', 'NONOS', 'Regular', 'Standard', 'No'],
            [self::CORPORATE, 'GSI', 'CBTL', 'Kitchen', 'Per Store', 'Yes'],
            [null, 'NCF', 'DSY', 'Both'],
            [null, 'DBS'],
        ], null, 'A1');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $this->applyValidations($sheet);

        $spreadsheet->setActiveSheetIndex(0);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return ['templates' => count($templates), 'rows' => count($rows), 'path' => $path];
    }

    private function includesAllDepartmentsImplementation(array $templates): bool
    {
        foreach ($templates as $template) {
            foreach ($template['milestones'] as $milestone) {
                if ($milestone['name'] === "All Departments' Process Implementation in LINK HUB") {
                    return true;
                }
            }
        }

        return false;
    }

    public function templates(): array
    {
        return [
            $this->template('TGI', 'NONOS', 'LINK HUB', [
                $this->feature('FM Ticketing'), $this->allDepartmentsLinkHubImplementation(),
            ]),
            $this->template('TGI', 'NONOS', 'DAVID', [
                $this->feature('EO Process Enablement'), $this->feature('AO Process Enablement'),
                $this->feature('INTERCO Process Enablement'), $this->inventoryScanning(), $this->bomUploading(),
            ]),
            $this->template('TGI', 'NONOS', 'DIWA', [
                $this->posAgentRollout(), $this->feature('Bulk Product Update (Push to POS Agent)'),
            ]),
            $this->template('TGI', 'NONOS', 'LINK PORTAL', [$this->ocr()]),
            $this->template('GSI', 'NONOS', 'DAVID', [
                $this->feature('Process Review'), $this->inventoryScanning('GSI Inventory Scanning'),
            ]),
            $this->template('TGI', 'DSY', 'DAVID', [
                $this->processReviewRollout('Three-Store Process Review'),
            ]),
            $this->template('TGI', 'CBTL', 'LINK HUB', [
                $this->feature('FM Ticketing'), $this->allDepartmentsLinkHubImplementation(),
                $this->feature('Campaign Programs'), $this->feature('Stamping Loyalty Campaign Programs (Mobile App)'),
            ], 'Both'),
            $this->template('TGI', 'CBTL', 'DAVID', [$this->davidTransactionRollout()], 'Both'),
            $this->template('TGI', 'CBTL', 'DIWA', [
                $this->posAgentRollout(), $this->feature('Bulk Product Update (Push to POS Agent)'),
            ], 'Both'),
            $this->template('NCF', 'CBTL', 'DAVID', [
                $this->feature('Process Review'), $this->inventoryScanning('NCF Inventory Scanning'),
            ], 'Both'),
            $this->template('TGI', null, 'LINK HUB', [
                $this->allDepartmentsLinkHubImplementation(), $this->poForms(),
            ], 'Both', self::CORPORATE),
            $this->template('TGI', null, 'LINK PORTAL', [$this->ocr()], 'Both', self::CORPORATE),
            $this->template('DBS', null, 'LINK HUB', [$this->feature('DBS Ticketing')], 'Both', self::CORPORATE),
        ];
    }

    private function template(string $entity, ?string $brand, string $project, array $milestones, string $storeClass = 'Both', string $type = self::FULL_SERVICE): array
    {
        $context = implode(' - ', array_filter([$entity, $brand, $project]));
        return [
            'name' => $context.' - Hybrid SDLC Agile', 'project_type' => $type,
            'entity' => $entity, 'brand' => $brand, 'project_name' => $project,
            'store_class' => $storeClass, 'milestones' => $milestones,
        ];
    }

    private function feature(string $name): array
    {
        return ['name' => $name, 'activities' => $this->featureActivities()];
    }

    private function featureActivities(): array
    {
        return [
            $this->activity('Discovery and Backlog', 10, 'Standard', [
                ['Confirm stakeholders, product owner, KPIs, and scope', 'Stakeholders, accountable owner, measurable KPIs, scope, and exclusions are documented and accepted.'],
                ['Document current and target process', 'The approved as-is and to-be flows identify actors, rules, exceptions, and hand-offs.'],
                ['Create and approve prioritized backlog', 'Epics and acceptance-ready stories are prioritized for the release.'],
            ], 'Business Analyst'),
            $this->activity('Solution Design and Release Planning', 10, 'Standard', [
                ['Complete functional and UX design', 'Business rules, screens, validations, and user journeys are approved.'],
                ['Complete architecture, security, and integration design', 'Technical design covers interfaces, data, access, audit, and non-functional requirements.'],
                ['Approve estimates, sprint plan, and release plan', 'Capacity, sprint goals, dependencies, risks, and release criteria are baselined.'],
            ], 'Product Owner / Technical Lead'),
            $this->activity('Sprint 0 - Foundation', 5, 'Standard', [
                ['Prepare environments and delivery pipeline', 'Development and test environments and deployment pipeline are usable.'],
                ['Create technical foundation and feature controls', 'Required scaffolding, configuration, access, and feature controls are implemented.'],
                ['Review Sprint 0 increment', 'The team demonstrates the foundation and closes blocking findings.'],
            ], 'Development Team'),
            $this->activity('Sprint 1 - Core Workflow', 20, 'Standard', [
                ['Deliver highest-priority workflow stories', 'Committed core stories meet their acceptance criteria and code-review standards.'],
                ['Execute developer and component tests', 'Automated and manual component tests pass with evidence.'],
                ['Hold sprint review and accept increment', 'The product owner accepts the sprint goal or records follow-up backlog items.'],
            ], 'Scrum Team'),
            $this->activity('Sprint 2 - Integration and Refinement', 15, 'Standard', [
                ['Deliver integrations, exceptions, and remaining release stories', 'Committed integration and exception-path stories meet acceptance criteria.'],
                ['Complete regression and defect correction', 'Release-scope regression passes and critical sprint defects are closed.'],
                ['Hold sprint review and freeze release scope', 'The product owner accepts the increment and confirms the release candidate scope.'],
            ], 'Scrum Team'),
            $this->activity('Integrated Quality Assurance', 15, 'Standard', [
                ['Execute functional, integration, and regression testing', 'Approved test cases pass and results are traceable to requirements.'],
                ['Validate permissions, audit, performance, and security', 'Applicable non-functional controls pass without release-blocking findings.'],
                ['Close release-blocking defects and issue QA exit', 'No unresolved release blocker remains and QA exit is approved.'],
            ], 'QA Team'),
            $this->activity('UAT and Operational Readiness', 15, 'Standard', [
                ['Prepare UAT scenarios, users, and representative data', 'UAT coverage, users, environment, and data are ready.'],
                ['Conduct UAT and resolve business findings', 'Business scenarios pass and blocking findings are closed or formally accepted.'],
                ['Complete SOP, training, support, and business sign-off', 'Users and support teams are prepared and the process owner signs acceptance.'],
            ], 'Product Owner / Key Users'),
            $this->activity('Release and Hypercare', 10, 'Standard', [
                ['Approve deployment and rollback readiness', 'Go/no-go participants approve the production and rollback plan.'],
                ['Deploy and complete production smoke tests', 'The release is deployed and critical production journeys pass.'],
                ['Complete hypercare and release closure', 'Priority production issues are resolved and the owner accepts operational handover.'],
            ], 'Release Manager'),
        ];
    }

    private function allDepartmentsLinkHubImplementation(): array
    {
        $processes = $this->collaborationProcesses();
        $activityWeights = $this->equalWeights(count($processes));
        $activities = [];

        foreach ($processes as $index => $process) {
            $tasks = [
                [
                    'Confirm lead, partner, scope, KPIs, and implementation outcome',
                    "{$process['lead']} and {$process['partner']} confirm the accountable roles, scope, measurable KPIs, exclusions, and intended LINK HUB outcome for {$process['name']}.",
                    "Lead: {$process['lead']} | Partner: {$process['partner']}",
                    $process['department'],
                ],
                [
                    'Document current process and approve target LINK HUB workflow',
                    "The as-is process, controls, exceptions, hand-offs, target workflow, and approval rules for {$process['name']} are documented and accepted.",
                    'Business Analyst / Process Owner',
                    $process['department'],
                ],
            ];

            foreach (self::COLLABORATION_DEPARTMENTS as $code => $department) {
                $tasks[] = [
                    "{$code} readiness and process checkpoint",
                    "{$department} records its owner, status or target week, requirements, dependencies, evidence, and approval decision for the {$process['name']} workflow in LINK HUB.",
                    "{$code} Process Representative",
                    $department,
                ];
            }

            array_push($tasks,
                [
                    'Refine backlog and approve sprint and release plan',
                    "Acceptance-ready stories for {$process['name']} are prioritized, estimated, assigned to sprints, and linked to an approved LINK HUB release plan.",
                    'Product Owner / Scrum Team',
                    'Technology and Solutions',
                ],
                [
                    'Configure and build the LINK HUB process workflow',
                    "Forms, routing, approvals, SLA rules, notifications, permissions, audit trail, reports, and required integrations for {$process['name']} meet the approved stories.",
                    'LINK HUB Development Team',
                    'Technology and Solutions',
                ],
                [
                    'Complete QA, security, integration, and regression testing',
                    "The {$process['name']} release scope passes functional, permission, audit, integration, security, exception-path, and regression tests without a release blocker.",
                    'QA Team / TAS',
                    'Technology and Solutions',
                ],
                [
                    'Conduct cross-department UAT and close findings',
                    "Applicable departments complete representative {$process['name']} scenarios, blocking findings are resolved, and the lead and partner approve UAT exit.",
                    "Lead: {$process['lead']} | Partner: {$process['partner']} / Key Users",
                    $process['department'],
                ],
                [
                    'Release, train users, monitor adoption, and close hypercare',
                    "The {$process['name']} workflow is deployed to LINK HUB, users receive SOP and training, adoption and KPI results are monitored, and the process owner accepts operational handover.",
                    'Release Manager / Process Owner',
                    $process['department'],
                ],
            );

            $activities[] = $this->activity(
                $process['name'],
                $activityWeights[$index],
                'Standard',
                $tasks,
                "Lead: {$process['lead']} | Partner: {$process['partner']}",
                $process['department'],
            );
        }

        return [
            'name' => "All Departments' Process Implementation in LINK HUB",
            'activities' => $activities,
        ];
    }

    private function collaborationProcesses(): array
    {
        return [
            ['group' => 1, 'name' => 'New Store Opening', 'lead' => 'Pam', 'partner' => 'Daphne', 'department' => 'Business Development', 'owner_code' => 'BD'],
            ['group' => 1, 'name' => 'Store Closure', 'lead' => 'Pam', 'partner' => 'Daphne', 'department' => 'Business Development', 'owner_code' => 'BD'],
            ['group' => 1, 'name' => 'Store Renovation', 'lead' => 'Pam', 'partner' => 'Daphne', 'department' => 'Project Development', 'owner_code' => 'PD'],
            ['group' => 2, 'name' => 'Requisition Process', 'lead' => 'Cel', 'partner' => 'Brendan', 'department' => 'Supply Chain Management', 'owner_code' => 'SCM'],
            ['group' => 2, 'name' => 'Purchasing Process', 'lead' => 'Cel', 'partner' => 'Brendan', 'department' => 'Supply Chain Management', 'owner_code' => 'SCM'],
            ['group' => 2, 'name' => 'Procure to Pay', 'lead' => 'Anna, Rach', 'partner' => 'Becky', 'department' => 'Finance and Accounting', 'owner_code' => 'F&A'],
            ['group' => 3, 'name' => 'Campaign Launch', 'lead' => 'Kim, Josie', 'partner' => 'Rach', 'department' => 'Marketing', 'owner_code' => 'Marketing'],
            ['group' => 3, 'name' => 'Product Launch', 'lead' => 'Kim, Josie', 'partner' => 'Cel', 'department' => 'Marketing', 'owner_code' => 'Marketing'],
            ['group' => 4, 'name' => 'Project Development', 'lead' => 'Becky, Sundae', 'partner' => 'Nef', 'department' => 'Project Development', 'owner_code' => 'PD'],
            ['group' => 4, 'name' => 'Equipment Planning', 'lead' => 'Becky, Sundae', 'partner' => 'Grace', 'department' => 'Facilities Management', 'owner_code' => 'FM'],
            ['group' => 4, 'name' => 'DBS Importation', 'lead' => 'Grace', 'partner' => 'Pam', 'department' => 'CBI / DBS', 'owner_code' => 'CBI / DBS'],
            ['group' => 5, 'name' => 'Manpower Planning and Employee Experience', 'lead' => 'Daphne', 'partner' => 'Sundae', 'department' => 'People and Organization', 'owner_code' => 'P&O'],
            ['group' => 5, 'name' => 'OWD and Foundational Training Initiatives', 'lead' => 'Mich C', 'partner' => 'Kim', 'department' => 'Organizational Wellness & Development', 'owner_code' => 'OWD'],
            ['group' => 5, 'name' => 'Leadership Development Initiatives', 'lead' => 'Mich V', 'partner' => 'Anna', 'department' => 'Leadership Development', 'owner_code' => 'LD'],
        ];
    }

    private function collaborationMatrixRows(): array
    {
        $rows = [[
            'Group', 'Process', 'Lead', 'Partner', ...array_keys(self::COLLABORATION_DEPARTMENTS),
        ]];

        foreach ($this->collaborationProcesses() as $process) {
            $statuses = [];
            foreach (array_keys(self::COLLABORATION_DEPARTMENTS) as $code) {
                $statuses[] = $code === $process['owner_code'] ? 'Milestone' : 'Waiting';
            }
            $rows[] = [$process['group'], $process['name'], $process['lead'], $process['partner'], ...$statuses];
        }

        return $rows;
    }

    private function inventoryScanning(string $name = 'Inventory Scanning with Barcode Scanner'): array
    {
        $milestone = $this->feature($name);
        $milestone['activities'][0]['tasks'][1] = ['Document inventory count, variance, and reconciliation workflow', 'Approved process covers barcode capture, count validation, variance investigation, and posting.'];
        $milestone['activities'][1]['tasks'][0] = ['Define barcode, item-master, and device standards', 'Supported symbologies, item mappings, scanner devices, and exception rules are approved.'];
        $milestone['activities'][4]['tasks'][0] = ['Deliver scanning, online/offline handling, and inventory integration', 'Scanning works on approved devices and synchronizes counts without duplication or data loss.'];
        $milestone['activities'][6]['tasks'][1] = ['Run pilot count and reconcile variances', 'Pilot results reconcile to the approved tolerance and business findings are closed.'];
        return $milestone;
    }

    private function bomUploading(): array
    {
        $milestone = $this->feature('BOM Uploading for New Menu Launch');
        $milestone['activities'][0]['tasks'][1] = ['Define source format, recipe mapping, and launch scope', 'Required columns, item/recipe mappings, effective dates, and menu scope are approved.'];
        $milestone['activities'][3]['tasks'][0] = ['Build upload, preview, validation, and rejection workflow', 'Users can preview valid rows and receive actionable row-level errors before committing.'];
        $milestone['activities'][6]['tasks'][1] = ['Upload representative menu file and reconcile BOM results', 'Every accepted recipe and component matches the approved source file.'];
        return $milestone;
    }

    private function ocr(): array
    {
        $milestone = $this->feature('PO/Invoice OCR for Vendors and External Partners');
        $milestone['activities'][0]['tasks'] = [
            ['Collect representative vendor document samples', 'Samples cover supported vendors, layouts, image qualities, languages, and exception cases.'],
            ['Define extraction fields, matching rules, and accuracy KPIs', 'Required fields, confidence thresholds, matching logic, and measurable accuracy targets are approved.'],
            ['Define privacy, retention, and exception-handling requirements', 'Document access, retention, duplicate handling, and manual validation controls are approved.'],
        ];
        $milestone['activities'][3]['tasks'][0] = ['Build upload, OCR extraction, confidence scoring, and review workflow', 'Users can upload, review low-confidence fields, correct values, and submit verified data.'];
        $milestone['activities'][5]['tasks'][1] = ['Test accuracy across vendor formats and poor-quality documents', 'Field-level accuracy meets the approved KPI and failures route to manual validation.'];
        $milestone['activities'][6]['tasks'][1] = ['Run controlled vendor pilot and compare manual results', 'Pilot extraction and processing-time results meet the agreed acceptance threshold.'];
        return $milestone;
    }

    private function posAgentRollout(): array
    {
        return $this->rollout('POS Agent Deployment', [
            ['Confirm store schedule, owner, and prerequisites', 'Store, support owner, deployment window, device, network, and access prerequisites are confirmed.'],
            ['Install and authenticate POS Agent', 'The approved agent version is installed and securely authenticated.'],
            ['Complete initial synchronization', 'Required master and transaction data synchronize without blocking errors.'],
            ['Execute end-to-end POS validation', 'Critical POS scenarios pass and evidence is recorded.'],
            ['Brief users and obtain store acceptance', 'Store users understand support procedures and the accountable manager accepts deployment.'],
        ]);
    }

    private function processReviewRollout(string $name): array
    {
        return $this->rollout($name, [
            ['Schedule process-review workshop', 'Store participants and review schedule are confirmed.'],
            ['Document current process and evidence', 'Actual process, controls, reports, and exceptions are documented.'],
            ['Assess gaps against DAVID target process', 'Gaps, impacts, risks, and owners are recorded.'],
            ['Agree corrective actions and target dates', 'Actions have accountable owners and accepted target dates.'],
            ['Approve store review and closure status', 'Store and process owner approve the review outcome.'],
        ]);
    }

    private function davidTransactionRollout(): array
    {
        return $this->rollout('DAVID Store Transaction Rollout', [
            ['Validate Order Placement', 'Store completes an accepted order-placement scenario.'],
            ['Validate Committing Order', 'Committed order totals and status match the approved scenario.'],
            ['Validate Receiving Orders', 'Receiving updates inventory and exceptions correctly.'],
            ['Validate Sales Uploading', 'Sales upload completes and reconciles to the source totals.'],
            ['Validate Wastage Uploading', 'Wastage upload posts valid reasons and quantities correctly.'],
            ['Validate MEC Uploading', 'MEC upload completes and reconciles without blocking errors.'],
            ['Complete end-to-end reconciliation', 'Transactions reconcile across store, DAVID, and dependent systems.'],
            ['Obtain store acceptance', 'The store manager accepts the rollout and support handover.'],
        ]);
    }

    private function rollout(string $name, array $storeTasks): array
    {
        return ['name' => $name, 'activities' => [
            $this->activity('Rollout Preparation', 15, 'Standard', [
                ['Confirm rollout scope, target count, waves, and owners', 'Approved rollout register identifies scope, waves, owners, and target count.'],
                ['Approve readiness checklist and support model', 'Entry criteria, escalation path, support coverage, and evidence requirements are approved.'],
                ['Confirm deployment package and rollback procedure', 'A tested deployment package and rollback procedure are available.'],
            ], 'Project Manager'),
            $this->activity('Store Deployment', 70, 'Per Store', $storeTasks, 'Deployment Team'),
            $this->activity('Stabilization and Closure', 15, 'Standard', [
                ['Monitor rollout KPIs and outstanding store issues', 'Completion, failure, and issue trends are current and actionable.'],
                ['Resolve priority deployment issues', 'No unresolved rollout blocker remains.'],
                ['Reconcile completed stores against target and close wave', 'Accepted stores reconcile to the rollout register and the sponsor approves closure.'],
            ], 'Project Manager / Support Team'),
        ]];
    }

    private function poForms(): array
    {
        $forms = [
            'Request for ID', 'Request for Name Plate', 'Request for Uniform',
            'Request for Globe Postpaid Plan', 'Request for Employee Sale',
            'Vehicle Repairs and Maintenance', 'Security Guard Concerns', 'Flight Accommodations',
        ];
        $weights = $this->equalWeights(count($forms));
        $activities = [];
        foreach ($forms as $index => $form) {
            $activities[] = $this->activity($form, $weights[$index], 'Standard', [
                ['Confirm form fields, eligibility, and business rules', 'Process owner approves the request data, validations, and requester eligibility.'],
                ['Configure approvals, SLA, routing, and notifications', 'The request follows approved routing, timing, and notification rules.'],
                ['Test permissions, attachments, and exception paths', 'Authorized scenarios pass and unauthorized access is rejected.'],
                ['Conduct UAT, publish form, and obtain acceptance', 'Business UAT passes and the process owner accepts the published form.'],
            ], 'P&O Process Owner / LINK HUB Team');
        }
        return ['name' => 'P&O Employee Services Agreed Forms', 'activities' => $activities];
    }

    private function activity(string $name, float $weight, string $mode, array $tasks, string $responsible, ?string $department = null): array
    {
        return compact('name', 'weight', 'mode', 'tasks', 'responsible', 'department');
    }

    private function templateRows(array $template): array
    {
        $rows = [];
        $milestoneWeights = $this->equalWeights(count($template['milestones']));
        $globalOrder = 1;
        $previousActivity = null;

        foreach ($template['milestones'] as $milestoneIndex => $milestone) {
            foreach ($milestone['activities'] as $activityIndex => $activity) {
                $activityKey = sprintf('M%02d-A%02d', $milestoneIndex + 1, $activityIndex + 1);
                $taskWeights = $this->equalWeights(count($activity['tasks']));
                $duration = max(1, count($activity['tasks']));
                $rows[] = $this->row($template, $activityKey, null, $activity['name'], $activity['mode'],
                    $milestone['name'], $milestoneIndex + 1, $milestoneWeights[$milestoneIndex],
                    $activity['weight'], null, $activity['name'].' is completed and accepted.',
                    $activity['responsible'], $duration, $globalOrder++, $previousActivity, $activity['department'] ?? null);

                $previousTask = $previousActivity;
                foreach ($activity['tasks'] as $taskIndex => $task) {
                    [$taskName, $acceptance] = $task;
                    $taskResponsible = $task[2] ?? $activity['responsible'];
                    $taskDepartment = $task[3] ?? ($activity['department'] ?? null);
                    $taskKey = $activityKey.'-S'.sprintf('%02d', $taskIndex + 1);
                    $rows[] = $this->row($template, $taskKey, $activityKey, $taskName, $activity['mode'],
                        $milestone['name'], $milestoneIndex + 1, $milestoneWeights[$milestoneIndex],
                        $activity['weight'], $taskWeights[$taskIndex], $acceptance,
                        $taskResponsible, 1, $globalOrder++, $previousTask, $taskDepartment);
                    $previousTask = $taskKey;
                }
                $previousActivity = $activityKey;
            }
        }
        return $rows;
    }

    private function row(array $template, string $key, ?string $parent, string $activity, string $mode,
        string $milestone, int $milestoneOrder, float $milestoneWeight, float $activityWeight,
        ?float $subTaskWeight, string $acceptance, string $responsible, int $days, int $order,
        ?string $requisite, ?string $department = null): array
    {
        return [
            $template['name'], $template['project_type'], $template['entity'], $template['brand'],
            $template['project_name'], $template['store_class'], $key, $parent, $activity, $mode,
            $milestone, $milestoneOrder, $milestoneWeight, $activityWeight, $subTaskWeight,
            $acceptance, null, null, 1, $responsible, $department, null, $days, $order, $requisite, 'No',
        ];
    }

    private function equalWeights(int $count): array
    {
        if ($count < 1) return [];
        $base = floor((100 / $count) * 100) / 100;
        $weights = array_fill(0, $count, $base);
        $weights[$count - 1] = round(100 - ($base * ($count - 1)), 2);
        return $weights;
    }

    private function styleDataSheet(Worksheet $sheet, int $rowCount): void
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:Z'.max(2, $rowCount + 1));
        $sheet->getStyle('A1:Z1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        foreach (range('A', 'Z') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        foreach (['A','B','E','I','K','P'] as $column) $sheet->getColumnDimension($column)->setWidth($column === 'P' ? 65 : 30);
        $sheet->getStyle('A1:Z'.($rowCount + 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle('M2:O'.($rowCount + 1))->getNumberFormat()->setFormatCode('0.00');
    }

    private function styleGuideSheet(Worksheet $sheet, string $lastColumn): void
    {
        $sheet->freezePane('A2');
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
        ]);
        foreach (range('A', $lastColumn) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        $sheet->getStyle("A1:{$lastColumn}200")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
    }

    private function styleCollaborationMatrix(Worksheet $sheet, int $processCount): void
    {
        $lastRow = $processCount + 1;
        $sheet->freezePane('E2');
        $sheet->setAutoFilter("A1:O{$lastRow}");
        $sheet->getStyle('A1:O1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7C3AED']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getColumnDimension('A')->setWidth(9);
        $sheet->getColumnDimension('B')->setWidth(46);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        foreach (range('E', 'O') as $column) {
            $sheet->getColumnDimension($column)->setWidth(16);
        }
        $sheet->getStyle("A1:O{$lastRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle("E2:O{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function applyValidations(Worksheet $sheet): void
    {
        $this->listValidation($sheet, 'B', 'A', 2);
        $this->listValidation($sheet, 'C', 'B', 4);
        $this->listValidation($sheet, 'D', 'C', 3, true);
        $this->listValidation($sheet, 'F', 'D', 3);
        $this->listValidation($sheet, 'J', 'E', 2);
        $this->listValidation($sheet, 'Z', 'F', 2);
        $this->rangeValidation($sheet, 'H', 'G');
        $this->rangeValidation($sheet, 'Y', 'G');
    }

    private function listValidation(Worksheet $sheet, string $target, string $source, int $count, bool $blank = false): void
    {
        $v = new DataValidation;
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank($blank)->setShowDropDown(true)
            ->setShowErrorMessage(true)->setError('Select a value from the workbook list.')
            ->setFormula1("Lists!\${$source}\$2:\${$source}\$".($count + 1))->setSqref("{$target}2:{$target}".self::VALIDATION_LAST_ROW);
        $sheet->setDataValidation("{$target}2:{$target}".self::VALIDATION_LAST_ROW, $v);
    }

    private function rangeValidation(Worksheet $sheet, string $target, string $source): void
    {
        $v = new DataValidation;
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            ->setFormula1("\${$source}\$2:\${$source}\$".self::VALIDATION_LAST_ROW)->setSqref("{$target}2:{$target}".self::VALIDATION_LAST_ROW);
        $sheet->setDataValidation("{$target}2:{$target}".self::VALIDATION_LAST_ROW, $v);
    }
}
