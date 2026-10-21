<?php
/**
 * When the FULL import runs: WP-Cron on the chosen interval, and a Sync now
 * button.
 *
 * THIS IS THE SAFETY NET, NOT THE MECHANISM. Since the plugin hears about
 * changes as they happen (class-btab-events.php), this run exists to catch
 * what a delivery missed: a webhook that was never created, a stretch while
 * the shop was down, an event dead-lettered after its last retry. It defaults
 * to nightly for that reason. A shop with no signing secret set falls back to
 * this alone, which is why it still walks the whole catalogue.
 *
 * Nothing here runs while a buyer's page renders. A buyer page never calls
 * btab at all - that is the whole point of copying products in.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Sync
{
    const HOOK = 'btab_wc_sync';
    const LOCK = 'btab_wc_sync_lock';
    const LAST = 'btab_wc_last_sync';

    public static function boot()
    {
        add_action(self::HOOK, array(__CLASS__, 'run_scheduled'));
        add_action('admin_post_btab_wc_sync_now', array(__CLASS__, 'handle_sync_now'));
        add_action('update_option_' . BTAB_WC_OPTION, array(__CLASS__, 'reschedule'), 10, 0);
    }

    /** Called on activation and whenever the interval setting changes. */
    public static function reschedule()
    {
        self::unschedule();
        $s = Btab_WC_Settings::get();
        $interval = isset($s['sync_interval']) ? $s['sync_interval'] : 'daily';
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, $interval, self::HOOK);
        }
    }

    public static function unschedule()
    {
        $ts = wp_next_scheduled(self::HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::HOOK);
            $ts = wp_next_scheduled(self::HOOK);
        }
    }

    public static function run_scheduled()
    {
        self::run('schedule');
    }

    /**
     * One run, guarded by a short lock.
     *
     * WP-Cron fires on a visitor's request, so two visitors a second apart can
     * start two runs; without the lock they race on the same products and
     * double-import photos. The lock expires on its own, so a crashed run does
     * not block the next one forever.
     *
     * @param string $source 'schedule' | 'manual'
     * @return array{ok:bool,stats:array,problems:array,message:string}
     */
    public static function run($source = 'manual')
    {
        if (get_transient(self::LOCK)) {
            return array(
                'ok' => false,
                'stats' => array(),
                'problems' => array(),
                'message' => 'A sync is already running. Try again in a minute.',
            );
        }
        set_transient(self::LOCK, time(), 15 * MINUTE_IN_SECONDS);

        $client = btab_wc_client();
        if (!$client) {
            delete_transient(self::LOCK);
            return array(
                'ok' => false,
                'stats' => array(),
                'problems' => array(),
                'message' => 'Set the API base and a publishable key first.',
            );
        }

        $importer = new Btab_WC_Importer($client);
        try {
            $stats = $importer->run();
        } catch (Exception $e) {
            delete_transient(self::LOCK);
            return array(
                'ok' => false,
                'stats' => $importer->stats(),
                'problems' => array_merge($importer->problems(), array($e->getMessage())),
                'message' => 'The sync stopped: ' . $e->getMessage(),
            );
        }
        delete_transient(self::LOCK);

        $problems = $importer->problems();
        update_option(self::LAST, array(
            'at' => time(),
            'source' => $source,
            'stats' => $stats,
            'problems' => $problems,
        ), false);

        return array(
            'ok' => empty($problems),
            'stats' => $stats,
            'problems' => $problems,
            'message' => self::describe($stats, $problems),
        );
    }

    /** The one-line report an owner reads, in plain words. */
    public static function describe(array $stats, array $problems)
    {
        $bits = array();
        if (!empty($stats['created'])) {
            $bits[] = (int) $stats['created'] . ' added';
        }
        if (!empty($stats['updated'])) {
            $bits[] = (int) $stats['updated'] . ' updated';
        }
        if (!empty($stats['images'])) {
            $bits[] = (int) $stats['images'] . ' photo' . ((int) $stats['images'] === 1 ? '' : 's') . ' copied in';
        }
        if (!empty($stats['failed'])) {
            $bits[] = (int) $stats['failed'] . ' failed';
        }
        $head = $bits ? implode(', ', $bits) . '.' : 'Nothing changed.';
        if ($problems) {
            $head .= ' ' . count($problems) . ' problem' . (count($problems) === 1 ? '' : 's') . ': ' . implode(' · ', array_slice($problems, 0, 3));
        }
        return $head;
    }

    public static function handle_sync_now()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You cannot manage this shop.');
        }
        check_admin_referer('btab_wc_sync_now');
        $result = self::run('manual');
        set_transient('btab_wc_sync_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=' . Btab_WC_Settings::PAGE));
        exit;
    }

    /** @return array|null the last run, for the settings page. */
    public static function last()
    {
        $last = get_option(self::LAST, null);
        return is_array($last) ? $last : null;
    }
}
