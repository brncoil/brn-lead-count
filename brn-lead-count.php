<?php
/**
 * Plugin Name: BRN Lead Count
 * Description: Counts and logs lead actions (phone clicks, WhatsApp clicks, email clicks, and form submissions).
 * Version: 1.3.6
 * Author: BRN
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ΓöÇΓöÇ Lifecycle hooks (must be outside the class, at file scope) ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //

register_activation_hook(
    __FILE__,
    function () {
        if ( ! wp_next_scheduled( 'brn_lead_count_daily_update_check' ) ) {
            wp_schedule_event( time(), 'daily', 'brn_lead_count_daily_update_check' );
        }

        if ( ! wp_next_scheduled( 'brn_lead_count_daily_report_event' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'brn_lead_count_daily_report_event' );
        }
    }
);

register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'brn_lead_count_daily_update_check' );
        wp_clear_scheduled_hook( 'brn_lead_count_daily_report_event' );
    }
);

if ( ! class_exists( 'BRN_Lead_Count' ) ) {
    class BRN_Lead_Count {
        const OPTION_STATS    = 'brn_lead_count_stats';
        const OPTION_SETTINGS = 'brn_lead_count_settings';
        const NONCE_ACTION    = 'brn_lead_count_track';
        const MAX_LOGS_DEFAULT = 300;
        const OPTION_REPORT_SCHEDULE_HASH = 'brn_lead_count_report_schedule_hash';
        const OPTION_LAST_REPORT_SENT = 'brn_lead_count_last_report_sent';

        /** Nonce action for the manual update-check AJAX call. */
        const NONCE_CHECK_UPDATES = 'brn_lead_count_check_updates';

        public function __construct() {
            add_action( 'init', array( $this, 'maybe_init_options' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
            add_action( 'wp_ajax_brn_lead_count_track', array( $this, 'track_event' ) );
            add_action( 'wp_ajax_nopriv_brn_lead_count_track', array( $this, 'track_event' ) );

            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );

            // Updater: register WP update-system hooks.
            add_action( 'admin_init', array( $this, 'init_updater' ) );

            // Cron callback ΓÇö refresh update cache once per day.
            add_action( 'brn_lead_count_daily_update_check', array( $this, 'run_update_check' ) );
            add_action( 'brn_lead_count_daily_report_event', array( $this, 'send_daily_report_email' ) );

            // Manual update-check AJAX (admin only).
            add_action( 'wp_ajax_brn_lead_count_check_updates', array( $this, 'ajax_check_updates' ) );

            // Keep daily report cron aligned to the configured send time.
            add_action( 'admin_init', array( $this, 'ensure_daily_report_schedule' ) );

            // Load Chart.js on our admin page only.
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        }

        public function maybe_init_options() {
            if ( false === get_option( self::OPTION_SETTINGS ) ) {
                add_option(
                    self::OPTION_SETTINGS,
                    array(
                        'enable_logging' => 1,
                        'max_logs'       => self::MAX_LOGS_DEFAULT,
                    )
                );
            }

            if ( false === get_option( self::OPTION_STATS ) ) {
                add_option( self::OPTION_STATS, $this->get_empty_stats() );
            }
        }

        private function get_empty_stats() {
            return array(
                'counts' => array(
                    'phone'      => 0,
                    'whatsapp'   => 0,
                    'email'      => 0,
                    'form_submit'=> 0,
                    'total'      => 0,
                ),
                'logs'   => array(),
            );
        }

        /**
         * Ensure legacy log rows include keys required by newer features.
         *
         * @return void
         */
        private function maybe_migrate_stats_schema() {
            $stats = get_option( self::OPTION_STATS, array() );

            if ( empty( $stats['logs'] ) || ! is_array( $stats['logs'] ) ) {
                return;
            }

            $changed = false;
            foreach ( $stats['logs'] as &$log ) {
                if ( ! is_array( $log ) ) {
                    continue;
                }

                if ( empty( $log['id'] ) ) {
                    $log['id'] = wp_generate_uuid4();
                    $changed   = true;
                }

                if ( ! isset( $log['is_test'] ) ) {
                    $log['is_test'] = 0;
                    $changed        = true;
                }
            }
            unset( $log );

            if ( $changed ) {
                update_option( self::OPTION_STATS, $stats, false );
            }
        }

        private function get_settings() {
            $defaults = array(
                'enable_logging' => 1,
                'max_logs'       => self::MAX_LOGS_DEFAULT,
                'test_users'     => '',
                'test_ips'       => '',
                'report_emails'  => '',
                'report_send_time' => '09:00',
                'report_language' => 'en',
            );

            $settings = get_option( self::OPTION_SETTINGS, array() );
            $settings = wp_parse_args( $settings, $defaults );

            $settings['enable_logging'] = empty( $settings['enable_logging'] ) ? 0 : 1;
            $settings['max_logs'] = max( 10, min( 2000, absint( $settings['max_logs'] ) ) );
            $settings['test_users'] = isset( $settings['test_users'] ) ? (string) $settings['test_users'] : '';
            $settings['test_ips'] = isset( $settings['test_ips'] ) ? (string) $settings['test_ips'] : '';
            $settings['report_emails'] = isset( $settings['report_emails'] ) ? (string) $settings['report_emails'] : '';
            $settings['report_send_time'] = isset( $settings['report_send_time'] ) ? (string) $settings['report_send_time'] : '09:00';
            $settings['report_language'] = ( isset( $settings['report_language'] ) && 'he' === $settings['report_language'] ) ? 'he' : 'en';

            return $settings;
        }

        /**
         * Return a normalized site domain for report subject/body.
         *
         * @return string
         */
        private function get_site_domain() {
            $host = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( empty( $host ) ) {
                $host = preg_replace( '#^https?://#', '', (string) home_url() );
                $host = preg_replace( '#/.*$#', '', (string) $host );
            }

            return strtolower( preg_replace( '/^www\./i', '', (string) $host ) );
        }

        /**
         * Parse a comma/newline list of user IDs and usernames.
         *
         * @param string $raw
         * @return array
         */
        private function parse_test_users( $raw ) {
            $result = array(
                'ids'       => array(),
                'usernames' => array(),
            );

            $items = preg_split( '/[\r\n,]+/', (string) $raw );
            if ( ! is_array( $items ) ) {
                return $result;
            }

            foreach ( $items as $item ) {
                $item = trim( $item );
                if ( '' === $item ) {
                    continue;
                }

                if ( ctype_digit( $item ) ) {
                    $result['ids'][] = (int) $item;
                } else {
                    $result['usernames'][] = strtolower( sanitize_user( $item ) );
                }
            }

            $result['ids']       = array_values( array_unique( $result['ids'] ) );
            $result['usernames'] = array_values( array_unique( $result['usernames'] ) );

            return $result;
        }

        /**
         * Parse a comma/newline list of exact IP strings.
         *
         * @param string $raw
         * @return array
         */
        private function parse_test_ips( $raw ) {
            $ips   = array();
            $items = preg_split( '/[\r\n,]+/', (string) $raw );
            if ( ! is_array( $items ) ) {
                return $ips;
            }

            foreach ( $items as $item ) {
                $item = trim( $item );
                if ( '' === $item ) {
                    continue;
                }
                $ips[] = $item;
            }

            return array_values( array_unique( $ips ) );
        }

        /**
         * Determine whether this lead should be treated as a test lead.
         *
         * @param array  $settings
         * @param string $request_ip
         * @param int    $user_id
         * @param bool   $manual_test
         * @return bool
         */
        private function is_test_lead( $settings, $request_ip, $user_id, $manual_test ) {
            if ( $manual_test ) {
                return true;
            }

            $parsed_users = $this->parse_test_users( isset( $settings['test_users'] ) ? $settings['test_users'] : '' );
            if ( $user_id > 0 && in_array( (int) $user_id, $parsed_users['ids'], true ) ) {
                return true;
            }

            if ( $user_id > 0 && ! empty( $parsed_users['usernames'] ) ) {
                $user = get_userdata( $user_id );
                if ( $user && isset( $user->user_login ) ) {
                    if ( in_array( strtolower( (string) $user->user_login ), $parsed_users['usernames'], true ) ) {
                        return true;
                    }
                }
            }

            $test_ips = $this->parse_test_ips( isset( $settings['test_ips'] ) ? $settings['test_ips'] : '' );
            if ( '' !== $request_ip && in_array( $request_ip, $test_ips, true ) ) {
                return true;
            }

            return false;
        }

        /**
         * Parse comma/newline list of report emails.
         *
         * @param string $raw
         * @return array
         */
        private function parse_report_emails( $raw ) {
            $emails = array();
            $items  = preg_split( '/[\r\n,;]+/', (string) $raw );

            if ( ! is_array( $items ) ) {
                return $emails;
            }

            foreach ( $items as $item ) {
                $email = sanitize_email( trim( $item ) );
                if ( '' !== $email && is_email( $email ) ) {
                    $emails[] = $email;
                }
            }

            return array_values( array_unique( $emails ) );
        }

        /**
         * Build counts from logs while excluding test leads.
         *
         * @param array $logs
         * @return array
         */
        private function rebuild_counts_from_logs( $logs ) {
            $counts = array(
                'phone'       => 0,
                'whatsapp'    => 0,
                'email'       => 0,
                'form_submit' => 0,
                'total'       => 0,
            );

            if ( ! is_array( $logs ) ) {
                return $counts;
            }

            foreach ( $logs as $log ) {
                if ( ! is_array( $log ) || ! empty( $log['is_test'] ) ) {
                    continue;
                }

                $type = isset( $log['type'] ) ? $log['type'] : '';
                if ( isset( $counts[ $type ] ) ) {
                    $counts[ $type ]++;
                    $counts['total']++;
                }
            }

            return $counts;
        }

        /**
         * Best-effort user-agent parser for table/report display.
         *
         * @param string $ua
         * @return array
         */
        private function parse_user_agent_data( $ua ) {
            $ua = (string) $ua;

            $browser = 'Unknown';
            if ( false !== stripos( $ua, 'Edg/' ) ) {
                $browser = 'Edge';
            } elseif ( false !== stripos( $ua, 'Chrome/' ) && false === stripos( $ua, 'Edg/' ) ) {
                $browser = 'Chrome';
            } elseif ( false !== stripos( $ua, 'Firefox/' ) ) {
                $browser = 'Firefox';
            } elseif ( false !== stripos( $ua, 'Safari/' ) && false === stripos( $ua, 'Chrome/' ) ) {
                $browser = 'Safari';
            } elseif ( false !== stripos( $ua, 'OPR/' ) || false !== stripos( $ua, 'Opera' ) ) {
                $browser = 'Opera';
            }

            $device = 'Desktop';
            if ( preg_match( '/ipad|tablet/i', $ua ) ) {
                $device = 'Tablet';
            } elseif ( preg_match( '/mobile|android|iphone/i', $ua ) ) {
                $device = 'Mobile';
            }

            return array(
                'browser' => $browser,
                'device'  => $device,
            );
        }

        /**
         * Resolve country by IP using cached lookup.
         *
         * @param string $ip
         * @return array
         */
        private function resolve_country_by_ip( $ip ) {
            $empty = array(
                'code' => '',
                'name' => '',
            );

            if ( '' === $ip || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
                return $empty;
            }

            $cache_key = 'brn_geo_' . md5( $ip );
            $cached    = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }

            $response = wp_remote_get(
                'https://ipwho.is/' . rawurlencode( $ip ),
                array(
                    'timeout' => 4,
                )
            );

            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return $empty;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) || empty( $body['success'] ) ) {
                return $empty;
            }

            $result = array(
                'code' => ! empty( $body['country_code'] ) ? strtoupper( sanitize_text_field( $body['country_code'] ) ) : '',
                'name' => ! empty( $body['country'] ) ? sanitize_text_field( $body['country'] ) : '',
            );

            set_transient( $cache_key, $result, WEEK_IN_SECONDS * 2 );

            return $result;
        }

        /**
         * Calculate next run timestamp for daily report at configured local time.
         *
         * @param string $time_string HH:MM
         * @return int
         */
        private function get_next_report_timestamp( $time_string ) {
            $time_string = preg_match( '/^\d{2}:\d{2}$/', (string) $time_string ) ? $time_string : '09:00';
            $parts       = explode( ':', $time_string );
            $hour        = (int) $parts[0];
            $minute      = (int) $parts[1];

            $tz  = wp_timezone();
            $now = new DateTimeImmutable( 'now', $tz );
            $run = $now->setTime( $hour, $minute, 0 );
            if ( $run <= $now ) {
                $run = $run->modify( '+1 day' );
            }

            return $run->getTimestamp();
        }

        /**
         * Ensure daily report cron timing matches configured settings.
         *
         * @return void
         */
        public function ensure_daily_report_schedule() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->get_settings();
            $hash     = (string) $settings['report_send_time'];

            $stored_hash = (string) get_option( self::OPTION_REPORT_SCHEDULE_HASH, '' );
            $next        = wp_next_scheduled( 'brn_lead_count_daily_report_event' );

            if ( $stored_hash === $hash && $next ) {
                return;
            }

            wp_clear_scheduled_hook( 'brn_lead_count_daily_report_event' );
            wp_schedule_event( $this->get_next_report_timestamp( $settings['report_send_time'] ), 'daily', 'brn_lead_count_daily_report_event' );
            update_option( self::OPTION_REPORT_SCHEDULE_HASH, $hash, false );
        }

        /**
         * Compute report counters for a time window (inclusive start/end).
         *
         * @param array $logs
         * @param int   $start_ts
         * @param int   $end_ts
         * @return array
         */
        private function get_window_counts( $logs, $start_ts, $end_ts ) {
            $counts = array(
                'phone'       => 0,
                'whatsapp'    => 0,
                'email'       => 0,
                'form_submit' => 0,
                'total'       => 0,
            );
            $tz = wp_timezone();

            foreach ( $logs as $log ) {
                if ( ! is_array( $log ) || empty( $log['time'] ) || ! empty( $log['is_test'] ) ) {
                    continue;
                }

                $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $log['time'], $tz );
                $ts = $dt ? $dt->getTimestamp() : false;
                if ( false === $ts || $ts < $start_ts || $ts > $end_ts ) {
                    continue;
                }

                $type = isset( $log['type'] ) ? $log['type'] : '';
                if ( isset( $counts[ $type ] ) ) {
                    $counts[ $type ]++;
                    $counts['total']++;
                }
            }

            return $counts;
        }

        /**
         * Build report payload with requested comparisons.
         *
         * @param int|null $reference_ts
         * @return array
         */
        private function build_daily_report_payload( $reference_ts = null ) {
            $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );
            $logs  = isset( $stats['logs'] ) && is_array( $stats['logs'] ) ? $stats['logs'] : array();

            $tz   = wp_timezone();
            $now  = new DateTimeImmutable( '@' . ( $reference_ts ? (int) $reference_ts : time() ) );
            $now  = $now->setTimezone( $tz );

            $report_day = $now->modify( '-1 day' );
            $report_day_start = $report_day->setTime( 0, 0, 0 );
            $report_day_end = $report_day->setTime( 23, 59, 59 );

            $last_month_report_day = $report_day->modify( '-1 month' );
            $last_month_report_day_start = $last_month_report_day->setTime( 0, 0, 0 );
            $last_month_report_day_end = $last_month_report_day->setTime( 23, 59, 59 );

            $mtd_start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $mtd_end = $report_day_end;

            $last_year_same_month_start = $now->modify( '-1 year' )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $last_year_same_month_end = $report_day_end->modify( '-1 year' );

            return array(
                'now_label' => wp_date( 'Y-m-d H:i', $now->getTimestamp() ),
                'report_day_label' => wp_date( 'Y-m-d', $report_day_start->getTimestamp() ),
                'report_day' => $this->get_window_counts( $logs, $report_day_start->getTimestamp(), $report_day_end->getTimestamp() ),
                'same_day_last_month' => $this->get_window_counts( $logs, $last_month_report_day_start->getTimestamp(), $last_month_report_day_end->getTimestamp() ),
                'mtd_current' => $this->get_window_counts( $logs, $mtd_start->getTimestamp(), $mtd_end->getTimestamp() ),
                'same_month_last_year' => $this->get_window_counts( $logs, $last_year_same_month_start->getTimestamp(), $last_year_same_month_end->getTimestamp() ),
            );
        }

        /**
         * Build trend data between two period totals.
         *
         * @param int $current
         * @param int $previous
         * @return array
         */
        private function build_trend_data( $current, $previous ) {
            $current  = (int) $current;
            $previous = (int) $previous;
            $delta    = $current - $previous;

            if ( $previous > 0 ) {
                $pct = ( $delta / $previous ) * 100;
            } elseif ( $current > 0 ) {
                $pct = 100;
            } else {
                $pct = 0;
            }

            $direction = 'flat';
            if ( $delta > 0 ) {
                $direction = 'up';
            } elseif ( $delta < 0 ) {
                $direction = 'down';
            }

            return array(
                'delta' => $delta,
                'pct' => round( $pct, 1 ),
                'direction' => $direction,
            );
        }

        /**
         * Build a styled HTML daily report email.
         *
         * @param array $report
         * @return string
         */
        private function build_daily_report_html( $report ) {
            $settings         = $this->get_settings();
            $is_hebrew        = ( isset( $settings['report_language'] ) && 'he' === $settings['report_language'] );
            $domain           = $this->get_site_domain();
            $period_title     = $is_hebrew ? 'סיכום יומי (יום קודם)' : 'Daily Report (Previous Day)';
            $period_subtitle  = $is_hebrew ? 'סקירת לידים יומית' : 'Your daily lead momentum report';
            $site_label       = $is_hebrew ? 'אתר' : 'Site';
            $last_day_label   = $is_hebrew ? 'סה"כ יום קודם' : 'Last Day Total';
            $mix_period_label = $is_hebrew ? 'תמהיל לידים לפי תקופה' : 'Lead Mix by Period';
            $breakdown_label  = $is_hebrew ? 'טלפון / וואטסאפ / אימייל / טופס' : 'Phone / WhatsApp / Email / Form';
            $mtd_label        = $is_hebrew ? 'מצטבר חודשי' : 'Month To Date';
            $mtd_compare_label = $is_hebrew ? 'השוואה לאותו חודש בשנה שעברה' : 'Compared against same month last year';
            $trend_last_day_vs_last_month = $is_hebrew ? 'יום קודם מול אותו יום בחודש שעבר' : 'Last day vs same day last month';
            $trend_mtd_vs_last_year = $is_hebrew ? 'מצטבר חודשי מול אותו חודש בשנה שעברה' : 'MTD vs same month last year';
            $trend_snapshot_label = $is_hebrew ? 'מבט מגמה' : 'Trend Snapshot';
            $no_change_label  = $is_hebrew ? 'ללא שינוי' : 'No change';
            $period_col_label = $is_hebrew ? 'תקופה' : 'Period';
            $phone_col_label  = $is_hebrew ? 'טלפון' : 'Phone';
            $whatsapp_col_label = $is_hebrew ? 'וואטסאפ' : 'WhatsApp';
            $email_col_label  = $is_hebrew ? 'אימייל' : 'Email';
            $form_col_label   = $is_hebrew ? 'טופס' : 'Form';
            $total_col_label  = $is_hebrew ? 'סה"כ' : 'Total';
            $last_day_row_label = $is_hebrew ? 'יום קודם' : 'Last day';
            $same_day_last_month_row_label = $is_hebrew ? 'אותו יום בחודש שעבר' : 'Same day last month';
            $mtd_row_label = $is_hebrew ? 'מצטבר חודשי' : 'Month to date';
            $same_month_last_year_row_label = $is_hebrew ? 'אותו חודש בשנה שעברה' : 'Same month last year';
            $mtd_table_row_label = $is_hebrew ? 'מצטבר חודשי' : 'Month-to-date';
            $footer_message = $is_hebrew
                ? 'התמדה בקמפיינים הופכת את הדופק היומי לצמיחה חודשית מצטברת.'
                : 'Stay consistent with campaigns, and your daily pulse can turn into monthly compounding growth.';

            $today_total      = isset( $report['report_day']['total'] ) ? (int) $report['report_day']['total'] : 0;
            $last_month_total = isset( $report['same_day_last_month']['total'] ) ? (int) $report['same_day_last_month']['total'] : 0;
            $mtd_total        = isset( $report['mtd_current']['total'] ) ? (int) $report['mtd_current']['total'] : 0;
            $last_year_total  = isset( $report['same_month_last_year']['total'] ) ? (int) $report['same_month_last_year']['total'] : 0;

            $trend_today = $this->build_trend_data( $today_total, $last_month_total );
            $trend_mtd   = $this->build_trend_data( $mtd_total, $last_year_total );

            $max_total = max( 1, $today_total, $last_month_total, $mtd_total, $last_year_total );
            $bar = static function ( $value ) use ( $max_total ) {
                return (int) round( ( max( 0, (int) $value ) / $max_total ) * 100 );
            };

            $trend_label = static function ( $trend ) use ( $no_change_label ) {
                $sign = ( $trend['delta'] > 0 ) ? '+' : '';
                if ( 'flat' === $trend['direction'] ) {
                    return $no_change_label;
                }
                return sprintf(
                    '%s%d (%s%s%%)',
                    $sign,
                    (int) $trend['delta'],
                    $sign,
                    (string) $trend['pct']
                );
            };

            $today_breakdown = sprintf(
                '%d / %d / %d / %d',
                isset( $report['report_day']['phone'] ) ? (int) $report['report_day']['phone'] : 0,
                isset( $report['report_day']['whatsapp'] ) ? (int) $report['report_day']['whatsapp'] : 0,
                isset( $report['report_day']['email'] ) ? (int) $report['report_day']['email'] : 0,
                isset( $report['report_day']['form_submit'] ) ? (int) $report['report_day']['form_submit'] : 0
            );

            $html  = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f7fb;padding:24px;">';
            $html .= '<div style="max-width:800px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(25,48,89,0.12);" dir="' . ( $is_hebrew ? 'rtl' : 'ltr' ) . '">';
            $html .= '<div style="background:linear-gradient(135deg,#0f5fb7 0%,#27b3a9 100%);padding:22px 26px;color:#ffffff;">';
            $html .= '<h1 style="margin:0 0 6px;font-size:28px;line-height:1.2;color:#ffffff;">' . esc_html( $period_title ) . '</h1>';
            $html .= '<p style="margin:0;font-size:14px;opacity:0.95;">' . esc_html( $period_subtitle ) . ' - ' . esc_html( isset( $report['report_day_label'] ) ? $report['report_day_label'] : '' ) . '</p>';
            $html .= '<p style="margin:8px 0 0;font-size:13px;opacity:0.95;">' . esc_html( $site_label . ': ' . $domain ) . '</p>';
            $html .= '</div>';

            $html .= '<div style="padding:20px 24px 8px;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:12px;"><tr>';
            $html .= '<td style="width:50%;padding:0 8px 8px 0;">';
            $html .= '<div style="background:#f5faff;border:1px solid #d7e8ff;border-radius:10px;padding:14px;">';
            $html .= '<div style="font-size:12px;color:#4a5d7a;text-transform:uppercase;letter-spacing:.4px;">' . esc_html( $last_day_label ) . '</div>';
            $html .= '<div style="font-size:34px;color:#0f5fb7;font-weight:700;line-height:1.1;">' . esc_html( (string) $today_total ) . '</div>';
            $html .= '<div style="font-size:12px;color:#60758f;">' . esc_html( $breakdown_label ) . ': ' . esc_html( $today_breakdown ) . '</div>';
            $html .= '</div></td>';
            $html .= '<td style="width:50%;padding:0 0 8px 8px;">';
            $html .= '<div style="background:#f7fff9;border:1px solid #d7f1df;border-radius:10px;padding:14px;">';
            $html .= '<div style="font-size:12px;color:#456a55;text-transform:uppercase;letter-spacing:.4px;">' . esc_html( $mtd_label ) . '</div>';
            $html .= '<div style="font-size:34px;color:#108554;font-weight:700;line-height:1.1;">' . esc_html( (string) $mtd_total ) . '</div>';
            $html .= '<div style="font-size:12px;color:#60758f;">' . esc_html( $mtd_compare_label ) . '</div>';
            $html .= '</div></td>';
            $html .= '</tr></table>';

            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr>';
            $html .= '<td style="width:50%;padding:0 8px 0 0;">';
            $html .= '<div style="background:#ffffff;border:1px solid #e4ebf4;border-radius:10px;padding:12px;">';
            $html .= '<div style="font-size:12px;color:#4a5d7a;">' . esc_html( $trend_last_day_vs_last_month ) . '</div>';
            $html .= '<div style="font-size:20px;font-weight:700;color:' . ( 'down' === $trend_today['direction'] ? '#c0392b' : '#108554' ) . ';">' . esc_html( $trend_label( $trend_today ) ) . '</div>';
            $html .= '</div></td>';
            $html .= '<td style="width:50%;padding:0 0 0 8px;">';
            $html .= '<div style="background:#ffffff;border:1px solid #e4ebf4;border-radius:10px;padding:12px;">';
            $html .= '<div style="font-size:12px;color:#4a5d7a;">' . esc_html( $trend_mtd_vs_last_year ) . '</div>';
            $html .= '<div style="font-size:20px;font-weight:700;color:' . ( 'down' === $trend_mtd['direction'] ? '#c0392b' : '#108554' ) . ';">' . esc_html( $trend_label( $trend_mtd ) ) . '</div>';
            $html .= '</div></td>';
            $html .= '</tr></table>';

            $html .= '<div style="font-size:14px;font-weight:600;color:#1a3252;margin-bottom:8px;">' . esc_html( $trend_snapshot_label ) . '</div>';

            $rows = array(
                $last_day_row_label => $today_total,
                $same_day_last_month_row_label => $last_month_total,
                $mtd_row_label => $mtd_total,
                $same_month_last_year_row_label => $last_year_total,
            );

            foreach ( $rows as $label => $value ) {
                $html .= '<div style="margin:0 0 8px;">';
                $html .= '<div style="display:flex;justify-content:space-between;font-size:12px;color:#4a5d7a;margin:0 0 4px;"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
                $html .= '<div style="height:10px;background:#e8eef6;border-radius:999px;overflow:hidden;"><div style="height:10px;background:linear-gradient(90deg,#2e7cd8,#2fc0a5);width:' . esc_attr( (string) $bar( $value ) ) . '%;"></div></div>';
                $html .= '</div>';
            }

            $html .= '<div style="margin-top:14px;font-size:14px;font-weight:600;color:#1a3252;">' . esc_html( $mix_period_label ) . '</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;border-collapse:collapse;font-size:12px;">';
            $html .= '<tr>';
            $html .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $period_col_label ) . '</th>';
            $html .= '<th style="text-align:right;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $phone_col_label ) . '</th>';
            $html .= '<th style="text-align:right;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $whatsapp_col_label ) . '</th>';
            $html .= '<th style="text-align:right;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $email_col_label ) . '</th>';
            $html .= '<th style="text-align:right;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $form_col_label ) . '</th>';
            $html .= '<th style="text-align:right;padding:8px;border-bottom:1px solid #e4ebf4;">' . esc_html( $total_col_label ) . '</th>';
            $html .= '</tr>';

            $mix_rows = array(
                $last_day_row_label => $report['report_day'],
                $same_day_last_month_row_label => $report['same_day_last_month'],
                $mtd_table_row_label => $report['mtd_current'],
                $same_month_last_year_row_label => $report['same_month_last_year'],
            );

            foreach ( $mix_rows as $label => $counts ) {
                $html .= '<tr>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f1f4f8;">' . esc_html( $label ) . '</td>';
                $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #f1f4f8;">' . esc_html( (string) ( isset( $counts['phone'] ) ? (int) $counts['phone'] : 0 ) ) . '</td>';
                $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #f1f4f8;">' . esc_html( (string) ( isset( $counts['whatsapp'] ) ? (int) $counts['whatsapp'] : 0 ) ) . '</td>';
                $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #f1f4f8;">' . esc_html( (string) ( isset( $counts['email'] ) ? (int) $counts['email'] : 0 ) ) . '</td>';
                $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #f1f4f8;">' . esc_html( (string) ( isset( $counts['form_submit'] ) ? (int) $counts['form_submit'] : 0 ) ) . '</td>';
                $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #f1f4f8;font-weight:700;">' . esc_html( (string) ( isset( $counts['total'] ) ? (int) $counts['total'] : 0 ) ) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';

            $html .= '<p style="margin:16px 0 0;font-size:13px;color:#5a6e86;">' . esc_html( $footer_message ) . '</p>';
            $html .= '</div></div></div>';

            return $html;
        }

        /**
         * Send daily report email.
         *
         * @return bool
         */
        public function send_daily_report_email( $override_emails = array() ) {
            $emails = array();
            if ( ! empty( $override_emails ) && is_array( $override_emails ) ) {
                foreach ( $override_emails as $email ) {
                    $email = sanitize_email( (string) $email );
                    if ( '' !== $email && is_email( $email ) ) {
                        $emails[] = $email;
                    }
                }
                $emails = array_values( array_unique( $emails ) );
            }

            if ( empty( $emails ) ) {
                $settings = $this->get_settings();
                $emails   = $this->parse_report_emails( $settings['report_emails'] );
            }

            if ( empty( $emails ) ) {
                return false;
            }

            $report = $this->build_daily_report_payload();
            $domain = $this->get_site_domain();
            $subject = sprintf(
                '"%s" - BRN Lead count - %s',
                $domain,
                isset( $report['report_day_label'] ) ? $report['report_day_label'] : wp_date( 'Y-m-d' )
            );

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            $message = $this->build_daily_report_html( $report );

            $sent = wp_mail( $emails, $subject, $message, $headers );
            if ( $sent ) {
                update_option( self::OPTION_LAST_REPORT_SENT, time(), false );
            }

            return (bool) $sent;
        }

        public function enqueue_scripts() {
            if ( is_admin() ) {
                return;
            }

            wp_enqueue_script(
                'brn-lead-count-tracker',
                plugin_dir_url( __FILE__ ) . 'assets/js/brn-lead-count-tracker.js',
                array(),
                '1.3.6',
                true
            );

            wp_localize_script(
                'brn-lead-count-tracker',
                'brnLeadCountData',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                )
            );
        }

        public function track_event() {
            if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
            }

            $request = wp_unslash( $_REQUEST );

            $type = isset( $request['lead_type'] ) ? sanitize_key( $request['lead_type'] ) : '';
            $label = isset( $request['label'] ) ? sanitize_text_field( $request['label'] ) : '';
            $url = isset( $request['url'] ) ? esc_url_raw( $request['url'] ) : '';
            $page_title = isset( $request['page_title'] ) ? sanitize_text_field( $request['page_title'] ) : '';
            $manual_test = ! empty( $request['is_test'] );

            $allowed_types = array( 'phone', 'whatsapp', 'email', 'form_submit' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid lead type.' ), 400 );
            }

            $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );

            if ( ! isset( $stats['counts'][ $type ] ) ) {
                $stats['counts'][ $type ] = 0;
            }

            $settings = $this->get_settings();
            $user_id  = get_current_user_id();
            $ip       = $this->get_request_ip();
            $is_test  = $this->is_test_lead( $settings, $ip, $user_id, $manual_test );
            $ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
            $ua_data  = $this->parse_user_agent_data( $ua );
            $country  = $this->resolve_country_by_ip( $ip );

            // Test leads must be excluded from totals and all reports.
            if ( ! $is_test ) {
                $stats['counts'][ $type ] += 1;
                $stats['counts']['total'] += 1;
            }

            if ( ! empty( $settings['enable_logging'] ) ) {
                $log_entry = array(
                    'id'        => wp_generate_uuid4(),
                    'time'      => wp_date( 'Y-m-d H:i:s' ),
                    'type'      => $type,
                    'label'     => $label,
                    'page_url'   => $url,
                    'page_title'  => $page_title,
                    'ip_hash'    => $this->get_request_ip_hash( $ip ),
                    'ip'         => $ip,
                    'is_test'    => $is_test ? 1 : 0,
                    'browser'   => $ua_data['browser'],
                    'device'    => $ua_data['device'],
                    'country_code' => $country['code'],
                    'country_name' => $country['name'],
                );

                if ( ! isset( $stats['logs'] ) || ! is_array( $stats['logs'] ) ) {
                    $stats['logs'] = array();
                }

                array_unshift( $stats['logs'], $log_entry );
                if ( count( $stats['logs'] ) > $settings['max_logs'] ) {
                    $stats['logs'] = array_slice( $stats['logs'], 0, $settings['max_logs'] );
                }
            }

            update_option( self::OPTION_STATS, $stats, false );

            wp_send_json_success(
                array(
                    'counts' => $stats['counts'],
                )
            );
        }

        private function get_request_ip() {
            $ip = '';

            if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
                $parts = explode( ',', $forwarded );
                $ip = trim( $parts[0] );
            }

            if ( empty( $ip ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            }

            if ( empty( $ip ) ) {
                return '';
            }

            return $ip;
        }

        /**
         * Hash the IP for storage.
         *
         * @param string $ip
         * @return string
         */
        private function get_request_ip_hash( $ip = '' ) {
            if ( '' === $ip ) {
                $ip = $this->get_request_ip();
            }

            if ( '' === $ip ) {
                return '';
            }

            return wp_hash( $ip );
        }

        public function register_admin_menu() {
            add_menu_page(
                __( 'BRN Lead Count', 'brn-lead-count' ),
                __( 'BRN Lead Count', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-analytics',
                array( $this, 'render_analytics_page' ),
                'dashicons-chart-bar',
                58
            );

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Analytics', 'brn-lead-count' ),
                __( 'Analytics', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-analytics',
                array( $this, 'render_analytics_page' )
            );

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Leads', 'brn-lead-count' ),
                __( 'Leads', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-leads',
                array( $this, 'render_leads_page' )
            );

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Settings', 'brn-lead-count' ),
                __( 'Settings', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-settings',
                array( $this, 'render_settings_page' )
            );

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Updates', 'brn-lead-count' ),
                __( 'Updates', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-updates',
                array( $this, 'render_updates_page' )
            );

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Daily Report', 'brn-lead-count' ),
                __( 'Daily Report', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-reports',
                array( $this, 'render_reports_page' )
            );
        }

        public function register_settings() {
            register_setting(
                'brn_lead_count_settings_group',
                self::OPTION_SETTINGS,
                array( $this, 'sanitize_settings' )
            );
        }

        public function render_analytics_page() {
            $this->render_admin_page( 'analytics' );
        }

        public function render_leads_page() {
            $this->render_admin_page( 'leads' );
        }

        public function render_settings_page() {
            $this->render_admin_page( 'settings' );
        }

        public function render_updates_page() {
            $this->render_admin_page( 'updates' );
        }

        public function render_reports_page() {
            $this->render_admin_page( 'reports' );
        }

        // ΓöÇΓöÇ Updater methods ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //

        /**
         * Loads and instantiates BRN_Updater so its WP filters are registered.
         * Runs on admin_init so get_plugins() is available.
         */
        public function init_updater() {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-brn-updater.php';
            new BRN_Updater();
        }

        /**
         * Cron callback: force-refresh the cached update info.
         */
        public function run_update_check() {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-brn-updater.php';
            $updater = new BRN_Updater();
            $updater->get_update_info( true );
        }

        /**
         * AJAX handler for the manual "Check for updates" button (admin only).
         */
        public function ajax_check_updates() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
            }

            if ( ! check_ajax_referer( self::NONCE_CHECK_UPDATES, 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
            }

            require_once plugin_dir_path( __FILE__ ) . 'includes/class-brn-updater.php';
            $updater = new BRN_Updater();
            $info    = $updater->get_update_info( true ); // force fresh fetch

            $installed = $this->get_installed_plugin_version();

            if ( false === $info ) {
                $error = get_option( BRN_Updater::OPT_LAST_ERROR, '' );
                wp_send_json_error(
                    array(
                        'message' => $error ? $error : __( 'Could not reach GitHub.', 'brn-lead-count' ),
                    )
                );
            }

            $update_available = version_compare( $installed, $info['version'], '<' );

            // Keep WP's plugin update transient in sync so the Plugins screen reflects manual checks immediately.
            $plugin_file = plugin_basename( __FILE__ );
            $transient   = get_site_transient( 'update_plugins' );
            if ( ! is_object( $transient ) ) {
                $transient = new stdClass();
            }
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = array();
            }
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = array();
            }
            if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
                $transient->checked = array();
            }

            $update_item              = new stdClass();
            $update_item->id          = $plugin_file;
            $update_item->slug        = dirname( $plugin_file );
            $update_item->plugin      = $plugin_file;
            $update_item->new_version = $info['version'];
            $update_item->url         = 'https://github.com/brncoil/brn-lead-count';
            $update_item->package     = $info['download_url'];

            $transient->checked[ $plugin_file ] = $installed;
            $transient->last_checked             = time();

            if ( $update_available ) {
                $transient->response[ $plugin_file ] = $update_item;
                unset( $transient->no_update[ $plugin_file ] );
            } else {
                $transient->no_update[ $plugin_file ] = $update_item;
                unset( $transient->response[ $plugin_file ] );
            }

            set_site_transient( 'update_plugins', $transient );

            wp_send_json_success(
                array(
                    'update_available' => $update_available,
                    'latest_version'   => $info['version'],
                    'current_version'  => $installed,
                    'last_checked'     => wp_date( 'Y-m-d H:i:s' ),
                )
            );
        }

        /**
         * Returns the Version header from this plugin file.
         *
         * @return string
         */
        private function get_installed_plugin_version() {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data( __FILE__, false, false );
            return isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
        }

        // ΓöÇΓöÇ Admin scripts ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //

        /**
         * Enqueues Chart.js in the <head> for our admin settings page only.
         */
        public function enqueue_admin_scripts( $hook ) {
            if ( false === strpos( (string) $hook, 'brn-lead-count' ) ) {
                return;
            }

            wp_enqueue_script(
                'brn-chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
                array(),
                '4.4.3',
                false // load in <head> so it is available when inline body script runs
            );
        }

        /**
         * Aggregates log entries into a per-date, per-type count array.
         * Returns: [ 'Y-m-d' => [ 'phone'=>N, 'whatsapp'=>N, 'email'=>N, 'form_submit'=>N ], ... ]
         *
         * @param array $logs
         * @return array
         */
        private function build_analytics_data( array $logs ) {
            $by_date = array();
            foreach ( $logs as $log ) {
                if ( empty( $log['time'] ) || empty( $log['type'] ) ) {
                    continue;
                }

                if ( ! empty( $log['is_test'] ) ) {
                    continue;
                }

                $date = substr( $log['time'], 0, 10 ); // Y-m-d
                if ( ! isset( $by_date[ $date ] ) ) {
                    $by_date[ $date ] = array(
                        'phone'       => 0,
                        'whatsapp'    => 0,
                        'email'       => 0,
                        'form_submit' => 0,
                    );
                }
                $type = $log['type'];
                if ( array_key_exists( $type, $by_date[ $date ] ) ) {
                    $by_date[ $date ][ $type ]++;
                }
            }
            ksort( $by_date );
            return $by_date;
        }

        // ΓöÇΓöÇ Settings ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //

        public function sanitize_settings( $input ) {
            $output = array();

            $output['enable_logging'] = empty( $input['enable_logging'] ) ? 0 : 1;
            $output['max_logs'] = isset( $input['max_logs'] ) ? max( 10, min( 2000, absint( $input['max_logs'] ) ) ) : self::MAX_LOGS_DEFAULT;
            $output['test_users'] = isset( $input['test_users'] ) ? sanitize_textarea_field( wp_unslash( $input['test_users'] ) ) : '';
            $output['test_ips'] = isset( $input['test_ips'] ) ? sanitize_textarea_field( wp_unslash( $input['test_ips'] ) ) : '';
            $output['report_emails'] = isset( $input['report_emails'] ) ? sanitize_textarea_field( wp_unslash( $input['report_emails'] ) ) : '';
            $output['report_send_time'] = isset( $input['report_send_time'] ) && preg_match( '/^\d{2}:\d{2}$/', (string) $input['report_send_time'] )
                ? (string) $input['report_send_time']
                : '09:00';
            $output['report_language'] = ( isset( $input['report_language'] ) && 'he' === (string) $input['report_language'] ) ? 'he' : 'en';

            return $output;
        }

        public function render_admin_page( $forced_section = '' ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $this->maybe_migrate_stats_schema();
            $report_preview_requested = false;
            $manual_report_email      = '';

            if ( isset( $_POST['brn_lead_count_clear_logs'] ) && check_admin_referer( 'brn_lead_count_clear_logs_action', 'brn_lead_count_clear_logs_nonce' ) ) {
                $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );
                $stats['logs'] = array();
                update_option( self::OPTION_STATS, $stats, false );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs cleared.', 'brn-lead-count' ) . '</p></div>';
            }

            if ( isset( $_POST['brn_lead_count_reset_all'] ) && check_admin_referer( 'brn_lead_count_reset_all_action', 'brn_lead_count_reset_all_nonce' ) ) {
                update_option( self::OPTION_STATS, $this->get_empty_stats(), false );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All counters and logs were reset.', 'brn-lead-count' ) . '</p></div>';
            }

            if ( isset( $_POST['brn_lead_count_toggle_test'] ) && check_admin_referer( 'brn_lead_count_toggle_test_action', 'brn_lead_count_toggle_test_nonce' ) ) {
                $target_id = isset( $_POST['log_id'] ) ? sanitize_text_field( wp_unslash( $_POST['log_id'] ) ) : '';
                $set_test  = ! empty( $_POST['set_test'] ) ? 1 : 0;

                $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );
                if ( ! empty( $target_id ) && ! empty( $stats['logs'] ) && is_array( $stats['logs'] ) ) {
                    foreach ( $stats['logs'] as &$log ) {
                        if ( ! is_array( $log ) || empty( $log['id'] ) || $target_id !== $log['id'] ) {
                            continue;
                        }

                        $old_test = ! empty( $log['is_test'] ) ? 1 : 0;
                        if ( $old_test !== $set_test ) {
                            $lead_type = isset( $log['type'] ) ? $log['type'] : '';
                            if ( isset( $stats['counts'][ $lead_type ] ) ) {
                                if ( 1 === $set_test ) {
                                    $stats['counts'][ $lead_type ] = max( 0, (int) $stats['counts'][ $lead_type ] - 1 );
                                    $stats['counts']['total'] = max( 0, (int) $stats['counts']['total'] - 1 );
                                } else {
                                    $stats['counts'][ $lead_type ] = (int) $stats['counts'][ $lead_type ] + 1;
                                    $stats['counts']['total'] = (int) $stats['counts']['total'] + 1;
                                }
                            }

                            $log['is_test'] = $set_test;
                        }
                        break;
                    }
                    unset( $log );

                    update_option( self::OPTION_STATS, $stats, false );
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lead updated.', 'brn-lead-count' ) . '</p></div>';
                }
            }

            if ( isset( $_POST['brn_lead_count_bulk_update'] ) && check_admin_referer( 'brn_lead_count_bulk_update_action', 'brn_lead_count_bulk_update_nonce' ) ) {
                $ids    = isset( $_POST['lead_ids'] ) && is_array( $_POST['lead_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['lead_ids'] ) ) : array();
                $action = isset( $_POST['bulk_action_test'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action_test'] ) ) : '';

                if ( ! empty( $ids ) && in_array( $action, array( 'mark_test', 'mark_real' ), true ) ) {
                    $set_test = ( 'mark_test' === $action ) ? 1 : 0;
                    $stats    = get_option( self::OPTION_STATS, $this->get_empty_stats() );

                    if ( ! empty( $stats['logs'] ) && is_array( $stats['logs'] ) ) {
                        foreach ( $stats['logs'] as &$log ) {
                            if ( ! is_array( $log ) || empty( $log['id'] ) ) {
                                continue;
                            }

                            if ( in_array( $log['id'], $ids, true ) ) {
                                $log['is_test'] = $set_test;
                            }
                        }
                        unset( $log );

                        $stats['counts'] = $this->rebuild_counts_from_logs( $stats['logs'] );
                        update_option( self::OPTION_STATS, $stats, false );
                        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bulk lead update completed.', 'brn-lead-count' ) . '</p></div>';
                    }
                }
            }

            if ( isset( $_POST['brn_send_daily_report_now'] ) && check_admin_referer( 'brn_send_daily_report_now_action', 'brn_send_daily_report_now_nonce' ) ) {
                $manual_report_email = isset( $_POST['manual_report_email'] ) ? sanitize_email( wp_unslash( $_POST['manual_report_email'] ) ) : '';
                if ( '' === $manual_report_email || ! is_email( $manual_report_email ) ) {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Enter a valid email address to send this report manually.', 'brn-lead-count' ) . '</p></div>';
                } else {
                    $sent = $this->send_daily_report_email( array( $manual_report_email ) );
                    if ( $sent ) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Daily report email sent.', 'brn-lead-count' ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Daily report was not sent.', 'brn-lead-count' ) . '</p></div>';
                    }
                }
            }

            if ( isset( $_POST['brn_preview_daily_report'] ) && check_admin_referer( 'brn_preview_daily_report_action', 'brn_preview_daily_report_nonce' ) ) {
                $report_preview_requested = true;
            }

            $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );
            $settings = $this->get_settings();
            $counts = isset( $stats['counts'] ) ? $stats['counts'] : array();
            $logs = isset( $stats['logs'] ) && is_array( $stats['logs'] ) ? $stats['logs'] : array();

            $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'brn-lead-count-analytics';
            $section      = $forced_section;
            if ( '' === $section ) {
                $section_map = array(
                    'brn-lead-count-analytics' => 'analytics',
                    'brn-lead-count-leads'     => 'leads',
                    'brn-lead-count-settings'  => 'settings',
                    'brn-lead-count-updates'   => 'updates',
                    'brn-lead-count-reports'   => 'reports',
                );
                $section = isset( $section_map[ $current_page ] ) ? $section_map[ $current_page ] : 'analytics';
            }

            $tabs = array(
                'analytics' => array(
                    'label' => __( 'Analytics', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-analytics' ),
                ),
                'leads' => array(
                    'label' => __( 'Leads', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-leads' ),
                ),
                'settings' => array(
                    'label' => __( 'Settings', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-settings' ),
                ),
                'updates' => array(
                    'label' => __( 'Updates', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-updates' ),
                ),
                'reports' => array(
                    'label' => __( 'Daily Report', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-reports' ),
                ),
            );

            $leads_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
            $leads_type   = isset( $_GET['lead_type'] ) ? sanitize_key( wp_unslash( $_GET['lead_type'] ) ) : 'all';
            $leads_status = isset( $_GET['lead_status'] ) ? sanitize_key( wp_unslash( $_GET['lead_status'] ) ) : 'all';
            $leads_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
            $leads_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
            $orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'time';
            $order        = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
            $order        = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

            if ( 'leads' === $section ) {
                $logs = array_values(
                    array_filter(
                        $logs,
                        function ( $log ) use ( $leads_search, $leads_type, $leads_status, $leads_from, $leads_to ) {
                            if ( ! is_array( $log ) ) {
                                return false;
                            }

                            if ( 'all' !== $leads_type && ( ! isset( $log['type'] ) || $log['type'] !== $leads_type ) ) {
                                return false;
                            }

                            if ( 'test' === $leads_status && empty( $log['is_test'] ) ) {
                                return false;
                            }

                            if ( 'real' === $leads_status && ! empty( $log['is_test'] ) ) {
                                return false;
                            }

                            $time = isset( $log['time'] ) ? (string) $log['time'] : '';
                            if ( '' !== $leads_from && ( '' === $time || substr( $time, 0, 10 ) < $leads_from ) ) {
                                return false;
                            }

                            if ( '' !== $leads_to && ( '' === $time || substr( $time, 0, 10 ) > $leads_to ) ) {
                                return false;
                            }

                            if ( '' !== $leads_search ) {
                                $haystack = strtolower(
                                    implode(
                                        ' ',
                                        array(
                                            isset( $log['label'] ) ? (string) $log['label'] : '',
                                            isset( $log['page_url'] ) ? (string) $log['page_url'] : '',
                                            isset( $log['type'] ) ? (string) $log['type'] : '',
                                            isset( $log['browser'] ) ? (string) $log['browser'] : '',
                                            isset( $log['device'] ) ? (string) $log['device'] : '',
                                            isset( $log['country_name'] ) ? (string) $log['country_name'] : '',
                                            isset( $log['ip'] ) ? (string) $log['ip'] : '',
                                        )
                                    )
                                );

                                if ( false === strpos( $haystack, strtolower( $leads_search ) ) ) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

                usort(
                    $logs,
                    function ( $a, $b ) use ( $orderby, $order ) {
                        $av = isset( $a[ $orderby ] ) ? $a[ $orderby ] : '';
                        $bv = isset( $b[ $orderby ] ) ? $b[ $orderby ] : '';

                        if ( 'is_test' === $orderby ) {
                            $cmp = (int) $av <=> (int) $bv;
                        } else {
                            $cmp = strnatcasecmp( (string) $av, (string) $bv );
                        }

                        return ( 'ASC' === $order ) ? $cmp : -$cmp;
                    }
                );
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'BRN Lead Count', 'brn-lead-count' ); ?></h1>
                <p><?php esc_html_e( 'Track and review lead events from phone clicks, WhatsApp clicks, email clicks, and form submissions.', 'brn-lead-count' ); ?></p>

                <h2 class="nav-tab-wrapper" style="margin-bottom:20px;">
                    <?php foreach ( $tabs as $tab_key => $tab ) : ?>
                        <a href="<?php echo esc_url( $tab['url'] ); ?>" class="nav-tab <?php echo ( $section === $tab_key ) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $tab['label'] ); ?></a>
                    <?php endforeach; ?>
                </h2>

                <?php if ( 'analytics' === $section ) : ?>
                <h2><?php esc_html_e( 'Totals', 'brn-lead-count' ); ?></h2>
                <table class="widefat striped" style="max-width:640px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Lead Type', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'brn-lead-count' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php esc_html_e( 'Phone Clicks', 'brn-lead-count' ); ?></td>
                            <td><?php echo esc_html( isset( $counts['phone'] ) ? (string) $counts['phone'] : '0' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WhatsApp Clicks', 'brn-lead-count' ); ?></td>
                            <td><?php echo esc_html( isset( $counts['whatsapp'] ) ? (string) $counts['whatsapp'] : '0' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Email Clicks', 'brn-lead-count' ); ?></td>
                            <td><?php echo esc_html( isset( $counts['email'] ) ? (string) $counts['email'] : '0' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Form Submissions', 'brn-lead-count' ); ?></td>
                            <td><?php echo esc_html( isset( $counts['form_submit'] ) ? (string) $counts['form_submit'] : '0' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Total Leads', 'brn-lead-count' ); ?></strong></td>
                            <td><strong><?php echo esc_html( isset( $counts['total'] ) ? (string) $counts['total'] : '0' ); ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // ΓöÇΓöÇ Analytics section ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //
                $analytics_data = $this->build_analytics_data( $logs );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
                $analytics_json = wp_json_encode( $analytics_data );
                ?>

                <h2 style="margin-top:32px;"><?php esc_html_e( 'Analytics', 'brn-lead-count' ); ?></h2>

                <?php if ( empty( $analytics_data ) ) : ?>
                    <p style="color:#646970;">
                        <?php esc_html_e( 'No data yet. Make sure "Enable Event Logs" is turned on in Settings below, then lead events will appear here.', 'brn-lead-count' ); ?>
                    </p>
                <?php else : ?>

                    <!-- Filter bar -->
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
                        <label style="font-weight:600;">
                            <?php esc_html_e( 'From', 'brn-lead-count' ); ?>:
                            <input type="date" id="brn-date-from" style="margin-left:4px;" />
                        </label>
                        <label style="font-weight:600;">
                            <?php esc_html_e( 'To', 'brn-lead-count' ); ?>:
                            <input type="date" id="brn-date-to" style="margin-left:4px;" />
                        </label>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button type="button" class="button button-primary brn-type-btn" data-type="all"><?php esc_html_e( 'All', 'brn-lead-count' ); ?></button>
                            <button type="button" class="button button-primary brn-type-btn" data-type="phone"><?php esc_html_e( 'Phone', 'brn-lead-count' ); ?></button>
                            <button type="button" class="button button-primary brn-type-btn" data-type="whatsapp"><?php esc_html_e( 'WhatsApp', 'brn-lead-count' ); ?></button>
                            <button type="button" class="button button-primary brn-type-btn" data-type="email"><?php esc_html_e( 'Email', 'brn-lead-count' ); ?></button>
                            <button type="button" class="button button-primary brn-type-btn" data-type="form_submit"><?php esc_html_e( 'Form Submit', 'brn-lead-count' ); ?></button>
                        </div>
                    </div>

                    <!-- Summary cards -->
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                        <div style="background:#2271b1;color:#fff;padding:12px 24px;border-radius:6px;min-width:110px;text-align:center;">
                            <div style="font-size:30px;font-weight:700;line-height:1.2;" id="brn-sum-phone">0</div>
                            <div style="font-size:12px;margin-top:4px;opacity:.9;"><?php esc_html_e( 'Phone', 'brn-lead-count' ); ?></div>
                        </div>
                        <div style="background:#00a32a;color:#fff;padding:12px 24px;border-radius:6px;min-width:110px;text-align:center;">
                            <div style="font-size:30px;font-weight:700;line-height:1.2;" id="brn-sum-whatsapp">0</div>
                            <div style="font-size:12px;margin-top:4px;opacity:.9;"><?php esc_html_e( 'WhatsApp', 'brn-lead-count' ); ?></div>
                        </div>
                        <div style="background:#dba617;color:#fff;padding:12px 24px;border-radius:6px;min-width:110px;text-align:center;">
                            <div style="font-size:30px;font-weight:700;line-height:1.2;" id="brn-sum-form">0</div>
                            <div style="font-size:12px;margin-top:4px;opacity:.9;"><?php esc_html_e( 'Form Submit', 'brn-lead-count' ); ?></div>
                        </div>
                        <div style="background:#d35400;color:#fff;padding:12px 24px;border-radius:6px;min-width:110px;text-align:center;">
                            <div style="font-size:30px;font-weight:700;line-height:1.2;" id="brn-sum-email">0</div>
                            <div style="font-size:12px;margin-top:4px;opacity:.9;"><?php esc_html_e( 'Email', 'brn-lead-count' ); ?></div>
                        </div>
                        <div style="background:#3c434a;color:#fff;padding:12px 24px;border-radius:6px;min-width:110px;text-align:center;">
                            <div style="font-size:30px;font-weight:700;line-height:1.2;" id="brn-sum-total">0</div>
                            <div style="font-size:12px;margin-top:4px;opacity:.9;"><?php esc_html_e( 'Total', 'brn-lead-count' ); ?></div>
                        </div>
                    </div>

                    <!-- Chart -->
                    <div style="max-width:960px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;">
                        <p id="brn-chart-empty" style="display:none;color:#646970;text-align:center;padding:40px 0;margin:0;">
                            <?php esc_html_e( 'No events in this date range.', 'brn-lead-count' ); ?>
                        </p>
                        <canvas id="brn-analytics-chart" style="max-height:340px;"></canvas>
                    </div>

                    <script>
                    (function () {
                        if ( typeof Chart === 'undefined' ) { return; }

                        var rawData  = <?php echo $analytics_json; ?>;
                        var allDates = Object.keys( rawData ).sort();

                        var fromInput = document.getElementById( 'brn-date-from' );
                        var toInput   = document.getElementById( 'brn-date-to' );

                        // Default range: last 30 days (or earliest date in data if more recent)
                        var today   = new Date().toISOString().slice( 0, 10 );
                        var d30     = new Date( Date.now() - 29 * 86400000 ).toISOString().slice( 0, 10 );
                        var minDate = allDates.length ? allDates[0] : d30;

                        fromInput.value = ( d30 < minDate ) ? minDate : d30;
                        toInput.value   = today;
                        fromInput.min   = minDate;
                        toInput.min     = minDate;
                        fromInput.max   = today;
                        toInput.max     = today;

                        var selectedTypes = [ 'phone', 'whatsapp', 'email', 'form_submit' ];
                        var chart         = null;

                        var COLORS = {
                            phone      : { bg: 'rgba(34,113,177,0.8)',  border: '#2271b1' },
                            whatsapp   : { bg: 'rgba(0,163,42,0.8)',    border: '#00a32a' },
                            email      : { bg: 'rgba(211,84,0,0.8)', border: '#d35400' },
                            form_submit: { bg: 'rgba(219,166,23,0.8)', border: '#dba617' }
                        };

                        var LABELS = {
                            phone      : <?php echo wp_json_encode( __( 'Phone', 'brn-lead-count' ) ); ?>,
                            whatsapp   : <?php echo wp_json_encode( __( 'WhatsApp', 'brn-lead-count' ) ); ?>,
                            email      : <?php echo wp_json_encode( __( 'Email', 'brn-lead-count' ) ); ?>,
                            form_submit: <?php echo wp_json_encode( __( 'Form Submit', 'brn-lead-count' ) ); ?>
                        };

                        function filteredDates() {
                            var from = fromInput.value;
                            var to   = toInput.value;
                            return allDates.filter( function ( d ) { return d >= from && d <= to; } );
                        }

                        function renderChart() {
                            var dates = filteredDates();

                            // Update summary cards
                            var sums = { phone: 0, whatsapp: 0, email: 0, form_submit: 0 };
                            dates.forEach( function ( d ) {
                                if ( ! rawData[ d ] ) { return; }
                                sums.phone       += rawData[ d ].phone       || 0;
                                sums.whatsapp    += rawData[ d ].whatsapp    || 0;
                                sums.email       += rawData[ d ].email       || 0;
                                sums.form_submit += rawData[ d ].form_submit || 0;
                            } );
                            document.getElementById( 'brn-sum-phone' ).textContent    = sums.phone;
                            document.getElementById( 'brn-sum-whatsapp' ).textContent = sums.whatsapp;
                            document.getElementById( 'brn-sum-email' ).textContent    = sums.email;
                            document.getElementById( 'brn-sum-form' ).textContent     = sums.form_submit;
                            document.getElementById( 'brn-sum-total' ).textContent    = sums.phone + sums.whatsapp + sums.email + sums.form_submit;

                            var empty  = document.getElementById( 'brn-chart-empty' );
                            var canvas = document.getElementById( 'brn-analytics-chart' );

                            if ( dates.length === 0 ) {
                                empty.style.display  = 'block';
                                canvas.style.display = 'none';
                                return;
                            }
                            empty.style.display  = 'none';
                            canvas.style.display = 'block';

                            var datasets = [ 'phone', 'whatsapp', 'email', 'form_submit' ]
                                .filter( function ( t ) { return selectedTypes.indexOf( t ) > -1; } )
                                .map( function ( t ) {
                                    return {
                                        label          : LABELS[ t ],
                                        data           : dates.map( function ( d ) { return ( rawData[ d ] && rawData[ d ][ t ] ) || 0; } ),
                                        backgroundColor: COLORS[ t ].bg,
                                        borderColor    : COLORS[ t ].border,
                                        borderWidth    : 1,
                                        borderRadius   : 3
                                    };
                                } );

                            if ( chart ) {
                                chart.data.labels   = dates;
                                chart.data.datasets = datasets;
                                chart.update();
                            } else {
                                chart = new Chart( canvas.getContext( '2d' ), {
                                    type: 'bar',
                                    data: { labels: dates, datasets: datasets },
                                    options: {
                                        responsive  : true,
                                        interaction : { mode: 'index', intersect: false },
                                        plugins     : { legend: { position: 'top' } },
                                        scales      : {
                                            x: { stacked: true, ticks: { maxRotation: 45, minRotation: 0 } },
                                            y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                                        }
                                    }
                                } );
                            }
                        }

                        // Type-filter toggle buttons
                        document.querySelectorAll( '.brn-type-btn' ).forEach( function ( btn ) {
                            btn.addEventListener( 'click', function () {
                                var type = this.getAttribute( 'data-type' );

                                if ( type === 'all' ) {
                                    selectedTypes = [ 'phone', 'whatsapp', 'email', 'form_submit' ];
                                } else {
                                    var idx = selectedTypes.indexOf( type );
                                    if ( idx > -1 ) {
                                        if ( selectedTypes.length > 1 ) { selectedTypes.splice( idx, 1 ); }
                                    } else {
                                        selectedTypes.push( type );
                                    }
                                }

                                // Sync button active states
                                document.querySelectorAll( '.brn-type-btn' ).forEach( function ( b ) {
                                    var bt     = b.getAttribute( 'data-type' );
                                    var active = ( bt === 'all' )
                                        ? selectedTypes.length === 4
                                        : selectedTypes.indexOf( bt ) > -1;
                                    b.classList.toggle( 'button-primary', active );
                                } );

                                renderChart();
                            } );
                        } );

                        fromInput.addEventListener( 'change', renderChart );
                        toInput.addEventListener( 'change', renderChart );

                        renderChart();
                    }());
                    </script>

                <?php endif; ?>
                <?php endif; ?>

                <?php if ( 'settings' === $section ) : ?>
                <h2 style="margin-top:24px;"><?php esc_html_e( 'Settings', 'brn-lead-count' ); ?></h2>
                <form method="post" action="options.php" style="max-width:640px;">
                    <?php settings_fields( 'brn_lead_count_settings_group' ); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Enable Event Logs', 'brn-lead-count' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?> />
                                        <?php esc_html_e( 'Store detailed logs for each event', 'brn-lead-count' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Max Log Rows', 'brn-lead-count' ); ?></th>
                                <td>
                                    <input type="number" min="10" max="2000" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[max_logs]" value="<?php echo esc_attr( (string) $settings['max_logs'] ); ?>" class="small-text" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Test Users', 'brn-lead-count' ); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[test_users]" rows="3" class="large-text" placeholder="1, 5, admin, qa-user"><?php echo esc_textarea( $settings['test_users'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Comma/new-line list of WP user IDs or usernames. Leads from these users are always marked as test leads.', 'brn-lead-count' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Test IPs', 'brn-lead-count' ); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[test_ips]" rows="3" class="large-text" placeholder="127.0.0.1, 192.168.1.25"><?php echo esc_textarea( $settings['test_ips'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Comma/new-line list of exact IP addresses. Leads from these IPs are always marked as test leads.', 'brn-lead-count' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Daily Report Emails', 'brn-lead-count' ); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[report_emails]" rows="3" class="large-text" placeholder="owner@example.com, manager@example.com"><?php echo esc_textarea( $settings['report_emails'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Comma/new-line list of recipients for the daily report.', 'brn-lead-count' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Daily Report Time', 'brn-lead-count' ); ?></th>
                                <td>
                                    <input type="time" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[report_send_time]" value="<?php echo esc_attr( $settings['report_send_time'] ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Local site time when the daily report is sent.', 'brn-lead-count' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Report Language', 'brn-lead-count' ); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[report_language]">
                                        <option value="en" <?php selected( 'en', isset( $settings['report_language'] ) ? $settings['report_language'] : 'en' ); ?>><?php esc_html_e( 'English', 'brn-lead-count' ); ?></option>
                                        <option value="he" <?php selected( 'he', isset( $settings['report_language'] ) ? $settings['report_language'] : 'en' ); ?>><?php esc_html_e( 'Hebrew', 'brn-lead-count' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Choose report email language.', 'brn-lead-count' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'brn-lead-count' ) ); ?>
                </form>
                <?php endif; ?>

                <?php if ( 'leads' === $section ) : ?>
                <h2 style="margin-top:24px;"><?php esc_html_e( 'Event Logs', 'brn-lead-count' ); ?></h2>
                <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="hidden" name="page" value="brn-lead-count-leads" />
                    <input type="search" name="s" value="<?php echo esc_attr( $leads_search ); ?>" placeholder="<?php esc_attr_e( 'Search leads...', 'brn-lead-count' ); ?>" />
                    <select name="lead_type">
                        <option value="all" <?php selected( 'all', $leads_type ); ?>><?php esc_html_e( 'All Types', 'brn-lead-count' ); ?></option>
                        <option value="phone" <?php selected( 'phone', $leads_type ); ?>><?php esc_html_e( 'Phone', 'brn-lead-count' ); ?></option>
                        <option value="whatsapp" <?php selected( 'whatsapp', $leads_type ); ?>><?php esc_html_e( 'WhatsApp', 'brn-lead-count' ); ?></option>
                        <option value="email" <?php selected( 'email', $leads_type ); ?>><?php esc_html_e( 'Email', 'brn-lead-count' ); ?></option>
                        <option value="form_submit" <?php selected( 'form_submit', $leads_type ); ?>><?php esc_html_e( 'Form Submit', 'brn-lead-count' ); ?></option>
                    </select>
                    <select name="lead_status">
                        <option value="all" <?php selected( 'all', $leads_status ); ?>><?php esc_html_e( 'All Statuses', 'brn-lead-count' ); ?></option>
                        <option value="real" <?php selected( 'real', $leads_status ); ?>><?php esc_html_e( 'Real', 'brn-lead-count' ); ?></option>
                        <option value="test" <?php selected( 'test', $leads_status ); ?>><?php esc_html_e( 'Test', 'brn-lead-count' ); ?></option>
                    </select>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $leads_from ); ?>" />
                    <input type="date" name="date_to" value="<?php echo esc_attr( $leads_to ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'brn-lead-count' ); ?></button>
                </form>
                <?php if ( empty( $logs ) ) : ?>
                    <p><?php esc_html_e( 'No logs yet.', 'brn-lead-count' ); ?></p>
                <?php else : ?>
                    <?php
                    $next_order = ( 'ASC' === $order ) ? 'DESC' : 'ASC';
                    $build_sort = static function ( $field ) use ( $next_order, $leads_search, $leads_type, $leads_status, $leads_from, $leads_to ) {
                        return add_query_arg(
                            array(
                                'page'        => 'brn-lead-count-leads',
                                'orderby'     => $field,
                                'order'       => $next_order,
                                's'           => $leads_search,
                                'lead_type'   => $leads_type,
                                'lead_status' => $leads_status,
                                'date_from'   => $leads_from,
                                'date_to'     => $leads_to,
                            ),
                            admin_url( 'admin.php' )
                        );
                    };
                    ?>
                    <form method="post">
                        <?php wp_nonce_field( 'brn_lead_count_bulk_update_action', 'brn_lead_count_bulk_update_nonce' ); ?>
                        <input type="hidden" name="brn_lead_count_bulk_update" value="1" />
                        <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;">
                            <select name="bulk_action_test">
                                <option value=""><?php esc_html_e( 'Bulk action', 'brn-lead-count' ); ?></option>
                                <option value="mark_test"><?php esc_html_e( 'Mark as Test', 'brn-lead-count' ); ?></option>
                                <option value="mark_real"><?php esc_html_e( 'Mark as Real', 'brn-lead-count' ); ?></option>
                            </select>
                            <button type="submit" class="button"><?php esc_html_e( 'Apply', 'brn-lead-count' ); ?></button>
                        </div>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="brn-select-all-leads" /></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'time' ) ); ?>"><?php esc_html_e( 'Time', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'type' ) ); ?>"><?php esc_html_e( 'Type', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'is_test' ) ); ?>"><?php esc_html_e( 'Test', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'ip' ) ); ?>"><?php esc_html_e( 'IP', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'browser' ) ); ?>"><?php esc_html_e( 'Browser', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'device' ) ); ?>"><?php esc_html_e( 'Device', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'country_name' ) ); ?>"><?php esc_html_e( 'Country', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'label' ) ); ?>"><?php esc_html_e( 'Label', 'brn-lead-count' ); ?></a></th>
                                    <th><a href="<?php echo esc_url( $build_sort( 'page_title' ) ); ?>"><?php esc_html_e( 'Page', 'brn-lead-count' ); ?></a></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr>
                                        <td><input type="checkbox" name="lead_ids[]" value="<?php echo esc_attr( isset( $log['id'] ) ? (string) $log['id'] : '' ); ?>" /></td>
                                        <td><?php echo esc_html( isset( $log['time'] ) ? (string) $log['time'] : '' ); ?></td>
                                        <td>
                                            <?php
                                            $lead_type_raw = isset( $log['type'] ) ? (string) $log['type'] : '';
                                            $lead_type_map = array(
                                                'phone' => __( 'Phone', 'brn-lead-count' ),
                                                'whatsapp' => __( 'WhatsApp', 'brn-lead-count' ),
                                                'email' => __( 'Email', 'brn-lead-count' ),
                                                'form_submit' => __( 'Form Submit', 'brn-lead-count' ),
                                            );
                                            echo esc_html( isset( $lead_type_map[ $lead_type_raw ] ) ? $lead_type_map[ $lead_type_raw ] : $lead_type_raw );
                                            ?>
                                        </td>
                                        <td><?php echo ! empty( $log['is_test'] ) ? esc_html__( 'Test', 'brn-lead-count' ) : esc_html__( 'Real', 'brn-lead-count' ); ?></td>
                                        <td><?php echo esc_html( isset( $log['ip'] ) ? (string) $log['ip'] : '' ); ?></td>
                                        <td><?php echo esc_html( isset( $log['browser'] ) ? (string) $log['browser'] : '' ); ?></td>
                                        <td><?php echo esc_html( isset( $log['device'] ) ? (string) $log['device'] : '' ); ?></td>
                                        <td>
                                            <?php
                                            $country_code = isset( $log['country_code'] ) ? strtolower( (string) $log['country_code'] ) : '';
                                            $country_name = isset( $log['country_name'] ) ? (string) $log['country_name'] : '';
                                            ?>
                                            <?php if ( '' !== $country_code ) : ?>
                                                <img src="<?php echo esc_url( 'https://flagcdn.com/16x12/' . $country_code . '.png' ); ?>" alt="<?php echo esc_attr( $country_name ); ?>" title="<?php echo esc_attr( $country_name ); ?>" width="16" height="12" />
                                            <?php endif; ?>
                                            <?php echo esc_html( $country_name ); ?>
                                        </td>
                                        <td><?php echo esc_html( isset( $log['label'] ) ? (string) $log['label'] : '' ); ?></td>
                                        <td>
                                            <?php
                                            $pg_url   = isset( $log['page_url'] ) ? (string) $log['page_url'] : '';
                                            $pg_title = isset( $log['page_title'] ) && '' !== $log['page_title'] ? (string) $log['page_title'] : $pg_url;
                                            if ( '' !== $pg_url ) :
                                            ?>
                                                <a href="<?php echo esc_url( $pg_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pg_title ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $pg_title ); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                    <script>
                    (function () {
                        var selectAll = document.getElementById( 'brn-select-all-leads' );
                        if ( ! selectAll ) {
                            return;
                        }
                        selectAll.addEventListener( 'change', function () {
                            document.querySelectorAll( 'input[name="lead_ids[]"]' ).forEach( function (cb) {
                                cb.checked = selectAll.checked;
                            } );
                        } );
                    }());
                    </script>
                <?php endif; ?>

                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field( 'brn_lead_count_clear_logs_action', 'brn_lead_count_clear_logs_nonce' ); ?>
                    <input type="hidden" name="brn_lead_count_clear_logs" value="1" />
                    <?php submit_button( __( 'Clear Logs', 'brn-lead-count' ), 'secondary', 'submit', false ); ?>
                </form>

                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field( 'brn_lead_count_reset_all_action', 'brn_lead_count_reset_all_nonce' ); ?>
                    <input type="hidden" name="brn_lead_count_reset_all" value="1" />
                    <?php submit_button( __( 'Reset All Counters and Logs', 'brn-lead-count' ), 'delete', 'submit', false ); ?>
                </form>
                <?php endif; ?>

                <?php if ( 'reports' === $section ) : ?>
                <?php
                $report_payload = $this->build_daily_report_payload();
                $last_sent      = (int) get_option( self::OPTION_LAST_REPORT_SENT, 0 );
                ?>
                <h2 style="margin-top:24px;"><?php esc_html_e( 'Daily Report', 'brn-lead-count' ); ?></h2>
                <p><?php echo esc_html( sprintf( __( 'Report day: %s', 'brn-lead-count' ), $report_payload['report_day_label'] ) ); ?></p>
                <p><?php echo esc_html( sprintf( __( 'Last email sent: %s', 'brn-lead-count' ), $last_sent ? wp_date( 'Y-m-d H:i', $last_sent ) : __( 'Never', 'brn-lead-count' ) ) ); ?></p>

                <table class="widefat striped" style="max-width:780px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Period', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Phone', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'WhatsApp', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Form Submit', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Total', 'brn-lead-count' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = array(
                            __( 'Last day', 'brn-lead-count' ) => $report_payload['report_day'],
                            __( 'Same day last month', 'brn-lead-count' ) => $report_payload['same_day_last_month'],
                            __( 'Month-to-date (through last day)', 'brn-lead-count' ) => $report_payload['mtd_current'],
                            __( 'Same month last year (through same day)', 'brn-lead-count' ) => $report_payload['same_month_last_year'],
                        );
                        foreach ( $rows as $label => $row_counts ) :
                        ?>
                        <tr>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><?php echo esc_html( (string) $row_counts['phone'] ); ?></td>
                            <td><?php echo esc_html( (string) $row_counts['whatsapp'] ); ?></td>
                            <td><?php echo esc_html( (string) $row_counts['email'] ); ?></td>
                            <td><?php echo esc_html( (string) $row_counts['form_submit'] ); ?></td>
                            <td><strong><?php echo esc_html( (string) $row_counts['total'] ); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px;">
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field( 'brn_preview_daily_report_action', 'brn_preview_daily_report_nonce' ); ?>
                    <input type="hidden" name="brn_preview_daily_report" value="1" />
                    <?php submit_button( __( 'Preview Email', 'brn-lead-count' ), 'secondary', 'submit', false ); ?>
                </form>

                <form method="post" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <?php wp_nonce_field( 'brn_send_daily_report_now_action', 'brn_send_daily_report_now_nonce' ); ?>
                    <input type="hidden" name="brn_send_daily_report_now" value="1" />
                    <label for="manual_report_email" style="font-weight:600;"><?php esc_html_e( 'Send to:', 'brn-lead-count' ); ?></label>
                    <input type="email" required id="manual_report_email" name="manual_report_email" placeholder="email@example.com" value="<?php echo esc_attr( $manual_report_email ); ?>" />
                    <?php submit_button( __( 'Send Report Now', 'brn-lead-count' ), 'primary', 'submit', false ); ?>
                </form>
                </div>

                <?php if ( $report_preview_requested ) : ?>
                <h3 style="margin-top:24px;"><?php esc_html_e( 'Email Preview', 'brn-lead-count' ); ?></h3>
                <div style="border:1px solid #ccd0d4;border-radius:6px;overflow:hidden;background:#fff;">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is generated from internal data and escaped values.
                    echo $this->build_daily_report_html( $report_payload );
                    ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ( 'updates' === $section ) : ?>
                <?php
                // ΓöÇΓöÇ Updates section ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ //
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-brn-updater.php';
                $current_version = $this->get_installed_plugin_version();
                $cached_info     = get_option( BRN_Updater::OPT_CACHE );
                $last_checked    = get_option( BRN_Updater::OPT_LAST_CHECKED, 0 );
                $last_error      = get_option( BRN_Updater::OPT_LAST_ERROR, '' );
                $latest_version  = ( $cached_info && isset( $cached_info['version'] ) ) ? $cached_info['version'] : '';
                $update_avail    = $latest_version && version_compare( $current_version, $latest_version, '<' );
                $plugin_file     = plugin_basename( __FILE__ );
                $update_url      = wp_nonce_url(
                    self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $plugin_file ) ),
                    'upgrade-plugin_' . $plugin_file
                );
                ?>

                <h2 style="margin-top:32px;"><?php esc_html_e( 'Plugin Updates', 'brn-lead-count' ); ?></h2>
                <table class="widefat striped" style="max-width:640px;margin-bottom:16px;">
                    <tbody>
                        <tr>
                            <th style="width:200px;"><?php esc_html_e( 'Installed version', 'brn-lead-count' ); ?></th>
                            <td><?php echo esc_html( $current_version ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Latest version (GitHub)', 'brn-lead-count' ); ?></th>
                            <td>
                                <?php if ( $latest_version ) : ?>
                                    <strong><?php echo esc_html( $latest_version ); ?></strong>
                                    <?php if ( $update_avail ) : ?>
                                        &nbsp;<span style="color:#d63638;"><?php esc_html_e( '- update available', 'brn-lead-count' ); ?></span>
                                    <?php else : ?>
                                        &nbsp;<span style="color:#00a32a;"><?php esc_html_e( '- up to date', 'brn-lead-count' ); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'Not checked yet', 'brn-lead-count' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Last checked', 'brn-lead-count' ); ?></th>
                            <td><?php echo $last_checked ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) ) : esc_html__( 'Never', 'brn-lead-count' ); ?></td>
                        </tr>
                        <?php if ( $last_error ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Last error', 'brn-lead-count' ); ?></th>
                            <td style="color:#d63638;"><?php echo esc_html( $last_error ); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $update_avail ) : ?>
                    <p>
                        <a href="<?php echo esc_url( $update_url ); ?>" class="button button-primary">
                            <?php
                            printf(
                                /* translators: %s = new version number */
                                esc_html__( 'Update now to v%s', 'brn-lead-count' ),
                                esc_html( $latest_version )
                            );
                            ?>
                        </a>
                        &nbsp;
                        <a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>" class="button">
                            <?php esc_html_e( 'Open Plugins screen', 'brn-lead-count' ); ?>
                        </a>
                    </p>
                <?php endif; ?>

                <button type="button" class="button" id="brn-check-updates-btn">
                    <?php esc_html_e( 'Check for updates now', 'brn-lead-count' ); ?>
                </button>
                <span id="brn-check-updates-status" style="margin-left:10px;vertical-align:middle;"></span>

                <script>
                (function () {
                    var btn    = document.getElementById( 'brn-check-updates-btn' );
                    var status = document.getElementById( 'brn-check-updates-status' );
                    if ( ! btn ) { return; }

                    btn.addEventListener( 'click', function () {
                        btn.disabled = true;
                        status.style.color = '';
                        status.textContent = <?php echo wp_json_encode( __( 'Checking...', 'brn-lead-count' ) ); ?>;

                        var body = new URLSearchParams();
                        body.append( 'action', 'brn_lead_count_check_updates' );
                        body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE_CHECK_UPDATES ) ); ?> );

                        fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                            method      : 'POST',
                            credentials : 'same-origin',
                            headers     : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body        : body.toString()
                        } )
                        .then( function ( r ) { return r.json(); } )
                        .then( function ( d ) {
                            btn.disabled = false;
                            if ( d.success ) {
                                var msg = d.data.update_available
                                    ? <?php echo wp_json_encode( __( 'Update available: v', 'brn-lead-count' ) ); ?> + d.data.latest_version
                                    : <?php echo wp_json_encode( __( 'You are up to date (v', 'brn-lead-count' ) ); ?> + d.data.current_version + ')';
                                status.style.color = d.data.update_available ? '#d63638' : '#00a32a';
                                status.textContent = msg;
                                if ( d.data.update_available ) {
                                    // Reload page so the update notice and link appear.
                                    setTimeout( function () { window.location.reload(); }, 1500 );
                                }
                            } else {
                                status.style.color = '#d63638';
                                status.textContent = ( d.data && d.data.message )
                                    ? d.data.message
                                    : <?php echo wp_json_encode( __( 'Check failed.', 'brn-lead-count' ) ); ?>;
                            }
                        } )
                        .catch( function () {
                            btn.disabled = false;
                            status.style.color = '#d63638';
                            status.textContent = <?php echo wp_json_encode( __( 'Network error.', 'brn-lead-count' ) ); ?>;
                        } );
                    } );
                }());
                </script>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}

new BRN_Lead_Count();
