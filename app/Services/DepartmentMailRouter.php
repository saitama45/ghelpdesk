<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Maps inbound support addresses to the department that serves them, and back.
 *
 * The app deliberately keeps ONE mailbox and ONE SMTP credential — the support
 * address configured in Settings. Departments are separated purely by the
 * address a message was sent TO:
 *
 *     tgiservices@tablegroup.com      -> catch-all, no department
 *     fm@tablegroup.com               -> Facilities
 *     scm@tablegroup.com              -> Supply Chain
 *     tgiservices+fm@tablegroup.com   -> Facilities (sub-address form)
 *
 * Each departmental address is a domain alias (or sub-address) delivered into
 * that same mailbox, so this costs no extra accounts, no extra IMAP connections
 * and no extra credentials to rotate — while giving inbound mail a real routing
 * key for tickets.serving_department_id.
 *
 * Addresses are stored WHOLE rather than derived from a tag. Where the
 * department name sits is the mail provider's business, not ours: delivery is
 * resolved from everything BEFORE the first "+", so `fm@domain` needs a real
 * alias while `mailbox+fm@domain` rides an existing one. Storing the full string
 * keeps both shapes valid and survives a change of provider.
 *
 * This class is the single source of truth for that mapping. Anything that needs
 * to know "which addresses are ours" must ask here rather than reading
 * Setting::get('imap_username') directly, or it will treat departmental
 * addresses as third-party recipients — see EmailTicketService::syncCcsFromEmail,
 * where that mistake would auto-CC a department's own inbox onto its tickets.
 */
class DepartmentMailRouter
{
    public const CACHE_KEY = 'department_mail_routes';

    /**
     * The configured support mailbox, normalized. Empty when inbound mail has not
     * been set up yet — callers must treat that as "route nothing".
     */
    public function baseAddress(): string
    {
        return $this->normalize(Setting::get('imap_username', config('imap.accounts.default.username', '')));
    }

    /**
     * The inbound address that routes to a department, or null when it has none
     * (in which case its mail simply arrives on the shared support address).
     */
    public function addressFor(?int $departmentId): ?string
    {
        if (! $departmentId) {
            return null;
        }

        $address = $this->normalize(Department::whereKey($departmentId)->value('mail_address'));

        return $address !== '' ? $address : null;
    }

    /**
     * address => department_id, for every department carrying an address.
     */
    public function routes(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $routes = [];

            foreach (Department::query()->whereNotNull('mail_address')->get(['id', 'mail_address']) as $department) {
                $address = $this->normalize($department->mail_address);

                if ($address !== '') {
                    $routes[$address] = (int) $department->id;
                }
            }

            return $routes;
        });
    }

    /**
     * name => address for every ACTIVE department that can receive mail, for the
     * "you wrote to the wrong address" reply. Inactive departments are omitted:
     * telling a requester to write somewhere nobody is watching is worse than
     * omitting it.
     *
     * @return array<string, string>
     */
    public function directory(): array
    {
        return Department::query()
            ->whereNotNull('mail_address')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('mail_address', 'name')
            ->map(fn ($address) => $this->normalize($address))
            ->filter()
            ->all();
    }

    /**
     * Whether new requests must be addressed to a department rather than the
     * shared mailbox. Always true once routing is actually usable — there is no
     * opt-out, because a request nobody owns is the problem this exists to fix.
     *
     * The directory check is a safety interlock, not a preference: with no
     * departmental addresses configured there is nowhere to redirect anyone, so
     * enforcing would reject every inbound message and hand the sender an empty
     * list. Until the first address is set the shared mailbox behaves as it
     * always did.
     */
    public function requiresDepartmentAddress(): bool
    {
        return $this->directory() !== [];
    }

    /**
     * Every address that belongs to this helpdesk — the support mailbox plus all
     * departmental ones. Use this for exclusion lists (auto-CC, recipient filtering).
     */
    public function allAddresses(): array
    {
        $base = $this->baseAddress();
        $addresses = array_keys($this->routes());

        if ($base !== '') {
            $addresses[] = $base;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Decides whether a message is for us, and which department it routes to.
     *
     * @param  array<int, string>  $recipientAddresses  every address found on the
     *                                                  message's recipient surfaces
     * @return array{matched: bool, department_id: int|null}
     *
     * A hit on a departmental address wins over a hit on the support mailbox:
     * mail sent to both (a requester CC'ing the general inbox) still belongs to
     * the specific desk. A base-only hit matches with a null department — the
     * shared intake pool, which is exactly the behaviour before routing existed.
     */
    public function resolve(array $recipientAddresses): array
    {
        $base = $this->baseAddress();
        $routes = $this->routes();

        if ($base === '' && $routes === []) {
            return ['matched' => false, 'department_id' => null];
        }

        $matchedBase = false;

        foreach ($recipientAddresses as $address) {
            $address = $this->normalize($address);

            if ($address === '') {
                continue;
            }

            if (isset($routes[$address])) {
                return ['matched' => true, 'department_id' => $routes[$address]];
            }

            if ($base !== '' && $address === $base) {
                $matchedBase = true;
            }
        }

        return ['matched' => $matchedBase, 'department_id' => null];
    }

    /**
     * The outbound display name for a department, falling back to the global
     * sender name. The From ADDRESS never varies — only this label — so DKIM
     * alignment on the sending domain is preserved.
     */
    public function fromNameFor(?int $departmentId): string
    {
        $global = (string) Setting::get('mail_from_name', config('mail.from.name', ''));

        if (! $departmentId) {
            return $global;
        }

        $name = Department::whereKey($departmentId)->value('mail_from_name');

        return $name !== null && trim($name) !== '' ? $name : $global;
    }

    /**
     * The address replies should come back to: the department's own when it has
     * one, otherwise the shared support mailbox. This is what keeps a reply
     * routed to the same desk that sent it.
     */
    public function replyToFor(?int $departmentId): string
    {
        return $this->addressFor($departmentId) ?? $this->baseAddress();
    }

    /**
     * Whether an address would actually reach us. A departmental address on a
     * different domain from the support mailbox can still be correct (it just
     * needs forwarding set up), so this is advisory only — surfaced as a hint in
     * Settings, never enforced.
     */
    public function sharesDomainWithMailbox(?string $address): bool
    {
        $address = $this->normalize($address);
        $base = $this->baseAddress();

        if ($address === '' || $base === '' || ! str_contains($address, '@') || ! str_contains($base, '@')) {
            return true;
        }

        return substr(strrchr($address, '@'), 1) === substr(strrchr($base, '@'), 1);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function normalize($email): string
    {
        return strtolower(trim((string) $email));
    }
}
