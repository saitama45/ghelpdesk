<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WIGS (Wildly Important Goals) — Yardstick reference / configuration tables.
 *
 * These tables back the admin-editable "Yardstick" tab. They double as the
 * source of truth for the PCF dropdowns:
 *   - wigs_performance_standards.general  -> "Performance Standards" select
 *   - wigs_track_values.name              -> "Value-Based Alignment" select
 *
 * Default content (from the company yardstick spec) is seeded inline so the
 * module is usable immediately after migrating, even when seeders aren't run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wigs_performance_standards', function (Blueprint $table) {
            $table->id();
            $table->string('general');                 // e.g. VOLUME/VALUE
            $table->text('specific')->nullable();      // description of how it is measured
            $table->text('rating_4')->nullable();      // passing / highest
            $table->text('rating_3')->nullable();
            $table->text('rating_2')->nullable();
            $table->text('rating_1')->nullable();      // lowest
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // TRACK values (Be Trustworthy, For Rehire, Be Fearless, Be Humble)
        Schema::create('wigs_track_values', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // value label
            $table->text('track_question')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wigs_track_guide_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_value_id')->constrained('wigs_track_values')->cascadeOnDelete();
            $table->text('question');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Global A/B/C/D rating definitions
        Schema::create('wigs_track_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('rating', 5);               // A, B, C, D
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Per-quarter guideline (Q1 Be Trustworthy ..., Q2 For Rehire ...)
        Schema::create('wigs_quarter_guidelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('quarter');    // 1-4
            $table->string('value_name')->nullable();  // e.g. Be Trustworthy
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('wigs_quarter_guidelines');
        Schema::dropIfExists('wigs_track_ratings');
        Schema::dropIfExists('wigs_track_guide_questions');
        Schema::dropIfExists('wigs_track_values');
        Schema::dropIfExists('wigs_performance_standards');
    }

    private function seedDefaults(): void
    {
        $now = now();

        $standards = [
            ['VOLUME/VALUE', 'e.g. revenue billings, NSO', '101% to 119% in target volume', '90-100% in target volume', '76-89% in target volume', 'Less than 75% in target volume'],
            ['TIMELINESS AND DEADLINES', 'Targets fulfilled based on the deadlines', 'Fulfills target before the deadline', 'Fulfills target on the deadline', 'Delayed submission/fulfillment', 'Does not submit / fails to complete and fulfill'],
            ['SATISFACTION RATING', 'Customer satisfaction to the services', '100% and above satisfactory rating', '90%-99% satisfactory rating', '80%-89% satisfactory rating', 'Below 80% satisfactory rating'],
            ['FREQUENCY OR CYCLE TIME', 'No. of requests processed within an hour/ time standard', '90% above meets the time standard', '80-90% meets the time standard', '60-79% meets the time standard', 'Below 60% of the time standard'],
            ['AVERAGE AGING', 'No. of days fulfilled vs specified lead time', 'Less than 5% of the aging target', '+/-5% of the aging target', 'Greater than 5% but less than 20% higher than target aging', '20% and over the aging target'],
            ['COMPLETENESS', 'No. of required vs submitted/fulfilled', '100% complete', '90%-99% complete', '80%-89% complete', 'Below 80%'],
            ['ACCURACY', 'e.g. No. of errors, discrepancies in documentation, products delivered according to customer’s specifications', '100% accurate', '90%-99% accurate', '80%-89% accurate', 'Below 80%'],
            ['COST MANAGEMENT', 'All acquired assets, activities vs allocated budget for the year', 'Less than 90% actual spending vs allocated budget', '90%-100% actual spending vs allocated budget', '101%-120% actual spending vs allocated budget', 'over 120% actual spending vs allocated budget'],
            ['ATTENDANCE', 'Total # of Absences within a quarter for the period beyond paid leaves', 'Perfect attendance to 1 day absent', 'Two to three (2-3) days absences or leave without pay', 'Four to six (4-6) days absences or leave without pay', 'More than six (6) days absences or leave without pay'],
            ['PUNCTUALITY', 'Average # of Tardiness within a quarter beyond the 10-minute grace period', 'No tardiness or late', 'Maximum of six (6) times tardy', 'Maximum of seven (7) to twelve (12) times tardy', 'More than twelve (12) times tardy'],
        ];

        $sort = 0;
        foreach ($standards as $s) {
            DB::table('wigs_performance_standards')->insert([
                'general' => $s[0], 'specific' => $s[1],
                'rating_4' => $s[2], 'rating_3' => $s[3], 'rating_2' => $s[4], 'rating_1' => $s[5],
                'sort_order' => $sort++, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $values = [
            ['Be Trustworthy', 'Can I rely/depend on this Team Member during difficult situations? Mapagkakatiwalaan ko ba ang Team Member na ito sa mga mahihirap na sitwasyon?', [
                'How consistent is the TM in achieving the agreed work goals and expectations?',
                'How committed is the TM in contributing to the overall health of the Team?',
                'Does the TM have a sense of urgency in addressing or supporting in critical situations of the Team?',
                'Does the Team Member appreciate and see that his/her work as a blessing, shows gratitude and commitment in what is entrusted to him/her?',
            ]],
            ['For Rehire', 'Will I enthusiastically rehire this Team Member? Masaya ba akong makatrabaho muli ang Team Member na ito?', [
                'How committed is the TM in aligning to the values of the Organization?',
                'Does the TM exert his/her best effort in cultivating his/her relationship with the Leader and other Team Members?',
                'Does the presence of the TM in the Team add value to the overall objectives of the Department and Organization?',
                'Is the Team Member resistant to change and at times does not show openness or willingness to participate in activities and programs?',
                'Does the Team Member have behaviors and beliefs that are hard to manage or correct?',
            ]],
            ['Be Fearless', 'Does this Team Member find ways to become better and do better? Gumagawa ba ang Team Member na ito ng mga paraan upang mas maging mahusay o maayos ang aking trabaho?', [
                'Can the TM identify the things that he/she needs to improve in his/her workflow?',
                'Does the Team Member commit the same mistakes in spite of receiving clear feedback and knowing what to improve on?',
            ]],
            ['Be Humble', 'Does this Team Member openly accept feedback and immediately learn from it? Malugod bang tumatanggap ng mga feedback (puna, obserbasyon o payo) ang Team Member na ito at natututo mula dito?', [
                'Does the TM implement the agreed changes and improvements discussed during regular feedback sessions with the Leader?',
                'Is the TM open to concepts and principles presented in various company initiatives and shows appreciation by applying it in his/her life?',
                'Is the Team Member open to correction but does not show a change in behavior and view about the feedback given?',
            ]],
        ];

        $vSort = 0;
        foreach ($values as $v) {
            $id = DB::table('wigs_track_values')->insertGetId([
                'name' => $v[0], 'track_question' => $v[1],
                'sort_order' => $vSort++, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $qSort = 0;
            foreach ($v[2] as $question) {
                DB::table('wigs_track_guide_questions')->insert([
                    'track_value_id' => $id, 'question' => $question,
                    'sort_order' => $qSort++,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $ratings = [
            ['A', 'Displays high level of alignment with the Company values as evidenced in consistently achieving agreed goals and expectations at work. The TM does not retreat from critical situations but engages them in a manner that cultivates personal development, effective communication and collaboration.'],
            ['B', 'Displays alignment with the Company values in work situations but displaying the need to be more consistent especially in critical situations at work.'],
            ['C', 'Level of alignment with the Company values is emerging and displayed in how the TM responds to structured or unstructured growth opportunities. The opportunities are explicitly seen in the TM’s work behavior on multiple occasions.'],
            ['D', 'No alignment with the Company values as seen in a pattern of behaviors at work and his relationships. There is low to no commitment to align with the values of the Organization.'],
        ];
        $rSort = 0;
        foreach ($ratings as $r) {
            DB::table('wigs_track_ratings')->insert([
                'rating' => $r[0], 'description' => $r[1], 'sort_order' => $rSort++,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $guidelines = [
            [1, 'Be Trustworthy', 'consistently meeting quarterly targets, showing reliability'],
            [2, 'For Rehire', 'record of achievements / mindset'],
            [3, 'Be Fearless', 'proactive self-improvement and the application of new skills.'],
            [4, 'Be Humble', 'improvement and adjustments from feedback'],
        ];
        foreach ($guidelines as $g) {
            DB::table('wigs_quarter_guidelines')->insert([
                'quarter' => $g[0], 'value_name' => $g[1], 'description' => $g[2],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
