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

    /** Set when an upgrade wants a resync; cleared only once one is really booked. */
    const PENDING_RESYNC = 'btab_wc_pending_resync';

    /** Its OWN hook. See resync_soon() for why it cannot share the recurring one. */
    const RESYNC_HOOK = 'btab_wc_upgrade_sync';

    public static function boot()
    {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade'), 20);
        add_action(self::RESYNC_HOOK, array(__CLASS__, 'run_resync'));
    }

    /** The version this shop last ran, or '' for a shop that has never recorded one. */
    public static function installed()
    {
        return (string) get_option(self::OPTION, '');
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
        update_option(self::OPTION, BTAB_WC_VERSION, false);

        // btab's reference values (what the price and stock SHOULD be) only
        // started being recorded in 0.2.0. A shop updating from before that has
        // products — and variations — with none, so the guard falls back to
        // whatever is stored and cannot tell a stale value from a right one.
        // One resync fixes every product at once.
        if ($was === '' || version_compare($was, '0.2.0', '<')) {
            update_option(self::PENDING_RESYNC, '1', false);
            self::book_resync();
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
        if (wp_next_scheduled(self::RESYNC_HOOK)) {
            delete_option(self::PENDING_RESYNC);
            return;
        }
        if (wp_schedule_single_event(time() + 60, self::RESYNC_HOOK) !== false) {
            delete_option(self::PENDING_RESYNC);
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
