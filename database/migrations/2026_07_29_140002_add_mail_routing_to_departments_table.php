<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives each department an inbound address and an outbound display name,
 * WITHOUT giving it a second mail configuration.
 *
 * The app keeps exactly ONE IMAP account and ONE SMTP credential — the support
 * mailbox in Settings. A department is distinguished only by the address a
 * message was sent TO, which is stored here in full:
 *
 *     fm@tablegroup.com              -> Facilities        (domain alias)
 *     scm@tablegroup.com             -> Supply Chain
 *     tgiservices+fm@tablegroup.com  -> Facilities        (sub-address form)
 *
 * The full address is stored rather than a tag the app splices on, because where
 * the department name sits in the address is the mail provider's business, not
 * ours: delivery is decided by everything BEFORE the first "+", so a leading
 * department name requires a real mailbox/alias on a domain you control, while a
 * trailing one works as a sub-address of an existing mailbox. Storing the whole
 * string keeps both shapes valid and means changing provider never invalidates
 * the routing table.
 *
 * Every one of these addresses must be delivered (aliased/forwarded) into the
 * single support mailbox — the app polls that one inbox and routes on the
 * recipient it finds.
 *
 * No address-history table is needed: threading keys off Message-ID and the
 * ticket key (App\Mail\Concerns\ThreadsTicketMail), never the recipient address,
 * so re-pointing a department cannot orphan a live thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            if (! Schema::hasColumn('departments', 'mail_address')) {
                $table->string('mail_address', 255)->nullable()->after('code');
            }

            if (! Schema::hasColumn('departments', 'mail_from_name')) {
                // Outbound display name, e.g. 'Facilities Service Desk'. Null falls
                // back to the global mail_from_name setting.
                $table->string('mail_from_name', 100)->nullable()->after('mail_address');
            }
        });

        if ($this->hasAddressIndex()) {
            return;
        }

        // Uniqueness has to be a FILTERED index, not a plain unique constraint.
        // SQL Server treats NULLs as equal for uniqueness, so a bare
        // $table->unique('mail_address') would allow only ONE department without an
        // address — i.e. it would fail on the second row the moment this deploys.
        // sqlite (test suite) follows the standard and permits many NULLs, so the
        // ordinary unique index is correct there.
        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            DB::statement('
                CREATE UNIQUE INDEX departments_mail_address_unique
                ON departments (mail_address)
                WHERE mail_address IS NOT NULL
            ');
        } else {
            Schema::table('departments', function (Blueprint $table) {
                $table->unique('mail_address', 'departments_mail_address_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'mail_address') && $this->hasAddressIndex()) {
            if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
                DB::statement('DROP INDEX departments_mail_address_unique ON departments');
            } else {
                Schema::table('departments', function (Blueprint $table) {
                    $table->dropUnique('departments_mail_address_unique');
                });
            }
        }

        Schema::table('departments', function (Blueprint $table) {
            if (Schema::hasColumn('departments', 'mail_address')) {
                $table->dropColumn('mail_address');
            }

            if (Schema::hasColumn('departments', 'mail_from_name')) {
                $table->dropColumn('mail_from_name');
            }
        });
    }

    /**
     * Keeps up() re-runnable: the column guards above are no-ops on a second
     * pass, and without this the CREATE INDEX would abort the whole deploy.
     */
    private function hasAddressIndex(): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlsrv') {
            return (int) $connection->scalar(
                "SELECT COUNT(*) FROM sys.indexes WHERE name = 'departments_mail_address_unique'"
            ) > 0;
        }

        return (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'departments_mail_address_unique'"
        ) > 0;
    }
};
