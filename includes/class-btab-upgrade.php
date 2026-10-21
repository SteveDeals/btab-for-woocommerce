<?php
/**
 * What happens when the plugin is updated.
 *
 * A shop updates by dropping new files in. Nothing runs, nothing is told, and
 * any change that needs existing data touched silently does not happen — the
 * shop just keeps whatever state the old version left behind. So the installed
 * version is remembered, and on the first load after it changes the steps for
 * the versions in between are run.
 *
 * TWO RULES FOR EVERY STEP HERE:
 *   it must be safe to run twice, because a shop can roll back and update again
 *   it must not do slow work inline, because this runs on somebody's page load
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Upgrade
{
    const OPTION = 'btab_wc_version';

    /**
     * '1' while an upgrade wants a resync, '0' once one is really booked.
     *
     * ALWAYS PRESENT, NEVER DELETED, AND AUTOLOADED. Both options in this class
     * are read on EVERY page load, and WordPress charges one database query per
     * request for any option that is not autoloaded - whether it exists or not.
     * Absence is not free: get_option() checks `alloptions`, then the
     * `notoptions` cache, then the object cache, and only then queries; without
     * a persistent object cache those two caches are empty at the start of every
     * request, so a MISSING option costs exactly as much as a present one.
     * (wp-includes/option.php, get_option(), read at WordPress 7.1.3.)
     *
     * That is why this is a '1'/'0' flag rather than present/absent: a deleted
     * flag would have gone on costing a query per request on every shop for
     * ever, which is what it was doing.
     */
    const PENDING_RESYNC = 'btab_wc_pending_resync';

    /** Its OWN hook. See resync_soon() for why it cannot share the recurring one. */
    const RESYNC_HOOK = 'btab_wc_upgrade_sync';

    public static function boot()
    {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade'), 20);
        add_action(self::RESYNC_HOOK, array(__CLASS__, 'run_resync'));
    }

    /**
     * The version this shop last ran, or '' for a shop that has never recorded
     * one. Free: the option is autoloaded, so this reads from the one
     * `alloptions` query WordPress already makes. See PENDING_RESYNC above for
     * why that matters on every single page load.
     */
    public static function installed()
    {
        return (string) get_option(self::OPTION, '');
    }

    /**
     * Write an option so it is AUTOLOADED, on every WordPress we support.
     *
     * Not simply update_option($k, $v, true): that only moves an EXISTING row's
     * autoload flag from WordPress 6.6, where the autoload rework landed. This
     * plugin's header promises 6.5, and on 6.5 the third argument is honoured
     * only when the option is being created - so an existing non-autoloaded row
     * would have silently stayed non-autoloaded and the fix would have done
     * nothing on exactly the shops it is aimed at. delete + add works on both.
     *
     * The option is absent for an instant in between. Every step in this class
     * is required to be safe to run twice, and the worst a racing request can
     * do is re-book an idempotent resync, so that is acceptable here - but it is
     * the reason this helper is not used for anything that is not.
     */
    private static function write_autoloaded($key, $value)
    {
        delete_option($key);
        add_option($key, $value, '', true);
    }

    public static function maybe_upgrade()
    {
        // BEFORE the version check, not after. A resync that failed to book
        // leaves the flag set, but the version is already current, so every
        // later load took the early return below and nothing ever tried again
        // — the retry existed and was unreachable.
        self::book_resync();

        $was = self::installed();
        if ($was === BTAB_WC_VERSION) {
            return;
        }
        // Write the new version FIRST. A step that fatals must not run again on
        // every single page load afterwards; a shop owner would see a broken
        // site rather than one missed step.
        self::write_autoloaded(self::OPTION, BTAB_WC_VERSION);

        // btab's reference values (what the price and stock SHOULD be) only
        // started being recorded in 0.2.0. A shop updating from before that has
        // products — and variations — with none, so the guard falls back to
        // whatever is stored and cannot tell a stale value from a right one.
        // One resync fixes every product at once.
        if ($was === '' || version_compare($was, '0.2.0', '<')) {
            self::write_autoloaded(self::PENDING_RESYNC, '1');
            self::book_resync();
        }

        // 0.2.1: both of this class's options were written non-autoloaded, so
        // every page load of every shop without a persistent object cache paid
        // two database queries for them - one for the version, one for a
        // pending-resync flag that is normally not even there. Rewrite them
        // autoloaded, and give the flag the '0' it now needs so that it is
        // never absent. The current value is carried over, so a shop that is
        // mid-upgrade with a resync still owed does not lose it.
        if ($was !== '' && version_compare($was, '0.2.1', '<')) {
            $pending = get_option(self::PENDING_RESYNC);
            self::write_autoloaded(self::OPTION, BTAB_WC_VERSION);
            self::write_autoloaded(self::PENDING_RESYNC, $pending ? '1' : '0');
        }
    }

    /**
     * Book the one-off resync, and keep asking until it is really booked.
     *
     * THE FIRST VERSION OF THIS NEVER RAN ON A REAL SHOP. It reused the
     * recurring `btab_wc_sync` hook and skipped when `wp_next_scheduled()`
     * found one — which it always does, because activation schedules the
     * recurring sync. The version was then written anyway, so it never tried
     * again. My own test hid it: it cleared the schedule first, which is the
     * one state a real shop is never in.
     *
     * So: its OWN hook, which nothing else books, and the pending flag is
     * cleared only when `wp_schedule_single_event()` actually returns true.
     * If cron is wedged or a filter refuses it, the next page load tries again
     * — which is why this is called BEFORE the version check in
     * maybe_upgrade(), not after it. Called after, the retry could never run:
     * the version was already current, so every later load returned early.
     */
    public static function book_resync()
    {
        if (!get_option(self::PENDING_RESYNC)) {
            return;
        }
        // Cleared to '0', not deleted: see PENDING_RESYNC. A deleted flag is
        // still one query per request, for ever.
        if (wp_next_scheduled(self::RESYNC_HOOK)) {
            self::write_autoloaded(self::PENDING_RESYNC, '0');
            return;
        }
        if (wp_schedule_single_event(time() + 60, self::RESYNC_HOOK) !== false) {
            self::write_autoloaded(self::PENDING_RESYNC, '0');
        }
    }

    /**
     * The one-off resync itself. A full sync walks the catalogue and copies
     * photos, so it runs on cron with nobody waiting, never on a page load.
     */
    public static function run_resync()
    {
        Btab_WC_Sync::run('upgrade');
    }
}
