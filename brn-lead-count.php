<?php
/**
 * Plugin Name: BRN Lead Count
 * Description: Counts and logs lead actions (phone clicks, WhatsApp clicks, and form submissions).
 * Version: 1.1.0
 * Author: BRN
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Lifecycle hooks (must be outside the class, at file scope) ────────────── //

register_activation_hook(
    __FILE__,
    function () {
        if ( ! wp_next_scheduled( 'brn_lead_count_daily_update_check' ) ) {
            wp_schedule_event( time(), 'daily', 'brn_lead_count_daily_update_check' );
        }
    }
);

register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'brn_lead_count_daily_update_check' );
    }
);

if ( ! class_exists( 'BRN_Lead_Count' ) ) {
    class BRN_Lead_Count {
        const OPTION_STATS    = 'brn_lead_count_stats';
        const OPTION_SETTINGS = 'brn_lead_count_settings';
        const NONCE_ACTION    = 'brn_lead_count_track';
        const MAX_LOGS_DEFAULT = 300;

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

            // Cron callback — refresh update cache once per day.
            add_action( 'brn_lead_count_daily_update_check', array( $this, 'run_update_check' ) );

            // Manual update-check AJAX (admin only).
            add_action( 'wp_ajax_brn_lead_count_check_updates', array( $this, 'ajax_check_updates' ) );

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
                    'form_submit'=> 0,
                    'total'      => 0,
                ),
                'logs'   => array(),
            );
        }

        private function get_settings() {
            $defaults = array(
                'enable_logging' => 1,
                'max_logs'       => self::MAX_LOGS_DEFAULT,
            );

            $settings = get_option( self::OPTION_SETTINGS, array() );
            $settings = wp_parse_args( $settings, $defaults );

            $settings['enable_logging'] = empty( $settings['enable_logging'] ) ? 0 : 1;
            $settings['max_logs'] = max( 10, min( 2000, absint( $settings['max_logs'] ) ) );

            return $settings;
        }

        public function enqueue_scripts() {
            if ( is_admin() ) {
                return;
            }

            wp_enqueue_script(
                'brn-lead-count-tracker',
                plugin_dir_url( __FILE__ ) . 'assets/js/brn-lead-count-tracker.js',
                array(),
                '1.1.0',
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

            $type = isset( $_POST['lead_type'] ) ? sanitize_key( wp_unslash( $_POST['lead_type'] ) ) : '';
            $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
            $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

            $allowed_types = array( 'phone', 'whatsapp', 'form_submit' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid lead type.' ), 400 );
            }

            $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );

            if ( ! isset( $stats['counts'][ $type ] ) ) {
                $stats['counts'][ $type ] = 0;
            }

            $stats['counts'][ $type ] += 1;
            $stats['counts']['total'] += 1;

            $settings = $this->get_settings();
            if ( ! empty( $settings['enable_logging'] ) ) {
                $log_entry = array(
                    'time'      => current_time( 'mysql' ),
                    'type'      => $type,
                    'label'     => $label,
                    'page_url'  => $url,
                    'ip_hash'   => $this->get_request_ip_hash(),
                    'user_id'   => get_current_user_id(),
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

        private function get_request_ip_hash() {
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

            return wp_hash( $ip );
        }

        public function register_admin_menu() {
            add_options_page(
                __( 'BRN Lead Count', 'brn-lead-count' ),
                __( 'BRN Lead Count', 'brn-lead-count' ),
                'manage_options',
                'brn-lead-count',
                array( $this, 'render_admin_page' )
            );
        }

        public function register_settings() {
            register_setting(
                'brn_lead_count_settings_group',
                self::OPTION_SETTINGS,
                array( $this, 'sanitize_settings' )
            );
        }

        // ── Updater methods ──────────────────────────────────────────────── //

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

            wp_send_json_success(
                array(
                    'update_available' => $update_available,
                    'latest_version'   => $info['version'],
                    'current_version'  => $installed,
                    'last_checked'     => current_time( 'mysql' ),
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

        // ── Admin scripts ────────────────────────────────────────────── //

        /**
         * Enqueues Chart.js in the <head> for our admin settings page only.
         */
        public function enqueue_admin_scripts( $hook ) {
            if ( 'settings_page_brn-lead-count' !== $hook ) {
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
         * Returns: [ 'Y-m-d' => [ 'phone'=>N, 'whatsapp'=>N, 'form_submit'=>N ], ... ]
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
                $date = substr( $log['time'], 0, 10 ); // Y-m-d
                if ( ! isset( $by_date[ $date ] ) ) {
                    $by_date[ $date ] = array(
                        'phone'       => 0,
                        'whatsapp'    => 0,
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

        // ── Settings ─────────────────────────────────────────────────────── //

        public function sanitize_settings( $input ) {
            $output = array();

            $output['enable_logging'] = empty( $input['enable_logging'] ) ? 0 : 1;
            $output['max_logs'] = isset( $input['max_logs'] ) ? max( 10, min( 2000, absint( $input['max_logs'] ) ) ) : self::MAX_LOGS_DEFAULT;

            return $output;
        }

        public function render_admin_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

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

            $stats = get_option( self::OPTION_STATS, $this->get_empty_stats() );
            $settings = $this->get_settings();
            $counts = isset( $stats['counts'] ) ? $stats['counts'] : array();
            $logs = isset( $stats['logs'] ) && is_array( $stats['logs'] ) ? $stats['logs'] : array();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'BRN Lead Count', 'brn-lead-count' ); ?></h1>
                <p><?php esc_html_e( 'Track and review lead events from phone clicks, WhatsApp clicks, and form submissions.', 'brn-lead-count' ); ?></p>

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
                // ── Analytics section ───────────────────────────────────── //
                $analytics_data = $this->build_analytics_data( $logs );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
                $analytics_json = wp_json_encode( $analytics_data );
                ?>

                <h2 style="margin-top:32px;"><?php esc_html_e( 'Analytics', 'brn-lead-count' ); ?></h2>

                <?php if ( empty( $analytics_data ) ) : ?>
                    <p style="color:#646970;">
                        <?php esc_html_e( 'No data yet. Make sure “Enable Event Logs” is turned on in Settings below, then lead events will appear here.', 'brn-lead-count' ); ?>
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

                        var selectedTypes = [ 'phone', 'whatsapp', 'form_submit' ];
                        var chart         = null;

                        var COLORS = {
                            phone      : { bg: 'rgba(34,113,177,0.8)',  border: '#2271b1' },
                            whatsapp   : { bg: 'rgba(0,163,42,0.8)',    border: '#00a32a' },
                            form_submit: { bg: 'rgba(219,166,23,0.8)', border: '#dba617' }
                        };

                        var LABELS = {
                            phone      : <?php echo wp_json_encode( __( 'Phone', 'brn-lead-count' ) ); ?>,
                            whatsapp   : <?php echo wp_json_encode( __( 'WhatsApp', 'brn-lead-count' ) ); ?>,
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
                            var sums = { phone: 0, whatsapp: 0, form_submit: 0 };
                            dates.forEach( function ( d ) {
                                if ( ! rawData[ d ] ) { return; }
                                sums.phone       += rawData[ d ].phone       || 0;
                                sums.whatsapp    += rawData[ d ].whatsapp    || 0;
                                sums.form_submit += rawData[ d ].form_submit || 0;
                            } );
                            document.getElementById( 'brn-sum-phone' ).textContent    = sums.phone;
                            document.getElementById( 'brn-sum-whatsapp' ).textContent = sums.whatsapp;
                            document.getElementById( 'brn-sum-form' ).textContent     = sums.form_submit;
                            document.getElementById( 'brn-sum-total' ).textContent    = sums.phone + sums.whatsapp + sums.form_submit;

                            var empty  = document.getElementById( 'brn-chart-empty' );
                            var canvas = document.getElementById( 'brn-analytics-chart' );

                            if ( dates.length === 0 ) {
                                empty.style.display  = 'block';
                                canvas.style.display = 'none';
                                return;
                            }
                            empty.style.display  = 'none';
                            canvas.style.display = 'block';

                            var datasets = [ 'phone', 'whatsapp', 'form_submit' ]
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
                                    selectedTypes = [ 'phone', 'whatsapp', 'form_submit' ];
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
                                        ? selectedTypes.length === 3
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
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'brn-lead-count' ) ); ?>
                </form>

                <h2 style="margin-top:24px;"><?php esc_html_e( 'Event Logs', 'brn-lead-count' ); ?></h2>
                <?php if ( empty( $logs ) ) : ?>
                    <p><?php esc_html_e( 'No logs yet.', 'brn-lead-count' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Time', 'brn-lead-count' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'brn-lead-count' ); ?></th>
                                <th><?php esc_html_e( 'Label', 'brn-lead-count' ); ?></th>
                                <th><?php esc_html_e( 'Page URL', 'brn-lead-count' ); ?></th>
                                <th><?php esc_html_e( 'User ID', 'brn-lead-count' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $logs as $log ) : ?>
                                <tr>
                                    <td><?php echo esc_html( isset( $log['time'] ) ? (string) $log['time'] : '' ); ?></td>
                                    <td><?php echo esc_html( isset( $log['type'] ) ? (string) $log['type'] : '' ); ?></td>
                                    <td><?php echo esc_html( isset( $log['label'] ) ? (string) $log['label'] : '' ); ?></td>
                                    <td style="word-break:break-all;"><?php echo esc_html( isset( $log['page_url'] ) ? (string) $log['page_url'] : '' ); ?></td>
                                    <td><?php echo esc_html( isset( $log['user_id'] ) ? (string) $log['user_id'] : '0' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

                <?php
                // ── Updates section ──────────────────────────────────────── //
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-brn-updater.php';
                $current_version = $this->get_installed_plugin_version();
                $cached_info     = get_option( BRN_Updater::OPT_CACHE );
                $last_checked    = get_option( BRN_Updater::OPT_LAST_CHECKED, 0 );
                $last_error      = get_option( BRN_Updater::OPT_LAST_ERROR, '' );
                $latest_version  = ( $cached_info && isset( $cached_info['version'] ) ) ? $cached_info['version'] : '';
                $update_avail    = $latest_version && version_compare( $current_version, $latest_version, '<' );
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
                                        &nbsp;<span style="color:#d63638;"><?php esc_html_e( '— update available', 'brn-lead-count' ); ?></span>
                                    <?php else : ?>
                                        &nbsp;<span style="color:#00a32a;"><?php esc_html_e( '— up to date', 'brn-lead-count' ); ?></span>
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
                        <a href="<?php echo esc_url( network_admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>" class="button button-primary">
                            <?php
                            printf(
                                /* translators: %s = new version number */
                                esc_html__( 'Go to Plugins screen to install v%s', 'brn-lead-count' ),
                                esc_html( $latest_version )
                            );
                            ?>
                        </a>
                        &nbsp;
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
                        status.textContent = <?php echo wp_json_encode( __( 'Checking…', 'brn-lead-count' ) ); ?>;

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
            </div>
            <?php
        }
    }
}

new BRN_Lead_Count();
