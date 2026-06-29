<?php
/**
 * Plugin Name: BRN Lead Count
 * Description: Counts and logs lead actions (phone clicks, WhatsApp clicks, email clicks, and form submissions), classifies PPC vs organic traffic, and tracks WooCommerce sales by source.
 * Version: 1.7.7
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
        const OPTION_TRACKING_TOKEN = 'brn_lead_count_tracking_token';
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

            // REST API endpoint — more reliable than admin-ajax.php on cached/proxied hosts (e.g. WP Engine).
            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

            // WooCommerce: store our richer lead-source classification on the order
            // at checkout. The Sales dashboard reads orders live from WooCommerce,
            // so historical orders are included too. These hooks only fire when
            // WooCommerce is active, so registering them unconditionally is safe.
            add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_order_source' ), 10, 1 );
            add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_order_source' ), 10, 1 );
        }

        /**
         * Return a permanent site-specific tracking token, creating one if absent.
         * Unlike WP nonces, this token never expires, so it works correctly even
         * when served from a cached page (e.g. WP Engine full-page cache).
         *
         * @return string
         */
        private function get_tracking_token() {
            $token = get_option( self::OPTION_TRACKING_TOKEN, '' );
            if ( '' === $token ) {
                $token = wp_generate_password( 32, false );
                update_option( self::OPTION_TRACKING_TOKEN, $token, false );
            }
            return $token;
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

                if ( ! isset( $log['source'] ) || '' === (string) $log['source'] ) {
                    $page_url      = isset( $log['page_url'] ) ? (string) $log['page_url'] : '';
                    $log['source'] = $this->normalize_source( $this->derive_source_from_url( $page_url ) );
                    $changed       = true;
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
                'report_send_time'         => '09:00',
                'report_language'          => 'en',
                'enable_recommendations'   => 0,
            );

            $settings = get_option( self::OPTION_SETTINGS, array() );
            $settings = wp_parse_args( $settings, $defaults );

            $settings['enable_logging'] = empty( $settings['enable_logging'] ) ? 0 : 1;
            $settings['max_logs'] = max( 10, min( 2000, absint( $settings['max_logs'] ) ) );
            $settings['test_users'] = isset( $settings['test_users'] ) ? (string) $settings['test_users'] : '';
            $settings['test_ips'] = isset( $settings['test_ips'] ) ? (string) $settings['test_ips'] : '';
            $settings['report_emails'] = isset( $settings['report_emails'] ) ? (string) $settings['report_emails'] : '';
            $settings['report_send_time'] = isset( $settings['report_send_time'] ) ? (string) $settings['report_send_time'] : '09:00';
            $settings['report_language']        = ( isset( $settings['report_language'] ) && 'he' === $settings['report_language'] ) ? 'he' : 'en';
            $settings['enable_recommendations'] = empty( $settings['enable_recommendations'] ) ? 0 : 1;

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
         * Count WooCommerce sales (orders + revenue) within a time window.
         *
         * Reads orders live from WooCommerce — matching the Sales dashboard — so
         * the report reflects real processing/completed orders. Returns zeros when
         * WooCommerce is inactive.
         *
         * @param int $start_ts
         * @param int $end_ts
         * @return array{orders:int,revenue:float,by_source:array<string,array{orders:int,revenue:float}>}
         */
        private function get_window_sales( $start_ts, $end_ts ) {
            $result = array(
                'orders'    => 0,
                'revenue'   => 0.0,
                'by_source' => array(),
            );

            if ( ! function_exists( 'wc_get_orders' ) ) {
                return $result;
            }

            $orders = wc_get_orders(
                array(
                    'status'       => array( 'wc-processing', 'wc-completed' ),
                    'limit'        => -1,
                    'date_created' => (int) $start_ts . '...' . (int) $end_ts,
                    'return'       => 'objects',
                )
            );
            if ( ! is_array( $orders ) ) {
                return $result;
            }

            foreach ( $orders as $order ) {
                if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
                    continue;
                }
                $total = (float) $order->get_total();
                $src   = $this->get_order_source( $order );

                $result['orders']  += 1;
                $result['revenue'] += $total;

                if ( ! isset( $result['by_source'][ $src ] ) ) {
                    $result['by_source'][ $src ] = array( 'orders' => 0, 'revenue' => 0.0 );
                }
                $result['by_source'][ $src ]['orders']  += 1;
                $result['by_source'][ $src ]['revenue'] += $total;
            }

            return $result;
        }

        /**
         * Build source totals for a given time window.
         *
         * @param array $logs
         * @param int   $start_ts
         * @param int   $end_ts
         * @return array
         */
        private function get_window_source_counts( $logs, $start_ts, $end_ts ) {
            $source_counts = array();
            $tz            = wp_timezone();

            foreach ( $logs as $log ) {
                if ( ! is_array( $log ) || empty( $log['time'] ) || ! empty( $log['is_test'] ) ) {
                    continue;
                }

                $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $log['time'], $tz );
                $ts = $dt ? $dt->getTimestamp() : false;
                if ( false === $ts || $ts < $start_ts || $ts > $end_ts ) {
                    continue;
                }

                $source = isset( $log['source'] ) ? (string) $log['source'] : '';
                $source = $this->normalize_source( $source );
                if ( ! isset( $source_counts[ $source ] ) ) {
                    $source_counts[ $source ] = 0;
                }
                $source_counts[ $source ]++;
            }

            arsort( $source_counts );

            return $source_counts;
        }

        /**
         * Resolve source from URL query params.
         *
         * @param string $url
         * @return string
         */
        private function derive_source_from_url( $url ) {
            $url = (string) $url;
            if ( '' === $url ) {
                return 'direct';
            }

            $query = wp_parse_url( $url, PHP_URL_QUERY );
            if ( ! empty( $query ) ) {
                parse_str( (string) $query, $params );

                // Paid-click detection runs first so PPC traffic is not mistaken
                // for organic (e.g. a Google Ads click whose referrer is google.com).
                $paid = $this->classify_paid_source( $params );
                if ( '' !== $paid ) {
                    return $paid;
                }

                foreach ( array( 'utm_source', 'source', 'src', 'ref' ) as $key ) {
                    if ( ! empty( $params[ $key ] ) ) {
                        return (string) $params[ $key ];
                    }
                }
            }

            return 'direct';
        }

        /**
         * Detect paid-traffic (PPC) sources from URL query parameters.
         *
         * Uses ad-network click identifiers — which are present even when no UTM
         * tags are set (e.g. Google Ads auto-tagging only adds gclid) — plus
         * explicit paid UTM mediums. Returns a distinct, normalized source label
         * (e.g. "google-ads") so paid traffic is reported separately from organic,
         * or '' when the visit is not identifiably paid.
         *
         * @param array $params Parsed query parameters.
         * @return string
         */
        private function classify_paid_source( $params ) {
            $params = is_array( $params ) ? $params : array();

            if ( ! empty( $params['gclid'] ) || ! empty( $params['gbraid'] ) || ! empty( $params['wbraid'] ) ) {
                return 'google-ads';
            }
            if ( ! empty( $params['msclkid'] ) ) {
                return 'microsoft-ads';
            }
            if ( ! empty( $params['fbclid'] ) ) {
                return 'facebook-ads';
            }

            $medium       = isset( $params['utm_medium'] ) ? strtolower( trim( (string) $params['utm_medium'] ) ) : '';
            $paid_mediums = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'paid_search', 'cpm', 'paid-social', 'paidsocial' );
            if ( in_array( $medium, $paid_mediums, true ) ) {
                $src = isset( $params['utm_source'] ) ? strtolower( trim( (string) $params['utm_source'] ) ) : '';
                if ( '' !== $src ) {
                    return ( 'ads' === substr( $src, -3 ) ) ? $src : $src . '-ads';
                }
                return 'paid';
            }

            return '';
        }

        /**
         * Normalize source string for storage.
         *
         * @param string $source
         * @return string
         */
        private function normalize_source( $source ) {
            $source = strtolower( trim( (string) $source ) );
            $source = preg_replace( '/\s+/', '-', $source );
            $source = preg_replace( '/[^a-z0-9_\-.]/', '', (string) $source );
            $source = trim( (string) $source, '-.' );

            if ( '' === $source ) {
                return 'direct';
            }

            // Vulnerability scanners inject SQLi/XSS/command-injection payloads
            // into the utm_source/source/src/ref params, which would otherwise be
            // logged and reported as fake "sources". Bucket such non-legitimate
            // traffic under "other" (kept distinct from genuine Direct) so it is
            // never stored at capture time and never shown verbatim in the report
            // or admin views. This single chokepoint covers every read and write
            // of a source value.
            if ( $this->is_suspicious_source( $source ) ) {
                return 'other';
            }

            return substr( $source, 0, 80 );
        }

        /**
         * Detect bot/scanner garbage masquerading as a traffic source.
         *
         * Operates on an already-normalized source (lowercase, spaces -> '-').
         * Genuine sources are short, single-ish tokens (e.g. "google.com",
         * "google-ads", "newsletter"); injected payloads are long, multi-word, or
         * carry recognisable attack signatures.
         *
         * @param string $source Normalized source value.
         * @return bool True when the value looks like attack/scanner noise.
         */
        private function is_suspicious_source( $source ) {
            $source = (string) $source;
            if ( '' === $source ) {
                return false;
            }

            // Real campaign sources are short; an injected sentence/payload is not.
            if ( strlen( $source ) > 40 ) {
                return true;
            }

            // Many dash-separated words => a phrase/payload, not a source label.
            if ( substr_count( $source, '-' ) >= 4 ) {
                return true;
            }

            // Signatures of common automated-scanner payloads (SQLi, XSS, command
            // and template injection, path traversal, OOB callback domains).
            $needles = array(
                'select', 'union', 'insert', 'update', 'delete', 'drop', 'from-dual',
                'sleep', 'benchmark', 'waitfor', 'concat', 'information-schema', 'pg_sleep',
                'response.write', 'echo', 'print', 'md5', 'eval', 'base64', 'array',
                'script', 'onerror', 'onload', 'alert', 'javascript', 'iframe', 'svg',
                'win.ini', 'boot.ini', 'etc-passwd', 'passwd', 'cmd', 'powershell',
                'bxss', 'r87.me', 'burpcollab', 'oastify', 'interact.sh', 'nslookup',
                'document.cookie', 'http-equiv',
            );
            foreach ( $needles as $needle ) {
                if ( false !== strpos( $source, $needle ) ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Human label for source value.
         *
         * @param string $source
         * @return string
         */
        private function source_label( $source ) {
            $source = $this->normalize_source( $source );
            if ( 'direct' === $source ) {
                return __( 'Direct', 'brn-lead-count' );
            }
            if ( 'other' === $source ) {
                return __( 'Other', 'brn-lead-count' );
            }

            return str_replace( '-', ' ', (string) $source );
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

            $tz  = wp_timezone();
            $now = new DateTimeImmutable( '@' . ( $reference_ts ? (int) $reference_ts : time() ) );
            $now = $now->setTimezone( $tz );

            // Yesterday (the report day).
            $report_day       = $now->modify( '-1 day' );
            $report_day_start = $report_day->setTime( 0, 0, 0 );
            $report_day_end   = $report_day->setTime( 23, 59, 59 );

            // Same calendar day of previous month (for single-day comparison).
            $last_month_day       = $report_day->modify( '-1 month' );
            $last_month_day_start = $last_month_day->setTime( 0, 0, 0 );
            $last_month_day_end   = $last_month_day->setTime( 23, 59, 59 );

            // Month-to-date: first of current month -> yesterday end.
            $mtd_start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $mtd_end   = $report_day_end;

            // Previous-month MTD: first of prev month -> same day-of-month in prev month.
            $prev_month_first    = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
            $prev_month_same_day = $last_month_day->setTime( 23, 59, 59 );

            // Same month last year (for legacy admin preview).
            $last_year_same_month_start = $now->modify( '-1 year' )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $last_year_same_month_end   = $report_day_end->modify( '-1 year' );

            // Source counts for all relevant windows.
            $report_day_sources = $this->get_window_source_counts( $logs, $report_day_start->getTimestamp(), $report_day_end->getTimestamp() );
            $mtd_sources        = $this->get_window_source_counts( $logs, $mtd_start->getTimestamp(), $mtd_end->getTimestamp() );
            $prev_mtd_sources   = $this->get_window_source_counts( $logs, $prev_month_first->getTimestamp(), $prev_month_same_day->getTimestamp() );

            // Build merged sources table sorted by MTD descending.
            $all_source_keys = array_unique( array_merge( array_keys( $mtd_sources ), array_keys( $prev_mtd_sources ) ) );
            $sources_table   = array();
            foreach ( $all_source_keys as $sk ) {
                $day_val  = isset( $report_day_sources[ $sk ] ) ? (int) $report_day_sources[ $sk ] : 0;
                $mtd_val  = isset( $mtd_sources[ $sk ] ) ? (int) $mtd_sources[ $sk ] : 0;
                $prev_val = isset( $prev_mtd_sources[ $sk ] ) ? (int) $prev_mtd_sources[ $sk ] : 0;
                $sources_table[ $sk ] = array(
                    'day'   => $day_val,
                    'mtd'   => $mtd_val,
                    'prev'  => $prev_val,
                    'trend' => $this->build_trend_data( $mtd_val, $prev_val ),
                );
            }
            uasort( $sources_table, function ( $a, $b ) {
                return $b['mtd'] - $a['mtd'];
            } );

            // WooCommerce sales windows (orders + revenue, with per-source breakdown).
            $sales_report_day = $this->get_window_sales( $report_day_start->getTimestamp(), $report_day_end->getTimestamp() );
            $sales_mtd        = $this->get_window_sales( $mtd_start->getTimestamp(), $mtd_end->getTimestamp() );
            $sales_prev_mtd   = $this->get_window_sales( $prev_month_first->getTimestamp(), $prev_month_same_day->getTimestamp() );

            // Build a "Sales by Source" table (month-to-date), sorted by revenue
            // descending, with each source's share of total MTD revenue.
            $mtd_by_source = isset( $sales_mtd['by_source'] ) && is_array( $sales_mtd['by_source'] ) ? $sales_mtd['by_source'] : array();
            $day_by_source = isset( $sales_report_day['by_source'] ) && is_array( $sales_report_day['by_source'] ) ? $sales_report_day['by_source'] : array();
            $mtd_revenue   = isset( $sales_mtd['revenue'] ) ? (float) $sales_mtd['revenue'] : 0.0;

            $sales_sources_table = array();
            foreach ( $mtd_by_source as $sk => $vals ) {
                $rev = isset( $vals['revenue'] ) ? (float) $vals['revenue'] : 0.0;
                $sales_sources_table[ $sk ] = array(
                    'day_orders'  => isset( $day_by_source[ $sk ]['orders'] ) ? (int) $day_by_source[ $sk ]['orders'] : 0,
                    'orders'      => isset( $vals['orders'] ) ? (int) $vals['orders'] : 0,
                    'revenue'     => $rev,
                    'share'       => ( $mtd_revenue > 0 ) ? round( ( $rev / $mtd_revenue ) * 100, 1 ) : 0.0,
                );
            }
            uasort( $sales_sources_table, function ( $a, $b ) {
                if ( $a['revenue'] === $b['revenue'] ) {
                    return 0;
                }
                return ( $a['revenue'] < $b['revenue'] ) ? 1 : -1;
            } );

            return array(
                'now_label'        => wp_date( 'Y-m-d H:i', $now->getTimestamp() ),
                'report_day_label' => wp_date( 'Y-m-d', $report_day_start->getTimestamp() ),
                // Single-day windows.
                'report_day'          => $this->get_window_counts( $logs, $report_day_start->getTimestamp(), $report_day_end->getTimestamp() ),
                'same_day_last_month' => $this->get_window_counts( $logs, $last_month_day_start->getTimestamp(), $last_month_day_end->getTimestamp() ),
                // MTD windows.
                'mtd_current'    => $this->get_window_counts( $logs, $mtd_start->getTimestamp(), $mtd_end->getTimestamp() ),
                'mtd_prev_month' => $this->get_window_counts( $logs, $prev_month_first->getTimestamp(), $prev_month_same_day->getTimestamp() ),
                // WooCommerce sales for the same windows (orders + revenue).
                'sales_enabled'        => function_exists( 'wc_get_orders' ),
                'sales_report_day'     => $sales_report_day,
                'sales_mtd_current'    => $sales_mtd,
                'sales_mtd_prev_month' => $sales_prev_mtd,
                'sales_sources_table'  => $sales_sources_table,
                // Sources.
                'report_day_sources' => $report_day_sources,
                'sources_table'      => $sources_table,
                // Legacy key kept for admin preview tab.
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
         * Build a styled HTML daily report email (Outlook-optimized).
         *
         * @param array $report
         * @return string
         */
        private function build_daily_report_html( $report ) {
            $settings  = $this->get_settings();
            $is_hebrew = ( isset( $settings['report_language'] ) && 'he' === $settings['report_language'] );
            $dir       = $is_hebrew ? 'rtl' : 'ltr';
            $domain    = $this->get_site_domain();

            $lbl_title          = $is_hebrew ? 'סיכום יומי - לידים' : 'Daily Lead Report';
            $lbl_subtitle       = $is_hebrew ? 'סקירת לידים יומית' : 'Your daily lead summary';
            $lbl_site           = $is_hebrew ? 'אתר' : 'Site';
            $lbl_yesterday      = $is_hebrew ? 'יום קודם' : 'Yesterday';
            $lbl_mtd            = $is_hebrew ? 'מצטבר חודשי' : 'Month to Date';
            $lbl_vs_prev        = $is_hebrew ? 'מול חודש קודם' : 'vs Prev Month';
            $lbl_no_change      = $is_hebrew ? 'ללא שינוי' : 'No change';
            $lbl_phone          = $is_hebrew ? 'טלפון' : 'Phone';
            $lbl_whatsapp       = $is_hebrew ? 'וואטסאפ' : 'WhatsApp';
            $lbl_email_type     = $is_hebrew ? 'אימייל' : 'Email';
            $lbl_form           = $is_hebrew ? 'טופס' : 'Form';
            $lbl_sales_section  = $is_hebrew ? 'מכירות (WooCommerce)' : 'Sales (WooCommerce)';
            $lbl_orders         = $is_hebrew ? 'הזמנות' : 'Orders';
            $lbl_revenue        = $is_hebrew ? 'הכנסה' : 'Revenue';
            $lbl_sales_by_src   = $is_hebrew ? 'מכירות לפי מקור (מצטבר חודשי)' : 'Sales by Source (Month to Date)';
            $lbl_share          = $is_hebrew ? 'נתח הכנסה' : 'Share';
            $lbl_source_section = $is_hebrew ? 'לידים לפי מקור' : 'Leads by Source';
            $lbl_source_col     = $is_hebrew ? 'מקור' : 'Source';
            $lbl_reco_section   = $is_hebrew ? 'המלצות לשיפור' : 'Recommendations';
            $lbl_footer         = $is_hebrew
                ? 'התמדה בקמפיינים הופכת את הדופק היומי לצמיחה חודשית מצטברת.'
                : 'Stay consistent with campaigns, and your daily pulse turns into compounding monthly growth.';

            $n = static function ( $arr, $key ) {
                return isset( $arr[ $key ] ) ? (int) $arr[ $key ] : 0;
            };

            $trend_text = function ( $current, $previous ) use ( $lbl_no_change ) {
                $t = $this->build_trend_data( $current, $previous );
                if ( 'flat' === $t['direction'] ) {
                    return array(
                        'text'  => $lbl_no_change,
                        'color' => '#60758f',
                    );
                }
                $sign = $t['delta'] > 0 ? '+' : '';
                return array(
                    'text'  => $sign . $t['delta'] . ' (' . $sign . $t['pct'] . '%)',
                    'color' => ( 'down' === $t['direction'] ) ? '#c0392b' : '#1a8a50',
                );
            };

            // Money formatter for the sales section (uses the WooCommerce currency
            // symbol when available; falls back to a plain localized number).
            $fmt_money = static function ( $amount ) {
                $amount = (float) $amount;
                $symbol = function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
                    : '';
                return $symbol . number_format_i18n( $amount, 0 );
            };
            $fmt_count = static function ( $amount ) {
                return number_format_i18n( (int) $amount );
            };

            $rd  = isset( $report['report_day'] ) ? $report['report_day'] : array();
            $mtd = isset( $report['mtd_current'] ) ? $report['mtd_current'] : array();
            $pmt = isset( $report['mtd_prev_month'] ) ? $report['mtd_prev_month'] : array();

            $today_total = $n( $rd, 'total' );
            $mtd_total   = $n( $mtd, 'total' );
            $pmt_total   = $n( $pmt, 'total' );
            $mtd_trend   = $trend_text( $mtd_total, $pmt_total );

            $align_primary = $is_hebrew ? 'right' : 'left';

            // Reusable Yesterday/MTD stat card (also used by the sales section).
            // $formatter renders the raw numeric value (count or money).
            // Inline style that forces a left-to-right run for a number, set on the
            // element that directly contains it. Uses both the CSS direction and a
            // dir="ltr" attribute (added at each call site) so the value renders
            // correctly even in RTL mail clients that strip one or the other.
            $ltr_style = 'direction:ltr;unicode-bidi:isolate;';

            $stat_card = function ( $label, $day_val, $mtd_val, $pmt_val, $formatter ) use ( $align_primary, $lbl_yesterday, $lbl_mtd, $trend_text, $ltr_style ) {
                $trend = $trend_text( (int) round( $mtd_val ), (int) round( $pmt_val ) );
                $h  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4ebf4;background:#fafbfd;">';
                $h .= '<tr><td style="padding:14px 14px;">';
                $h .= '<div style="font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;margin-bottom:12px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $label ) . '</div>';
                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
                $h .= '<tr>';
                $h .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-right:8px;">';
                $h .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</div>';
                $h .= '<div dir="ltr" style="font-size:28px;line-height:32px;font-weight:bold;color:#0f5fb7;margin-top:6px;text-align:' . esc_attr( $align_primary ) . ';' . $ltr_style . '">' . esc_html( $formatter( $day_val ) ) . '</div>';
                $h .= '</td>';
                $h .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-left:8px;border-left:1px solid #e4ebf4;">';
                $h .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</div>';
                $h .= '<div dir="ltr" style="font-size:28px;line-height:32px;font-weight:bold;color:#1a8a50;margin-top:6px;text-align:' . esc_attr( $align_primary ) . ';' . $ltr_style . '">' . esc_html( $formatter( $mtd_val ) ) . '</div>';
                $h .= '<div dir="ltr" style="font-size:11px;line-height:14px;color:' . esc_attr( $trend['color'] ) . ';font-weight:bold;margin-top:4px;text-align:' . esc_attr( $align_primary ) . ';' . $ltr_style . '">' . esc_html( $trend['text'] ) . '</div>';
                $h .= '</td>';
                $h .= '</tr></table></td></tr></table>';
                return $h;
            };

            // Outer container.
            // Arial font, applied on every text container so it is honored across
            // mail clients that don't reliably inherit font-family into nested tables.
            $font = 'font-family:Arial,Helvetica,sans-serif;';

            $html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0;padding:0;background:#f4f7fb;' . $font . '" dir="' . esc_attr( $dir ) . '">';
            $html .= '<tr><td align="center" style="padding:20px 8px;' . $font . '">';

            // Main email wrapper.
            $html .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#ffffff;border:1px solid #dbe4ef;' . $font . '">';

            // Header.
            $html .= '<tr><td style="background:#0f5fb7;padding:20px 20px;color:#ffffff;' . $font . '">';
            $html .= '<div style="font-size:26px;line-height:32px;font-weight:bold;margin:0 0 8px 0;color:#ffffff;' . $font . '">' . esc_html( $lbl_title ) . '</div>';
            $html .= '<div style="font-size:13px;line-height:18px;color:#eaf2ff;' . $font . '">' . esc_html( $lbl_subtitle ) . ' - ' . esc_html( isset( $report['report_day_label'] ) ? $report['report_day_label'] : '' ) . '</div>';
            // Render the domain as an explicit white, underlined link so mail
            // clients don't auto-linkify it in their default (blue) style.
            $domain_link = '<a href="' . esc_url( 'https://' . $domain ) . '" style="color:#ffffff;text-decoration:underline;' . $font . '">' . esc_html( $domain ) . '</a>';
            $html .= '<div style="font-size:12px;line-height:16px;color:#ffffff;margin-top:8px;' . $font . '">' . esc_html( $lbl_site ) . ': ' . $domain_link . '</div>';
            $html .= '</td></tr>';

            // Top spacing.
            $html .= '<tr><td height="14" style="height:14px;line-height:14px;font-size:1px;">&nbsp;</td></tr>';

            // Section 1: KPI boxes (Yesterday & MTD).
            $html .= '<tr><td style="padding:0 16px;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            $html .= '<tr>';
            // Both inner tables use height:100% so the two coloured boxes match the
            // taller one (the MTD box carries an extra trend line).
            $html .= '<td width="50%" style="padding-right:8px;vertical-align:top;height:100%;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #cce0ff;background:#f5faff;height:100%;">';
            $html .= '<tr><td style="padding:16px 14px;vertical-align:top;' . $font . '">';
            $html .= '<div style="font-size:11px;line-height:14px;color:#4a5d7a;text-transform:uppercase;font-weight:bold;' . $font . '">' . esc_html( $lbl_yesterday ) . '</div>';
            $html .= '<div style="font-size:48px;line-height:52px;font-weight:bold;color:#0f5fb7;margin-top:8px;' . $font . '">' . esc_html( (string) $today_total ) . '</div>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';
            $html .= '<td width="50%" style="padding-left:8px;vertical-align:top;height:100%;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #b8ebd0;background:#f6fff9;height:100%;">';
            $html .= '<tr><td style="padding:16px 14px;vertical-align:top;' . $font . '">';
            $html .= '<div style="font-size:11px;line-height:14px;color:#3a6650;text-transform:uppercase;font-weight:bold;' . $font . '">' . esc_html( $lbl_mtd ) . '</div>';
            $html .= '<div style="font-size:48px;line-height:52px;font-weight:bold;color:#1a8a50;margin-top:8px;' . $font . '">' . esc_html( (string) $mtd_total ) . '</div>';
            $html .= '<div style="font-size:12px;line-height:16px;color:' . esc_attr( $mtd_trend['color'] ) . ';font-weight:bold;margin-top:8px;' . $font . '">' . esc_html( $mtd_trend['text'] ) . '<br/><span style="color:#8a9bb0;font-weight:normal;font-size:11px;' . $font . '">' . esc_html( $lbl_vs_prev ) . '</span></div>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';

            // Spacing between sections.
            $html .= '<tr><td height="16" style="height:16px;line-height:16px;font-size:1px;">&nbsp;</td></tr>';

            // Section 2: Lead type boxes (Phone, WhatsApp, Email, Form).
            $types = array(
                'phone'       => $lbl_phone,
                'whatsapp'    => $lbl_whatsapp,
                'form_submit' => $lbl_form,
                'email'       => $lbl_email_type,
            );

            $type_keys = array_keys( $types );

            // First row (Phone & WhatsApp).
            $html .= '<tr><td style="padding:0 16px;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            $html .= '<tr>';

            // Phone box.
            $k     = $type_keys[0];
            $label = $types[ $k ];
            $day   = $n( $rd, $k );
            $mtd_v = $n( $mtd, $k );
            $pmt_v = $n( $pmt, $k );
            $trend = $trend_text( $mtd_v, $pmt_v );

            $html .= '<td width="50%" style="padding-right:8px;vertical-align:top;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4ebf4;background:#fafbfd;">';
            $html .= '<tr><td style="padding:14px 14px;">';
            $html .= '<div style="font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;margin-bottom:12px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $label ) . '</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
            $html .= '<tr>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-right:8px;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#0f5fb7;margin-top:6px;">' . esc_html( (string) $day ) . '</div>';
            $html .= '</td>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-left:8px;border-left:1px solid #e4ebf4;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#1a8a50;margin-top:6px;">' . esc_html( (string) $mtd_v ) . '</div>';
            $html .= '<div style="font-size:11px;line-height:14px;color:' . esc_attr( $trend['color'] ) . ';font-weight:bold;margin-top:4px;">' . esc_html( $trend['text'] ) . '</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';

            // WhatsApp box.
            $k     = $type_keys[1];
            $label = $types[ $k ];
            $day   = $n( $rd, $k );
            $mtd_v = $n( $mtd, $k );
            $pmt_v = $n( $pmt, $k );
            $trend = $trend_text( $mtd_v, $pmt_v );

            $html .= '<td width="50%" style="padding-left:8px;vertical-align:top;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4ebf4;background:#fafbfd;">';
            $html .= '<tr><td style="padding:14px 14px;">';
            $html .= '<div style="font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;margin-bottom:12px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $label ) . '</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
            $html .= '<tr>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-right:8px;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#0f5fb7;margin-top:6px;">' . esc_html( (string) $day ) . '</div>';
            $html .= '</td>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-left:8px;border-left:1px solid #e4ebf4;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#1a8a50;margin-top:6px;">' . esc_html( (string) $mtd_v ) . '</div>';
            $html .= '<div style="font-size:11px;line-height:14px;color:' . esc_attr( $trend['color'] ) . ';font-weight:bold;margin-top:4px;">' . esc_html( $trend['text'] ) . '</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';

            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';

            // Spacing between rows.
            $html .= '<tr><td height="14" style="height:14px;line-height:14px;font-size:1px;">&nbsp;</td></tr>';

            // Second row (Email & Form).
            $html .= '<tr><td style="padding:0 16px;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            $html .= '<tr>';

            // Email box.
            $k     = $type_keys[3];
            $label = $types[ $k ];
            $day   = $n( $rd, $k );
            $mtd_v = $n( $mtd, $k );
            $pmt_v = $n( $pmt, $k );
            $trend = $trend_text( $mtd_v, $pmt_v );

            $html .= '<td width="50%" style="padding-right:8px;vertical-align:top;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4ebf4;background:#fafbfd;">';
            $html .= '<tr><td style="padding:14px 14px;">';
            $html .= '<div style="font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;margin-bottom:12px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $label ) . '</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
            $html .= '<tr>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-right:8px;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#0f5fb7;margin-top:6px;">' . esc_html( (string) $day ) . '</div>';
            $html .= '</td>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-left:8px;border-left:1px solid #e4ebf4;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#1a8a50;margin-top:6px;">' . esc_html( (string) $mtd_v ) . '</div>';
            $html .= '<div style="font-size:11px;line-height:14px;color:' . esc_attr( $trend['color'] ) . ';font-weight:bold;margin-top:4px;">' . esc_html( $trend['text'] ) . '</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';

            // Form box.
            $k     = $type_keys[2];
            $label = $types[ $k ];
            $day   = $n( $rd, $k );
            $mtd_v = $n( $mtd, $k );
            $pmt_v = $n( $pmt, $k );
            $trend = $trend_text( $mtd_v, $pmt_v );

            $html .= '<td width="50%" style="padding-left:8px;vertical-align:top;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4ebf4;background:#fafbfd;">';
            $html .= '<tr><td style="padding:14px 14px;">';
            $html .= '<div style="font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;margin-bottom:12px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $label ) . '</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
            $html .= '<tr>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-right:8px;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#0f5fb7;margin-top:6px;">' . esc_html( (string) $day ) . '</div>';
            $html .= '</td>';
            $html .= '<td width="50%" style="text-align:' . esc_attr( $align_primary ) . ';padding-left:8px;border-left:1px solid #e4ebf4;">';
            $html .= '<div style="font-size:10px;line-height:14px;color:#8a9bb0;text-transform:uppercase;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</div>';
            $html .= '<div style="font-size:28px;line-height:32px;font-weight:bold;color:#1a8a50;margin-top:6px;">' . esc_html( (string) $mtd_v ) . '</div>';
            $html .= '<div style="font-size:11px;line-height:14px;color:' . esc_attr( $trend['color'] ) . ';font-weight:bold;margin-top:4px;">' . esc_html( $trend['text'] ) . '</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';
            $html .= '</table>';
            $html .= '</td>';

            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td></tr>';

            // Section 2c: WooCommerce sales (Orders & Revenue). Only shown when
            // WooCommerce is active so non-shop sites don't get an empty block.
            if ( ! empty( $report['sales_enabled'] ) ) {
                $sales_rd  = isset( $report['sales_report_day'] ) ? $report['sales_report_day'] : array();
                $sales_mtd = isset( $report['sales_mtd_current'] ) ? $report['sales_mtd_current'] : array();
                $sales_pmt = isset( $report['sales_mtd_prev_month'] ) ? $report['sales_mtd_prev_month'] : array();

                $sales_get = static function ( $arr, $key ) {
                    return isset( $arr[ $key ] ) ? $arr[ $key ] : 0;
                };

                // Spacing + section heading.
                $html .= '<tr><td height="16" style="height:16px;line-height:16px;font-size:1px;">&nbsp;</td></tr>';
                $html .= '<tr><td style="padding:0 16px 8px 16px;font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $lbl_sales_section ) . '</td></tr>';

                $html .= '<tr><td style="padding:0 16px;">';
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
                $html .= '<tr>';
                $html .= '<td width="50%" style="padding-right:8px;vertical-align:top;">';
                $html .= $stat_card( $lbl_orders, $sales_get( $sales_rd, 'orders' ), $sales_get( $sales_mtd, 'orders' ), $sales_get( $sales_pmt, 'orders' ), $fmt_count );
                $html .= '</td>';
                $html .= '<td width="50%" style="padding-left:8px;vertical-align:top;">';
                $html .= $stat_card( $lbl_revenue, $sales_get( $sales_rd, 'revenue' ), $sales_get( $sales_mtd, 'revenue' ), $sales_get( $sales_pmt, 'revenue' ), $fmt_money );
                $html .= '</td>';
                $html .= '</tr>';
                $html .= '</table>';
                $html .= '</td></tr>';

                // Sales by Source (month-to-date), sorted by revenue with each
                // source's share of total MTD revenue.
                $sales_sources = isset( $report['sales_sources_table'] ) && is_array( $report['sales_sources_table'] ) ? $report['sales_sources_table'] : array();

                $html .= '<tr><td height="14" style="height:14px;line-height:14px;font-size:1px;">&nbsp;</td></tr>';
                $html .= '<tr><td style="padding:0 16px;">';
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dde5f0;background:#ffffff;">';
                $html .= '<tr><td colspan="4" style="padding:14px 14px 12px 14px;font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $lbl_sales_by_src ) . '</td></tr>';
                $html .= '<tr style="background:#f0f4fb;border-bottom:1px solid #dde5f0;">';
                $html .= '<th style="padding:10px 12px;text-align:' . esc_attr( $align_primary ) . ';font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_source_col ) . '</th>';
                $html .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_orders ) . '</th>';
                $html .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_revenue ) . '</th>';
                $html .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_share ) . '</th>';
                $html .= '</tr>';

                if ( empty( $sales_sources ) ) {
                    $html .= '<tr><td colspan="4" style="padding:12px 14px;color:#8a9bb0;font-size:12px;line-height:16px;">' . esc_html__( 'No sales data.', 'brn-lead-count' ) . '</td></tr>';
                } else {
                    $s_idx = 0;
                    foreach ( $sales_sources as $sk => $row ) {
                        $bg         = ( 0 === $s_idx % 2 ) ? '#ffffff' : '#f9fbfd';
                        $share_txt  = number_format_i18n( isset( $row['share'] ) ? (float) $row['share'] : 0, 1 ) . '%';
                        $html      .= '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #edf2f8;">';
                        $html      .= '<td style="padding:10px 12px;font-size:12px;line-height:16px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $this->source_label( (string) $sk ) ) . '</td>';
                        $html      .= '<td dir="ltr" style="padding:10px 8px;font-size:12px;line-height:16px;text-align:center;' . $ltr_style . '">' . esc_html( $fmt_count( isset( $row['orders'] ) ? $row['orders'] : 0 ) ) . '</td>';
                        $html      .= '<td dir="ltr" style="padding:10px 8px;font-size:12px;line-height:16px;font-weight:bold;text-align:center;' . $ltr_style . '">' . esc_html( $fmt_money( isset( $row['revenue'] ) ? $row['revenue'] : 0 ) ) . '</td>';
                        $html      .= '<td dir="ltr" style="padding:10px 8px;font-size:12px;line-height:16px;text-align:center;color:#3a6650;' . $ltr_style . '">' . esc_html( $share_txt ) . '</td>';
                        $html      .= '</tr>';
                        ++$s_idx;
                    }
                }
                $html .= '</table>';
                $html .= '</td></tr>';
            }

            // Spacing before sources table.
            $html .= '<tr><td height="16" style="height:16px;line-height:16px;font-size:1px;">&nbsp;</td></tr>';

            // Section 3: Sources table.
            $sources_table = isset( $report['sources_table'] ) && is_array( $report['sources_table'] ) ? $report['sources_table'] : array();
            $html         .= '<tr><td style="padding:0 16px;">';
            $html         .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dde5f0;background:#ffffff;">';
            $html         .= '<tr><td colspan="4" style="padding:14px 14px 12px 14px;font-size:14px;line-height:18px;font-weight:bold;color:#1a3252;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $lbl_source_section ) . '</td></tr>';
            $html         .= '<tr style="background:#f0f4fb;border-bottom:1px solid #dde5f0;">';
            $html         .= '<th style="padding:10px 12px;text-align:' . esc_attr( $align_primary ) . ';font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_source_col ) . '</th>';
            $html         .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_yesterday ) . '</th>';
            $html         .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_mtd ) . '</th>';
            $html         .= '<th style="padding:10px 8px;text-align:center;font-size:12px;line-height:15px;color:#3e4f66;font-weight:bold;">' . esc_html( $lbl_vs_prev ) . '</th>';
            $html         .= '</tr>';

            if ( empty( $sources_table ) ) {
                $html .= '<tr><td colspan="4" style="padding:12px 14px;color:#8a9bb0;font-size:12px;line-height:16px;">' . esc_html__( 'No source data.', 'brn-lead-count' ) . '</td></tr>';
            } else {
                $row_idx = 0;
                foreach ( $sources_table as $sk => $row ) {
                    $bg         = ( 0 === $row_idx % 2 ) ? '#ffffff' : '#f9fbfd';
                    $t          = isset( $row['trend'] ) ? $row['trend'] : array( 'direction' => 'flat', 'delta' => 0, 'pct' => 0 );
                    $sign       = $t['delta'] > 0 ? '+' : '';
                    $trend_col  = 'down' === $t['direction'] ? '#c0392b' : ( 'up' === $t['direction'] ? '#1a8a50' : '#8a9bb0' );
                    $trend_val  = 'flat' === $t['direction'] ? $lbl_no_change : $sign . $t['delta'] . ' (' . $sign . $t['pct'] . '%)';
                    $html      .= '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #edf2f8;">';
                    $html      .= '<td style="padding:10px 12px;font-size:12px;line-height:16px;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $this->source_label( (string) $sk ) ) . '</td>';
                    $html      .= '<td style="padding:10px 8px;font-size:12px;line-height:16px;text-align:center;">' . esc_html( (string) $row['day'] ) . '</td>';
                    $html      .= '<td style="padding:10px 8px;font-size:12px;line-height:16px;font-weight:bold;text-align:center;">' . esc_html( (string) $row['mtd'] ) . '</td>';
                    $html      .= '<td style="padding:10px 8px;font-size:12px;line-height:16px;font-weight:bold;color:' . esc_attr( $trend_col ) . ';text-align:center;">' . esc_html( $trend_val ) . '</td>';
                    $html      .= '</tr>';
                    ++$row_idx;
                }
            }
            $html .= '</table>';
            $html .= '</td></tr>';

            // Spacing before recommendations.
            $html .= '<tr><td height="16" style="height:16px;line-height:16px;font-size:1px;">&nbsp;</td></tr>';

            // Section 4: Recommendations (optional).
            if ( ! empty( $settings['enable_recommendations'] ) ) {
                $recs = $this->build_recommendations( $report, $is_hebrew );
                if ( ! empty( $recs ) ) {
                    $html .= '<tr><td style="padding:0 16px;">';
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f0d97a;background:#fffbea;">';
                    $html .= '<tr><td style="padding:14px 14px;font-size:14px;line-height:18px;font-weight:bold;color:#7a5c00;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $lbl_reco_section ) . '</td></tr>';
                    foreach ( $recs as $rec ) {
                        $html .= '<tr><td style="padding:6px 14px 8px 14px;font-size:12px;line-height:16px;color:#4a3c00;text-align:' . esc_attr( $align_primary ) . ';">• ' . esc_html( $rec ) . '</td></tr>';
                    }
                    $html .= '</table>';
                    $html .= '</td></tr>';

                    // Spacing after recommendations.
                    $html .= '<tr><td height="16" style="height:16px;line-height:16px;font-size:1px;">&nbsp;</td></tr>';
                }
            }

            // Footer.
            $html .= '<tr><td style="padding:0 16px 16px 16px;">';
            $html .= '<div style="padding:12px 12px;border-top:1px solid #edf0f5;font-size:12px;line-height:16px;color:#8a9bb0;text-align:' . esc_attr( $align_primary ) . ';">' . esc_html( $lbl_footer ) . '</div>';
            $html .= '</td></tr>';

            // Close main wrapper.
            $html .= '</table>';
            $html .= '</td></tr></table>';

            return $html;
        }

        /**
         * Generate rule-based recommendations from the report data.
         *
         * @param array $report
         * @return string[]
         */
        private function build_recommendations( $report, $is_hebrew = false ) {
            $recs = array();

            $mtd     = isset( $report['mtd_current'] ) ? $report['mtd_current'] : array();
            $pmt     = isset( $report['mtd_prev_month'] ) ? $report['mtd_prev_month'] : array();
            $sources = isset( $report['sources_table'] ) && is_array( $report['sources_table'] ) ? $report['sources_table'] : array();

            $n = static function ( $arr, $key ) {
                return isset( $arr[ $key ] ) ? (int) $arr[ $key ] : 0;
            };

            $mtd_total = $n( $mtd, 'total' );
            $pmt_total = $n( $pmt, 'total' );

            // 1. MTD total down >= 20% vs prev month.
            if ( $pmt_total > 0 ) {
                $drop_pct = ( ( $pmt_total - $mtd_total ) / $pmt_total ) * 100;
                if ( $drop_pct >= 20 ) {
                    $recs[] = $is_hebrew
                        ? sprintf( 'סה"כ הלידים ירד ב-%.0f%% לעומת החודש הקודם — מומלץ לבדוק את תקציב הקמפיין והמסרים.', $drop_pct )
                        : sprintf( 'Total leads are down %.0f%% vs last month — consider reviewing your campaign budget or messaging.', $drop_pct );
                }
            }

            // 2. No leads this month at all.
            if ( 0 === $mtd_total ) {
                $recs[] = $is_hebrew
                    ? 'לא נרשמו לידים החודש — ודא שסקריפט המעקב פעיל בכל דפי הנחיתה.'
                    : 'No leads recorded this month — verify the tracking script is active on all landing pages.';
                return $recs; // Most other rules won't add value; return early.
            }

            // 3. WhatsApp is top type and growing.
            $type_counts = array(
                'phone'       => $n( $mtd, 'phone' ),
                'whatsapp'    => $n( $mtd, 'whatsapp' ),
                'form_submit' => $n( $mtd, 'form_submit' ),
                'email'       => $n( $mtd, 'email' ),
            );
            arsort( $type_counts );
            reset( $type_counts );
            $top_type     = key( $type_counts );
            $top_type_val = current( $type_counts );
            if ( 'whatsapp' === $top_type && $top_type_val > 0 ) {
                $prev_wa = $n( $pmt, 'whatsapp' );
                if ( $top_type_val > $prev_wa ) {
                    $recs[] = $is_hebrew
                        ? 'וואטסאפ הוא הערוץ הצומח ביותר שלך — הוסף כפתורי WhatsApp בולטים לדפים המרכזיים כדי לנצל את המגמה.'
                        : 'WhatsApp is your fastest-growing channel — add more WhatsApp CTA buttons to key pages to capitalise on the trend.';
                }
            }

            // 4. Direct traffic dominates (>= 70% of MTD leads by source).
            if ( ! empty( $sources ) && $mtd_total > 0 ) {
                $direct_mtd = isset( $sources['direct']['mtd'] ) ? (int) $sources['direct']['mtd'] : 0;
                if ( ( $direct_mtd / $mtd_total ) >= 0.70 ) {
                    $recs[] = $is_hebrew
                        ? 'מעל 70% מהלידים מגיעים ממקור לא מזוהה (ישיר) — הוסף פרמטרי UTM לקמפיינים שלך כדי לדעת אילו ערוצים עובדים.'
                        : 'Over 70% of your leads have no tracked source (Direct) — add UTM parameters to your campaigns to identify which channels are working.';
                }
            }

            // 5. No form submissions but other leads exist.
            if ( 0 === $n( $mtd, 'form_submit' ) && ( $n( $mtd, 'phone' ) > 0 || $n( $mtd, 'whatsapp' ) > 0 ) ) {
                $recs[] = $is_hebrew
                    ? 'אין הגשות טופס החודש — שקול להוסיף טופס יצירת קשר בולט, או בדוק שאירועי טפסי Elementor נרשמים כראוי.'
                    : 'No form submissions this month — consider adding a prominent contact form, or check that Elementor form events are being tracked.';
            }

            // 6. No email link clicks.
            if ( 0 === $n( $mtd, 'email' ) ) {
                $recs[] = $is_hebrew
                    ? 'לא נרשמו לחיצות על קישורי אימייל החודש — ודא שקישורי mailto: משתמשים בתבנית href="mailto:…" תקנית ונראים בדפים מרכזיים.'
                    : 'No email link clicks tracked this month — ensure mailto: links use a standard href="mailto:…" format and are visible on key pages.';
            }

            // 7. Only one source.
            if ( 1 === count( $sources ) ) {
                $recs[] = $is_hebrew
                    ? 'כל הלידים שלך מגיעים ממקור תנועה אחד — פיזור הערוצים יפחית סיכון ויפתח הזדמנויות צמיחה חדשות.'
                    : 'All your leads are coming from a single traffic source — diversifying channels will reduce risk and open new growth opportunities.';
            }

            return $recs;
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

            // Show "BRN Lead Count" as the sender name instead of the generic
            // WordPress default. Scoped to this send only (keeps the site's
            // default from-address, so deliverability is unaffected).
            $from_name = static function () {
                return 'BRN Lead Count';
            };
            add_filter( 'wp_mail_from_name', $from_name );

            $sent = wp_mail( $emails, $subject, $message, $headers );

            remove_filter( 'wp_mail_from_name', $from_name );

            if ( $sent ) {
                update_option( self::OPTION_LAST_REPORT_SENT, time(), false );
            }

            return (bool) $sent;
        }

        /**
         * Register REST API tracking endpoint.
         * /wp-json/brn/v1/track  — accepts unauthenticated POST.
         */
        public function register_rest_routes() {
            register_rest_route(
                'brn/v1',
                '/track',
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_track_event' ),
                    'permission_callback' => '__return_true',
                )
            );
        }

        /**
         * REST API handler — validates static token then records the lead.
         *
         * @param \WP_REST_Request $req
         * @return \WP_REST_Response
         */
        public function rest_track_event( $req ) {
            $supplied_token = sanitize_text_field( (string) $req->get_param( 'nonce' ) );
            $stored_token   = $this->get_tracking_token();

            if ( '' === $supplied_token || ! hash_equals( $stored_token, $supplied_token ) ) {
                return new \WP_REST_Response( array( 'success' => false, 'message' => 'Invalid token.' ), 403 );
            }

            $type        = sanitize_key( (string) $req->get_param( 'lead_type' ) );
            $label       = sanitize_text_field( (string) $req->get_param( 'label' ) );
            $url         = esc_url_raw( (string) $req->get_param( 'url' ) );
            $page_title  = sanitize_text_field( (string) $req->get_param( 'page_title' ) );
            $source      = sanitize_text_field( (string) $req->get_param( 'source' ) );
            $manual_test = (bool) $req->get_param( 'is_test' );

            $counts = $this->process_track( $type, $label, $url, $page_title, $source, $manual_test );

            if ( null === $counts ) {
                return new \WP_REST_Response( array( 'success' => false, 'message' => 'Invalid lead type.' ), 400 );
            }

            return new \WP_REST_Response( array( 'success' => true, 'counts' => $counts ), 200 );
        }

        public function enqueue_scripts() {
            if ( is_admin() ) {
                return;
            }

            wp_enqueue_script(
                'brn-lead-count-tracker',
                plugin_dir_url( __FILE__ ) . 'assets/js/brn-lead-count-tracker.js',
                array(),
                '1.7.7',
                true
            );

            wp_localize_script(
                'brn-lead-count-tracker',
                'brnLeadCountData',
                array(
                    'restUrl' => rest_url( 'brn/v1/track' ),
                    'nonce'   => $this->get_tracking_token(),
                )
            );

            // Prevent caching/optimization plugins (NitroPack, Cloudflare, WP Rocket, etc.)
            // from deferring or delaying this script — it must run immediately on page load.
            add_filter( 'script_loader_tag', array( $this, 'add_tracker_no_defer_attrs' ), 10, 2 );
        }

        public function add_tracker_no_defer_attrs( $tag, $handle ) {
            if ( 'brn-lead-count-tracker' !== $handle ) {
                return $tag;
            }
            // data-nitro-exclude  → NitroPack
            // data-cfasync="false" → Cloudflare Rocket Loader
            // data-no-defer       → WP Rocket / generic
            $tag = str_replace( ' src=', ' data-nitro-exclude="1" data-cfasync="false" data-no-defer="1" src=', $tag );
            return $tag;
        }

        public function track_event() {
            $request        = wp_unslash( $_POST );
            $supplied_token = isset( $request['nonce'] ) ? sanitize_text_field( $request['nonce'] ) : '';
            $stored_token   = $this->get_tracking_token();

            if ( '' === $supplied_token || ! hash_equals( $stored_token, $supplied_token ) ) {
                wp_send_json_error( array( 'message' => 'Invalid token.' ), 403 );
                return;
            }

            $type        = isset( $request['lead_type'] ) ? sanitize_key( $request['lead_type'] ) : '';
            $label       = isset( $request['label'] ) ? sanitize_text_field( $request['label'] ) : '';
            $url         = isset( $request['url'] ) ? esc_url_raw( $request['url'] ) : '';
            $page_title  = isset( $request['page_title'] ) ? sanitize_text_field( $request['page_title'] ) : '';
            $source      = isset( $request['source'] ) ? sanitize_text_field( $request['source'] ) : '';
            $manual_test = ! empty( $request['is_test'] );

            $counts = $this->process_track( $type, $label, $url, $page_title, $source, $manual_test );

            if ( null === $counts ) {
                wp_send_json_error( array( 'message' => 'Invalid lead type.' ), 400 );
                return;
            }

            wp_send_json_success( array( 'counts' => $counts ) );
        }

        /**
         * Core tracking logic shared by admin-ajax and REST handlers.
         * Returns counts array on success, null if lead type is invalid.
         *
         * @param string $type
         * @param string $label
         * @param string $url
         * @param string $page_title
         * @param string $source
         * @param bool   $manual_test
         * @return array|null
         */
        private function process_track( $type, $label, $url, $page_title, $source, $manual_test ) {
            $allowed_types = array( 'phone', 'whatsapp', 'email', 'form_submit' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                return null;
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
            $source   = $this->normalize_source( $source );
            if ( '' === $source ) {
                $source = $this->normalize_source( $this->derive_source_from_url( $url ) );
            }

            // If the current page can't identify a real source — e.g. the visitor
            // landed via a Google Ad (gclid) or organic Google referrer, then
            // navigated to another page before converting — fall back to the
            // persisted first-touch source cookie (the same one used to attribute
            // WooCommerce orders). Without this, those leads are mislabelled
            // "direct" because the gclid/referrer only existed on the landing page.
            if ( '' === $source || 'direct' === $source ) {
                $cookie_source = $this->get_source_from_cookie();
                if ( '' !== $cookie_source && 'direct' !== $cookie_source && 'other' !== $cookie_source ) {
                    $source = $cookie_source;
                }
            }

            if ( ! $is_test ) {
                $stats['counts'][ $type ] += 1;
                $stats['counts']['total'] += 1;
            }

            if ( ! empty( $settings['enable_logging'] ) ) {
                $log_entry = array(
                    'id'           => wp_generate_uuid4(),
                    'time'         => wp_date( 'Y-m-d H:i:s' ),
                    'type'         => $type,
                    'label'        => $label,
                    'page_url'     => $url,
                    'page_title'   => $page_title,
                    'source'       => $source,
                    'ip_hash'      => $this->get_request_ip_hash( $ip ),
                    'ip'           => $ip,
                    'is_test'      => $is_test ? 1 : 0,
                    'browser'      => $ua_data['browser'],
                    'device'       => $ua_data['device'],
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

            return $stats['counts'];
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
                __( 'Sales', 'brn-lead-count' ),
                __( 'Sales', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-sales',
                array( $this, 'render_sales_page' )
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

            add_submenu_page(
                'brn-lead-count-analytics',
                __( 'Diagnostics', 'brn-lead-count' ),
                __( 'Diagnostics', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count-diagnostics',
                array( $this, 'render_diagnostics_page' )
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

        public function render_diagnostics_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $rest_url     = rest_url( 'brn/v1/track' );
            $token        = $this->get_tracking_token();
            $plugin_ver   = '1.7.7';
            $rest_enabled = (bool) get_option( 'permalink_structure', '' );
            ?>
            <div class="wrap">
                <h1>BRN Lead Count — Diagnostics</h1>
                <table class="widefat" style="max-width:700px;margin-top:16px;">
                    <tr>
                        <th style="width:200px;">Plugin version</th>
                        <td><code><?php echo esc_html( $plugin_ver ); ?></code></td>
                    </tr>
                    <tr>
                        <th>REST endpoint</th>
                        <td><code id="brn-rest-url"><?php echo esc_html( $rest_url ); ?></code></td>
                    </tr>
                    <tr>
                        <th>Tracking token</th>
                        <td><code><?php echo esc_html( substr( $token, 0, 8 ) . '…' ); ?></code> (first 8 chars shown)</td>
                    </tr>
                    <tr>
                        <th>Permalinks flushed</th>
                        <td><?php echo $rest_enabled ? '<span style="color:green">✓ Yes (pretty permalinks active)</span>' : '<span style="color:red">✗ No — go to Settings → Permalinks and click Save</span>'; ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;">Live connection test</h2>
                <p>Click the button below. It fires the exact same request as a real lead from the frontend, using the current token. The raw HTTP status and response body are shown immediately.</p>
                <button id="brn-diag-btn" class="button button-primary" style="font-size:15px;padding:6px 18px;">Run Test Now</button>
                <pre id="brn-diag-result" style="margin-top:12px;background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;font-size:13px;min-height:60px;white-space:pre-wrap;display:none;"></pre>

                <script>
                document.getElementById('brn-diag-btn').addEventListener('click', function () {
                    var btn    = this;
                    var result = document.getElementById('brn-diag-result');
                    var url    = <?php echo wp_json_encode( $rest_url ); ?>;
                    var token  = <?php echo wp_json_encode( $token ); ?>;

                    btn.disabled = true;
                    btn.textContent = 'Testing…';
                    result.style.display = 'block';
                    result.textContent  = 'Sending request to:\n' + url + '\n\nWaiting…';

                    var body = 'nonce=' + encodeURIComponent(token)
                             + '&lead_type=phone'
                             + '&label=diagnostics+test'
                             + '&url=' + encodeURIComponent(window.location.href)
                             + '&page_title=Diagnostics'
                             + '&is_test=1';

                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body
                    })
                    .then(function (resp) {
                        var status = resp.status;
                        return resp.text().then(function (text) {
                            result.textContent =
                                'HTTP ' + status + '\n\n' +
                                'URL: ' + url + '\n\n' +
                                'Response body:\n' + text;

                            result.style.color = (status === 200) ? '#4ec94e' : '#f47b7b';
                            btn.disabled = false;
                            btn.textContent = 'Run Test Now';
                        });
                    })
                    .catch(function (err) {
                        result.textContent = 'NETWORK ERROR: ' + err.message + '\n\nURL: ' + url;
                        result.style.color = '#f47b7b';
                        btn.disabled = false;
                        btn.textContent = 'Run Test Now';
                    });
                });
                </script>
            </div>
            <?php
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

        /**
         * Aggregates lead logs into per-date source totals.
         * Returns: [ 'Y-m-d' => [ 'google'=>N, 'direct'=>N, ... ], ... ]
         *
         * @param array $logs
         * @return array
         */
        private function build_source_analytics_data( array $logs ) {
            $by_date = array();
            foreach ( $logs as $log ) {
                if ( ! is_array( $log ) || empty( $log['time'] ) ) {
                    continue;
                }

                if ( ! empty( $log['is_test'] ) ) {
                    continue;
                }

                $date   = substr( (string) $log['time'], 0, 10 );
                $source = $this->normalize_source( isset( $log['source'] ) ? (string) $log['source'] : '' );

                if ( ! isset( $by_date[ $date ] ) ) {
                    $by_date[ $date ] = array();
                }

                if ( ! isset( $by_date[ $date ][ $source ] ) ) {
                    $by_date[ $date ][ $source ] = 0;
                }

                $by_date[ $date ][ $source ]++;
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
            $output['report_language']        = ( isset( $input['report_language'] ) && 'he' === (string) $input['report_language'] ) ? 'he' : 'en';
            $output['enable_recommendations'] = empty( $input['enable_recommendations'] ) ? 0 : 1;

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
                'sales' => array(
                    'label' => __( 'Sales', 'brn-lead-count' ),
                    'url'   => admin_url( 'admin.php?page=brn-lead-count-sales' ),
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
                                            isset( $log['source'] ) ? (string) $log['source'] : '',
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
                $source_analytics_data = $this->build_source_analytics_data( $logs );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
                $analytics_json = wp_json_encode( $analytics_data );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
                $source_analytics_json = wp_json_encode( $source_analytics_data );
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

                    <h3 style="margin:0 0 8px;"><?php esc_html_e( 'Leads by Source', 'brn-lead-count' ); ?></h3>
                    <div id="brn-source-cards" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 20px;"></div>

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
                        var rawSourceData = <?php echo $source_analytics_json; ?>;
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
                            renderSourceCards( dates );

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

                        function renderSourceCards( dates ) {
                            var wrapper = document.getElementById( 'brn-source-cards' );
                            if ( ! wrapper ) {
                                return;
                            }

                            var sourceTotals = {};
                            dates.forEach( function ( d ) {
                                var daySources = rawSourceData[ d ] || {};
                                Object.keys( daySources ).forEach( function ( sourceKey ) {
                                    if ( ! sourceTotals[ sourceKey ] ) {
                                        sourceTotals[ sourceKey ] = 0;
                                    }
                                    sourceTotals[ sourceKey ] += daySources[ sourceKey ] || 0;
                                } );
                            } );

                            var sorted = Object.keys( sourceTotals ).sort( function ( a, b ) {
                                return sourceTotals[ b ] - sourceTotals[ a ];
                            } );

                            if ( sorted.length === 0 ) {
                                wrapper.innerHTML = '<div style="color:#646970;">' + <?php echo wp_json_encode( __( 'No source data in this range.', 'brn-lead-count' ) ); ?> + '</div>';
                                return;
                            }

                            var html = '';
                            sorted.forEach( function ( key ) {
                                html += '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px 14px;border-radius:6px;min-width:120px;text-align:center;">';
                                html += '<div style="font-size:22px;font-weight:700;line-height:1.2;color:#1d2327;">' + sourceTotals[ key ] + '</div>';
                                html += '<div style="font-size:12px;margin-top:4px;color:#50575e;">' + key + '</div>';
                                html += '</div>';
                            } );
                            wrapper.innerHTML = html;
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
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Inference Recommendations', 'brn-lead-count' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[enable_recommendations]" value="1" <?php checked( 1, isset( $settings['enable_recommendations'] ) ? (int) $settings['enable_recommendations'] : 0 ); ?> />
                                        <?php esc_html_e( 'Include rule-based recommendations in the daily email report.', 'brn-lead-count' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Suggests actions to increase leads based on current trends and lead mix. Default: off.', 'brn-lead-count' ); ?></p>
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
                                    <th><a href="<?php echo esc_url( $build_sort( 'source' ) ); ?>"><?php esc_html_e( 'Source', 'brn-lead-count' ); ?></a></th>
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
                                        <td><?php echo esc_html( $this->source_label( isset( $log['source'] ) ? (string) $log['source'] : '' ) ); ?></td>
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

                <h3 style="margin-top:18px;"><?php esc_html_e( 'Source Breakdown (Last Day)', 'brn-lead-count' ); ?></h3>
                <table class="widefat striped" style="max-width:460px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Source', 'brn-lead-count' ); ?></th>
                            <th><?php esc_html_e( 'Leads', 'brn-lead-count' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $report_sources = isset( $report_payload['report_day_sources'] ) && is_array( $report_payload['report_day_sources'] ) ? $report_payload['report_day_sources'] : array(); ?>
                        <?php if ( empty( $report_sources ) ) : ?>
                            <tr>
                                <td colspan="2"><?php esc_html_e( 'No source data.', 'brn-lead-count' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $report_sources as $source_key => $source_total ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $this->source_label( (string) $source_key ) ); ?></td>
                                    <td><strong><?php echo esc_html( (string) (int) $source_total ); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

        /* ----------------------------------------------------------------- *
         * WooCommerce sales attribution
         * ----------------------------------------------------------------- */

        /**
         * Read the visitor's tracked lead source from the cookie the tracker sets.
         *
         * @return string Normalized source, or '' when no cookie is present.
         */
        private function get_source_from_cookie() {
            if ( empty( $_COOKIE['brn_lead_source'] ) ) {
                return '';
            }
            $raw = urldecode( wp_unslash( $_COOKIE['brn_lead_source'] ) );
            return $this->normalize_source( $raw );
        }

        /**
         * Persist the lead source onto the order at checkout, while the cookie is
         * still available on the request.
         *
         * @param int $order_id
         * @return void
         */
        public function capture_order_source( $order_id ) {
            if ( empty( $order_id ) || ! function_exists( 'wc_get_order' ) ) {
                return;
            }
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            if ( '' !== (string) $order->get_meta( '_brn_source' ) ) {
                return;
            }
            $source = $this->get_source_from_cookie();
            if ( '' === $source ) {
                $source = 'direct';
            }
            $order->update_meta_data( '_brn_source', $source );
            $order->save();
        }

        /**
         * Determine a normalized source label for a WooCommerce order. Prefers our
         * own _brn_source (richer PPC detection from the tracker cookie), then falls
         * back to WooCommerce Order Attribution data, then 'unknown'. This lets the
         * Sales dashboard attribute historical orders placed before this plugin
         * started capturing _brn_source.
         *
         * @param WC_Order $order
         * @return string
         */
        private function get_order_source( $order ) {
            $brn = (string) $order->get_meta( '_brn_source' );
            if ( '' !== $brn ) {
                return $this->normalize_source( $brn );
            }

            // WooCommerce Order Attribution (WC 8.5+).
            $utm_source = (string) $order->get_meta( '_wc_order_attribution_utm_source' );
            $utm_medium = (string) $order->get_meta( '_wc_order_attribution_utm_medium' );
            $type       = (string) $order->get_meta( '_wc_order_attribution_source_type' );
            $referrer   = (string) $order->get_meta( '_wc_order_attribution_referrer' );

            $params = array();
            if ( '' !== $utm_source ) {
                $params['utm_source'] = $utm_source;
            }
            if ( '' !== $utm_medium ) {
                $params['utm_medium'] = $utm_medium;
            }

            $paid = $this->classify_paid_source( $params );
            if ( '' !== $paid ) {
                return $this->normalize_source( $paid );
            }

            if ( '' !== $utm_source ) {
                return $this->normalize_source( $utm_source );
            }

            if ( 'typein' === $type || 'direct' === $type ) {
                return 'direct';
            }
            if ( 'organic' === $type ) {
                return 'organic';
            }
            if ( 'referral' === $type && '' !== $referrer ) {
                $host = wp_parse_url( $referrer, PHP_URL_HOST );
                if ( $host ) {
                    return $this->normalize_source( preg_replace( '/^www\./', '', strtolower( (string) $host ) ) );
                }
            }

            return 'unknown';
        }

        /**
         * HPOS-safe admin edit URL for an order.
         *
         * @param int $order_id
         * @return string
         */
        private function wc_order_edit_url( $order_id ) {
            if (
                class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
            ) {
                return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $order_id );
            }
            return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
        }

        /**
         * Sales dashboard: totals, sales-by-source, and recent sales.
         *
         * @return void
         */
        public function render_sales_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $wc_active = function_exists( 'wc_get_orders' );

            $allowed_ranges = array( 7, 30, 90, 365, 0 );
            $range          = isset( $_GET['range'] ) ? absint( wp_unslash( $_GET['range'] ) ) : 30;
            if ( ! in_array( $range, $allowed_ranges, true ) ) {
                $range = 30;
            }

            // Read orders live from WooCommerce so the dashboard reflects real
            // sales, including orders placed before this plugin was installed.
            $query_limit = 5000;
            $orders      = array();
            $capped      = false;

            if ( $wc_active ) {
                $args = array(
                    'status'  => array( 'wc-processing', 'wc-completed' ),
                    'limit'   => $query_limit,
                    'orderby' => 'date',
                    'order'   => 'DESC',
                    'return'  => 'objects',
                );
                if ( $range > 0 ) {
                    $args['date_created'] = '>=' . ( time() - ( $range * DAY_IN_SECONDS ) );
                }
                $orders = wc_get_orders( $args );
                if ( ! is_array( $orders ) ) {
                    $orders = array();
                }
                if ( count( $orders ) >= $query_limit ) {
                    $capped = true;
                }
            }

            $total_orders  = 0;
            $total_revenue = 0.0;
            $by_source     = array();
            $filtered      = array();

            foreach ( $orders as $order ) {
                if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
                    continue;
                }
                $src   = $this->get_order_source( $order );
                $total = (float) $order->get_total();

                $total_orders++;
                $total_revenue += $total;

                if ( ! isset( $by_source[ $src ] ) ) {
                    $by_source[ $src ] = array( 'orders' => 0, 'revenue' => 0.0 );
                }
                $by_source[ $src ]['orders']  += 1;
                $by_source[ $src ]['revenue'] += $total;

                $date_obj   = $order->get_date_created();
                $filtered[] = array(
                    'order_id' => $order->get_id(),
                    'time'     => $date_obj ? wc_format_datetime( $date_obj, 'Y-m-d H:i' ) : '',
                    'source'   => $src,
                    'total'    => $total,
                    'currency' => $order->get_currency(),
                    'email'    => $order->get_billing_email(),
                    'status'   => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status(),
                );
            }
            uasort(
                $by_source,
                static function ( $a, $b ) {
                    if ( $a['revenue'] === $b['revenue'] ) {
                        return 0;
                    }
                    return ( $a['revenue'] < $b['revenue'] ) ? 1 : -1;
                }
            );

            $money = static function ( $amount, $currency = '' ) {
                if ( function_exists( 'wc_price' ) ) {
                    $args = ( '' !== $currency ) ? array( 'currency' => $currency ) : array();
                    return wp_kses_post( wc_price( (float) $amount, $args ) );
                }
                return esc_html( number_format_i18n( (float) $amount, 2 ) );
            };

            $base_url = admin_url( 'admin.php?page=brn-lead-count-sales' );
            $ranges   = array(
                7   => __( 'Last 7 days', 'brn-lead-count' ),
                30  => __( 'Last 30 days', 'brn-lead-count' ),
                90  => __( 'Last 90 days', 'brn-lead-count' ),
                365 => __( 'Last year', 'brn-lead-count' ),
                0   => __( 'All time', 'brn-lead-count' ),
            );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'BRN Lead Count — Sales', 'brn-lead-count' ); ?></h1>

                <?php if ( ! $wc_active ) : ?>
                    <div class="notice notice-warning"><p><?php esc_html_e( 'WooCommerce is not active. Sales are read directly from WooCommerce and will appear here once it is enabled.', 'brn-lead-count' ); ?></p></div>
                <?php endif; ?>

                <p style="color:#646970;margin-top:4px;"><?php esc_html_e( 'Sales are read live from WooCommerce (processing and completed orders). Source is taken from this plugin\'s tracking when available, otherwise from WooCommerce order attribution.', 'brn-lead-count' ); ?></p>

                <p>
                    <?php
                    foreach ( $ranges as $value => $label ) {
                        $url   = esc_url( add_query_arg( 'range', $value, $base_url ) );
                        $style = ( $value === $range ) ? ' style="font-weight:600;text-decoration:underline;"' : '';
                        printf( '<a href="%s"%s>%s</a> &nbsp; ', $url, $style, esc_html( $label ) );
                    }
                    ?>
                </p>

                <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0 24px;">
                    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 22px;min-width:150px;">
                        <div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.4px;"><?php esc_html_e( 'Orders', 'brn-lead-count' ); ?></div>
                        <div style="font-size:26px;font-weight:700;margin-top:4px;"><?php echo esc_html( number_format_i18n( $total_orders ) ); ?></div>
                    </div>
                    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 22px;min-width:150px;">
                        <div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.4px;"><?php esc_html_e( 'Revenue', 'brn-lead-count' ); ?></div>
                        <div style="font-size:26px;font-weight:700;margin-top:4px;"><?php echo $money( $total_revenue ); ?></div>
                    </div>
                </div>

                <?php if ( $capped ) : ?>
                    <div class="notice notice-info inline"><p><?php printf( esc_html__( 'Showing the most recent %d orders for this period.', 'brn-lead-count' ), (int) $query_limit ); ?></p></div>
                <?php endif; ?>

                <h2><?php esc_html_e( 'Sales by source', 'brn-lead-count' ); ?></h2>
                <table class="widefat striped" style="max-width:680px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'Source', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Orders', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Revenue', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Share of revenue', 'brn-lead-count' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $by_source ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No sales in this period.', 'brn-lead-count' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $by_source as $src => $agg ) : ?>
                            <tr>
                                <td><?php echo esc_html( $this->source_label( (string) $src ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $agg['orders'] ) ); ?></td>
                                <td><?php echo $money( $agg['revenue'] ); ?></td>
                                <td><?php echo $total_revenue > 0 ? esc_html( round( $agg['revenue'] / $total_revenue * 100, 1 ) . '%' ) : '0%'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <h2 style="margin-top:28px;"><?php esc_html_e( 'Recent sales', 'brn-lead-count' ); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Date', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Order', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'brn-lead-count' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'brn-lead-count' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $filtered ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No sales in this period.', 'brn-lead-count' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( array_slice( $filtered, 0, 100 ) as $row ) : ?>
                            <?php $oid = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0; ?>
                            <tr>
                                <td><?php echo esc_html( isset( $row['time'] ) ? (string) $row['time'] : '' ); ?></td>
                                <td>
                                    <?php if ( $oid ) : ?>
                                        <a href="<?php echo esc_url( $this->wc_order_edit_url( $oid ) ); ?>">#<?php echo esc_html( (string) $oid ); ?></a>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $this->source_label( isset( $row['source'] ) ? (string) $row['source'] : '' ) ); ?></td>
                                <td><?php echo esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ); ?></td>
                                <td><?php echo $money( isset( $row['total'] ) ? (float) $row['total'] : 0, isset( $row['currency'] ) ? (string) $row['currency'] : '' ); ?></td>
                                <td><?php echo esc_html( isset( $row['email'] ) ? (string) $row['email'] : '' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }
}

new BRN_Lead_Count();
