<?php

namespace App\Support;

/**
 * The accounts the delete icon on /users removes for GOOD instead of archiving,
 * named by `services.hard_delete_accounts.email`.
 *
 * Deleting a login normally archives it (Settings → Account Archive) so it can
 * be restored. This is the exception, by address: the listed account skips the
 * archive and its rows leave the database on the spot — see
 * `AccountArchiveService::deleteUserPermanently()`. Everyone else is unaffected.
 *
 * `HARD_DELETE_ACCOUNT_EMAIL` takes one address or several separated by commas;
 * set it to an empty value to switch the exception off.
 */
final class HardDeleteAccounts
{
    /**
     * Lower-cased, trimmed addresses; empty when the exception is off.
     *
     * @return list<string>
     */
    public static function emails(): array
    {
        $raw = (string) config('services.hard_delete_accounts.email', '');

        return array_values(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', $raw),
        )));
    }

    public static function includes(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return in_array(mb_strtolower(trim($email)), self::emails(), true);
    }
}
