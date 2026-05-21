<?php
/**
 * Plugin Name: BU Scopus Research Dashboard
 * Description: Scopus dashboard for Bennett University with admin tools, public shortcode view, clickable charts, horizontal subject graph, world map, popup details, CSV export, SDG and WOS sections, and safe filtering of Erratum / Retracted / Preprint records.
 * Version: 4.6.0
 * Author: Ashutosh Mishra
 * Text Domain: bu-scopus-research-dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BU_Scopus_Research_Dashboard')) :

final class BU_Scopus_Research_Dashboard {
    const OPT_SETTINGS   = 'bu_scopus_rd_settings';
    const OPT_CACHE      = 'bu_scopus_rd_cache';
    const OPT_CACHE_DATE = 'bu_scopus_rd_cache_date';
    const OPT_EXTRA_METRICS = 'bu_scopus_rd_extra_metrics';
    const CRON_HOOK      = 'bu_scopus_rd_update_cache';

    private static $instance = null;
    private $logo_url = 'https://library.bennett.edu.in/wp-content/uploads/2026/03/BU_Research_Analytics_Dashboard_logo-removebg-preview.png';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_shortcode('bu_scopus_research_dashboard', array($this, 'shortcode_dashboard'));

        add_action('wp_ajax_bu_scopus_rd_details', array($this, 'ajax_details'));
        add_action('wp_ajax_nopriv_bu_scopus_rd_details', array($this, 'ajax_details'));
        add_action('wp_ajax_bu_scopus_rd_publication_detail', array($this, 'ajax_publication_detail'));
        add_action('wp_ajax_nopriv_bu_scopus_rd_publication_detail', array($this, 'ajax_publication_detail'));
        add_action('wp_ajax_bu_scopus_rd_update_cache', array($this, 'ajax_update_cache'));
        add_action('wp_ajax_bu_scopus_rd_progress', array($this, 'ajax_progress'));
        add_action('wp_ajax_bu_scopus_rd_export_csv', array($this, 'ajax_export_csv'));

        add_action(self::CRON_HOOK, array($this, 'cron_update_cache'));

        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $ts = strtotime('tomorrow 03:00:00');
            if (!$ts) {
                $ts = time() + DAY_IN_SECONDS;
            }
            wp_schedule_event($ts, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    public function admin_menu() {
        $cap = 'manage_options';

        add_menu_page(
            __('BU Research Dashboard', 'bu-scopus-research-dashboard'),
            __('BU Research Dashboard', 'bu-scopus-research-dashboard'),
            $cap,
            'bu-scopus-rd',
            array($this, 'page_dashboard'),
            'dashicons-chart-area',
            7
        );

        add_submenu_page(
            'bu-scopus-rd',
            __('Dashboard', 'bu-scopus-research-dashboard'),
            __('Dashboard', 'bu-scopus-research-dashboard'),
            $cap,
            'bu-scopus-rd',
            array($this, 'page_dashboard')
        );

        add_submenu_page(
            'bu-scopus-rd',
            __('Settings', 'bu-scopus-research-dashboard'),
            __('Settings', 'bu-scopus-research-dashboard'),
            $cap,
            'bu-scopus-rd-settings',
            array($this, 'page_settings')
        );

        add_submenu_page(
            'bu-scopus-rd',
            __('Manual Metrics', 'bu-scopus-research-dashboard'),
            __('Manual Metrics', 'bu-scopus-research-dashboard'),
            $cap,
            'bu-scopus-rd-manual-metrics',
            array($this, 'page_manual_metrics')
        );
    }

    public function register_settings() {
        register_setting('bu_scopus_rd_group', self::OPT_SETTINGS, array($this, 'sanitize'));

        add_settings_section(
            'bu_scopus_rd_conn',
            __('Scopus API Settings', 'bu-scopus-research-dashboard'),
            function () {
                echo '<p class="description">' . esc_html__('Use your institutional Elsevier API key and Scopus Affiliation ID.', 'bu-scopus-research-dashboard') . '</p>';
            },
            'bu-scopus-rd-settings'
        );

        $fields = array(
            array('api_key', __('Elsevier API Key', 'bu-scopus-research-dashboard'), 'text', '', __('Your Elsevier developer API key.', 'bu-scopus-research-dashboard')),
            array('afid', __('Scopus Affiliation ID', 'bu-scopus-research-dashboard'), 'text', '60121496', __('Example: 60121496', 'bu-scopus-research-dashboard')),
            array('institution_label', __('Institution Label', 'bu-scopus-research-dashboard'), 'text', 'Bennett University', __('Shown in the affiliation card.', 'bu-scopus-research-dashboard')),
            array('auto_fetch', __('Auto Update Daily', 'bu-scopus-research-dashboard'), 'checkbox', '', __('Refresh dashboard cache daily using cron.', 'bu-scopus-research-dashboard')),
            array('json_dir', __('JSON Folder (optional fallback)', 'bu-scopus-research-dashboard'), 'text', 'scopus-mis-cache', __('Relative to uploads folder. Used only when API is unavailable.', 'bu-scopus-research-dashboard')),
            array('manual_csv', __('Manual CSV File (optional)', 'bu-scopus-research-dashboard'), 'text', 'custom_metrics.csv', __('Relative to uploads folder. Use for custom metrics.', 'bu-scopus-research-dashboard')),
            array('dashboard_auto_refresh_minutes', __('Dashboard Auto Refresh (minutes)', 'bu-scopus-research-dashboard'), 'number', '0', __('Set 0 to disable. Example: 15 refreshes the dashboard automatically every 15 minutes.', 'bu-scopus-research-dashboard')),
            array('recent_publications_limit', __('Recent Publications Cards', 'bu-scopus-research-dashboard'), 'number', '6', __('How many recent publication cards to show on the dashboard.', 'bu-scopus-research-dashboard')),
        );

        foreach ($fields as $f) {
            add_settings_field(
                $f[0],
                $f[1],
                array($this, 'render_field'),
                'bu-scopus-rd-settings',
                'bu_scopus_rd_conn',
                array(
                    'key'         => $f[0],
                    'type'        => $f[2],
                    'placeholder' => $f[3],
                    'help'        => $f[4],
                )
            );
        }

        add_settings_section(
            'bu_scopus_rd_dspace',
            __('DSpace API Settings', 'bu-scopus-research-dashboard'),
            function () {
                echo '<p class="description">' . esc_html__('Configure the DSpace repository endpoint used for the admin dashboard. For DSpace 7-9, the API base is usually https://your-domain/server/api.', 'bu-scopus-research-dashboard') . '</p>';
            },
            'bu-scopus-rd-settings'
        );

        $dspace_fields = array(
            array('dspace_repo_url', __('Repository URL', 'bu-scopus-research-dashboard'), 'text', 'https://lrcdrs.bennett.edu.in', __('Repository base URL, e.g. https://lrcdrs.bennett.edu.in', 'bu-scopus-research-dashboard')),
            array('dspace_api_base', __('API Base URL', 'bu-scopus-research-dashboard'), 'text', 'https://lrcdrs.bennett.edu.in/server/api', __('Leave blank to auto-build from the repository URL.', 'bu-scopus-research-dashboard')),
            array('dspace_timeout', __('Request Timeout (seconds)', 'bu-scopus-research-dashboard'), 'number', '20', __('HTTP timeout for DSpace requests.', 'bu-scopus-research-dashboard')),
            array('dspace_cache_ttl', __('Cache TTL (seconds)', 'bu-scopus-research-dashboard'), 'number', '600', __('How long DSpace thesis data should stay cached.', 'bu-scopus-research-dashboard')),
            array('dspace_rows_per_details', __('Rows per details section', 'bu-scopus-research-dashboard'), 'number', '25', __('Maximum thesis rows shown inside the details popup table.', 'bu-scopus-research-dashboard')),
            array('dspace_deep_scan_page_size', __('Deep Scan Page Size', 'bu-scopus-research-dashboard'), 'number', '100', __('Number of repository items fetched per page during thesis scan.', 'bu-scopus-research-dashboard')),
            array('dspace_deep_scan_max_pages', __('Deep Scan Max Pages', 'bu-scopus-research-dashboard'), 'number', '100', __('Maximum pages scanned while building thesis metrics.', 'bu-scopus-research-dashboard')),
            array('dspace_verify_ssl', __('Verify SSL', 'bu-scopus-research-dashboard'), 'checkbox', '', __('Enable SSL certificate verification.', 'bu-scopus-research-dashboard')),
        );

        foreach ($dspace_fields as $f) {
            add_settings_field(
                $f[0],
                $f[1],
                array($this, 'render_field'),
                'bu-scopus-rd-settings',
                'bu_scopus_rd_dspace',
                array(
                    'key'         => $f[0],
                    'type'        => $f[2],
                    'placeholder' => $f[3],
                    'help'        => $f[4],
                )
            );
        }
    }

    public function sanitize($input) {
        $defaults = $this->get_settings();

        return array(
            'api_key'                    => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : $defaults['api_key'],
            'afid'                       => isset($input['afid']) ? sanitize_text_field($input['afid']) : $defaults['afid'],
            'institution_label'          => isset($input['institution_label']) ? sanitize_text_field($input['institution_label']) : $defaults['institution_label'],
            'auto_fetch'                 => !empty($input['auto_fetch']) ? 1 : 0,
            'json_dir'                   => isset($input['json_dir']) ? sanitize_text_field($input['json_dir']) : $defaults['json_dir'],
            'manual_csv'                 => isset($input['manual_csv']) ? sanitize_text_field($input['manual_csv']) : $defaults['manual_csv'],
            'dashboard_auto_refresh_minutes' => isset($input['dashboard_auto_refresh_minutes']) ? max(0, (int) $input['dashboard_auto_refresh_minutes']) : $defaults['dashboard_auto_refresh_minutes'],
            'recent_publications_limit'  => isset($input['recent_publications_limit']) ? max(0, (int) $input['recent_publications_limit']) : $defaults['recent_publications_limit'],
            'dspace_repo_url'            => isset($input['dspace_repo_url']) ? esc_url_raw(trim((string) $input['dspace_repo_url'])) : $defaults['dspace_repo_url'],
            'dspace_api_base'            => isset($input['dspace_api_base']) ? esc_url_raw(trim((string) $input['dspace_api_base'])) : $defaults['dspace_api_base'],
            'dspace_timeout'             => isset($input['dspace_timeout']) ? max(5, (int) $input['dspace_timeout']) : $defaults['dspace_timeout'],
            'dspace_cache_ttl'           => isset($input['dspace_cache_ttl']) ? max(60, (int) $input['dspace_cache_ttl']) : $defaults['dspace_cache_ttl'],
            'dspace_rows_per_details'    => isset($input['dspace_rows_per_details']) ? max(1, (int) $input['dspace_rows_per_details']) : $defaults['dspace_rows_per_details'],
            'dspace_deep_scan_page_size' => isset($input['dspace_deep_scan_page_size']) ? max(1, (int) $input['dspace_deep_scan_page_size']) : $defaults['dspace_deep_scan_page_size'],
            'dspace_deep_scan_max_pages' => isset($input['dspace_deep_scan_max_pages']) ? max(1, (int) $input['dspace_deep_scan_max_pages']) : $defaults['dspace_deep_scan_max_pages'],
            'dspace_verify_ssl'          => !empty($input['dspace_verify_ssl']) ? 1 : 0,
        );
    }

    public function render_field($args) {
        $settings    = $this->get_settings();
        $key         = $args['key'];
        $type        = $args['type'];
        $placeholder = $args['placeholder'];
        $help        = $args['help'];
        $value       = isset($settings[$key]) ? $settings[$key] : '';

        if ($type === 'checkbox') {
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPT_SETTINGS) . '[' . esc_attr($key) . ']" value="1" ' . checked($value, 1, false) . ' /> ' . esc_html($help) . '</label>';
            return;
        }

        printf(
            '<input type="%s" class="regular-text" name="%s[%s]" value="%s" placeholder="%s" />',
            esc_attr($type),
            esc_attr(self::OPT_SETTINGS),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if ($help) {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
    }

    private function get_settings() {
        $defaults = array(
            'api_key'                    => '',
            'afid'                       => '60121496',
            'institution_label'          => 'Bennett University',
            'auto_fetch'                 => 0,
            'json_dir'                   => 'scopus-mis-cache',
            'manual_csv'                 => 'custom_metrics.csv',
            'dashboard_auto_refresh_minutes' => 0,
            'recent_publications_limit'  => 6,
            'dspace_repo_url'            => 'https://lrcdrs.bennett.edu.in',
            'dspace_api_base'            => 'https://lrcdrs.bennett.edu.in/server/api',
            'dspace_timeout'             => 20,
            'dspace_cache_ttl'           => 600,
            'dspace_rows_per_details'    => 25,
            'dspace_deep_scan_page_size' => 100,
            'dspace_deep_scan_max_pages' => 100,
            'dspace_verify_ssl'          => 1,
        );

        $saved = get_option(self::OPT_SETTINGS, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
    }


    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'bu-scopus-rd') === false) {
            return;
        }

        $data    = $this->get_data(false);
        $stats   = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
        $collabs = isset($stats['global_collaborations']) && is_array($stats['global_collaborations']) ? $stats['global_collaborations'] : array();

        $this->enqueue_dashboard_assets(true, $collabs);
    }

    private function enqueue_dashboard_assets($is_admin_page = false, $collabs = array()) {
        wp_register_style('bu-scopus-rd-admin', false, array(), '4.6.0');
        wp_enqueue_style('bu-scopus-rd-admin');
        wp_add_inline_style('bu-scopus-rd-admin', $this->admin_css());

        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true);

        wp_register_script('bu-scopus-rd-inline', false, array('chartjs'), '4.6.0', true);
        wp_enqueue_script('bu-scopus-rd-inline');

        $settings = $this->get_settings();

        wp_localize_script('bu-scopus-rd-inline', 'BU_SCOPUS_RD', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('bu_scopus_rd_nonce'),
            'is_admin' => $is_admin_page ? 1 : 0,
            'collab'   => $this->collab_rows($collabs),
            'auto_refresh_minutes' => max(0, (int) ($settings['dashboard_auto_refresh_minutes'] ?? 0)),
            'current_year' => (int) gmdate('Y'),
            'labels'   => array(
                'details'       => __('Details', 'bu-scopus-research-dashboard'),
                'loading'       => __('Loading...', 'bu-scopus-research-dashboard'),
                'requestFail'   => __('Request failed.', 'bu-scopus-research-dashboard'),
                'noRows'        => __('No matching records found.', 'bu-scopus-research-dashboard'),
                'absNA'         => __('Abstract not available for this record.', 'bu-scopus-research-dashboard'),
                'fetchErr'      => __('Could not retrieve article details.', 'bu-scopus-research-dashboard'),
                'pubDetails'    => __('Publication Details', 'bu-scopus-research-dashboard'),
                'filterHeading' => __('Detailed Statistics', 'bu-scopus-research-dashboard'),
                'search'        => __('Search', 'bu-scopus-research-dashboard'),
                'reset'         => __('Reset', 'bu-scopus-research-dashboard'),
                'updateDone'    => __('Dashboard updated successfully.', 'bu-scopus-research-dashboard'),
                'loadingData'   => __('Fetching data...', 'bu-scopus-research-dashboard'),
                'openingArticle'=> __('Opening article...', 'bu-scopus-research-dashboard'),
                'updatingData'  => __('Updating dashboard...', 'bu-scopus-research-dashboard'),
                'pleaseWait'    => __('Please wait while data is being fetched.', 'bu-scopus-research-dashboard'),
                'progressReady' => __('Preparing request...', 'bu-scopus-research-dashboard'),
                'progressFetch' => __('Fetching records from API...', 'bu-scopus-research-dashboard'),
                'progressBuild' => __('Building dashboard data...', 'bu-scopus-research-dashboard'),
                'progressDone'  => __('Completed.', 'bu-scopus-research-dashboard'),
                'cancel'        => __('Cancel', 'bu-scopus-research-dashboard'),
                'elapsed'       => __('Elapsed', 'bu-scopus-research-dashboard'),
                'cancelled'     => __('Request cancelled.', 'bu-scopus-research-dashboard'),
                'autoRefresh'   => __('Auto refresh applied.', 'bu-scopus-research-dashboard'),
            ),
        ));

        $js = <<<'JS'
(function(){
    function esc(v){
        return String(v === undefined || v === null ? '' : v)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#39;');
    }

    function toDisplay(v){
        return (v === undefined || v === null) ? '' : String(v);
    }

    function makeDoiLink(doi){
        if (!doi) return '';
        const href = /^https?:\/\//i.test(doi) ? doi : 'https://doi.org/' + doi;
        return '<a href="'+esc(href)+'" target="_blank" rel="noopener noreferrer">'+esc(doi)+'</a>';
    }

    function colorPalette(len) {
        const base = [
            '#ef4444','#2563eb','#16a34a','#f97316','#9333ea','#db2777',
            '#14b8a6','#ea580c','#475569','#0ea5e9','#ca8a04','#7c3aed',
            '#84cc16','#f43f5e','#22c55e','#3b82f6','#f59e0b','#10b981'
        ];
        const out = [];
        for (let i = 0; i < len; i++) out.push(base[i % base.length]);
        return out;
    }

    function createLoaderAPI(){
        const box = document.getElementById('bu-rd-loader');
        if (!box) {
            return {
                show: function(){}, hide: function(){}, update: function(){},
                fakeStart: function(){ return function(){}; }
            };
        }
        const title = document.getElementById('bu-rd-loader-title');
        const text  = document.getElementById('bu-rd-loader-text');
        const bar   = document.getElementById('bu-rd-loader-bar');
        const pct   = document.getElementById('bu-rd-loader-pct');
        const timerEl = document.getElementById('bu-rd-loader-timer');
        const cancelBtn = document.getElementById('bu-rd-loader-cancel');
        let timer = null;
        let elapsedTimer = null;
        let startedAt = 0;
        let currentCancel = null;

        function clearTimers(){
            if (timer) { clearInterval(timer); timer = null; }
            if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
        }

        function tickElapsed(){
            if (!timerEl || !startedAt) return;
            const seconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
            timerEl.textContent = seconds + 's';
        }

        function update(percent, message, heading){
            const current = Math.max(0, Math.min(100, Number(percent || 0)));
            if (title && heading) title.textContent = heading;
            if (text && message) text.textContent = message;
            if (bar) bar.style.width = current + '%';
            if (pct) pct.textContent = Math.round(current) + '%';
            tickElapsed();
        }

        function show(heading, message, onCancel){
            clearTimers();
            startedAt = Date.now();
            currentCancel = typeof onCancel === 'function' ? onCancel : null;
            if (cancelBtn) {
                cancelBtn.style.display = currentCancel ? 'inline-flex' : 'none';
                cancelBtn.onclick = function(){
                    if (typeof currentCancel === 'function') currentCancel();
                };
            }
            update(6, message || BU_SCOPUS_RD.labels.pleaseWait, heading || BU_SCOPUS_RD.labels.loadingData);
            if (timerEl) timerEl.textContent = '0s';
            elapsedTimer = setInterval(tickElapsed, 250);
            box.style.display = 'flex';
        }

        function hide(){
            clearTimers();
            update(100, BU_SCOPUS_RD.labels.progressDone, title ? title.textContent : '');
            currentCancel = null;
            if (cancelBtn) cancelBtn.onclick = null;
            setTimeout(function(){ box.style.display = 'none'; }, 180);
        }

        function fakeStart(heading, onCancel){
            show(heading || BU_SCOPUS_RD.labels.loadingData, BU_SCOPUS_RD.labels.progressReady, onCancel);
            const steps = [
                {p: 12, m: BU_SCOPUS_RD.labels.progressReady},
                {p: 34, m: BU_SCOPUS_RD.labels.progressFetch},
                {p: 58, m: BU_SCOPUS_RD.labels.progressFetch},
                {p: 76, m: BU_SCOPUS_RD.labels.progressBuild},
                {p: 90, m: BU_SCOPUS_RD.labels.progressBuild}
            ];
            let idx = 0;
            timer = setInterval(function(){
                if (idx >= steps.length) return;
                update(steps[idx].p, steps[idx].m, heading || BU_SCOPUS_RD.labels.loadingData);
                idx++;
            }, 420);
            return function stop(){ hide(); };
        }

        return { show, hide, update, fakeStart };
    }

    function buildCollabMap(onCountrySelected){
        const D = window.BU_SCOPUS_RD || {};
        const el = document.getElementById('bpt-world');
        if (!el || !D.collab || !D.collab.length) return;

        const render = function(){
            if (typeof google === 'undefined' || !google.visualization || !google.visualization.GeoChart) return;
            const data = new google.visualization.DataTable();
            data.addColumn('string', 'Country');
            data.addColumn('number', 'Publications');

            (D.collab || []).forEach(function(r){
                data.addRow([String(r.country || ''), Number(r.pubs || 0)]);
            });

            const opt = {
                legend: 'none',
                colorAxis: { colors: ['#ffd000', '#ff7b00'] },
                datalessRegionColor: '#eeeeee',
                backgroundColor: 'transparent'
            };

            const chart = new google.visualization.GeoChart(el);
            chart.draw(data, opt);

            if (typeof onCountrySelected === 'function') {
                google.visualization.events.addListener(chart, 'select', function(){
                    const sel = chart.getSelection();
                    if (!sel || !sel.length || sel[0].row == null) return;
                    const rowIndex = sel[0].row;
                    const country = data.getValue(rowIndex, 0);
                    if (country) onCountrySelected(country);
                });
            }
        };

        if (window.google && google.charts) {
            google.charts.load('current', { packages: ['geochart'] });
            google.charts.setOnLoadCallback(render);
            return;
        }

        const existing = document.querySelector('script[data-bu-rd-map="1"]');
        if (existing) {
            existing.addEventListener('load', function(){
                google.charts.load('current', { packages: ['geochart'] });
                google.charts.setOnLoadCallback(render);
            }, {once:true});
            return;
        }

        const s = document.createElement('script');
        s.src = 'https://www.gstatic.com/charts/loader.js';
        s.setAttribute('data-bu-rd-map', '1');
        s.onload = function(){
            google.charts.load('current', { packages: ['geochart'] });
            google.charts.setOnLoadCallback(render);
        };
        document.head.appendChild(s);
    }

    document.addEventListener('DOMContentLoaded', function(){
        const loader = createLoaderAPI();
        const modal   = document.getElementById('bu-rd-modal');
        const summary = document.getElementById('bu-rd-summary');
        const content = document.getElementById('bu-rd-content');
        const detailModal = document.getElementById('bu-rd-detail-modal');
        const detailTitle = document.getElementById('bu-rd-detail-title');
        const detailMeta  = document.getElementById('bu-rd-detail-meta');
        const detailAbs   = document.getElementById('bu-rd-detail-abstract');
        const detailAbsWrap = document.getElementById('bu-rd-detail-abstract-wrap');
        const detailPlumXWrap = document.getElementById('bu-rd-detail-plumx-wrap');
        const detailPlumXBox = document.getElementById('bu-rd-detail-plumx-box');
        const detailOpen  = document.getElementById('bu-rd-detail-open');
        const authorI = document.getElementById('bu-rd-author');
        const titleI  = document.getElementById('bu-rd-title');
        const yearI   = document.getElementById('bu-rd-year');
        const accessI = document.getElementById('bu-rd-access');
        const docTypeI= document.getElementById('bu-rd-doc-type');
        const sortYI  = document.getElementById('bu-rd-sort-year');
        const sortCI  = document.getElementById('bu-rd-sort-citations');
        const yearChart = document.getElementById('bu-rd-year-chart');
        const citationTrendChart = document.getElementById('bu-rd-citation-trend-chart');
        const docTypeChart = document.getElementById('bu-rd-doc-type-chart');
        const sdgChart = document.getElementById('bu-rd-sdg-chart');
        const subjectChart = document.getElementById('bu-rd-subject-chart') || document.getElementById('bu-rd-manual-subject-chart');
        const recentList = document.getElementById('bu-rd-recent-list');
        const searchBtn = document.getElementById('bu-rd-search');
        const resetBtn = document.getElementById('bu-rd-reset');
        const closeBtn = document.getElementById('bu-rd-close');
        const detailCloseBtn = document.getElementById('bu-rd-detail-close');
        const updateBtn = document.getElementById('bu-rd-update-cache-btn');
        const exportBtn = document.getElementById('bu-rd-export-btn');

        let currentMetric = '';
        let currentExtra  = {};
        let years = [];
        let counts = [];

        try {
            years = JSON.parse(yearChart ? yearChart.getAttribute('data-years') : '[]') || [];
            counts = JSON.parse(yearChart ? yearChart.getAttribute('data-counts') : '[]') || [];
        } catch(e) {}

        function resetFilters(clearHeading){
            if (authorI) authorI.value = '';
            if (titleI) titleI.value = '';
            if (yearI) yearI.value = '';
            if (accessI) accessI.value = '';
            if (docTypeI) docTypeI.value = '';
            if (sortYI) sortYI.value = 'desc';
            if (sortCI) sortCI.value = 'none';
            if (clearHeading !== false) {
                const h = document.getElementById('bu-rd-modal-heading');
                if (h) h.textContent = BU_SCOPUS_RD.labels.filterHeading;
            }
        }

        function renderSummary(obj){
            if (!summary) return;
            if (!obj || typeof obj !== 'object') {
                summary.innerHTML = '';
                return;
            }
            let html = '';
            Object.keys(obj).forEach(function(k, idx){
                const classes = ['sum-red','sum-blue','sum-green','sum-purple','sum-orange','sum-teal'];
                html += '<div class="bu-rd-summary-card '+classes[idx % classes.length]+'"><div>'+esc(k)+'</div><strong>'+esc(obj[k])+'</strong></div>';
            });
            summary.innerHTML = html;
        }

        function renderTable(rows){
            if (!Array.isArray(rows) || rows.length === 0) {
                return '<p>'+esc(BU_SCOPUS_RD.labels.noRows)+'</p>';
            }
            const keys = Object.keys(rows[0]).filter(k => !['__EID','__SCOPUS_URL','__AUTHORS_HTML','__DETAIL_TYPE','__DETAIL_B64'].includes(k));
            let html = '<table class="widefat striped"><thead><tr>';
            keys.forEach(function(k){ html += '<th>'+esc(k)+'</th>'; });
            html += '</tr></thead><tbody>';

            rows.forEach(function(row){
                const hasRecord = !!(row.__EID || row.__DETAIL_B64);
                let attrs = '';
                if (hasRecord) {
                    attrs += ' class="bu-rd-row"';
                    if (row.__EID) attrs += ' data-eid="'+esc(row.__EID || '')+'"';
                    if (row.__SCOPUS_URL) attrs += ' data-scopus-url="'+esc(row.__SCOPUS_URL || '')+'"';
                    if (row.__DETAIL_TYPE) attrs += ' data-detail-type="'+esc(row.__DETAIL_TYPE || '')+'"';
                    if (row.__DETAIL_B64) attrs += ' data-detail-b64="'+esc(row.__DETAIL_B64 || '')+'"';
                }
                html += '<tr' + attrs + '>';
                keys.forEach(function(k){
                    let val = (row[k] === undefined || row[k] === null) ? '' : row[k];
                    if (k === 'Title') {
                        if (hasRecord) {
                            val = '&#8599; ' + esc(toDisplay(val));
                            html += '<td class="bu-rd-title-cell bu-rd-clickable">'+val+'</td>';
                        } else {
                            html += '<td>'+esc(toDisplay(val))+'</td>';
                        }
                    } else if (k === 'Authors' && row.__AUTHORS_HTML) {
                        html += '<td class="bu-rd-rich-authors">'+row.__AUTHORS_HTML+'</td>';
                    } else if (k === 'Handle' && val) {
                        html += '<td><a href="'+esc(toDisplay(val))+'" target="_blank" rel="noopener noreferrer">'+esc(toDisplay(val))+'</a></td>';
                    } else {
                        html += '<td>'+esc(toDisplay(val))+'</td>';
                    }
                });
                html += '</tr>';
            });

            html += '</tbody></table>';
            return html;
        }

        function loadDetails(){
            if (!content) return;
            const controller = new AbortController();
            const stopLoader = loader.fakeStart(BU_SCOPUS_RD.labels.loadingData, function(){ controller.abort(); });

            const params = new URLSearchParams();
            params.set('action','bu_scopus_rd_details');
            params.set('_ajax_nonce', BU_SCOPUS_RD.nonce);
            params.set('metric', currentMetric);
            params.set('author', authorI ? authorI.value : '');
            params.set('title', titleI ? titleI.value : '');
            params.set('year', yearI ? yearI.value : '');
            params.set('access', accessI ? accessI.value : '');
            params.set('doc_type', docTypeI ? docTypeI.value : '');
            params.set('sort_year', sortYI ? sortYI.value : 'desc');
            params.set('sort_citations', sortCI ? sortCI.value : 'none');
            Object.keys(currentExtra || {}).forEach(function(k){ params.set(k, currentExtra[k]); });
            content.innerHTML = '<p>'+esc(BU_SCOPUS_RD.labels.loading)+'</p>';

            fetch(BU_SCOPUS_RD.ajax_url, {
                method: 'POST', signal: controller.signal,
                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(function(res){
                stopLoader();
                if (res && res.ok) {
                    renderSummary(res.summary || {});
                    content.innerHTML = renderTable(res.rows || []);
                } else {
                    renderSummary({});
                    content.innerHTML = '<p>'+esc(res && res.message ? res.message : BU_SCOPUS_RD.labels.requestFail)+'</p>';
                }
            })
            .catch(function(err){
                stopLoader();
                if (err && err.name === 'AbortError') {
                    content.innerHTML = '<p>'+esc(BU_SCOPUS_RD.labels.cancelled)+'</p>';
                    return;
                }
                renderSummary({});
                content.innerHTML = '<p>'+esc(BU_SCOPUS_RD.labels.requestFail)+'</p>';
            });
        }

        function openMetric(metric, extra, heading, presetYear){
            if (!metric) return;
            currentMetric = metric || '';
            currentExtra  = extra || {};
            resetFilters(false);
            const h = document.getElementById('bu-rd-modal-heading');
            if (h && heading) h.textContent = heading;
            if (yearI && presetYear) yearI.value = String(presetYear);
            if (docTypeI && currentExtra.doc_type_label) docTypeI.value = String(currentExtra.doc_type_label);
            if (modal) modal.style.display = 'flex';
            loadDetails();
        }


        function hidePlumX(){
            if (detailPlumXBox) detailPlumXBox.innerHTML = '';
            if (detailPlumXWrap) detailPlumXWrap.style.display = 'none';
        }

        function ensurePlumXScript(callback){
            if (window.__plumX && window.__plumX.widgets && typeof window.__plumX.widgets.init === 'function') {
                if (typeof callback === 'function') callback();
                return;
            }
            let script = document.querySelector('script[data-bu-rd-plumx="1"]');
            if (!script) {
                script = document.createElement('script');
                script.src = 'https://cdn.plu.mx/widget-all.js';
                script.async = true;
                script.setAttribute('data-bu-rd-plumx', '1');
                document.body.appendChild(script);
            }
            if (typeof callback === 'function') {
                script.addEventListener('load', function(){ callback(); }, {once:true});
            }
        }

        function renderPlumX(doi){
            if (!detailPlumXWrap || !detailPlumXBox) return;
            const cleanDoi = String(doi || '').trim();
            if (!cleanDoi) {
                hidePlumX();
                return;
            }
            detailPlumXWrap.style.display = '';
            detailPlumXBox.innerHTML = '<a href="https://plu.mx/plum/a/?doi=' + encodeURIComponent(cleanDoi) + '" class="plumx-summary" data-orientation="horizontal" data-hide-when-empty="true" data-hide-print="false"></a>';
            ensurePlumXScript(function(){
                try {
                    if (window.__plumX && window.__plumX.widgets && typeof window.__plumX.widgets.init === 'function') {
                        window.__plumX.widgets.init();
                    }
                } catch(e) {}
            });
        }

        function openDetail(row){
            if (!row || !detailModal) return;

            const detailCache = window.__BU_RD_DETAIL_CACHE = window.__BU_RD_DETAIL_CACHE || {};
            const detailType = row.getAttribute('data-detail-type') || '';
            const detailB64  = row.getAttribute('data-detail-b64') || '';
            const eid = row.getAttribute('data-eid') || '';
            const titleCell = row.querySelector('.bu-rd-title-cell');
            const clickedTitle = titleCell ? titleCell.textContent.replace(/^↗\s*/, '') : '';
            let preview = null;

            function decodePreview(){
                if (!detailB64) return null;
                try {
                    return JSON.parse(atob(detailB64));
                } catch(e) {
                    return null;
                }
            }

            function renderDetail(payload, type){
                if (!payload) return;
                detailTitle.textContent = '↗ ' + (payload.title || clickedTitle || '');
                if (type === 'dspace') {
                    detailMeta.innerHTML =
                        '<p><strong>Author:</strong> ' + esc(payload.authors || '') + '</p>' +
                        '<p><strong>Year:</strong> ' + esc(payload.year || '') + '</p>' +
                        '<p><strong>Collections:</strong> ' + esc(payload.collection || '') + '</p>' +
                        '<p><strong>Handle:</strong> ' + (payload.handle ? '<a href="'+esc(payload.handle)+'" target="_blank" rel="noopener noreferrer">'+esc(payload.handle)+'</a>' : '—') + '</p>';
                    const abstractText = payload.abstract || '';
                    if (detailAbsWrap) detailAbsWrap.style.display = abstractText ? '' : 'none';
                    detailAbs.textContent = abstractText;
                    detailOpen.textContent = 'Open Handle';
                    detailOpen.setAttribute('href', payload.handle || '#');
                    hidePlumX();
                } else {
                    let doiHtml = makeDoiLink(payload.doi || '');
                    const targetUrl = payload.scopus_url || '#';
                    if (!doiHtml) {
                        doiHtml = 'No';
                        if (targetUrl && targetUrl !== '#') doiHtml += ' — <a href="'+esc(targetUrl)+'" target="_blank" rel="noopener noreferrer">Click here</a>';
                    }
                    let metaHtml = '<p><strong>Authors:</strong> ' + (payload.authors_html || esc((payload.authors || []).join ? payload.authors.join(', ') : (payload.authors || ''))) + '</p>';
                    if (payload.journal || payload.year) {
                        metaHtml += '<p><strong>Source:</strong> ' + esc(payload.journal || '') + (payload.year ? ' (' + esc(payload.year) + ')' : '') + '</p>';
                    }
                    metaHtml += '<p><strong>DOI:</strong> ' + doiHtml + '</p>';
                    detailMeta.innerHTML = metaHtml;
                    const abstractText = payload.abstract || '';
                    if (detailAbsWrap) detailAbsWrap.style.display = abstractText ? '' : 'none';
                    detailAbs.textContent = abstractText;
                    detailOpen.textContent = 'Open in Scopus';
                    detailOpen.setAttribute('href', targetUrl);
                    renderPlumX(payload.doi || '');
                }
                detailOpen.setAttribute('target', '_blank');
                detailOpen.setAttribute('rel', 'noopener noreferrer');
                detailModal.style.display = 'flex';
            }

            if (detailType === 'dspace') {
                preview = decodePreview();
                if (preview) {
                    renderDetail(preview, 'dspace');
                    return;
                }
                alert(BU_SCOPUS_RD.labels.fetchErr);
                return;
            }

            preview = decodePreview();
            if (preview) {
                renderDetail(preview, 'scopus');
            }

            if (eid && detailCache[eid]) {
                renderDetail(detailCache[eid], 'scopus');
                return;
            }

            if (!eid) {
                if (!preview) alert(BU_SCOPUS_RD.labels.fetchErr);
                return;
            }

            if (window.__BU_RD_DETAIL_CONTROLLER && typeof window.__BU_RD_DETAIL_CONTROLLER.abort === 'function') {
                try { window.__BU_RD_DETAIL_CONTROLLER.abort(); } catch(e) {}
            }
            const controller = new AbortController();
            window.__BU_RD_DETAIL_CONTROLLER = controller;
            const scopusUrl = row.getAttribute('data-scopus-url') || (preview ? (preview.scopus_url || '#') : '#');
            const p = new URLSearchParams();
            p.set('action','bu_scopus_rd_publication_detail');
            p.set('_ajax_nonce', BU_SCOPUS_RD.nonce);
            p.set('eid', eid);

            const timeoutId = setTimeout(function(){
                try { controller.abort(); } catch(e) {}
            }, 4500);

            fetch(BU_SCOPUS_RD.ajax_url, {
                method: 'POST', signal: controller.signal,
                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: p.toString()
            })
            .then(r => r.json())
            .then(function(res){
                clearTimeout(timeoutId);
                if (res && res.ok) {
                    const payload = {
                        title: res.title || clickedTitle || (preview ? preview.title : ''),
                        abstract: res.abstract || (preview ? preview.abstract : ''),
                        authors: res.authors || (preview ? preview.authors : []),
                        authors_html: res.authors_html || (preview ? preview.authors_html : ''),
                        doi: res.doi || (preview ? preview.doi : ''),
                        journal: preview ? (preview.journal || '') : '',
                        year: preview ? (preview.year || '') : '',
                        scopus_url: (res.scopus_url && res.scopus_url !== '#') ? res.scopus_url : (scopusUrl || '#')
                    };
                    detailCache[eid] = payload;
                    renderDetail(payload, 'scopus');
                } else if (!preview) {
                    alert(res && res.message ? res.message : BU_SCOPUS_RD.labels.fetchErr);
                }
            })
            .catch(function(err){
                clearTimeout(timeoutId);
                if (err && err.name === 'AbortError') {
                    return;
                }
                if (!preview) alert(BU_SCOPUS_RD.labels.fetchErr);
            });
        }

        buildCollabMap(function(country){
            openMetric('country_publications', {country_label: country}, country + ' — ' + BU_SCOPUS_RD.labels.details);
        });

        document.querySelectorAll('.bu-rd-kpi').forEach(function(card){
            card.addEventListener('click', function(){
                const metric = card.getAttribute('data-metric') || '';
                if (!metric) return;
                const extra = {};
                const oaLabel = card.getAttribute('data-oa-label');
                const subjectLabel = card.getAttribute('data-subject-label');
                const subjectCode = card.getAttribute('data-subject-code');
                const countryLabel = card.getAttribute('data-country-label');
                const docTypeLabel = card.getAttribute('data-doc-type-label');
                if (oaLabel) extra.oa_label = oaLabel;
                if (subjectLabel) extra.subject_label = subjectLabel;
                if (subjectCode) extra.subject_code = subjectCode;
                if (countryLabel) extra.country_label = countryLabel;
                if (docTypeLabel) extra.doc_type_label = docTypeLabel;
                openMetric(metric, extra, (card.getAttribute('data-label') || '') + ' — ' + BU_SCOPUS_RD.labels.details);
            });
        });

        if (searchBtn) searchBtn.addEventListener('click', loadDetails);
        if (resetBtn) resetBtn.addEventListener('click', function(){ currentExtra = {}; resetFilters(false); loadDetails(); });
        if (closeBtn) closeBtn.addEventListener('click', function(){ if (modal) modal.style.display = 'none'; });
        if (detailCloseBtn) detailCloseBtn.addEventListener('click', function(){ if (detailModal) detailModal.style.display = 'none'; });

        window.addEventListener('click', function(e){
            if (e.target === modal && modal) modal.style.display = 'none';
            if (e.target === detailModal && detailModal) detailModal.style.display = 'none';
        });

        if (updateBtn) {
            updateBtn.addEventListener('click', function(){
                const controller = new AbortController();
                const jobId = 'job_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
                loader.show(BU_SCOPUS_RD.labels.updatingData, BU_SCOPUS_RD.labels.progressReady, function(){ controller.abort(); });
                const poll = setInterval(function(){
                    const q = new URLSearchParams();
                    q.set('action','bu_scopus_rd_progress');
                    q.set('_ajax_nonce', BU_SCOPUS_RD.nonce);
                    q.set('job_id', jobId);
                    fetch(BU_SCOPUS_RD.ajax_url, {
                        method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: q.toString()
                    }).then(r => r.json()).then(function(res){
                        if (!res || !res.ok) return;
                        const d = res.data || {};
                        loader.update(d.percent || 0, d.message || BU_SCOPUS_RD.labels.pleaseWait, BU_SCOPUS_RD.labels.updatingData);
                        if (d.done) clearInterval(poll);
                    }).catch(function(){});
                }, 700);

                const params = new URLSearchParams();
                params.set('action','bu_scopus_rd_update_cache');
                params.set('_ajax_nonce', BU_SCOPUS_RD.nonce);
                params.set('job_id', jobId);

                fetch(BU_SCOPUS_RD.ajax_url, {
                    method:'POST', signal: controller.signal,
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: params.toString()
                }).then(r => r.json()).then(function(res){
                    clearInterval(poll);
                    loader.update(100, (res && res.message) ? res.message : BU_SCOPUS_RD.labels.updateDone, BU_SCOPUS_RD.labels.updatingData);
                    setTimeout(function(){ loader.hide(); window.location.reload(); }, 600);
                }).catch(function(err){
                    clearInterval(poll);
                    loader.hide();
                    if (err && err.name === 'AbortError') return;
                    alert('Update failed.');
                });
            });
        }

        if (exportBtn) exportBtn.addEventListener('click', function(){
            window.location.href = BU_SCOPUS_RD.ajax_url + '?action=bu_scopus_rd_export_csv&_ajax_nonce=' + encodeURIComponent(BU_SCOPUS_RD.nonce);
        });

                document.addEventListener('click', function(event){
            const opener = event.target.closest('[data-bu-rd-open="1"]');
            if (opener) {
                const metric = opener.getAttribute('data-metric') || '';
                if (!metric) return;
                let extra = {};
                const rawExtra = opener.getAttribute('data-extra') || '';
                if (rawExtra) {
                    try { extra = JSON.parse(rawExtra); } catch(e) { extra = {}; }
                }
                const heading = opener.getAttribute('data-heading') || ((opener.textContent || '').trim() + ' — ' + BU_SCOPUS_RD.labels.details);
                const presetYear = extra.year_only || '';
                openMetric(metric, extra, heading, presetYear);
                return;
            }

            const titleCell = event.target.closest('.bu-rd-title-cell');
            if (!titleCell) return;
            const row = titleCell.closest('.bu-rd-row');
            if (!row) return;
            openDetail(row);
        });

        if (typeof Chart !== 'undefined' && sdgChart) {
            let sdgLabels = [], sdgCounts = [];
            try {
                sdgLabels = JSON.parse(sdgChart.getAttribute('data-labels') || '[]') || [];
                sdgCounts = JSON.parse(sdgChart.getAttribute('data-counts') || '[]') || [];
            } catch(e) {}
            if (sdgLabels.length > 0) {
                const sdgCtx = sdgChart.getContext('2d');
                new Chart(sdgCtx, {
                    type: 'bar',
                    data: { labels: sdgLabels, datasets: [{ data: sdgCounts, borderWidth: 0, borderRadius: 8, backgroundColor: colorPalette(sdgLabels.length) }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0, color: '#000000', font: { size: 12, weight: '800' } } }, y: { ticks: { color: '#000000', font: { size: 11, weight: '800' } } } } }
                });
            }
        }

        if (typeof Chart !== 'undefined' && subjectChart) {
            let subjectLabels = [], subjectCounts = [], subjectCodes = [];
            try {
                subjectLabels = JSON.parse(subjectChart.getAttribute('data-labels') || '[]') || [];
                subjectCounts = JSON.parse(subjectChart.getAttribute('data-counts') || '[]') || [];
                subjectCodes = JSON.parse(subjectChart.getAttribute('data-codes') || '[]') || [];
            } catch(e) {}
            if (subjectLabels.length > 0) {
                const subjectCtx = subjectChart.getContext('2d');
                const clickMetric = subjectChart.getAttribute('data-click-metric') || 'subject_publications';
                const subjectValuePlugin = {
                    id: 'subjectValuePlugin',
                    afterDatasetsDraw(chart){
                        const ctx = chart.ctx; ctx.save(); ctx.font = '700 11px sans-serif'; ctx.fillStyle = '#111827'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
                        const meta = chart.getDatasetMeta(0);
                        meta.data.forEach(function(bar, index){
                            const value = subjectCounts[index] || 0;
                            ctx.fillText(String(value), bar.x + 8, bar.y);
                        });
                        ctx.restore();
                    }
                };
                new Chart(subjectCtx, {
                    type: 'bar',
                    data: { labels: subjectLabels, datasets: [{ data: subjectCounts, borderWidth: 0, borderRadius: 8, backgroundColor: colorPalette(subjectLabels.length) }] },
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                        scales: { x: { beginAtZero: true, ticks: { precision: 0, color: '#111827', font: { size: 12, weight: '800' } } }, y: { ticks: { color: '#111827', font: { size: 11, weight: '800' } } } },
                        onClick: function(evt, elements){
                            if (!elements || !elements.length) return;
                            const index = elements[0].index;
                            const label = subjectLabels[index] || '';
                            const code = subjectCodes[index] || '';
                            if (!label) return;
                            const extra = clickMetric === 'manual_subject_area' ? { manual_subject_label: label } : { subject_label: label, subject_code: code };
                            openMetric(clickMetric, extra, label + ' — ' + BU_SCOPUS_RD.labels.details);
                        }
                    },
                    plugins: [subjectValuePlugin]
                });
            }
        }

        if (typeof Chart !== 'undefined' && citationTrendChart) {
            let labels = [], counts = [];
            try {
                labels = JSON.parse(citationTrendChart.getAttribute('data-labels') || '[]') || [];
                counts = JSON.parse(citationTrendChart.getAttribute('data-counts') || '[]') || [];
            } catch(e) {}
            if (labels.length > 0) {
                const ctx = citationTrendChart.getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: [{ label: 'Citations', data: counts, tension: 0.35, fill: false, borderWidth: 3, pointRadius: 4, pointHoverRadius: 5 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, color: '#111827', font: { size: 12, weight: '700' } } },
                            x: { ticks: { color: '#111827', font: { size: 11, weight: '700' } } }
                        }
                    }
                });
            }
        }

        if (typeof Chart !== 'undefined' && docTypeChart) {
            let labels = [], counts = [];
            try {
                labels = JSON.parse(docTypeChart.getAttribute('data-labels') || '[]') || [];
                counts = JSON.parse(docTypeChart.getAttribute('data-counts') || '[]') || [];
            } catch(e) {}
            if (labels.length > 0) {
                const ctx = docTypeChart.getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: { labels: labels, datasets: [{ data: counts, borderWidth: 2, backgroundColor: colorPalette(labels.length) }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { color: '#111827', font: { size: 11, weight: '700' } } } },
                        onClick: function(evt, elements){
                            if (!elements || !elements.length) return;
                            const index = elements[0].index;
                            const label = labels[index] || '';
                            if (!label) return;
                            openMetric('document_type_publications', { doc_type_label: label }, label + ' — ' + BU_SCOPUS_RD.labels.details);
                        }
                    }
                });
            }
        }

        if (typeof Chart !== 'undefined' && yearChart && years.length > 0) {
            const valueLabelPlugin = {
                id: 'valueLabelPlugin',
                afterDatasetsDraw(chart){
                    const ctx = chart.ctx; ctx.save(); ctx.font = '700 11px sans-serif'; ctx.fillStyle = '#111827'; ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
                    const meta = chart.getDatasetMeta(0);
                    meta.data.forEach(function(bar, index){ const value = counts[index] || 0; ctx.fillText(String(value), bar.x, bar.y - 6); });
                    ctx.restore();
                }
            };
            const ctx = yearChart.getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: { labels: years, datasets: [{ label: 'Publications', data: counts, borderWidth: 0, borderRadius: 10, backgroundColor: colorPalette(years.length), maxBarThickness: 48 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { autoSkip: false, maxRotation: 0, minRotation: 0 } } },
                    onClick: function(evt, elements){
                        if (!elements || !elements.length) return;
                        const index = elements[0].index; const year = years[index]; if (!year) return;
                        openMetric('year_publications', { year_only: String(year) }, year + ' — ' + BU_SCOPUS_RD.labels.pubDetails, year);
                    }
                }, plugins: [valueLabelPlugin]
            });
        }

        if (Number(BU_SCOPUS_RD.auto_refresh_minutes || 0) > 0) {
            setTimeout(function(){
                window.location.reload();
            }, Number(BU_SCOPUS_RD.auto_refresh_minutes) * 60 * 1000);
        }
    });
})();
JS;
        wp_add_inline_script('bu-scopus-rd-inline', $js);
    }

    public function shortcode_dashboard($atts = array()) {
        $data    = $this->get_data(false);
        $stats   = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
        $collabs = isset($stats['global_collaborations']) && is_array($stats['global_collaborations']) ? $stats['global_collaborations'] : array();

        $this->enqueue_dashboard_assets(false, $collabs);

        ob_start();
        $this->render_dashboard(false);
        return ob_get_clean();
    }

    public function page_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $uploads  = wp_upload_dir();

        $json_path = trailingslashit($uploads['basedir']) . trim($settings['json_dir'], '/');
        $csv_path  = trailingslashit($uploads['basedir']) . trim($settings['manual_csv'], '/');

        echo '<div class="wrap bu-rd-wrap">';
        echo '<h1 class="bu-rd-settings-title">' . esc_html__('BU Research Dashboard — Settings', 'bu-scopus-research-dashboard') . '</h1>';

        echo '<div class="bu-rd-card">';
        echo '<p><strong>' . esc_html__('JSON Fallback Path:', 'bu-scopus-research-dashboard') . '</strong><br><code>' . esc_html($json_path) . '</code></p>';
        echo '<p><strong>' . esc_html__('Manual CSV Path:', 'bu-scopus-research-dashboard') . '</strong><br><code>' . esc_html($csv_path) . '</code></p>';
        echo '</div>';

        echo '<form method="post" action="options.php">';
        settings_fields('bu_scopus_rd_group');
        do_settings_sections('bu-scopus-rd-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function page_manual_metrics() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = '';
        if (!empty($_POST['bu_scopus_rd_manual_metrics_submit'])) {
            check_admin_referer('bu_scopus_rd_manual_metrics_save');

            $metrics = $this->default_extra_metrics();

            $metrics['wos']['total_publications'] = isset($_POST['wos_total_publications']) ? max(0, intval(wp_unslash($_POST['wos_total_publications']))) : 0;
            $metrics['wos']['total_citations']    = isset($_POST['wos_total_citations']) ? max(0, intval(wp_unslash($_POST['wos_total_citations']))) : 0;
            $metrics['wos']['h_index']            = isset($_POST['wos_h_index']) ? max(0, intval(wp_unslash($_POST['wos_h_index']))) : 0;

            $metrics['research_areas']['scopus'] = $this->textarea_to_lines(isset($_POST['research_areas_scopus']) ? wp_unslash($_POST['research_areas_scopus']) : '');
            $metrics['research_areas']['wos']    = $this->textarea_to_lines(isset($_POST['research_areas_wos']) ? wp_unslash($_POST['research_areas_wos']) : '');

            $metrics['manual_subject_areas'] = $this->parse_subject_area_json(isset($_POST['manual_subject_areas_json']) ? wp_unslash($_POST['manual_subject_areas_json']) : '');
            $metrics['sdg']                  = $this->parse_sdg_json(isset($_POST['sdg_json']) ? wp_unslash($_POST['sdg_json']) : '');

            update_option(self::OPT_EXTRA_METRICS, $this->normalize_extra_metrics($metrics), false);
            $this->write_extra_metrics_json($metrics);

            $cache = get_option(self::OPT_CACHE, array());
            if (is_array($cache)) {
                $cache['extras'] = $this->read_extra_metrics($this->get_settings()['json_dir'] ?? 'scopus-mis-cache');
                update_option(self::OPT_CACHE, $cache, false);
            }

            $notice = __('Manual metrics saved successfully. Dashboard cache was refreshed for manual JSON sections.', 'bu-scopus-research-dashboard');
        }

        $settings        = $this->get_settings();
        $metrics         = $this->read_extra_metrics($settings['json_dir']);
        $wos             = isset($metrics['wos']) && is_array($metrics['wos']) ? $metrics['wos'] : array();
        $areas           = isset($metrics['research_areas']) && is_array($metrics['research_areas']) ? $metrics['research_areas'] : array();
        $sdg             = isset($metrics['sdg']) && is_array($metrics['sdg']) ? $metrics['sdg'] : array();
        $manual_subjects = isset($metrics['manual_subject_areas']) && is_array($metrics['manual_subject_areas']) ? $metrics['manual_subject_areas'] : array();

        if (empty($sdg)) {
            $sdg = $this->default_extra_metrics()['sdg'];
        }

        $subject_json_value = $this->pretty_json($manual_subjects);
        $sdg_json_value     = $this->pretty_json($sdg);

        $subject_example = wp_json_encode(array(
            array('label' => 'Computer Science', 'count' => 2950),
            array('label' => 'Engineering', 'count' => 2089),
            array('label' => 'Mathematics', 'count' => 898),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sdg_example = wp_json_encode(array(
            array('goal' => 'Goal 1: No poverty', 'documents' => 27),
            array('goal' => 'Goal 2: Zero hunger', 'documents' => 72),
            array('goal' => 'Goal 3: Good health and well-being', 'documents' => 481),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo '<div class="wrap bu-rd-wrap">';
        echo '<h1 class="bu-rd-settings-title">' . esc_html__('BU Research Dashboard — Manual Metrics', 'bu-scopus-research-dashboard') . '</h1>';

        if ($notice !== '') {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<form method="post" class="bu-rd-manual-form">';
        wp_nonce_field('bu_scopus_rd_manual_metrics_save');

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Web of Science KPIs', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-manual-grid">';
        echo '<div><label>' . esc_html__('Total Publications', 'bu-scopus-research-dashboard') . '</label><input type="number" min="0" name="wos_total_publications" value="' . esc_attr((int) ($wos['total_publications'] ?? 0)) . '" /></div>';
        echo '<div><label>' . esc_html__('Total Citations', 'bu-scopus-research-dashboard') . '</label><input type="number" min="0" name="wos_total_citations" value="' . esc_attr((int) ($wos['total_citations'] ?? 0)) . '" /></div>';
        echo '<div><label>' . esc_html__('h-index', 'bu-scopus-research-dashboard') . '</label><input type="number" min="0" name="wos_h_index" value="' . esc_attr((int) ($wos['h_index'] ?? 0)) . '" /></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Top Research Areas', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-manual-grid bu-rd-manual-grid-2">';
        echo '<div><label>' . esc_html__('Scopus Areas (one per line)', 'bu-scopus-research-dashboard') . '</label><textarea name="research_areas_scopus" rows="8">' . esc_textarea(implode("
", (array) ($areas['scopus'] ?? array()))) . '</textarea></div>';
        echo '<div><label>' . esc_html__('Web of Science Areas (one per line)', 'bu-scopus-research-dashboard') . '</label><textarea name="research_areas_wos" rows="8">' . esc_textarea(implode("
", (array) ($areas['wos'] ?? array()))) . '</textarea></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Subject Areas (JSON)', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<p class="description">' . esc_html__('Paste JSON here. You can use either an array of objects like [{"label":"Computer Science","count":2950}] or an object map like {"Computer Science":2950,"Engineering":2089}. This chart is shown just below Publication Trends.', 'bu-scopus-research-dashboard') . '</p>';
        echo '<textarea name="manual_subject_areas_json" rows="14" style="width:100%;font-family:monospace;">' . esc_textarea($subject_json_value) . '</textarea>';
        echo '<p class="description"><strong>' . esc_html__('Example JSON:', 'bu-scopus-research-dashboard') . '</strong></p>';
        echo '<textarea rows="8" readonly style="width:100%;font-family:monospace;opacity:.9;">' . esc_textarea((string) $subject_example) . '</textarea>';
        echo '</div>';

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('SDG Goal Metrics (JSON)', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<p class="description">' . esc_html__('Paste JSON here. You can use either an array like [{"goal":"Goal 1: No poverty","documents":27}] or an object map like {"Goal 1: No poverty":27}.', 'bu-scopus-research-dashboard') . '</p>';
        echo '<textarea name="sdg_json" rows="16" style="width:100%;font-family:monospace;">' . esc_textarea($sdg_json_value) . '</textarea>';
        echo '<p class="description"><strong>' . esc_html__('Example JSON:', 'bu-scopus-research-dashboard') . '</strong></p>';
        echo '<textarea rows="8" readonly style="width:100%;font-family:monospace;opacity:.9;">' . esc_textarea((string) $sdg_example) . '</textarea>';
        echo '</div>';

        echo '<p><button type="submit" name="bu_scopus_rd_manual_metrics_submit" value="1" class="button button-primary button-large">' . esc_html__('Save Manual Metrics', 'bu-scopus-research-dashboard') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public function page_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->render_dashboard(true);
    }

    private function render_dashboard($is_admin_page = false) {
        $settings = $this->get_settings();
        $data     = $this->get_data(false);

        if (empty($data['entries']) && !empty($settings['api_key']) && !empty($settings['afid'])) {
            $data = $this->get_data(true);
        }

        $aff             = isset($data['affiliation']) && is_array($data['affiliation']) ? $data['affiliation'] : array();
        $stats           = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
        $manual          = isset($data['manual']) && is_array($data['manual']) ? $data['manual'] : array();
        $extras          = isset($data['extras']) && is_array($data['extras']) ? $data['extras'] : array();
        $error           = isset($data['error']) ? $data['error'] : '';
        $years           = isset($stats['year_counts']) && is_array($stats['year_counts']) ? $stats['year_counts'] : array();
        $collabs         = isset($stats['global_collaborations']) && is_array($stats['global_collaborations']) ? $stats['global_collaborations'] : array();
        $top_auth        = isset($stats['top_authors']) && is_array($stats['top_authors']) ? $stats['top_authors'] : array();
        $top_jour        = isset($stats['top_journals']) && is_array($stats['top_journals']) ? $stats['top_journals'] : array();
        $doc_types       = isset($stats['document_types']) && is_array($stats['document_types']) ? $stats['document_types'] : array();
        $wos             = isset($extras['wos']) && is_array($extras['wos']) ? $extras['wos'] : array();
        $sdg             = isset($extras['sdg']) && is_array($extras['sdg']) ? $extras['sdg'] : array();
        $areas           = isset($extras['research_areas']) && is_array($extras['research_areas']) ? $extras['research_areas'] : array();
        $manual_subjects = isset($extras['manual_subject_areas']) && is_array($extras['manual_subject_areas']) ? $extras['manual_subject_areas'] : array();
        $dspace_total    = (int) ($stats['dspace_theses_total'] ?? 0);

        $subject_area_counts = array();
        if (!empty($manual_subjects)) {
            foreach ($manual_subjects as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                if ($label === '') continue;
                $subject_area_counts[$label] = max(0, (int) ($row['count'] ?? 0));
            }
        } elseif (!empty($stats['subject_counts']) && is_array($stats['subject_counts'])) {
            $subject_area_counts = $stats['subject_counts'];
        }

        $subject_area_total = count($subject_area_counts);
        $institution_g_index_value = (int) ($stats['institution_g_index'] ?? 0);
        if ($institution_g_index_value <= 0 && !empty($data['entries']) && is_array($data['entries'])) {
            $institution_g_index_value = (int) $this->calculate_g_index($data['entries']);
            $stats['institution_g_index'] = $institution_g_index_value;
        }

        $institution_label = trim((string) ($settings['institution_label'] ?? ''));
        if ($institution_label === '') {
            $institution_label = 'Bennett University';
        }

        $cache_date   = (int) get_option(self::OPT_CACHE_DATE, 0);
        $recent_limit = max(0, (int) ($settings['recent_publications_limit'] ?? 6));

        $wrap_class = $is_admin_page ? 'wrap bu-rd-wrap' : 'bu-rd-wrap bu-rd-front-wrap';

        echo '<div class="' . esc_attr($wrap_class) . '">';
        echo '<div class="bu-rd-header-wrap">';
        echo '<img class="bu-rd-logo" src="' . esc_url($this->logo_url) . '" alt="' . esc_attr__('BU Research Analytics Dashboard Logo', 'bu-scopus-research-dashboard') . '" />';
        echo '</div>';

        $this->render_dashboard_overview($data, $stats, $settings, $cache_date, $error);

        if ($error) {
            $notice_class = $is_admin_page ? 'notice notice-warning' : 'bu-rd-front-notice';
            echo '<div class="' . esc_attr($notice_class) . '"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<div class="bu-rd-card bu-rd-affiliation">';
        echo '<div class="bu-rd-aff-grid">';
        echo '<div>';
        echo '<div class="bu-rd-aff-name">' . esc_html($institution_label) . '</div>';
        if (!empty($aff['address'])) {
            echo '<div class="bu-rd-aff-meta">' . esc_html($aff['address']) . '</div>';
        }
        $loc = trim((string) ($aff['city'] ?? ''));
        if (!empty($aff['country'])) {
            $loc .= ($loc !== '' ? ', ' : '') . (string) $aff['country'];
        }
        if ($loc !== '') {
            echo '<div class="bu-rd-aff-meta">' . esc_html($loc) . '</div>';
        }
        echo '</div>';
        echo '<div class="bu-rd-badge"><span>' . esc_html__('AF-ID', 'bu-scopus-research-dashboard') . '</span><strong>' . esc_html($this->clean_affiliation_id($aff['afid'] ?? ($settings['afid'] ?? '60121496'))) . '</strong></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bu-rd-grid">';
        $this->kpi_card(__('Total Publications', 'bu-scopus-research-dashboard'), $stats['total_publications'] ?? 0, 'total_publications', 'border1');
        $this->kpi_card(__('Total Authors', 'bu-scopus-research-dashboard'), $stats['total_authors'] ?? 0, 'total_authors', 'border2');
        $this->kpi_card(__('Open Access', 'bu-scopus-research-dashboard'), $stats['open_access_publications'] ?? 0, 'open_access_publications', 'border3');
        $this->kpi_card(__('Total Citations', 'bu-scopus-research-dashboard'), $stats['total_citations'] ?? 0, 'total_citations', 'border4');
        $this->kpi_card(__('Institution h-index', 'bu-scopus-research-dashboard'), $stats['institution_h_index'] ?? 0, 'institution_h_index', 'border5');
        $this->kpi_card(__('Institution G-index', 'bu-scopus-research-dashboard'), $institution_g_index_value, 'institution_g_index', 'border9');
        $this->kpi_card(__('Institution i10-index', 'bu-scopus-research-dashboard'), $stats['institution_i10_index'] ?? 0, 'institution_i10_index', 'border6');
        $this->kpi_card(__('Student Theses', 'bu-scopus-research-dashboard'), $dspace_total, 'dspace_theses', 'border1');
        $this->kpi_card(__('Avg Citations / Paper', 'bu-scopus-research-dashboard'), $stats['avg_citations_per_paper'] ?? 0, 'avg_citations_per_paper', 'border7');
        $this->kpi_card(__('Top Journal Count', 'bu-scopus-research-dashboard'), $stats['top_journal_count'] ?? 0, 'top_journals', 'border8');
        $this->kpi_card(__('Publication Years', 'bu-scopus-research-dashboard'), $stats['year_count'] ?? 0, 'year_breakdown', 'border2');
        $this->kpi_card(__('Subject Areas', 'bu-scopus-research-dashboard'), $subject_area_total, 'subject_counts', 'border5');
        $this->kpi_card(__('Document Types', 'bu-scopus-research-dashboard'), count($doc_types), 'document_type_summary', 'border3');
        $this->kpi_card(__('Top Authors', 'bu-scopus-research-dashboard'), implode(', ', array_slice($this->safe_preview_labels(array_keys($top_auth)), 0, 3)), 'top_authors', 'border6', false);
        $this->kpi_card(__('Top Journals', 'bu-scopus-research-dashboard'), implode(', ', array_slice($this->safe_preview_labels(array_keys($top_jour)), 0, 3)), 'top_journals', 'border8', false);
        echo '</div>';

        $this->render_dashboard_quick_actions($stats, $years);
        $this->render_dashboard_insights($stats, $subject_area_counts, $doc_types, $collabs);

        $this->render_sdg_chart($sdg);

        if (!empty($years)) {
            echo '<div class="bu-rd-card">';
            echo '<h2>' . esc_html__('Publication Trends', 'bu-scopus-research-dashboard') . '</h2>';
            echo '<div class="bu-rd-chart-wrap">';
            echo '<canvas id="bu-rd-year-chart" height="120" data-years="' . esc_attr(wp_json_encode(array_keys($years))) . '" data-counts="' . esc_attr(wp_json_encode(array_values($years))) . '"></canvas>';
            echo '</div>';
            echo '</div>';
        }

        $this->render_secondary_analytics($data['entries'] ?? array(), $doc_types);
        $this->render_recent_publications($data['entries'] ?? array(), $recent_limit);
        $this->render_subject_chart($subject_area_counts, array(), !empty($manual_subjects) ? 'manual_subject_area' : 'subject_publications');
        $this->render_wos_kpis($wos);
        $this->render_research_areas_section($areas);

        if (!empty($collabs)) {
            echo '<section id="collab" class="bu-rd-card">';
            echo '<h2>' . esc_html__('Global Collaborations', 'bu-scopus-research-dashboard') . '</h2>';
            echo '<div id="bpt-world" style="width:100%;height:420px"></div>';
            echo '</section>';
        }

        if (!empty($manual)) {
            $this->render_manual_sections($manual);
        }

        if ($is_admin_page) {
            echo '<div class="bu-rd-card">';
            echo '<h2>' . esc_html__('Actions', 'bu-scopus-research-dashboard') . '</h2>';
            echo '<p><button type="button" class="mis-btn secondary" id="bu-rd-update-cache-btn">' . esc_html__('Update Data', 'bu-scopus-research-dashboard') . '</button> ';
            echo '<button type="button" class="mis-btn" id="bu-rd-export-btn">' . esc_html__('Export CSV', 'bu-scopus-research-dashboard') . '</button></p>';
            echo '<p class="description">' . esc_html__('Update Data refreshes live Scopus API cache and DSpace thesis cache. Export CSV downloads normalized publication data currently stored in cache.', 'bu-scopus-research-dashboard') . '</p>';
            echo '</div>';
        }

        $this->render_dashboard_footer();

        echo '<div id="bu-rd-modal" class="bu-rd-modal" style="display:none;">';
        echo '<div class="bu-rd-modal-inner">';
        echo '<button type="button" class="bu-rd-close" id="bu-rd-close">&times;</button>';
        echo '<h2 id="bu-rd-modal-heading">' . esc_html__('Detailed Statistics', 'bu-scopus-research-dashboard') . '</h2>';

        echo '<div class="bu-rd-filter-panel">';
        echo '<div class="bu-rd-filters">';
        echo '<div class="field"><label>' . esc_html__('Author', 'bu-scopus-research-dashboard') . '</label><input type="text" id="bu-rd-author" class="regular-text" placeholder="' . esc_attr__('Search author', 'bu-scopus-research-dashboard') . '" /></div>';
        echo '<div class="field"><label>' . esc_html__('Title / Journal', 'bu-scopus-research-dashboard') . '</label><input type="text" id="bu-rd-title" class="regular-text" placeholder="' . esc_attr__('Search title or journal', 'bu-scopus-research-dashboard') . '" /></div>';
        echo '<div class="field"><label>' . esc_html__('Year', 'bu-scopus-research-dashboard') . '</label><input type="text" id="bu-rd-year" class="regular-text" placeholder="' . esc_attr__('e.g. 2025', 'bu-scopus-research-dashboard') . '" /></div>';
        echo '<div class="field"><label>' . esc_html__('Access', 'bu-scopus-research-dashboard') . '</label><select id="bu-rd-access" class="regular-text"><option value="">' . esc_html__('All', 'bu-scopus-research-dashboard') . '</option><option value="open">' . esc_html__('Open Access', 'bu-scopus-research-dashboard') . '</option><option value="closed">' . esc_html__('Closed Access', 'bu-scopus-research-dashboard') . '</option></select></div>';
        echo '<div class="field"><label>' . esc_html__('Document Type', 'bu-scopus-research-dashboard') . '</label><input type="text" id="bu-rd-doc-type" class="regular-text" placeholder="' . esc_attr__('e.g. Article', 'bu-scopus-research-dashboard') . '" /></div>';
        echo '<div class="field"><label>' . esc_html__('Year Sort', 'bu-scopus-research-dashboard') . '</label><select id="bu-rd-sort-year" class="regular-text"><option value="desc">' . esc_html__('Newest ↓', 'bu-scopus-research-dashboard') . '</option><option value="asc">' . esc_html__('Oldest ↑', 'bu-scopus-research-dashboard') . '</option></select></div>';
        echo '<div class="field"><label>' . esc_html__('Citation Sort', 'bu-scopus-research-dashboard') . '</label><select id="bu-rd-sort-citations" class="regular-text"><option value="none">' . esc_html__('None', 'bu-scopus-research-dashboard') . '</option><option value="desc">' . esc_html__('High ↓', 'bu-scopus-research-dashboard') . '</option><option value="asc">' . esc_html__('Low ↑', 'bu-scopus-research-dashboard') . '</option></select></div>';
        echo '</div>';
        echo '<div class="bu-rd-filter-actions">';
        echo '<button type="button" class="mis-btn" id="bu-rd-search">' . esc_html__('Search', 'bu-scopus-research-dashboard') . '</button>';
        echo '<button type="button" class="mis-btn secondary" id="bu-rd-reset">' . esc_html__('Reset', 'bu-scopus-research-dashboard') . '</button>';
        echo '</div>';
        echo '</div>';

        echo '<div id="bu-rd-summary" class="bu-rd-summary-grid"></div>';
        echo '<div id="bu-rd-content" class="bu-rd-table-wrap"><p>' . esc_html__('Click any colourful box to open details.', 'bu-scopus-research-dashboard') . '</p></div>';
        echo '</div>';
        echo '</div>';

        echo '<div id="bu-rd-detail-modal" class="bu-rd-modal" style="display:none;">';
        echo '<div class="bu-rd-modal-inner bu-rd-detail-modal-inner">';
        echo '<button type="button" class="bu-rd-close" id="bu-rd-detail-close">&times;</button>';
        echo '<h2 id="bu-rd-detail-title">' . esc_html__('Article Details', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div id="bu-rd-detail-meta"></div>';
        echo '<div id="bu-rd-detail-abstract-wrap">';
        echo '<h3>' . esc_html__('Abstract', 'bu-scopus-research-dashboard') . '</h3>';
        echo '<div id="bu-rd-detail-abstract" class="bu-rd-abstract-box"></div>';
        echo '</div>';
        echo '<div id="bu-rd-detail-plumx-wrap" style="display:none;">';
        echo '<h3>' . esc_html__('PlumX Metrics', 'bu-scopus-research-dashboard') . '</h3>';
        echo '<div id="bu-rd-detail-plumx-box" class="bu-rd-plumx-box"></div>';
        echo '</div>';
        echo '<p><a id="bu-rd-detail-open" class="mis-btn bu-rd-small-btn" href="#" target="_blank" rel="noopener noreferrer">' . esc_html__('Open in Scopus', 'bu-scopus-research-dashboard') . '</a></p>';
        echo '</div>';
        echo '</div>';

        echo '<div id="bu-rd-loader" class="bu-rd-loader-backdrop" style="display:none;">';
        echo '<div class="bu-rd-loader-box">';
        echo '<div class="bu-rd-loader-orbs"><span></span><span></span><span></span></div>';
        echo '<h3 id="bu-rd-loader-title">' . esc_html__('Loading', 'bu-scopus-research-dashboard') . '</h3>';
        echo '<p id="bu-rd-loader-text">' . esc_html__('Please wait while data is being fetched.', 'bu-scopus-research-dashboard') . '</p>';
        echo '<div class="bu-rd-loader-progress"><i id="bu-rd-loader-bar"></i></div>';
        echo '<div class="bu-rd-loader-meta"><strong id="bu-rd-loader-pct">0%</strong><span class="bu-rd-loader-time-label">' . esc_html__('Elapsed', 'bu-scopus-research-dashboard') . ': <b id="bu-rd-loader-timer">0s</b></span></div>';
        echo '<div class="bu-rd-loader-actions"><button type="button" id="bu-rd-loader-cancel" class="mis-btn secondary bu-rd-loader-cancel">' . esc_html__('Cancel', 'bu-scopus-research-dashboard') . '</button></div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }


    private function kpi_card($label, $value, $metric, $color_class, $format_number = true) {
        if ($format_number && is_numeric($value)) {
            $number = (float) $value;
            $display = floor($number) != $number ? number_format_i18n($number, 2) : number_format_i18n((int) $number);
        } else {
            $display = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        if ($display === '') {
            $display = '—';
        }

        $display_len = function_exists('mb_strlen') ? mb_strlen($display) : strlen($display);
        $label_len   = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);

        $label_class = 'kpi-label';
        $value_class = 'kpi-value';

        if ($label_len > 18) {
            $label_class .= ' kpi-label-compact';
        }
        if ($display_len > 36) {
            $value_class .= ' kpi-value-compact';
        } elseif ($display_len > 20) {
            $value_class .= ' kpi-value-medium';
        }

        $is_clickable = ($metric !== 'manual_metric');
        $attrs = $is_clickable
            ? ' data-metric="' . esc_attr($metric) . '" data-label="' . esc_attr($label) . '"'
            : ' data-metric="" data-label="' . esc_attr($label) . '"';

        echo '<div class="bu-rd-kpi ' . esc_attr($color_class) . ($is_clickable ? '' : ' bu-rd-kpi-static') . '"' . $attrs . '>';
        echo '<span class="mis-kpi-chip">' . esc_html($this->card_chip($label)) . '</span>';
        echo '<div class="' . esc_attr($label_class) . '">' . esc_html($label) . '</div>';
        echo '<div class="' . esc_attr($value_class) . '">' . esc_html($display) . '</div>';
        if ($is_clickable) {
            echo '<div class="mis-kpi-foot"><span>' . esc_html__('Click for details', 'bu-scopus-research-dashboard') . '</span></div>';
        }
        echo '</div>';
    }

    private function card_chip($label) {
        $map = array(
            'Total Publications'    => '📚',
            'Total Authors'         => '👥',
            'Open Access'           => '🔓',
            'Total Citations'       => '🔗',
            'Institution h-index'   => '📈',
            'Institution G-index'   => '📐',
            'Institution i10-index' => '🏅',
            'Student Theses' => '🎓',
            'Avg Citations / Paper' => '🧮',
                        'Top Journal Count'     => '📰',
            'Publication Years'     => '🗓️',
            'Subject Areas'         => '🧠',
                        'Document Types'        => '🧾',
            'Top Authors'           => '✍️',
            'Top Journals'          => '🏛️',
            'Web of Science Publications' => '🌐',
            'Web of Science Total Publications' => '🌐',
            'Web of Science Citations'    => '📊',
            'Web of Science Total Citations' => '📊',
            'Web of Science h-index'      => '🏆',
            'SDG Goals Covered'           => '🎯',
        );

        return $map[$label] ?? '•';
    }


    private function render_dashboard_overview($data, $stats, $settings, $cache_date, $error = '') {
        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : array();
        $has_api = !empty($settings['api_key']) && !empty($settings['afid']);
        $source_label = $has_api && empty($error)
            ? __('Live Scopus + Cache', 'bu-scopus-research-dashboard')
            : __('Cached / Fallback Mode', 'bu-scopus-research-dashboard');

        $fresh_state = 'fresh';
        if ($cache_date <= 0) {
            $fresh_state = 'stale';
        } elseif ((time() - $cache_date) > (24 * HOUR_IN_SECONDS)) {
            $fresh_state = 'stale';
        } elseif ((time() - $cache_date) > (6 * HOUR_IN_SECONDS)) {
            $fresh_state = 'warm';
        }

        $last_updated = $cache_date > 0
            ? sprintf(
                __('Updated %1$s ago • %2$s', 'bu-scopus-research-dashboard'),
                human_time_diff($cache_date, time()),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $cache_date)
            )
            : __('No cache timestamp available yet.', 'bu-scopus-research-dashboard');

        $oa_total = (int) ($stats['open_access_publications'] ?? 0);
        $total_publications = max(0, (int) ($stats['total_publications'] ?? count($entries)));
        $oa_rate = $total_publications > 0 ? round(($oa_total / $total_publications) * 100, 1) : 0;
        $dspace_total = (int) ($stats['dspace_theses_total'] ?? 0);
        $manual_sections = isset($data['manual']) && is_array($data['manual']) ? count($data['manual']) : 0;

        echo '<div class="bu-rd-overview-card bu-rd-card">';
        echo '<div class="bu-rd-overview-top">';
        echo '<div class="bu-rd-overview-copy">';
        echo '<div class="bu-rd-overview-kicker">' . esc_html__('Research Analytics Overview', 'bu-scopus-research-dashboard') . '</div>';
        echo '<h2>' . esc_html__('Automated, live, and insight-driven research dashboard', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<p>' . esc_html__('This view combines Scopus, DSpace thesis counts, and manual enrichment sections into one decision-friendly dashboard.', 'bu-scopus-research-dashboard') . '</p>';
        echo '</div>';
        echo '<div class="bu-rd-overview-badges">';
        echo '<span class="bu-rd-status-pill bu-rd-status-pill-' . esc_attr($fresh_state) . '">' . esc_html($source_label) . '</span>';
        echo '<span class="bu-rd-status-pill bu-rd-status-pill-neutral">' . esc_html($last_updated) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bu-rd-overview-metrics">';
        echo '<div class="bu-rd-overview-box"><strong>' . esc_html(number_format_i18n($total_publications)) . '</strong><span>' . esc_html__('Normalized records in dashboard', 'bu-scopus-research-dashboard') . '</span></div>';
        echo '<div class="bu-rd-overview-box"><strong>' . esc_html(number_format_i18n($oa_total)) . ' / ' . esc_html($oa_rate) . '%</strong><span>' . esc_html__('Open access strength', 'bu-scopus-research-dashboard') . '</span></div>';
        echo '<div class="bu-rd-overview-box"><strong>' . esc_html(number_format_i18n($dspace_total)) . '</strong><span>' . esc_html__('Live DSpace theses linked', 'bu-scopus-research-dashboard') . '</span></div>';
        echo '<div class="bu-rd-overview-box"><strong>' . esc_html(number_format_i18n($manual_sections)) . '</strong><span>' . esc_html__('Manual metric sections loaded', 'bu-scopus-research-dashboard') . '</span></div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_dashboard_quick_actions($stats, $years) {
        $current_year = (int) gmdate('Y');
        $current_year_total = isset($years[(string) $current_year]) ? (int) $years[(string) $current_year] : 0;
        $buttons = array(
            array(
                'label'   => sprintf(__('Latest Year (%d)', 'bu-scopus-research-dashboard'), $current_year),
                'metric'  => 'year_publications',
                'heading' => sprintf(__('%d — Publication Details', 'bu-scopus-research-dashboard'), $current_year),
                'extra'   => array('year_only' => (string) $current_year),
                'count'   => $current_year_total,
            ),
            array(
                'label'   => __('Open Access Papers', 'bu-scopus-research-dashboard'),
                'metric'  => 'open_access_publications',
                'heading' => __('Open Access — Details', 'bu-scopus-research-dashboard'),
                'extra'   => array(),
                'count'   => (int) ($stats['open_access_publications'] ?? 0),
            ),
            array(
                'label'   => __('Highly Cited (25+)', 'bu-scopus-research-dashboard'),
                'metric'  => 'total_publications',
                'heading' => __('Highly Cited Publications — Details', 'bu-scopus-research-dashboard'),
                'extra'   => array('citation_min' => 25),
                'count'   => '',
            ),
            array(
                'label'   => __('Article Records', 'bu-scopus-research-dashboard'),
                'metric'  => 'document_type_publications',
                'heading' => __('Articles — Details', 'bu-scopus-research-dashboard'),
                'extra'   => array('doc_type_label' => 'Article'),
                'count'   => '',
            ),
            array(
                'label'   => __('Student Theses', 'bu-scopus-research-dashboard'),
                'metric'  => 'dspace_theses',
                'heading' => __('Student Theses — Details', 'bu-scopus-research-dashboard'),
                'extra'   => array(),
                'count'   => (int) ($stats['dspace_theses_total'] ?? 0),
            ),
        );

        echo '<div class="bu-rd-card">';
        echo '<div class="bu-rd-flex-head">';
        echo '<h2>' . esc_html__('Quick Dynamic Views', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<p class="description">' . esc_html__('Use these smart shortcuts to open the most useful filtered views in one click.', 'bu-scopus-research-dashboard') . '</p>';
        echo '</div>';
        echo '<div class="bu-rd-quick-actions">';
        foreach ($buttons as $button) {
            echo '<button type="button" class="bu-rd-quick-chip" data-bu-rd-open="1" data-metric="' . esc_attr($button['metric']) . '" data-heading="' . esc_attr($button['heading']) . '" data-extra="' . esc_attr(wp_json_encode($button['extra'])) . '">';
            echo '<span>' . esc_html($button['label']) . '</span>';
            if ($button['count'] !== '') {
                echo '<strong>' . esc_html(number_format_i18n((int) $button['count'])) . '</strong>';
            }
            echo '</button>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_dashboard_insights($stats, $subject_area_counts, $doc_types, $collabs) {
        $best_year = '';
        $best_year_count = 0;
        foreach ((array) ($stats['year_counts'] ?? array()) as $year => $count) {
            if ((int) $count > $best_year_count) {
                $best_year = (string) $year;
                $best_year_count = (int) $count;
            }
        }

        $top_subject = !empty($subject_area_counts) ? (string) array_key_first($subject_area_counts) : __('N/A', 'bu-scopus-research-dashboard');
        $top_subject_count = !empty($subject_area_counts) ? (int) reset($subject_area_counts) : 0;
        $top_doc_type = !empty($doc_types) ? (string) array_key_first($doc_types) : __('N/A', 'bu-scopus-research-dashboard');
        $top_doc_type_count = !empty($doc_types) ? (int) reset($doc_types) : 0;
        $top_country = !empty($collabs) ? (string) array_key_first($collabs) : __('N/A', 'bu-scopus-research-dashboard');
        $top_country_count = !empty($collabs) ? (int) reset($collabs) : 0;
        $avg = (float) ($stats['avg_citations_per_paper'] ?? 0);
        $cited = (int) ($stats['cited_papers'] ?? 0);
        $uncited = (int) ($stats['uncited_papers'] ?? 0);

        $cards = array(
            array('label' => __('Best publication year', 'bu-scopus-research-dashboard'), 'value' => $best_year !== '' ? $best_year : '—', 'meta' => $best_year_count > 0 ? sprintf(__('%d papers', 'bu-scopus-research-dashboard'), $best_year_count) : __('No data', 'bu-scopus-research-dashboard')),
            array('label' => __('Top subject area', 'bu-scopus-research-dashboard'), 'value' => $top_subject, 'meta' => $top_subject_count > 0 ? sprintf(__('%d papers', 'bu-scopus-research-dashboard'), $top_subject_count) : __('No data', 'bu-scopus-research-dashboard')),
            array('label' => __('Top document type', 'bu-scopus-research-dashboard'), 'value' => $top_doc_type, 'meta' => $top_doc_type_count > 0 ? sprintf(__('%d records', 'bu-scopus-research-dashboard'), $top_doc_type_count) : __('No data', 'bu-scopus-research-dashboard')),
            array('label' => __('Top collaboration country', 'bu-scopus-research-dashboard'), 'value' => $top_country, 'meta' => $top_country_count > 0 ? sprintf(__('%d linked papers', 'bu-scopus-research-dashboard'), $top_country_count) : __('No data', 'bu-scopus-research-dashboard')),
            array('label' => __('Average citations per paper', 'bu-scopus-research-dashboard'), 'value' => number_format_i18n($avg, 2), 'meta' => __('Performance intensity', 'bu-scopus-research-dashboard')),
            array('label' => __('Cited vs uncited papers', 'bu-scopus-research-dashboard'), 'value' => number_format_i18n($cited) . ' / ' . number_format_i18n($uncited), 'meta' => __('Cited / uncited', 'bu-scopus-research-dashboard')),
        );

        echo '<div class="bu-rd-card">';
        echo '<div class="bu-rd-flex-head">';
        echo '<h2>' . esc_html__('Auto Insights', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<p class="description">' . esc_html__('These cards turn raw counts into faster management insights for review meetings and reports.', 'bu-scopus-research-dashboard') . '</p>';
        echo '</div>';
        echo '<div class="bu-rd-insight-grid">';
        foreach ($cards as $card) {
            echo '<div class="bu-rd-insight-card">';
            echo '<span>' . esc_html($card['label']) . '</span>';
            echo '<strong>' . esc_html($card['value']) . '</strong>';
            echo '<small>' . esc_html($card['meta']) . '</small>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_secondary_analytics($entries, $doc_types) {
        $entries = is_array($entries) ? $entries : array();

        $citations_by_year = array();
        foreach ($entries as $row) {
            $year = trim((string) ($row['Year'] ?? ''));
            if ($year === '') {
                continue;
            }
            if (!isset($citations_by_year[$year])) {
                $citations_by_year[$year] = 0;
            }
            $citations_by_year[$year] += (int) ($row['Citations'] ?? 0);
        }
        ksort($citations_by_year);

        $top_doc_types = array_slice((array) $doc_types, 0, 8, true);

        if (empty($citations_by_year) && empty($top_doc_types)) {
            return;
        }

        echo '<div class="bu-rd-analytics-grid">';
        if (!empty($citations_by_year)) {
            echo '<div class="bu-rd-card">';
            echo '<h2>' . esc_html__('Citations by Year', 'bu-scopus-research-dashboard') . '</h2>';
            echo '<div class="bu-rd-chart-wrap bu-rd-medium-chart">';
            echo '<canvas id="bu-rd-citation-trend-chart" height="180" data-labels="' . esc_attr(wp_json_encode(array_keys($citations_by_year))) . '" data-counts="' . esc_attr(wp_json_encode(array_values($citations_by_year))) . '"></canvas>';
            echo '</div>';
            echo '</div>';
        }

        if (!empty($top_doc_types)) {
            echo '<div class="bu-rd-card">';
            echo '<h2>' . esc_html__('Document Type Mix', 'bu-scopus-research-dashboard') . '</h2>';
            echo '<div class="bu-rd-chart-wrap bu-rd-medium-chart">';
            echo '<canvas id="bu-rd-doc-type-chart" height="180" data-labels="' . esc_attr(wp_json_encode(array_keys($top_doc_types))) . '" data-counts="' . esc_attr(wp_json_encode(array_values($top_doc_types))) . '"></canvas>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_recent_publications($entries, $limit = 6) {
        $entries = is_array($entries) ? $entries : array();
        $limit = max(0, (int) $limit);
        if ($limit < 1 || empty($entries)) {
            return;
        }

        usort($entries, function($a, $b){
            $year_cmp = (int) ($b['Year'] ?? 0) <=> (int) ($a['Year'] ?? 0);
            if ($year_cmp !== 0) {
                return $year_cmp;
            }
            return (int) ($b['Citations'] ?? 0) <=> (int) ($a['Citations'] ?? 0);
        });

        $entries = array_slice($entries, 0, $limit);

        echo '<div class="bu-rd-card">';
        echo '<div class="bu-rd-flex-head">';
        echo '<h2>' . esc_html__('Recent Publications Snapshot', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<button type="button" class="mis-btn secondary" data-bu-rd-open="1" data-metric="total_publications" data-heading="' . esc_attr__('All Publications — Details', 'bu-scopus-research-dashboard') . '" data-extra="' . esc_attr(wp_json_encode(array())) . '">' . esc_html__('Open full list', 'bu-scopus-research-dashboard') . '</button>';
        echo '</div>';
        echo '<div id="bu-rd-recent-list" class="bu-rd-publication-grid">';
        foreach ($entries as $entry) {
            $row = $this->publication_row_for_modal($entry);
            $authors = $this->authors_array((string) ($entry['Authors'] ?? ''));
            $authors_preview = !empty($authors) ? implode(', ', array_slice($authors, 0, 4)) : __('Author details unavailable', 'bu-scopus-research-dashboard');
            if (count($authors) > 4) {
                $authors_preview .= ' …';
            }

            echo '<article class="bu-rd-publication-card bu-rd-row" data-eid="' . esc_attr((string) ($row['__EID'] ?? '')) . '" data-scopus-url="' . esc_attr((string) ($row['__SCOPUS_URL'] ?? '#')) . '" data-detail-type="scopus" data-detail-b64="' . esc_attr((string) ($row['__DETAIL_B64'] ?? '')) . '">';
            echo '<div class="bu-rd-publication-meta">';
            echo '<span>' . esc_html((string) ($entry['Year'] ?? '—')) . '</span>';
            echo '<span>' . esc_html((string) ($entry['Document Type'] ?? '—')) . '</span>';
            echo '<span>' . esc_html__('Citations:', 'bu-scopus-research-dashboard') . ' ' . esc_html(number_format_i18n((int) ($entry['Citations'] ?? 0))) . '</span>';
            echo '</div>';
            echo '<h3 class="bu-rd-title-cell bu-rd-clickable">↗ ' . esc_html((string) ($entry['Title'] ?? '')) . '</h3>';
            echo '<p class="bu-rd-publication-authors">' . esc_html($authors_preview) . '</p>';
            echo '<div class="bu-rd-publication-foot">';
            echo '<strong>' . esc_html((string) ($entry['Journal'] ?? __('Source not available', 'bu-scopus-research-dashboard'))) . '</strong>';
            echo '<span>' . esc_html(!empty($entry['Open Access']) ? __('Open Access', 'bu-scopus-research-dashboard') : __('Closed Access', 'bu-scopus-research-dashboard')) . '</span>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function collab_rows($collabs) {
        $rows = array();
        if (!is_array($collabs)) {
            return $rows;
        }

        foreach ($collabs as $country => $count) {
            $country = trim((string) $country);
            if ($country === '') continue;
            $rows[] = array(
                'country' => $country,
                'pubs'    => (int) $count,
            );
        }

        return $rows;
    }

    private function safe_preview_labels($labels) {
        $out = array();
        if (!is_array($labels)) return $out;

        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') continue;
            if (stripos($label, 'AUTHOR_ID:') === 0) continue;
            $out[] = $label;
        }

        return $out;
    }

    private function get_data($force = false) {
        $cache      = get_option(self::OPT_CACHE, array());
        $cache_date = (int) get_option(self::OPT_CACHE_DATE, 0);

        if (!$force && !empty($cache) && (time() - $cache_date) < (12 * HOUR_IN_SECONDS)) {
            $settings = $this->get_settings();
            $cache['manual'] = $this->read_manual_csv($settings['manual_csv'] ?? 'custom_metrics.csv');
            $cache['extras'] = $this->read_extra_metrics($settings['json_dir'] ?? 'scopus-mis-cache');
            return $this->maybe_refresh_dspace_cache($cache);
        }

        return $this->update_cache();
    }

    public function update_cache($job_id = '') {
        $settings = $this->get_settings();
        $data = array(
            'affiliation' => array(),
            'entries'     => array(),
            'stats'       => array(),
            'manual'      => array(),
            'extras'      => array(),
            'error'       => '',
        );

        $this->set_progress($job_id, 10, __('Reading plugin settings...', 'bu-scopus-research-dashboard'));

        if (!empty($settings['api_key']) && !empty($settings['afid'])) {
            $this->set_progress($job_id, 18, __('Connecting to Scopus API...', 'bu-scopus-research-dashboard'));
            $data = $this->fetch_scopus_data($settings['api_key'], $settings['afid'], $job_id);
        }

        if (empty($data['entries'])) {
            $this->set_progress($job_id, 58, __('Reading JSON fallback cache...', 'bu-scopus-research-dashboard'));
            $fallback = $this->read_json_files($settings['json_dir']);
            $computed = $this->compute_metrics($fallback);
            $data['entries'] = $computed['entries'];
            $data['stats']   = $computed['stats'];
            $data['error']   = $data['error'] ? $data['error'] : __('API unavailable. Showing JSON fallback data.', 'bu-scopus-research-dashboard');
        }

        $this->set_progress($job_id, 78, __('Loading manual metrics...', 'bu-scopus-research-dashboard'));
        $data['manual'] = $this->read_manual_csv($settings['manual_csv']);
        $data['extras'] = $this->read_extra_metrics($settings['json_dir']);

        $this->set_progress($job_id, 88, __('Fetching DSpace thesis data...', 'bu-scopus-research-dashboard'));
        $dspace = $this->fetch_dspace_thesis_data($settings, $job_id);
        $data['stats']['dspace_theses_total']   = (int) ($dspace['total'] ?? 0);
        $data['stats']['dspace_theses_details'] = isset($dspace['items']) && is_array($dspace['items']) ? $dspace['items'] : array();
        $data['stats']['dspace_cache_time']     = time();
        if (!empty($dspace['error']) && empty($data['stats']['dspace_error'])) {
            $data['stats']['dspace_error'] = (string) $dspace['error'];
        }

        update_option(self::OPT_CACHE, $data);
        update_option(self::OPT_CACHE_DATE, time());

        $this->set_progress($job_id, 98, __('Saving dashboard cache...', 'bu-scopus-research-dashboard'));

        return $data;
    }

    public function cron_update_cache() {
        $settings = $this->get_settings();
        if (!empty($settings['auto_fetch'])) {
            $this->update_cache();
        }
    }

    public function ajax_update_cache() {
        check_ajax_referer('bu_scopus_rd_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json(array('ok' => false, 'message' => __('Not allowed.', 'bu-scopus-research-dashboard')));
        }

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';
        if ($job_id === '') {
            $job_id = 'job_' . wp_generate_password(12, false, false);
        }

        $this->set_progress($job_id, 5, __('Preparing dashboard update...', 'bu-scopus-research-dashboard'));

        try {
            $this->update_cache($job_id);
            $this->set_progress($job_id, 100, __('Dashboard updated successfully.', 'bu-scopus-research-dashboard'), true);
            wp_send_json(array('ok' => true, 'message' => __('Dashboard updated successfully.', 'bu-scopus-research-dashboard'), 'job_id' => $job_id));
        } catch (Exception $e) {
            $this->set_progress($job_id, 100, $e->getMessage(), true, $e->getMessage());
            wp_send_json(array('ok' => false, 'message' => $e->getMessage(), 'job_id' => $job_id));
        }
    }

    public function ajax_progress() {
        check_ajax_referer('bu_scopus_rd_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json(array('ok' => false, 'message' => __('Not allowed.', 'bu-scopus-research-dashboard')));
        }

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';
        if ($job_id === '') {
            wp_send_json(array('ok' => false, 'message' => __('Missing progress job id.', 'bu-scopus-research-dashboard')));
        }

        $payload = get_transient($this->progress_key($job_id));
        if (!is_array($payload)) {
            $payload = array(
                'percent' => 0,
                'message' => __('Preparing request...', 'bu-scopus-research-dashboard'),
                'done'    => false,
                'error'   => '',
            );
        }

        wp_send_json(array('ok' => true, 'data' => $payload));
    }

    public function ajax_export_csv() {
        check_ajax_referer('bu_scopus_rd_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Not allowed.', 'bu-scopus-research-dashboard'));
        }

        $data    = $this->get_data(false);
        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : array();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bu-scopus-publications-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('EID', 'Identifier', 'Title', 'Authors', 'Journal', 'Year', 'Citations', 'Open Access', 'Document Type', 'DOI', 'Scopus URL'));

        foreach ($entries as $row) {
            fputcsv($out, array(
                $row['EID'] ?? '',
                $row['Identifier'] ?? '',
                $row['Title'] ?? '',
                $row['Authors'] ?? '',
                $row['Journal'] ?? '',
                $row['Year'] ?? '',
                $row['Citations'] ?? '',
                !empty($row['Open Access']) ? 'Yes' : 'No',
                $row['Document Type'] ?? '',
                $row['DOI'] ?? '',
                $row['Scopus URL'] ?? '',
            ));
        }

        fclose($out);
        exit;
    }

    public function ajax_details() {
        check_ajax_referer('bu_scopus_rd_nonce');

        $metric             = isset($_POST['metric']) ? sanitize_text_field(wp_unslash($_POST['metric'])) : '';
        $author             = isset($_POST['author']) ? sanitize_text_field(wp_unslash($_POST['author'])) : '';
        $title              = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $year               = isset($_POST['year']) ? sanitize_text_field(wp_unslash($_POST['year'])) : '';
        $year_only          = isset($_POST['year_only']) ? sanitize_text_field(wp_unslash($_POST['year_only'])) : '';
        $access             = isset($_POST['access']) ? sanitize_text_field(wp_unslash($_POST['access'])) : '';
        $doc_type           = isset($_POST['doc_type']) ? sanitize_text_field(wp_unslash($_POST['doc_type'])) : '';
        $sort_year          = isset($_POST['sort_year']) ? sanitize_text_field(wp_unslash($_POST['sort_year'])) : 'desc';
        $sort_citations     = isset($_POST['sort_citations']) ? sanitize_text_field(wp_unslash($_POST['sort_citations'])) : 'none';
        $oa_label           = isset($_POST['oa_label']) ? sanitize_text_field(wp_unslash($_POST['oa_label'])) : '';
        $subject_label      = isset($_POST['subject_label']) ? sanitize_text_field(wp_unslash($_POST['subject_label'])) : '';
        $subject_code       = isset($_POST['subject_code']) ? sanitize_text_field(wp_unslash($_POST['subject_code'])) : '';
        $country_label      = isset($_POST['country_label']) ? sanitize_text_field(wp_unslash($_POST['country_label'])) : '';
        $doc_type_label     = isset($_POST['doc_type_label']) ? sanitize_text_field(wp_unslash($_POST['doc_type_label'])) : '';
        $manual_subject_label = isset($_POST['manual_subject_label']) ? sanitize_text_field(wp_unslash($_POST['manual_subject_label'])) : '';
        $citation_min       = isset($_POST['citation_min']) ? max(0, (int) wp_unslash($_POST['citation_min'])) : 0;
        $citation_max       = isset($_POST['citation_max']) ? max(0, (int) wp_unslash($_POST['citation_max'])) : 0;

        $data    = $this->get_data(false);
        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : array();
        $stats   = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
        $extras  = isset($data['extras']) && is_array($data['extras']) ? $data['extras'] : array();

        if (empty($stats['subject_counts']) && !empty($entries) && in_array($metric, array('subject_counts', 'subject_publications'), true)) {
            $recomputed = $this->compute_metrics($entries);
            if (!empty($recomputed['stats']['subject_counts']) && is_array($recomputed['stats']['subject_counts'])) {
                $stats['subject_counts'] = $recomputed['stats']['subject_counts'];
            }
        }

        if (empty($stats['subject_counts']) && in_array($metric, array('subject_counts', 'subject_publications'), true)) {
            $settings = $this->get_settings();
            if (!empty($settings['api_key']) && !empty($settings['afid'])) {
                $facet_bundle = $this->fetch_scopus_facets($settings['api_key'], $settings['afid']);
                if (!empty($facet_bundle['subject_counts'])) {
                    $stats['subject_counts'] = $facet_bundle['subject_counts'];
                    $stats['subject_code_map'] = $facet_bundle['subject_code_map'];
                }
            }
        }

        $author_l   = strtolower($author);
        $title_l    = strtolower($title);
        $year_l     = strtolower($year !== '' ? $year : $year_only);
        $oa_l       = strtolower($oa_label);
        $subject_l  = strtolower($subject_label);
        $country_l  = strtolower($country_label);
        $doc_type_l = strtolower($doc_type !== '' ? $doc_type : $doc_type_label);
        $manual_subject_l = strtolower($manual_subject_label);

        $citation_pass = function($entry) use ($citation_min, $citation_max) {
            $cit = (int) ($entry['Citations'] ?? 0);
            if ($citation_min > 0 && $cit < $citation_min) {
                return false;
            }
            if ($citation_max > 0 && $cit > $citation_max) {
                return false;
            }
            return true;
        };

        $summary = array(
            __('Total Publications', 'bu-scopus-research-dashboard') => number_format_i18n($stats['total_publications'] ?? 0),
            __('Total Authors', 'bu-scopus-research-dashboard')      => number_format_i18n($stats['total_authors'] ?? 0),
            __('Open Access', 'bu-scopus-research-dashboard')        => number_format_i18n($stats['open_access_publications'] ?? 0),
            __('Total Citations', 'bu-scopus-research-dashboard')    => number_format_i18n($stats['total_citations'] ?? 0),
        );

        $rows = array();
        $g_index_core_map = array();
        if ($metric === 'institution_g_index') {
            $g_index_entries = $this->g_index_core_entries($entries);
            $g_index_citations_total = 0;
            foreach ($g_index_entries as $core_entry) {
                $core_key = (string) ($core_entry['EID'] ?? ($core_entry['Identifier'] ?? ''));
                if ($core_key !== '') {
                    $g_index_core_map[$core_key] = true;
                }
                $g_index_citations_total += (int) ($core_entry['Citations'] ?? 0);
            }

            $g_index_value = count($g_index_entries);
            $summary = array(
                __('Institution G-index', 'bu-scopus-research-dashboard') => number_format_i18n($g_index_value),
                __('Core Papers Used', 'bu-scopus-research-dashboard')   => number_format_i18n($g_index_value),
                __('Core Citations Sum', 'bu-scopus-research-dashboard') => number_format_i18n($g_index_citations_total),
            );
        }

        if ($metric === 'manual_metric') {
            $wos = isset($extras['wos']) && is_array($extras['wos']) ? $extras['wos'] : array();
            $sdg = isset($extras['sdg']) && is_array($extras['sdg']) ? $extras['sdg'] : array();
            $rows[] = array('Metric' => 'Web of Science — Total Publications', 'Value' => (int) ($wos['total_publications'] ?? 0));
            $rows[] = array('Metric' => 'Web of Science — Total Citations', 'Value' => (int) ($wos['total_citations'] ?? 0));
            $rows[] = array('Metric' => 'Web of Science — h-index', 'Value' => (int) ($wos['h_index'] ?? 0));
            foreach ($sdg as $row) {
                $rows[] = array('Metric' => (string) ($row['goal'] ?? ''), 'Value' => (int) ($row['documents'] ?? 0));
            }
        } elseif ($metric === 'manual_subject_area') {
            $manual_subjects = isset($extras['manual_subject_areas']) && is_array($extras['manual_subject_areas']) ? $extras['manual_subject_areas'] : array();
            $summary = array(
                __('Subject Areas', 'bu-scopus-research-dashboard') => number_format_i18n(count($manual_subjects)),
            );
            foreach ($manual_subjects as $row) {
                $label = (string) ($row['label'] ?? '');
                $count = (int) ($row['count'] ?? 0);
                if ($manual_subject_l && stripos(strtolower($label), $manual_subject_l) === false) continue;
                if ($title_l && stripos(strtolower($label), $title_l) === false) continue;
                $rows[] = array(
                    __('Subject Area', 'bu-scopus-research-dashboard') => $label,
                    __('Documents', 'bu-scopus-research-dashboard')    => $count,
                );
            }
        } elseif ($metric === 'dspace_theses') {
            $items = isset($stats['dspace_theses_details']) && is_array($stats['dspace_theses_details']) ? $stats['dspace_theses_details'] : array();
            $settings = $this->get_settings();
            $limit = max(1, (int) ($settings['dspace_rows_per_details'] ?? 25));
            $summary = array(
                __('Student Theses', 'bu-scopus-research-dashboard') => number_format_i18n((int) ($stats['dspace_theses_total'] ?? count($items))),
            );
            $count = 0;
            foreach ($items as $item) {
                if (!$this->dspace_matches_filters($item, $author_l, $title_l, $year_l)) continue;
                $rows[] = $this->dspace_row_for_modal($item);
                $count++;
                if ($count >= $limit) break;
            }
        } elseif ($metric === 'global_collaborations') {
            $collabs = isset($stats['global_collaborations']) && is_array($stats['global_collaborations']) ? $stats['global_collaborations'] : array();
            foreach ($collabs as $country => $count) {
                if ($title_l && stripos((string) $country, $title_l) === false) continue;
                $rows[] = array(
                    __('Country', 'bu-scopus-research-dashboard')      => $country,
                    __('Publications', 'bu-scopus-research-dashboard') => $count,
                );
            }
        } elseif ($metric === 'subject_counts') {
            $subjects = array();
            $manual_subjects = isset($extras['manual_subject_areas']) && is_array($extras['manual_subject_areas']) ? $extras['manual_subject_areas'] : array();
            if (!empty($manual_subjects)) {
                foreach ($manual_subjects as $row) {
                    $label = trim((string) ($row['label'] ?? ''));
                    if ($label === '') continue;
                    $subjects[$label] = max(0, (int) ($row['count'] ?? 0));
                }
            } else {
                $subjects = isset($stats['subject_counts']) && is_array($stats['subject_counts']) ? $stats['subject_counts'] : array();
            }
            $summary = array(
                __('Subject Areas', 'bu-scopus-research-dashboard') => number_format_i18n(count($subjects)),
            );
            foreach ($subjects as $name => $count) {
                if ($title_l && stripos((string) $name, $title_l) === false) continue;
                $rows[] = array(
                    __('Subject Area', 'bu-scopus-research-dashboard') => $name,
                    __('Documents', 'bu-scopus-research-dashboard')    => $count,
                );
            }
        } elseif ($metric === 'top_authors' || $metric === 'total_authors') {
            $authors = isset($stats['top_authors']) && is_array($stats['top_authors']) ? $stats['top_authors'] : array();
            foreach ($authors as $name => $count) {
                if (stripos((string) $name, 'AUTHOR_ID:') === 0) continue;
                if ($author_l && stripos((string) $name, $author_l) === false) continue;
                $rows[] = array(
                    __('Author', 'bu-scopus-research-dashboard')       => $name,
                    __('Publications', 'bu-scopus-research-dashboard') => $count,
                );
            }
        } elseif ($metric === 'top_journals') {
            $journals = isset($stats['top_journals']) && is_array($stats['top_journals']) ? $stats['top_journals'] : array();
            foreach ($journals as $name => $count) {
                if ($title_l && stripos((string) $name, $title_l) === false) continue;
                $rows[] = array(
                    __('Journal', 'bu-scopus-research-dashboard')      => $name,
                    __('Publications', 'bu-scopus-research-dashboard') => $count,
                );
            }
        } elseif ($metric === 'document_types' || $metric === 'document_type_summary') {
            $types = isset($stats['document_types']) && is_array($stats['document_types']) ? $stats['document_types'] : array();
            foreach ($types as $name => $count) {
                if ($doc_type_l && stripos(strtolower((string) $name), $doc_type_l) === false) continue;
                $rows[] = array(
                    __('Document Type', 'bu-scopus-research-dashboard') => $name,
                    __('Publications', 'bu-scopus-research-dashboard')  => $count,
                );
            }
        } elseif ($metric === 'year_breakdown') {
            $years = isset($stats['year_counts']) && is_array($stats['year_counts']) ? $stats['year_counts'] : array();
            foreach ($years as $y => $count) {
                if ($year_l && stripos((string) $y, $year_l) === false) continue;
                $rows[] = array(
                    __('Year', 'bu-scopus-research-dashboard')         => $y,
                    __('Publications', 'bu-scopus-research-dashboard') => $count,
                );
            }
            usort($rows, function ($a, $b) {
                return (int) $b[__('Year', 'bu-scopus-research-dashboard')] <=> (int) $a[__('Year', 'bu-scopus-research-dashboard')];
            });
        } elseif ($metric === 'subject_publications' && (!empty($subject_code) || !empty($subject_label))) {
            $settings = $this->get_settings();
            if (!empty($settings['api_key']) && !empty($settings['afid'])) {
                $resolved_code = $subject_code;
                if ($resolved_code === '' && !empty($subject_label) && !empty($stats['subject_code_map']) && is_array($stats['subject_code_map'])) {
                    if (!empty($stats['subject_code_map'][$subject_label])) {
                        $resolved_code = (string) $stats['subject_code_map'][$subject_label];
                    } else {
                        foreach ($stats['subject_code_map'] as $label => $code) {
                            if (strcasecmp((string) $label, (string) $subject_label) === 0) {
                                $resolved_code = (string) $code;
                                break;
                            }
                        }
                    }
                }
                if ($resolved_code !== '') {
                    $live_rows = $this->fetch_live_publications_for_subject($settings['api_key'], $settings['afid'], $resolved_code);
                    foreach ($live_rows as $e) {
                        if (!$this->publication_matches_filters($e, $author_l, $title_l, $year_l, $access, $doc_type_l)) continue;
                        if (!$citation_pass($e)) continue;
                        $rows[] = $this->publication_row_for_modal($e);
                    }
                }
            }
            if (empty($rows)) {
                foreach ($entries as $e) {
                    if (!$this->publication_matches_filters($e, $author_l, $title_l, $year_l, $access, $doc_type_l)) continue;
                    if (!$citation_pass($e)) continue;
                    if (!$this->publication_matches_subject($e, $subject_l)) continue;
                    $rows[] = $this->publication_row_for_modal($e);
                }
            }
        } else {
            foreach ($entries as $e) {
                if (!$this->publication_matches_filters($e, $author_l, $title_l, $year_l, $access, $doc_type_l)) continue;
                if (!$citation_pass($e)) continue;
                if ($metric === 'open_access_publications' && empty($e['Open Access'])) continue;
                if ($metric === 'oa_publications' && !$this->publication_matches_oa_label($e, $oa_l)) continue;
                if ($metric === 'subject_publications' && !$this->publication_matches_subject($e, $subject_l)) continue;
                if ($metric === 'country_publications' && !$this->publication_matches_country($e, $country_l)) continue;
                if ($metric === 'document_type_publications' && $doc_type_l && stripos(strtolower((string) ($e['Document Type'] ?? '')), $doc_type_l) === false) continue;

                if ($metric === 'institution_h_index') {
                    if ((int) ($e['Citations'] ?? 0) < (int) ($stats['institution_h_index'] ?? 0)) continue;
                } elseif ($metric === 'institution_h5_index') {
                    $curYear = (int) gmdate('Y');
                    $pubYear = (int) ($e['Year'] ?? 0);
                    if ($pubYear < ($curYear - 4)) continue;
                    if ((int) ($e['Citations'] ?? 0) < (int) ($stats['institution_h5_index'] ?? 0)) continue;
                } elseif ($metric === 'institution_g_index') {
                    $row_key = (string) ($e['EID'] ?? ($e['Identifier'] ?? ''));
                    if ($row_key === '' || empty($g_index_core_map[$row_key])) continue;
                } elseif ($metric === 'institution_i10_index') {
                    if ((int) ($e['Citations'] ?? 0) < 10) continue;
                } elseif ($metric === 'cited_papers') {
                    if ((int) ($e['Citations'] ?? 0) <= 0) continue;
                } elseif ($metric === 'uncited_papers') {
                    if ((int) ($e['Citations'] ?? 0) !== 0) continue;
                }

                $rows[] = $this->publication_row_for_modal($e);
            }
        }

        if ($sort_citations === 'desc') {
            usort($rows, array($this, 'sort_rows_by_citations_desc'));
        } elseif ($sort_citations === 'asc') {
            usort($rows, array($this, 'sort_rows_by_citations_asc'));
        } else {
            usort($rows, $sort_year === 'asc' ? array($this, 'sort_rows_by_year_asc') : array($this, 'sort_rows_by_year_desc'));
        }

        $settings = $this->get_settings();
        if (!empty($rows) && !empty($settings['api_key']) && $metric !== 'dspace_theses') {
            $rows = $this->enrich_publication_rows_with_authors($rows, $settings['api_key'], (string) ($settings['afid'] ?? '60121496'));
        }

        wp_send_json(array('ok' => true, 'summary' => $summary, 'rows' => $rows));
    }

    public function ajax_publication_detail() {
        check_ajax_referer('bu_scopus_rd_nonce');

        $eid = isset($_POST['eid']) ? sanitize_text_field(wp_unslash($_POST['eid'])) : '';
        if ($eid === '') {
            wp_send_json(array('ok' => false, 'message' => __('Missing record identifier.', 'bu-scopus-research-dashboard')));
        }

        $settings = $this->get_settings();
        $api_key  = isset($settings['api_key']) ? $settings['api_key'] : '';
        $target_afid = (string) ($settings['afid'] ?? '60121496');
        $cache = $this->get_data(false);
        $entry = $this->find_entry_by_eid($cache['entries'] ?? array(), $eid);
        $normalized_eid = $this->normalize_eid($eid);
        $detail_cache_key = 'bu_scopus_pub_detail_' . md5((string) $normalized_eid . '|' . (string) $target_afid);
        $detail_cached = get_transient($detail_cache_key);
        if (is_array($detail_cached) && !empty($detail_cached['ok'])) {
            wp_send_json($detail_cached);
        }

        if ($api_key === '') {
            if ($entry) {
                $authors = $this->authors_array($entry['Authors'] ?? '');
                $payload = array(
                    'ok'           => true,
                    'title'        => $entry['Title'] ?? '',
                    'abstract'     => '',
                    'authors'      => $authors,
                    'authors_html' => esc_html(implode(', ', $authors)),
                    'doi'          => $entry['DOI'] ?? '',
                    'scopus_url'   => $entry['Scopus URL'] ?? '#',
                );
                set_transient($detail_cache_key, $payload, 12 * HOUR_IN_SECONDS);
                wp_send_json($payload);
            }
            wp_send_json(array('ok' => false, 'message' => __('API key not configured.', 'bu-scopus-research-dashboard')));
        }

        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );

        $scopus_id      = preg_replace('/^2-s2\.0-/', '', $normalized_eid);

        $json = $this->api_get_json(
            'https://api.elsevier.com/content/abstract/scopus_id/' . rawurlencode($scopus_id) . '?view=FULL&httpAccept=application/json',
            $headers
        );

        if (!is_array($json) || empty($json['abstracts-retrieval-response'])) {
            $json = $this->api_get_json(
                'https://api.elsevier.com/content/abstract/eid/' . rawurlencode($normalized_eid) . '?view=FULL&httpAccept=application/json',
                $headers
            );
        }

        if (!is_array($json) || empty($json['abstracts-retrieval-response'])) {
            if ($entry) {
                $authors = $this->authors_array($entry['Authors'] ?? '');
                $payload = array(
                    'ok'           => true,
                    'title'        => $entry['Title'] ?? '',
                    'abstract'     => '',
                    'authors'      => $authors,
                    'authors_html' => esc_html(implode(', ', $authors)),
                    'doi'          => $entry['DOI'] ?? '',
                    'scopus_url'   => $entry['Scopus URL'] ?? '#',
                );
                set_transient($detail_cache_key, $payload, 12 * HOUR_IN_SECONDS);
                wp_send_json($payload);
            }
            wp_send_json(array('ok' => false, 'message' => __('Could not retrieve article details from Elsevier API.', 'bu-scopus-research-dashboard')));
        }

        $resp = $json['abstracts-retrieval-response'];

        $abstract = $this->extract_abstract_text($resp);
        if ($abstract === '' && $entry && !empty($entry['Abstract'])) {
            $abstract = wp_strip_all_tags((string) $entry['Abstract']);
        }

        $authors = array();
        if (!empty($resp['authors']['author']) && is_array($resp['authors']['author'])) {
            foreach ($resp['authors']['author'] as $a) {
                $name = $this->extract_author_name_from_abstract($a);
                if ($name !== '') {
                    $authors[] = $name;
                }
            }
        }
        if (empty($authors) && $entry) {
            $authors = $this->authors_array($entry['Authors'] ?? '');
        }

        $authors = array_values(array_unique(array_filter($authors)));
        $bennett_authors = $this->extract_bennett_author_names($resp, $target_afid);
        $authors_html = $this->authors_html($authors, $bennett_authors);

        $doi = (string) ($resp['coredata']['prism:doi'] ?? ($entry['DOI'] ?? ''));
        $title = (string) ($resp['coredata']['dc:title'] ?? ($entry['Title'] ?? ''));
        $scopus_url = (string) ($entry['Scopus URL'] ?? '#');

        if (!empty($resp['coredata']['link']) && is_array($resp['coredata']['link'])) {
            foreach ($resp['coredata']['link'] as $lnk) {
                if (($lnk['@ref'] ?? '') === 'scopus' && !empty($lnk['@href'])) {
                    $scopus_url = (string) $lnk['@href'];
                    break;
                }
            }
        }
        if (($scopus_url === '' || $scopus_url === '#') && !empty($resp['link']) && is_array($resp['link'])) {
            foreach ($resp['link'] as $lnk) {
                if (($lnk['@ref'] ?? '') === 'scopus' && !empty($lnk['@href'])) {
                    $scopus_url = (string) $lnk['@href'];
                    break;
                }
            }
        }

        $payload = array(
            'ok'           => true,
            'title'        => $title,
            'abstract'     => $abstract,
            'authors'      => $authors,
            'authors_html' => $authors_html,
            'doi'          => $doi,
            'scopus_url'   => $scopus_url ?: '#',
        );
        set_transient($detail_cache_key, $payload, 12 * HOUR_IN_SECONDS);
        wp_send_json($payload);
    }

    private function fetch_scopus_data($api_key, $afid, $job_id = '') {
        $afid = $this->clean_affiliation_id($afid);

        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );

        $data = array(
            'affiliation' => array(),
            'entries'     => array(),
            'stats'       => array(),
            'manual'      => array(),
            'extras'      => array(),
            'error'       => '',
        );

        $this->set_progress($job_id, 22, __('Fetching affiliation profile...', 'bu-scopus-research-dashboard'));

        $aff_json = $this->api_get_json(
            'https://api.elsevier.com/content/search/affiliation?query=AF-ID(' . rawurlencode($afid) . ')&count=1&httpAccept=application/json',
            $headers
        );

        $official_docs_total = 0;
        $official_authors_total = 0;

        if (!empty($aff_json['search-results']['entry'][0]) && is_array($aff_json['search-results']['entry'][0])) {
            $entry = $aff_json['search-results']['entry'][0];
            $official_docs_total = (int) ($entry['document-count'] ?? 0);
            $data['affiliation'] = array(
                'name'    => $entry['affiliation-name'] ?? 'Bennett University',
                'address' => $entry['address'] ?? '',
                'city'    => $entry['city'] ?? '',
                'country' => $entry['country'] ?? '',
                'afid'    => $this->clean_affiliation_id($entry['dc:identifier'] ?? $afid),
            );
        } else {
            $data['affiliation'] = array(
                'name'    => 'Bennett University',
                'address' => '',
                'city'    => 'Greater Noida',
                'country' => 'India',
                'afid'    => $afid,
            );
        }

        $author_json = $this->api_get_json(
            'https://api.elsevier.com/content/search/author?query=AF-ID(' . rawurlencode($afid) . ')&count=25&start=0&httpAccept=application/json',
            $headers
        );

        if (is_array($author_json)) {
            $official_authors_total = (int) ($author_json['search-results']['opensearch:totalResults'] ?? 0);
        }

        $raw_entries = array();
        $per_page    = 25;
        $start       = 0;
        $pages       = 0;
        $max_pages   = 400;
        $expected_total = max(1, $official_docs_total);

        while ($pages < $max_pages) {
            $this->set_progress($job_id, min(70, 28 + (int) round(($start / $expected_total) * 40)), sprintf(__('Fetching publication records... %d retrieved', 'bu-scopus-research-dashboard'), count($raw_entries)));

            $json = $this->api_get_json(
                'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&view=STANDARD&count=' . $per_page . '&start=' . $start . '&sort=coverDate&httpAccept=application/json',
                $headers
            );

            if (!$json || empty($json['search-results']['entry']) || !is_array($json['search-results']['entry'])) {
                if ($pages === 0) {
                    $json = $this->api_get_json(
                        'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&view=STANDARD&count=' . $per_page . '&start=' . $start . '&httpAccept=application/json',
                        $headers
                    );
                }

                if (!$json || empty($json['search-results']['entry']) || !is_array($json['search-results']['entry'])) {
                    break;
                }
            }

            $batch = $json['search-results']['entry'];
            foreach ($batch as $entry) {
                if (is_array($entry) && !empty($entry['error'])) {
                    continue;
                }
                $raw_entries[] = $entry;
            }

            $start += $per_page;
            $pages++;
            usleep(120000);

            $total_results = (int) ($json['search-results']['opensearch:totalResults'] ?? 0);
            if ($official_docs_total > 0 && $start >= $official_docs_total) break;
            if ($official_docs_total === 0 && $total_results > 0 && $start >= $total_results) break;
            if ($total_results > 0 && count($raw_entries) >= $total_results) break;
        }

        $this->set_progress($job_id, 74, __('Computing metrics...', 'bu-scopus-research-dashboard'));
        $computed = $this->compute_metrics($raw_entries);

        if ($official_docs_total > 0) {
            $computed['stats']['total_publications'] = $official_docs_total;
        }
        if ($official_authors_total > 0) {
            $computed['stats']['total_authors'] = $official_authors_total;
        }

        $facet_bundle = $this->fetch_scopus_facets($api_key, $afid);
        if (!empty($facet_bundle['subject_counts'])) {
            $computed['stats']['subject_counts'] = $facet_bundle['subject_counts'];
            $computed['stats']['subject_code_map'] = $facet_bundle['subject_code_map'];
        }
        $computed_oa = isset($computed['stats']['oa_categories']) && is_array($computed['stats']['oa_categories']) ? $computed['stats']['oa_categories'] : array();
        if (!empty($facet_bundle['oa_categories'])) {
            $computed['stats']['oa_categories'] = $this->merge_oa_counts(
                $this->normalize_oa_counts($computed_oa, (int) ($computed['stats']['open_access_publications'] ?? 0)),
                $this->normalize_oa_counts($facet_bundle['oa_categories'], (int) ($computed['stats']['open_access_publications'] ?? 0))
            );
        } else {
            $computed['stats']['oa_categories'] = $this->normalize_oa_counts(
                $computed_oa,
                (int) ($computed['stats']['open_access_publications'] ?? 0)
            );
        }

        $this->set_progress($job_id, 79, __('Refreshing author counts...', 'bu-scopus-research-dashboard'));
        $fresh_author_counts = $this->fetch_all_author_counts($api_key, $afid, $official_authors_total);
        if (!empty($fresh_author_counts)) {
            $computed['stats']['top_authors'] = $fresh_author_counts;
        }

        $data['entries'] = $computed['entries'];
        $data['stats']   = $computed['stats'];

        if (empty($data['entries']) && $official_docs_total > 0) {
            $data['stats']['total_publications'] = $official_docs_total;
            $data['error'] = __('Scopus API search returned no rows in this build. The plugin now uses safer page size and STANDARD view. Please click Update Data once again after replacing the file.', 'bu-scopus-research-dashboard');
        } elseif (empty($data['entries']) && empty($data['error'])) {
            $data['error'] = __('Scopus API returned no publication records.', 'bu-scopus-research-dashboard');
        }

        return $data;
    }

    private function compute_metrics($entries) {
        $normalized = array();

        if (is_array($entries)) {
            foreach ($entries as $e) {
                $row = $this->normalize_entry($e);
                if (empty($row)) continue;
                if ($this->is_excluded_document($row)) continue;
                $normalized[] = $row;
            }
        }

        $subject_counts = array();
        $country_counts = array();
        $oa_counts      = array();
        $doc_counts     = array();
        $journal_counts = array();

        $total_citations = 0;
        $open_access     = 0;
        $cited_papers    = 0;
        $uncited_papers  = 0;
        $year_counts     = array();

        foreach ($normalized as $row) {
            $cit = (int) ($row['Citations'] ?? 0);
            $yr  = (string) ($row['Year'] ?? '');
            $doc = trim((string) ($row['Document Type'] ?? ''));
            $jour= trim((string) ($row['Journal'] ?? ''));

            $total_citations += $cit;
            if (!empty($row['Open Access'])) $open_access++;
            if ($cit > 0) $cited_papers++; else $uncited_papers++;

            if ($yr !== '') {
                $year_counts[$yr] = isset($year_counts[$yr]) ? $year_counts[$yr] + 1 : 1;
            }

            if ($doc !== '') {
                $doc_counts[$doc] = isset($doc_counts[$doc]) ? $doc_counts[$doc] + 1 : 1;
            }

            if ($jour !== '') {
                $journal_counts[$jour] = isset($journal_counts[$jour]) ? $journal_counts[$jour] + 1 : 1;
            }

            $subjects = isset($row['Subject Areas']) && is_array($row['Subject Areas']) ? $row['Subject Areas'] : array();
            foreach ($subjects as $subject) {
                $subject = trim((string) $subject);
                if ($subject === '') continue;
                $subject_counts[$subject] = isset($subject_counts[$subject]) ? $subject_counts[$subject] + 1 : 1;
            }

            $countries = isset($row['Countries']) && is_array($row['Countries']) ? $row['Countries'] : array();
            foreach ($countries as $country) {
                $country = trim((string) $country);
                if ($country === '') continue;
                $country_counts[$country] = isset($country_counts[$country]) ? $country_counts[$country] + 1 : 1;
            }

            $labels = isset($row['OA Labels']) && is_array($row['OA Labels']) ? $row['OA Labels'] : array();
            foreach ($labels as $label) {
                $label = trim((string) $label);
                if ($label === '') continue;
                $oa_counts[$label] = isset($oa_counts[$label]) ? $oa_counts[$label] + 1 : 1;
            }
        }

        krsort($year_counts);
        arsort($subject_counts);
        arsort($country_counts);
        arsort($oa_counts);
        arsort($doc_counts);
        arsort($journal_counts);

        $stats = array(
            'total_publications'        => count($normalized),
            'total_authors'             => count($this->count_unique_authors($normalized)),
            'open_access_publications'  => $open_access,
            'total_citations'           => $total_citations,
            'avg_citations_per_paper'   => count($normalized) ? round($total_citations / count($normalized), 2) : 0,
            'institution_h_index'       => $this->calculate_h_index($normalized),
            'institution_h5_index'      => $this->calculate_h5_index($normalized),
            'institution_g_index'       => $this->calculate_g_index($normalized),
            'institution_i10_index'     => $this->calculate_i10_index($normalized),
            'cited_papers'              => $cited_papers,
            'uncited_papers'            => $uncited_papers,
            'top_journal_count'         => !empty($journal_counts) ? reset($journal_counts) : 0,
            'year_count'                => count($year_counts),
            'year_counts'               => $year_counts,
            'subject_counts'            => $subject_counts,
            'global_collaborations'     => $country_counts,
            'document_types'            => $doc_counts,
            'oa_categories'             => $this->normalize_oa_counts($oa_counts, $open_access),
            'top_authors'               => $this->count_authors($normalized),
            'top_journals'              => $journal_counts,
        );

        return array(
            'entries' => $normalized,
            'stats'   => $stats,
        );
    }

    private function normalize_entry($e) {
        if (!is_array($e)) return array();

        $eid        = $this->extract_eid($e);
        $identifier = $this->extract_identifier($e, $eid);
        $title      = trim((string) ($e['dc:title'] ?? $e['title'] ?? ''));
        $journal    = trim((string) ($e['prism:publicationName'] ?? $e['source-title'] ?? ''));
        $year       = $this->extract_year($e);
        $cit        = (int) ($e['citedby-count'] ?? $e['citedby_count'] ?? 0);
        $open       = !empty($e['openaccess']) && (string) $e['openaccess'] !== '0';
        $docType    = $this->human_document_type((string) ($e['subtypeDescription'] ?? $e['subtype'] ?? $e['aggregationType'] ?? ''));
        $doi        = trim((string) ($e['prism:doi'] ?? ''));
        $authors    = $this->extract_authors_string($e);
        $scopus_url = $this->extract_scopus_url($e);
        $subjects   = $this->extract_subject_areas($e);
        $countries  = $this->extract_countries($e);
        $oa_labels  = $this->extract_oa_labels($e);

        if ($title === '' && $journal === '' && $eid === '' && $identifier === '') {
            return array();
        }

        return array(
            'EID'            => $eid,
            'Identifier'     => $identifier,
            'Title'          => $title,
            'Authors'        => $authors,
            'Journal'        => $journal,
            'Year'           => $year,
            'Citations'      => $cit,
            'Open Access'    => $open ? 1 : 0,
            'Document Type'  => $docType,
            'Scopus URL'     => $scopus_url,
            'DOI'            => $doi,
            'Subject Areas'  => $subjects,
            'Countries'      => $countries,
            'OA Labels'      => $oa_labels,
        );
    }

    private function extract_eid($e) {
        $eid = trim((string) ($e['eid'] ?? ''));
        if ($eid !== '') return $this->normalize_eid($eid);

        $identifier = trim((string) ($e['dc:identifier'] ?? ''));
        if (stripos($identifier, 'SCOPUS_ID:') === 0) {
            $id = trim(substr($identifier, 10));
            if ($id !== '') return '2-s2.0-' . $id;
        }

        return '';
    }

    private function normalize_eid($eid) {
        $eid = trim((string) $eid);
        if ($eid === '') return '';
        if (stripos($eid, '2-s2.0-') === 0) return $eid;
        if (preg_match('/^\d+$/', $eid)) return '2-s2.0-' . $eid;
        return $eid;
    }

    private function clean_affiliation_id($afid) {
        $afid = trim((string) $afid);
        if ($afid === '') {
            return '';
        }
        if (preg_match('/(\d{5,})/', $afid, $m)) {
            return $m[1];
        }
        return preg_replace('/\D+/', '', $afid);
    }

    private function extract_identifier($e, $eid = '') {
        if ($eid !== '') return $eid;

        $identifier = trim((string) ($e['dc:identifier'] ?? ''));
        if ($identifier !== '') return $identifier;

        return trim((string) ($e['identifier'] ?? ''));
    }

    private function extract_year($e) {
        $cover = (string) ($e['prism:coverDate'] ?? $e['coverDate'] ?? '');
        if ($cover !== '' && preg_match('/^\d{4}/', $cover, $m)) {
            return $m[0];
        }

        $year = trim((string) ($e['prism:coverDisplayDate'] ?? $e['coverDisplayDate'] ?? ''));
        if ($year !== '' && preg_match('/\b(19|20)\d{2}\b/', $year, $m)) {
            return $m[0];
        }

        return '';
    }

    private function extract_authors_string($e) {
        $names = array();

        if (!empty($e['author']) && is_array($e['author'])) {
            foreach ($e['author'] as $a) {
                $name = $this->extract_author_name($a);
                if ($name !== '') $names[] = $name;
            }
        }

        if (empty($names) && !empty($e['dc:creator'])) {
            $creator = trim((string) $e['dc:creator']);
            if ($creator !== '') {
                $parts = preg_split('/\s*;\s*|\s*,\s*(?=[A-Z][a-z])/u', $creator);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $part = trim((string) $part);
                        if ($part !== '') $names[] = $part;
                    }
                }
                if (empty($names)) $names[] = $creator;
            }
        }

        $names = array_values(array_unique(array_filter($names)));
        return implode(', ', $names);
    }

    private function extract_author_name($a) {
        if (!is_array($a)) return '';

        $name = trim((string) ($a['authname'] ?? $a['ce:indexed-name'] ?? ''));
        if ($name !== '') return $name;

        $name = trim((string) ($a['preferred-name']['indexed-name'] ?? $a['preferred-name']['ce:indexed-name'] ?? ''));
        if ($name !== '') return $name;

        $given = trim((string) ($a['preferred-name']['ce:given-name'] ?? $a['given-name'] ?? $a['ce:given-name'] ?? ''));
        $sur   = trim((string) ($a['preferred-name']['ce:surname'] ?? $a['surname'] ?? $a['ce:surname'] ?? ''));
        $full  = trim($given . ' ' . $sur);
        return $full;
    }

    private function extract_author_name_from_abstract($a) {
        return $this->extract_author_name($a);
    }

    private function extract_scopus_url($e) {
        if (!empty($e['link']) && is_array($e['link'])) {
            foreach ($e['link'] as $lnk) {
                if (($lnk['@ref'] ?? '') === 'scopus' && !empty($lnk['@href'])) {
                    return (string) $lnk['@href'];
                }
            }
        }
        return '#';
    }


    private function extract_subject_areas($e) {
        $out = array();

        $push = function($value) use (&$out, &$push) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        foreach (array('$', '@abbrev', 'label', '@code', 'name', 'subject', 'ce:text', 'text', 'mainterm', 'author-keyword') as $key) {
                            if (!empty($v[$key])) {
                                $push($v[$key]);
                            }
                        }
                        $push($v);
                    } else {
                        if (is_string($k) && in_array($k, array('$', '@abbrev', 'label', '@code', 'name', 'subject', 'ce:text', 'text', 'mainterm', 'author-keyword'), true)) {
                            $label = trim((string) $v);
                            if ($label !== '') $out[] = $label;
                        } else {
                            $label = trim((string) $v);
                            if ($label !== '') $out[] = $label;
                        }
                    }
                }
                return;
            }
            $value = trim((string) $value);
            if ($value === '') return;
            $parts = preg_split('/\s*[;,|]\s*/', $value);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ($part !== '') $out[] = $part;
                }
            }
        };

        foreach (array('subject-area', 'subject_areas', 'subjectAreas', 'subject-area-name', 'subject', 'subjects') as $key) {
            if (isset($e[$key])) {
                $push($e[$key]);
            }
        }

        if (!empty($e['idxterms'])) {
            $push($e['idxterms']);
        }

        if (!empty($e['authkeywords'])) {
            $push($e['authkeywords']);
        }

        if (!empty($e['authkeywords-author'])) {
            $push($e['authkeywords-author']);
        }

        if (!empty($e['authkeywords']['author-keyword'])) {
            $push($e['authkeywords']['author-keyword']);
        }

        $clean = array();
        foreach ($out as $label) {
            $label = trim((string) $label);
            if ($label === '') continue;
            if (preg_match('/^[A-Z]{2,6}$/', $label)) continue;
            if (!in_array($label, $clean, true)) {
                $clean[] = $label;
            }
        }

        return $clean;
    }

    private function extract_countries($e) {
        $out = array();

        if (!empty($e['affiliation']) && is_array($e['affiliation'])) {
            foreach ($e['affiliation'] as $aff) {
                if (!is_array($aff)) continue;
                $country = trim((string) ($aff['affiliation-country'] ?? $aff['country'] ?? ''));
                if ($country !== '') $out[] = $country;
            }
        }

        $out = array_values(array_unique($out));
        return $out;
    }

    private function extract_oa_labels($e) {
        $out = array();

        if (!empty($e['openaccessFlag']) && (string) $e['openaccessFlag'] !== '0') {
            $out[] = 'Open Access';
        }

        if (!empty($e['freetoreadLabel']) && is_array($e['freetoreadLabel'])) {
            foreach ($e['freetoreadLabel'] as $label) {
                $label = trim((string) $label);
                if ($label !== '') $out[] = $label;
            }
        }

        if (!empty($e['freetoread']) && is_array($e['freetoread'])) {
            foreach ($e['freetoread'] as $label) {
                if (is_array($label)) {
                    $v = trim((string) ($label['$'] ?? ''));
                    if ($v !== '') $out[] = $v;
                } else {
                    $label = trim((string) $label);
                    if ($label !== '') $out[] = $label;
                }
            }
        }

        if (empty($out) && !empty($e['openaccess']) && (string) $e['openaccess'] !== '0') {
            $out[] = 'Open Access';
        }

        $map = array(
            'all oa; gold'              => 'Gold',
            'all oa; hybrid gold'       => 'Hybrid Gold',
            'all oa; green'             => 'Green',
            'all oa; bronze'            => 'Bronze',
            'all open access'           => 'All open access',
            'open access'               => 'All open access',
            'gold'                      => 'Gold',
            'green'                     => 'Green',
            'hybrid gold'               => 'Hybrid Gold',
            'hybridgold'                => 'Hybrid Gold',
            'bronze'                    => 'Bronze',
            'publisherfullgold'         => 'Gold',
            'publisher hybrid gold'     => 'Hybrid Gold',
            'publisherhybridgold'       => 'Hybrid Gold',
            'repository'                => 'Green',
            'publisherfree2read'        => 'Bronze',
            'free2read'                 => 'Bronze',
        );

        $final = array();
        foreach ($out as $label) {
            $key = strtolower(trim((string) $label));
            $final[] = $map[$key] ?? trim((string) $label);
        }

        $final = array_values(array_unique(array_filter($final)));
        return $final;
    }

    private function human_document_type($type) {
        $type = trim((string) $type);
        if ($type === '') return '';

        $map = array(
            'ar' => 'Article',
            're' => 'Review',
            'cp' => 'Conference Paper',
            'ch' => 'Book Chapter',
            'bk' => 'Book',
            'ed' => 'Editorial',
            'er' => 'Erratum',
            'le' => 'Letter',
            'no' => 'Note',
            'sh' => 'Short Survey',
            'ip' => 'Article in Press',
            'dp' => 'Data Paper',
        );

        $lower = strtolower($type);
        if (isset($map[$lower])) return $map[$lower];
        return $type;
    }

    private function is_excluded_document($row) {
        $type  = strtolower((string) ($row['Document Type'] ?? ''));
        $title = strtolower((string) ($row['Title'] ?? ''));

        $blocked = array('erratum', 'retracted', 'retraction', 'preprint', 'pre-print');

        foreach ($blocked as $word) {
            if (($type !== '' && strpos($type, $word) !== false) || ($title !== '' && strpos($title, $word) !== false)) {
                return true;
            }
        }

        return false;
    }

    private function count_unique_authors($rows) {
        $counts = array();
        foreach ($rows as $r) {
            $authors = isset($r['Authors']) ? (string) $r['Authors'] : '';
            if ($authors === '') continue;

            $parts = preg_split('/\s*[,;]\s*/', $authors);
            if (!is_array($parts)) continue;

            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $counts[$p] = true;
            }
        }

        return array_keys($counts);
    }

    private function count_authors($rows) {
        $counts = array();
        foreach ($rows as $r) {
            $authors = isset($r['Authors']) ? (string) $r['Authors'] : '';
            if ($authors === '') continue;

            $parts = preg_split('/\s*[,;]\s*/', $authors);
            if (!is_array($parts)) continue;

            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '' || stripos($p, 'AUTHOR_ID:') === 0) continue;
                $counts[$p] = isset($counts[$p]) ? $counts[$p] + 1 : 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    private function fetch_all_author_counts($api_key, $afid, $official_authors_total) {
        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );

        $counts = array();
        $start = 0;
        $per_page = 25;
        $pages = 0;

        while ($pages < 30) {
            $json = $this->api_get_json(
                'https://api.elsevier.com/content/search/author?query=AF-ID(' . rawurlencode($afid) . ')&count=' . $per_page . '&start=' . $start . '&httpAccept=application/json',
                $headers
            );

            if (!$json || empty($json['search-results']['entry']) || !is_array($json['search-results']['entry'])) {
                break;
            }

            foreach ($json['search-results']['entry'] as $entry) {
                $name = $this->extract_author_search_name($entry);
                if ($name === '') continue;
                $doc_count = (int) ($entry['document-count'] ?? 0);
                $counts[$name] = $doc_count;
            }

            $start += $per_page;
            $pages++;
            if ($official_authors_total > 0 && $start >= $official_authors_total) {
                break;
            }
        }

        arsort($counts);
        return $counts;
    }

    private function extract_author_search_name($entry) {
        if (!is_array($entry)) return '';

        $name = trim((string) ($entry['preferred-name']['indexed-name'] ?? ''));
        if ($name !== '') return $name;

        $given = trim((string) ($entry['preferred-name']['given-name'] ?? ''));
        $sur   = trim((string) ($entry['preferred-name']['surname'] ?? ''));
        $full  = trim($given . ' ' . $sur);
        if ($full !== '') return $full;

        $creator = trim((string) ($entry['dc:creator'] ?? ''));
        if ($creator !== '' && stripos($creator, 'AUTHOR_ID:') !== 0) return $creator;

        return '';
    }

    private function publication_matches_filters($e, $author_l, $title_l, $year_l, $access, $doc_type_l) {
        if ($author_l && stripos(strtolower((string) ($e['Authors'] ?? '')), $author_l) === false) return false;

        if ($title_l) {
            $hay = strtolower((string) ($e['Title'] ?? '') . ' ' . (string) ($e['Journal'] ?? ''));
            if (stripos($hay, $title_l) === false) return false;
        }

        if ($year_l && stripos((string) ($e['Year'] ?? ''), $year_l) === false) return false;

        if ($access === 'open' && empty($e['Open Access'])) return false;
        if ($access === 'closed' && !empty($e['Open Access'])) return false;

        if ($doc_type_l && stripos(strtolower((string) ($e['Document Type'] ?? '')), $doc_type_l) === false) return false;

        return true;
    }

    private function publication_matches_oa_label($e, $oa_l) {
        if ($oa_l === '') return true;
        $labels = isset($e['OA Labels']) && is_array($e['OA Labels']) ? $e['OA Labels'] : array();
        foreach ($labels as $l) {
            if (stripos(strtolower((string) $l), $oa_l) !== false) return true;
        }
        return false;
    }

    private function publication_matches_subject($e, $subject_l) {
        if ($subject_l === '') return true;

        $subjects = isset($e['Subject Areas']) && is_array($e['Subject Areas']) ? $e['Subject Areas'] : array();
        if (!empty($subjects)) {
            foreach ($subjects as $s) {
                if (stripos(strtolower((string) $s), $subject_l) !== false) return true;
            }
        }

        $title_journal = strtolower((string) ($e['Title'] ?? '') . ' ' . (string) ($e['Journal'] ?? ''));
        return stripos($title_journal, $subject_l) !== false;
    }

    private function publication_matches_country($e, $country_l) {
        if ($country_l === '') return true;
        $countries = isset($e['Countries']) && is_array($e['Countries']) ? $e['Countries'] : array();
        foreach ($countries as $c) {
            if (stripos(strtolower((string) $c), $country_l) !== false) return true;
        }
        return false;
    }

    private function publication_row_for_modal($e) {
        $preview = array(
            'type'        => 'scopus',
            'title'       => (string) ($e['Title'] ?? ''),
            'authors'     => $this->authors_array((string) ($e['Authors'] ?? '')),
            'authors_html'=> '',
            'journal'     => (string) ($e['Journal'] ?? ''),
            'year'        => (string) ($e['Year'] ?? ''),
            'doi'         => (string) ($e['DOI'] ?? ''),
            'scopus_url'  => (string) ($e['Scopus URL'] ?? '#'),
            'abstract'    => trim(wp_strip_all_tags((string) ($e['Abstract'] ?? ''))),
        );

        return array(
            'Title'         => $e['Title'] ?? '',
            'Authors'       => $e['Authors'] ?? '',
            'Journal'       => $e['Journal'] ?? '',
            'Year'          => $e['Year'] ?? '',
            'Citations'     => (string) ((int) ($e['Citations'] ?? 0)),
            'Open Access'   => !empty($e['Open Access']) ? 'Yes' : 'No',
            'Document Type' => $e['Document Type'] ?? '',
            '__EID'         => $e['EID'] ?? ($e['Identifier'] ?? ''),
            '__SCOPUS_URL'  => $e['Scopus URL'] ?? '#',
            '__DETAIL_TYPE' => 'scopus',
            '__DETAIL_B64'  => base64_encode(wp_json_encode($preview)),
            '__AUTHORS_HTML'=> '',
        );
    }

    private function authors_array($authors) {
        if (!is_string($authors) || trim($authors) === '') return array();
        $parts = preg_split('/\s*,\s*/', $authors);
        return is_array($parts) ? array_values(array_filter(array_map('trim', $parts))) : array();
    }

    private function find_entry_by_eid($entries, $eid) {
        if (!is_array($entries)) return null;
        $eid = $this->normalize_eid($eid);

        foreach ($entries as $entry) {
            $candidate = $this->normalize_eid((string) ($entry['EID'] ?? $entry['Identifier'] ?? ''));
            if ($candidate !== '' && $candidate === $eid) {
                return $entry;
            }
        }
        return null;
    }

    private function calculate_h_index($rows) {
        $cites = array();
        foreach ($rows as $row) {
            $cites[] = (int) ($row['Citations'] ?? 0);
        }
        rsort($cites);
        $h = 0;
        foreach ($cites as $i => $c) {
            if ($c >= ($i + 1)) $h = $i + 1;
            else break;
        }
        return $h;
    }

    private function calculate_h5_index($rows) {
        $curYear = (int) gmdate('Y');
        $recent = array();
        foreach ($rows as $row) {
            $year = (int) ($row['Year'] ?? 0);
            if ($year >= ($curYear - 4)) {
                $recent[] = $row;
            }
        }
        return $this->calculate_h_index($recent);
    }

    private function calculate_i10_index($rows) {
        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['Citations'] ?? 0) >= 10) $count++;
        }
        return $count;
    }

    private function calculate_g_index($rows) {
        $cites = array();
        foreach ($rows as $row) {
            $cites[] = max(0, (int) ($row['Citations'] ?? 0));
        }
        rsort($cites);
        $g = 0;
        $sum = 0;
        foreach ($cites as $i => $c) {
            $sum += $c;
            $rank = $i + 1;
            if ($sum >= ($rank * $rank)) {
                $g = $rank;
            }
        }
        return $g;
    }

    private function g_index_core_entries($rows) {
        $sorted = is_array($rows) ? $rows : array();
        usort($sorted, function($a, $b){ return (int) ($b['Citations'] ?? 0) <=> (int) ($a['Citations'] ?? 0); });
        $g = $this->calculate_g_index($sorted);
        return $g > 0 ? array_slice($sorted, 0, $g) : array();
    }

    private function sort_rows_by_year_desc($a, $b) {
        return (int) ($b['Year'] ?? 0) <=> (int) ($a['Year'] ?? 0);
    }

    private function sort_rows_by_year_asc($a, $b) {
        return (int) ($a['Year'] ?? 0) <=> (int) ($b['Year'] ?? 0);
    }

    private function sort_rows_by_citations_desc($a, $b) {
        return (int) ($b['Citations'] ?? 0) <=> (int) ($a['Citations'] ?? 0);
    }

    private function sort_rows_by_citations_asc($a, $b) {
        return (int) ($a['Citations'] ?? 0) <=> (int) ($b['Citations'] ?? 0);
    }

    private function read_json_files($json_dir) {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . trim((string) $json_dir, '/');
        $entries = array();

        if (!is_dir($base)) {
            return $entries;
        }

        $files = glob($base . '/*.json');
        if (!$files) {
            return $entries;
        }

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if (!is_string($raw) || trim($raw) === '') continue;
            $json = json_decode($raw, true);
            if (!is_array($json)) continue;

            if (isset($json['entries']) && is_array($json['entries'])) {
                $entries = array_merge($entries, $json['entries']);
            } elseif (isset($json[0]) && is_array($json[0])) {
                $entries = array_merge($entries, $json);
            }
        }

        return $entries;
    }

    private function read_manual_csv($manual_csv) {
        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['basedir']) . trim((string) $manual_csv, '/');

        if (!is_file($path) || !is_readable($path)) {
            return array();
        }

        $fh = fopen($path, 'r');
        if (!$fh) return array();

        $rows = array();
        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            return array();
        }

        while (($line = fgetcsv($fh)) !== false) {
            $assoc = array();
            foreach ($headers as $i => $head) {
                $assoc[$head] = $line[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        fclose($fh);
        return $rows;
    }

    private function render_manual_sections($manual) {
        if (!is_array($manual) || empty($manual)) return;

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Custom Metrics', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-table-wrap"><table class="widefat striped"><thead><tr>';

        $keys = array_keys($manual[0]);
        foreach ($keys as $key) {
            echo '<th>' . esc_html($key) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($manual as $row) {
            echo '<tr>';
            foreach ($keys as $key) {
                echo '<td>' . esc_html((string) ($row[$key] ?? '')) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';
    }



    private function read_extra_metrics($json_dir) {
        $saved = get_option(self::OPT_EXTRA_METRICS, array());
        if (is_array($saved) && !empty($saved)) {
            return $this->normalize_extra_metrics($saved);
        }

        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['basedir']) . trim((string) $json_dir, '/') . '/manual-metrics.json';

        if (is_file($path) && is_readable($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json)) {
                return $this->normalize_extra_metrics($json);
            }
        }

        return $this->default_extra_metrics();
    }


    private function default_extra_metrics() {
        return array(
            'wos' => array(
                'total_publications' => 1320,
                'total_citations'    => 17993,
                'h_index'            => 59,
            ),
            'research_areas' => array(
                'scopus' => array(
                    'Computer Science',
                    'Engineering',
                    'Mathematics',
                    'Decision Sciences',
                    'Physics and Astronomy',
                ),
                'wos' => array(
                    'Computer Science',
                    'Engineering',
                    'Telecommunications',
                    'Physics',
                    'Material Science',
                ),
            ),
            'manual_subject_areas' => array(),
            'sdg' => array(
                array('goal' => 'Goal 1: No poverty', 'documents' => 27),
                array('goal' => 'Goal 2: Zero hunger', 'documents' => 72),
                array('goal' => 'Goal 3: Good health and well-being', 'documents' => 481),
                array('goal' => 'Goal 4: Quality education', 'documents' => 68),
                array('goal' => 'Goal 5: Gender equality', 'documents' => 41),
                array('goal' => 'Goal 6: Clean water and sanitation', 'documents' => 46),
                array('goal' => 'Goal 7: Affordable and clean energy', 'documents' => 212),
                array('goal' => 'Goal 8: Decent work and economic growth', 'documents' => 129),
                array('goal' => 'Goal 9: Industry, innovation and infrastructure', 'documents' => 301),
                array('goal' => 'Goal 10: Reduced inequalities', 'documents' => 62),
                array('goal' => 'Goal 11: Sustainable cities and communities', 'documents' => 137),
                array('goal' => 'Goal 12: Responsible consumption and production', 'documents' => 101),
                array('goal' => 'Goal 13: Climate action', 'documents' => 77),
                array('goal' => 'Goal 14: Life below water', 'documents' => 11),
                array('goal' => 'Goal 15: Life on land', 'documents' => 22),
                array('goal' => 'Goal 16: Peace, justice and strong institutions', 'documents' => 67),
                array('goal' => 'Goal 17: Partnership for the goals', 'documents' => 118),
            ),
        );
    }

    private function fetch_scopus_facets($api_key, $afid) {
        $afid = $this->clean_affiliation_id($afid);

        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );

        $subject_counts   = array();
        $subject_code_map = array();
        $oa_categories    = array();

        $queries = array(
            'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&count=0&facets=subjarea(count=200,sort=fd),freetoreadLabel(count=20,sort=fd),freetoread(count=20,sort=fd)&httpAccept=application/json',
            'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&count=25&start=0&facets=subjarea(count=200,sort=fd),freetoreadLabel(count=20,sort=fd),freetoread(count=20,sort=fd)&httpAccept=application/json',
            'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&count=0&facets=subjectarea(count=200),freetoreadLabel(count=20),freetoread(count=20)&httpAccept=application/json',
            'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ')&count=25&start=0&facets=subjectarea(count=200),freetoreadLabel(count=20),freetoread(count=20)&httpAccept=application/json',
        );

        $facets = array();
        foreach ($queries as $url) {
            $json = $this->api_get_json($url, $headers);
            if (!empty($json['search-results']['facet']) && is_array($json['search-results']['facet'])) {
                $facets = $json['search-results']['facet'];
                break;
            }
        }

        foreach ($facets as $facet) {
            if (!is_array($facet)) continue;

            $name = strtolower(trim((string) ($facet['@name'] ?? $facet['name'] ?? '')));
            $values = $facet['facet-value'] ?? ($facet['value'] ?? array());
            if (isset($values['@label']) || isset($values['$'])) {
                $values = array($values);
            }
            if (!is_array($values)) continue;

            foreach ($values as $row) {
                if (!is_array($row)) continue;

                $label = trim((string) ($row['@label'] ?? $row['label'] ?? $row['$'] ?? $row['value'] ?? $row['name'] ?? ''));
                $count = (int) ($row['@count'] ?? $row['count'] ?? 0);
                $code  = trim((string) ($row['@abbrev'] ?? $row['abbrev'] ?? $row['@code'] ?? $row['code'] ?? ''));

                if ($count <= 0) continue;

                if ($name === 'subjarea' || $name === 'subjectarea') {
                    if ($label === '' && $code !== '') {
                        $label = $this->map_subject_code_to_label($code);
                    }
                    if ($label === '') continue;

                    if (!isset($subject_counts[$label])) {
                        $subject_counts[$label] = 0;
                    }
                    $subject_counts[$label] += $count;

                    if ($code !== '') {
                        $subject_code_map[$label] = strtoupper($code);
                    }
                } elseif ($name === 'freetoread' || $name === 'freetoreadlabel' || $name === 'openaccess') {
                    $raw_key = $label !== '' ? $label : $code;
                    if ($raw_key === '' && !empty($row['$'])) {
                        $raw_key = trim((string) $row['$']);
                    }
                    if ($raw_key === '') continue;

                    if (!isset($oa_categories[$raw_key])) {
                        $oa_categories[$raw_key] = 0;
                    }
                    $oa_categories[$raw_key] += $count;
                }
            }
        }

        arsort($subject_counts);

        return array(
            'subject_counts'   => $subject_counts,
            'subject_code_map' => $subject_code_map,
            'oa_categories'    => $oa_categories,
        );
    }

    private function fetch_live_publications_for_subject($api_key, $afid, $subject_code) {
        $subject_code = strtoupper(trim((string) $subject_code));
        if ($subject_code === '') return array();

        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );

        $rows = array();
        $per_page = 25;
        $start = 0;
        $max_pages = 25;

        for ($page = 0; $page < $max_pages; $page++) {
            $json = $this->api_get_json(
                'https://api.elsevier.com/content/search/scopus?query=AF-ID(' . rawurlencode($afid) . ') AND SUBJAREA(' . rawurlencode($subject_code) . ')&view=STANDARD&count=' . $per_page . '&start=' . $start . '&sort=coverDate&httpAccept=application/json',
                $headers
            );

            if (empty($json['search-results']['entry']) || !is_array($json['search-results']['entry'])) {
                break;
            }

            foreach ($json['search-results']['entry'] as $entry) {
                $normalized = $this->normalize_entry($entry);
                if (!empty($normalized) && !$this->is_excluded_document($normalized)) {
                    if (empty($normalized['Subject Areas'])) {
                        $normalized['Subject Areas'] = array($subject_code);
                    }
                    $rows[] = $normalized;
                }
            }

            $start += $per_page;
            $total = (int) ($json['search-results']['opensearch:totalResults'] ?? 0);
            if ($total > 0 && $start >= $total) {
                break;
            }
        }

        return $rows;
    }

    private function normalize_oa_counts($raw_counts, $open_access_total = 0) {
        $normalized = array(
            'All open access' => max(0, (int) $open_access_total),
            'Gold'            => 0,
            'Green'           => 0,
            'Hybrid gold'     => 0,
            'Bronze'          => 0,
        );

        if (!is_array($raw_counts)) {
            return $normalized;
        }

        foreach ($raw_counts as $label => $count) {
            $count = max(0, (int) $count);
            $key = strtolower(trim((string) $label));
            $key = str_replace(array('_', '-'), ' ', $key);
            $key = preg_replace('/\s+/', ' ', $key);

            if ($key === '' || $count <= 0) {
                continue;
            }

            if (in_array($key, array('open access', 'all open access', 'all oa', 'all open access articles'), true)) {
                $normalized['All open access'] = max($normalized['All open access'], $count);
            } elseif (strpos($key, 'publisherfullgold') !== false || $key === 'gold' || (strpos($key, 'gold') !== false && strpos($key, 'hybrid') === false)) {
                $normalized['Gold'] += $count;
            } elseif (strpos($key, 'repository') !== false || strpos($key, 'green') !== false) {
                $normalized['Green'] += $count;
            } elseif (strpos($key, 'hybrid') !== false) {
                $normalized['Hybrid gold'] += $count;
            } elseif (strpos($key, 'bronze') !== false || strpos($key, 'free2read') !== false) {
                $normalized['Bronze'] += $count;
            }
        }

        return $normalized;
    }

    private function merge_oa_counts($base, $extra) {
        $keys = array('All open access', 'Gold', 'Green', 'Hybrid gold', 'Bronze');
        $out = array();
        foreach ($keys as $key) {
            $out[$key] = max((int) ($base[$key] ?? 0), (int) ($extra[$key] ?? 0));
        }
        return $out;
    }

    private function normalize_extra_metrics($data) {
        $defaults = $this->default_extra_metrics();

        $wos = isset($data['wos']) && is_array($data['wos']) ? $data['wos'] : array();
        $areas = isset($data['research_areas']) && is_array($data['research_areas']) ? $data['research_areas'] : array();
        $manual_subjects = isset($data['manual_subject_areas']) && is_array($data['manual_subject_areas']) ? $data['manual_subject_areas'] : array();
        $sdg = isset($data['sdg']) && is_array($data['sdg']) ? $data['sdg'] : array();

        $normalized = array(
            'wos' => array(
                'total_publications' => max(0, (int) ($wos['total_publications'] ?? $defaults['wos']['total_publications'])),
                'total_citations'    => max(0, (int) ($wos['total_citations'] ?? $defaults['wos']['total_citations'])),
                'h_index'            => max(0, (int) ($wos['h_index'] ?? $defaults['wos']['h_index'])),
            ),
            'research_areas' => array(
                'scopus' => array_values(array_filter(array_map('sanitize_text_field', (array) ($areas['scopus'] ?? $defaults['research_areas']['scopus'])))),
                'wos'    => array_values(array_filter(array_map('sanitize_text_field', (array) ($areas['wos'] ?? $defaults['research_areas']['wos'])))),
            ),
            'manual_subject_areas' => array(),
            'sdg' => array(),
        );

        foreach ($manual_subjects as $row) {
            if (!is_array($row)) continue;
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $count = max(0, (int) ($row['count'] ?? 0));
            if ($label === '') continue;
            $normalized['manual_subject_areas'][] = array(
                'label' => $label,
                'count' => $count,
            );
        }

        foreach ($sdg as $row) {
            if (!is_array($row)) continue;
            $goal = sanitize_text_field((string) ($row['goal'] ?? ''));
            $documents = max(0, (int) ($row['documents'] ?? 0));
            if ($goal === '') continue;
            $normalized['sdg'][] = array(
                'goal' => $goal,
                'documents' => $documents,
            );
        }

        if (empty($normalized['sdg'])) {
            $normalized['sdg'] = $defaults['sdg'];
        }

        return $normalized;
    }

    private function pretty_json($value) {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '[]';
    }

    private function parse_subject_area_json($json_text) {
        $decoded = json_decode((string) $json_text, true);
        $out = array();

        if (is_array($decoded)) {
            $is_list = array_keys($decoded) === range(0, count($decoded) - 1);
            if ($is_list) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $label = sanitize_text_field((string) ($row['label'] ?? $row['subject'] ?? $row['name'] ?? ''));
                    $count = max(0, (int) ($row['count'] ?? $row['documents'] ?? $row['value'] ?? 0));
                    if ($label === '') {
                        continue;
                    }
                    $out[] = array('label' => $label, 'count' => $count);
                }
            } else {
                foreach ($decoded as $label => $count) {
                    $label = sanitize_text_field((string) $label);
                    if ($label === '') {
                        continue;
                    }
                    $out[] = array('label' => $label, 'count' => max(0, (int) $count));
                }
            }
        }

        return $out;
    }

    private function parse_sdg_json($json_text) {
        $decoded = json_decode((string) $json_text, true);
        $out = array();

        if (is_array($decoded)) {
            $is_list = array_keys($decoded) === range(0, count($decoded) - 1);
            if ($is_list) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $goal = sanitize_text_field((string) ($row['goal'] ?? $row['label'] ?? $row['name'] ?? ''));
                    $documents = max(0, (int) ($row['documents'] ?? $row['count'] ?? $row['value'] ?? 0));
                    if ($goal === '') {
                        continue;
                    }
                    $out[] = array('goal' => $goal, 'documents' => $documents);
                }
            } else {
                foreach ($decoded as $goal => $documents) {
                    $goal = sanitize_text_field((string) $goal);
                    if ($goal === '') {
                        continue;
                    }
                    $out[] = array('goal' => $goal, 'documents' => max(0, (int) $documents));
                }
            }
        }

        return $out;
    }

    private function textarea_to_lines($text) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $out = array();
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = sanitize_text_field(trim((string) $line));
                if ($line !== '') $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    private function write_extra_metrics_json($metrics) {
        $settings = $this->get_settings();
        $uploads  = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . trim((string) ($settings['json_dir'] ?? 'scopus-mis-cache'), '/');
        if (!wp_mkdir_p($dir)) {
            return;
        }
        $path = trailingslashit($dir) . 'manual-metrics.json';
        @file_put_contents($path, wp_json_encode($this->normalize_extra_metrics($metrics), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function render_sdg_chart($sdg) {
        if (!is_array($sdg) || empty($sdg)) return;

        $labels = array();
        $counts = array();
        foreach ($sdg as $row) {
            $labels[] = (string) ($row['goal'] ?? '');
            $counts[] = (int) ($row['documents'] ?? 0);
        }

        echo '<div class="bu-rd-card">';
        echo '<h2 class="bu-rd-sdg-title">' . esc_html__('SDG Publication Metrics', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-chart-wrap bu-rd-sdg-wrap">';
        echo '<canvas id="bu-rd-sdg-chart" height="220" data-labels="' . esc_attr(wp_json_encode($labels)) . '" data-counts="' . esc_attr(wp_json_encode($counts)) . '"></canvas>';
        echo '</div>';
        echo '</div>';
    }



    private function maybe_refresh_dspace_cache($data) {
        if (!is_array($data)) {
            return $data;
        }

        $settings = $this->get_settings();
        $ttl      = max(60, (int) ($settings['dspace_cache_ttl'] ?? 600));
        $last     = (int) ($data['stats']['dspace_cache_time'] ?? 0);

        if ($last > 0 && (time() - $last) < $ttl) {
            return $data;
        }

        $dspace = $this->fetch_dspace_thesis_data($settings);
        $data['stats']['dspace_theses_total']   = (int) ($dspace['total'] ?? 0);
        $data['stats']['dspace_theses_details'] = isset($dspace['items']) && is_array($dspace['items']) ? $dspace['items'] : array();
        $data['stats']['dspace_cache_time']     = time();
        if (!empty($dspace['error'])) {
            $data['stats']['dspace_error'] = (string) $dspace['error'];
        }

        update_option(self::OPT_CACHE, $data, false);
        return $data;
    }

    private function dspace_repo_url($settings = array()) {
        $repo = trim((string) ($settings['dspace_repo_url'] ?? ''));
        if ($repo !== '') {
            return untrailingslashit($repo);
        }

        $api = trim((string) ($settings['dspace_api_base'] ?? ''));
        if ($api === '') {
            return '';
        }

        $api = untrailingslashit($api);
        return preg_replace('#/server/api$#', '', $api);
    }

    private function dspace_api_base($settings = array()) {
        $api = trim((string) ($settings['dspace_api_base'] ?? ''));
        if ($api !== '') {
            return untrailingslashit($api);
        }

        $repo = trim((string) ($settings['dspace_repo_url'] ?? ''));
        if ($repo === '') {
            return '';
        }

        return untrailingslashit($repo) . '/server/api';
    }

    private function fetch_dspace_thesis_data($settings = array(), $job_id = '') {
        $api_base = $this->dspace_api_base($settings);
        $repo_url = $this->dspace_repo_url($settings);
        if ($api_base === '') {
            return array('total' => 0, 'items' => array(), 'error' => '');
        }

        $ttl       = max(60, (int) ($settings['dspace_cache_ttl'] ?? 600));
        $page_size = max(1, (int) ($settings['dspace_deep_scan_page_size'] ?? 100));
        $max_pages = max(1, (int) ($settings['dspace_deep_scan_max_pages'] ?? 100));
        $key       = 'bu_scopus_dspace_theses_v2_' . md5($api_base . '|' . $repo_url . '|' . $page_size . '|' . $max_pages);

        $cached = get_transient($key);
        if ($job_id === '' && is_array($cached) && isset($cached['total']) && isset($cached['items'])) {
            return $cached;
        }

        $items = array();
        $seen  = array();
        $error = '';

        for ($page = 0; $page < $max_pages; $page++) {
            $percent = 88 + min(8, (int) floor((($page + 1) / max(1, $max_pages)) * 8));
            $this->set_progress($job_id, $percent, sprintf(__('Scanning DSpace theses page %1$d of %2$d...', 'bu-scopus-research-dashboard'), $page + 1, $max_pages));

            $url = $api_base . '/discover/search/objects?dsoType=item&sort=dc.date.accessioned,DESC&size=' . $page_size . '&page=' . $page;
            $json = $this->dspace_request_json($url, $settings);

            if (!is_array($json)) {
                $error = __('Could not retrieve DSpace thesis data.', 'bu-scopus-research-dashboard');
                break;
            }

            $objects = $this->dspace_objects_from_response($json);
            if (empty($objects)) {
                break;
            }

            foreach ($objects as $object) {
                $item = $this->normalize_dspace_thesis_item($object, $repo_url);
                if (empty($item)) {
                    continue;
                }

                $item['collection'] = $this->resolve_dspace_collection_name($item, $settings);

                $unique_key = strtolower(trim((string) ($item['handle'] ?? '')));
                if ($unique_key === '') {
                    $unique_key = md5((string) ($item['title'] ?? '') . '|' . (string) ($item['year'] ?? ''));
                }
                if (isset($seen[$unique_key])) {
                    continue;
                }
                $seen[$unique_key] = true;
                $items[] = $item;
            }

            if (count($objects) < $page_size) {
                break;
            }
        }

        usort($items, function($a, $b){
            return (int) ($b['year'] ?? 0) <=> (int) ($a['year'] ?? 0);
        });

        $payload = array(
            'total' => count($items),
            'items' => $items,
            'error' => $error,
        );

        set_transient($key, $payload, $ttl);
        return $payload;
    }

    private function dspace_request_json($url, $settings = array()) {
        $args = array(
            'timeout'   => max(5, (int) ($settings['dspace_timeout'] ?? 20)),
            'sslverify' => !empty($settings['dspace_verify_ssl']),
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        );

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return null;
        }

        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function dspace_objects_from_response($json) {
        if (!is_array($json)) {
            return array();
        }

        $candidates = array(
            $json['_embedded']['searchResult']['_embedded']['objects'] ?? null,
            $json['_embedded']['objects'] ?? null,
            $json['searchResult']['_embedded']['objects'] ?? null,
            $json['objects'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate)) {
                return $candidate;
            }
        }

        return array();
    }

    private function dspace_metadata_values($metadata, $key) {
        if (!is_array($metadata) || $key === '') {
            return array();
        }

        $values = array();
        if (!empty($metadata[$key]) && is_array($metadata[$key])) {
            foreach ($metadata[$key] as $row) {
                if (is_array($row) && isset($row['value'])) {
                    $values[] = trim((string) $row['value']);
                } elseif (is_string($row)) {
                    $values[] = trim($row);
                }
            }
        }

        return array_values(array_filter($values, function($value){
            return $value !== '';
        }));
    }

    private function dspace_metadata_first($metadata, $keys) {
        foreach ((array) $keys as $key) {
            $values = $this->dspace_metadata_values($metadata, $key);
            if (!empty($values)) {
                return (string) $values[0];
            }
        }
        return '';
    }

    private function normalize_dspace_handle_url($value, $repo_url) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        if (stripos($value, 'handle/') !== false) {
            return untrailingslashit($repo_url) . '/' . ltrim($value, '/');
        }

        if (preg_match('#^\d+/\d+#', $value)) {
            return untrailingslashit($repo_url) . '/handle/' . ltrim($value, '/');
        }

        return $value;
    }

    private function clean_dspace_abstract($abstract, $handle_url = '') {
        $abstract = trim(wp_strip_all_tags((string) $abstract));
        if ($abstract === '') {
            return '';
        }

        $handle_url = trim((string) $handle_url);
        if ($handle_url !== '' && stripos($abstract, $handle_url) !== false) {
            return '';
        }

        if (preg_match('#^https?://#i', $abstract) && stripos($abstract, '/handle/') !== false) {
            return '';
        }

        return $abstract;
    }

    private function normalize_dspace_thesis_item($object, $repo_url = '') {
        if (!is_array($object)) {
            return array();
        }

        $indexable = array();
        if (!empty($object['_embedded']['indexableObject']) && is_array($object['_embedded']['indexableObject'])) {
            $indexable = $object['_embedded']['indexableObject'];
        } elseif (!empty($object['indexableObject']) && is_array($object['indexableObject'])) {
            $indexable = $object['indexableObject'];
        } else {
            $indexable = $object;
        }

        $metadata = isset($indexable['metadata']) && is_array($indexable['metadata']) ? $indexable['metadata'] : array();

        $types = array_merge(
            $this->dspace_metadata_values($metadata, 'dc.type'),
            $this->dspace_metadata_values($metadata, 'dc.type.uri')
        );

        $is_thesis = false;
        foreach ($types as $type) {
            if (stripos((string) $type, 'thesis') !== false) {
                $is_thesis = true;
                break;
            }
        }
        if (!$is_thesis) {
            return array();
        }

        $title = $this->dspace_metadata_first($metadata, array('dc.title'));
        if ($title === '') {
            return array();
        }

        $authors = $this->dspace_metadata_values($metadata, 'dc.contributor.author');
        if (empty($authors)) {
            $authors = $this->dspace_metadata_values($metadata, 'dc.creator');
        }
        if (empty($authors)) {
            $authors = $this->dspace_metadata_values($metadata, 'dc.contributor');
        }

        $year_source = $this->dspace_metadata_first($metadata, array('dc.date.issued', 'dc.date.accessioned'));
        $year = 0;
        if ($year_source !== '' && preg_match('/\b(19|20)\d{2}\b/', $year_source, $m)) {
            $year = (int) $m[0];
        }

        $handle = $this->dspace_metadata_first($metadata, array('dc.identifier.uri', 'dc.identifier.handle'));
        if ($handle === '' && !empty($indexable['handle'])) {
            $handle = (string) $indexable['handle'];
        }
        $handle = $this->normalize_dspace_handle_url($handle, $repo_url);

        $abstract = $this->clean_dspace_abstract(
            $this->dspace_metadata_first($metadata, array('dc.description.abstract', 'dc.description')),
            $handle
        );

        $collection = $this->extract_dspace_collection_name($object, $indexable, $metadata);

        $uuid = '';
        foreach (array($indexable['uuid'] ?? '', $indexable['id'] ?? '', $object['uuid'] ?? '', $object['id'] ?? '') as $id_candidate) {
            $id_candidate = trim((string) $id_candidate);
            if ($id_candidate !== '') {
                $uuid = $id_candidate;
                break;
            }
        }

        return array(
            'uuid'     => $uuid,
            'title'    => $title,
            'authors'  => array_values(array_unique(array_filter(array_map('trim', $authors)))),
            'authors_text' => implode(', ', array_values(array_unique(array_filter(array_map('trim', $authors))))),
            'year'     => $year,
            'handle'   => $handle,
            'abstract' => $abstract,
            'collection' => $collection,
        );
    }


    private function extract_dspace_collection_name($object, $indexable, $metadata) {
        $candidates = array();

        $paths = array(
            $object['_embedded']['owningCollection']['name'] ?? '',
            $object['_embedded']['indexableObject']['_embedded']['owningCollection']['name'] ?? '',
            $indexable['_embedded']['owningCollection']['name'] ?? '',
            $object['owningCollection']['name'] ?? '',
            $indexable['owningCollection']['name'] ?? '',
        );
        foreach ($paths as $value) {
            $value = trim((string) $value);
            if ($value !== '') $candidates[] = $value;
        }

        $meta_candidates = array(
            'thesis.degree.department',
            'dc.publisher.department',
            'dc.contributor.department',
            'local.department',
            'dc.subject.department',
            'dc.relation.ispartof',
            'dc.relation.ispartofseries',
            'dc.publisher',
        );
        foreach ($meta_candidates as $key) {
            foreach ($this->dspace_metadata_values($metadata, $key) as $value) {
                $value = trim((string) $value);
                if ($value !== '') $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') continue;
            if (preg_match('/school|department|scset|management|law|media|engineering|science|liberal arts/i', $candidate)) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '') return $candidate;
        }

        return '';
    }


    private function is_generic_dspace_collection_name($name) {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return true;
        }
        if (in_array($name, array('bennett university', 'thesis', 'theses', '2. bu archives', 'bu archives', 'repository', 'all of dspace'), true)) {
            return true;
        }
        return false;
    }

    private function choose_best_dspace_collection_name($names) {
        $preferred = array();
        $fallback = array();
        foreach ((array) $names as $name) {
            $name = trim(wp_strip_all_tags((string) $name));
            if ($name === '') continue;
            if (preg_match('/^(home|all of dspace)$/i', $name)) continue;
            if (preg_match('/school|department|scset|cse|engineering|management|law|media|liberal arts|artificial intelligence|applied science/i', $name)) {
                $preferred[] = $name;
            } else {
                $fallback[] = $name;
            }
        }
        foreach (array_merge($preferred, $fallback) as $name) {
            if (!$this->is_generic_dspace_collection_name($name)) {
                return $name;
            }
        }
        return $preferred[0] ?? $fallback[0] ?? '';
    }

    private function dspace_collection_names_from_response($json) {
        $names = array();
        if (!is_array($json)) {
            return $names;
        }

        $containers = array();
        if (!empty($json['_embedded']) && is_array($json['_embedded'])) {
            foreach ($json['_embedded'] as $embedded) {
                if (is_array($embedded)) {
                    $containers[] = $embedded;
                }
            }
        }
        $containers[] = $json;

        foreach ($containers as $container) {
            if (isset($container['name'])) {
                $names[] = (string) $container['name'];
            }
            foreach ($container as $value) {
                if (is_array($value) && isset($value['name'])) {
                    $names[] = (string) $value['name'];
                } elseif (is_array($value)) {
                    foreach ($value as $row) {
                        if (is_array($row) && isset($row['name'])) {
                            $names[] = (string) $row['name'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $names))));
    }

    private function extract_dspace_collection_from_handle_html($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $patterns = array(
            '#<div[^>]*class="[^"]*simple-item-view-uri[^"]*"[^>]*>.*?</div>#is',
            '#<dt[^>]*>\s*Collections\s*</dt>\s*<dd[^>]*>(.*?)</dd>#is',
            '#>\s*Collections\s*<.*?<a[^>]*>(.*?)</a>#is',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $segment = $m[1] ?? '';
                preg_match_all('#<a[^>]*>(.*?)</a>#is', $segment, $links);
                $names = array();
                foreach (($links[1] ?? array()) as $label) {
                    $label = trim(wp_strip_all_tags(html_entity_decode((string) $label, ENT_QUOTES | ENT_HTML5)));
                    if ($label !== '') $names[] = $label;
                }
                $best = $this->choose_best_dspace_collection_name($names);
                if ($best !== '') {
                    return $best;
                }
                $plain = trim(wp_strip_all_tags(html_entity_decode((string) $segment, ENT_QUOTES | ENT_HTML5)));
                if ($plain !== '') {
                    return $plain;
                }
            }
        }

        return '';
    }

    private function resolve_dspace_collection_name($item, $settings = array()) {
        $current = trim((string) ($item['collection'] ?? ''));
        if (!$this->is_generic_dspace_collection_name($current)) {
            return $current;
        }

        $uuid = trim((string) ($item['uuid'] ?? ''));
        $handle = trim((string) ($item['handle'] ?? ''));
        $api_base = $this->dspace_api_base($settings);
        $cache_key = 'bu_scopus_dspace_coll_' . md5($uuid . '|' . $handle);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $names = array();
        if ($uuid !== '' && $api_base !== '') {
            foreach (array('/core/items/' . rawurlencode($uuid) . '/mappedCollections', '/core/items/' . rawurlencode($uuid) . '/owningCollection') as $path) {
                $json = $this->dspace_request_json($api_base . $path, $settings);
                $names = array_merge($names, $this->dspace_collection_names_from_response($json));
            }
        }

        $best = $this->choose_best_dspace_collection_name($names);
        if ($best === '' && $handle !== '') {
            $response = wp_remote_get($handle, array(
                'timeout'   => max(5, (int) ($settings['dspace_timeout'] ?? 20)),
                'sslverify' => !empty($settings['dspace_verify_ssl']),
                'headers'   => array('Accept' => 'text/html'),
            ));
            if (!is_wp_error($response)) {
                $best = $this->extract_dspace_collection_from_handle_html((string) wp_remote_retrieve_body($response));
            }
        }

        if ($best === '') {
            $best = $current;
        }
        if ($best !== '') {
            set_transient($cache_key, $best, 7 * DAY_IN_SECONDS);
        }
        return $best;
    }

    private function dspace_matches_filters($item, $author_l = '', $title_l = '', $year_l = '') {
        $authors = strtolower((string) ($item['authors_text'] ?? ''));
        $title   = strtolower((string) ($item['title'] ?? ''));
        $year    = strtolower((string) ($item['year'] ?? ''));

        if ($author_l !== '' && stripos($authors, $author_l) === false) {
            return false;
        }
        if ($title_l !== '' && stripos($title, $title_l) === false) {
            return false;
        }
        if ($year_l !== '' && stripos($year, $year_l) === false) {
            return false;
        }
        return true;
    }

    private function dspace_row_for_modal($item) {
        $detail = array(
            'type'       => 'dspace',
            'title'      => (string) ($item['title'] ?? ''),
            'authors'    => (string) ($item['authors_text'] ?? ''),
            'year'       => (string) ($item['year'] ?? ''),
            'collection' => (string) ($item['collection'] ?? ''),
            'handle'     => (string) ($item['handle'] ?? ''),
            'abstract'   => (string) ($item['abstract'] ?? ''),
        );

        return array(
            'Title'           => (string) ($item['title'] ?? ''),
            'Authors'         => (string) ($item['authors_text'] ?? ''),
            'Collections'     => (string) ($item['collection'] ?? ''),
            'Year'            => (string) ($item['year'] ?? ''),
            'Handle'          => (string) ($item['handle'] ?? ''),
            '__DETAIL_TYPE'   => 'dspace',
            '__DETAIL_B64'    => base64_encode(wp_json_encode($detail)),
            '__AUTHORS_HTML'  => '',
        );
    }

    private function render_manual_subject_area_chart($subjects) {
        if (!is_array($subjects) || empty($subjects)) {
            return;
        }

        $labels = array();
        $counts = array();
        foreach ($subjects as $row) {
            if (!is_array($row)) continue;
            $label = (string) ($row['label'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            if ($label === '') continue;
            $labels[] = $label;
            $counts[] = $count;
        }

        if (empty($labels)) {
            return;
        }

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Subject Areas', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-chart-wrap bu-rd-subject-wrap">';
        echo '<canvas id="bu-rd-manual-subject-chart" height="360" data-labels="' . esc_attr(wp_json_encode($labels)) . '" data-counts="' . esc_attr(wp_json_encode($counts)) . '"></canvas>';
        echo '</div>';
        echo '</div>';
    }
    private function render_subject_chart($subjects, $subject_code_map = array(), $click_metric = 'subject_publications') {
        if (!is_array($subjects) || empty($subjects)) return;

        $labels = array();
        $counts = array();
        $codes  = array();

        foreach ($subjects as $name => $count) {
            $labels[] = (string) $name;
            $counts[] = (int) $count;
            $codes[]  = isset($subject_code_map[$name]) && (string) $subject_code_map[$name] !== ''
                ? (string) $subject_code_map[$name]
                : strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $name), 0, 4));
        }

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Subject Areas', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-chart-wrap bu-rd-subject-wrap">';
        echo '<canvas id="bu-rd-subject-chart" height="360" data-click-metric="' . esc_attr($click_metric) . '" data-labels="' . esc_attr(wp_json_encode($labels)) . '" data-counts="' . esc_attr(wp_json_encode($counts)) . '" data-codes="' . esc_attr(wp_json_encode($codes)) . '"></canvas>';
        echo '</div>';
        echo '</div>';
    }

    private function render_wos_kpis($wos) {
        if (!is_array($wos) || empty($wos)) return;

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Web of Science', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-grid bu-rd-wos-grid">';
        $this->kpi_card(__('Web of Science Total Publications', 'bu-scopus-research-dashboard'), $wos['total_publications'] ?? 0, 'manual_metric', 'border9');
        $this->kpi_card(__('Web of Science Total Citations', 'bu-scopus-research-dashboard'), $wos['total_citations'] ?? 0, 'manual_metric', 'border2');
        $this->kpi_card(__('Web of Science h-index', 'bu-scopus-research-dashboard'), $wos['h_index'] ?? 0, 'manual_metric', 'border5');
        echo '</div>';
        echo '</div>';
    }

    private function render_research_areas_section($areas) {
        if (!is_array($areas) || empty($areas)) return;

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('Top Research Areas', 'bu-scopus-research-dashboard') . '</h2>';
        echo '<div class="bu-rd-area-grid">';
        echo '<div class="bu-rd-area-box bu-rd-area-box-scopus"><div class="bu-rd-area-head"><span class="bu-rd-area-icon">🧠</span><h3>' . esc_html__('Scopus', 'bu-scopus-research-dashboard') . '</h3></div><div class="bu-rd-area-pills">';
        foreach ((array) ($areas['scopus'] ?? array()) as $item) {
            echo '<span class="bu-rd-area-pill">' . esc_html((string) $item) . '</span>';
        }
        echo '</div></div>';
        echo '<div class="bu-rd-area-box bu-rd-area-box-wos"><div class="bu-rd-area-head"><span class="bu-rd-area-icon">🌐</span><h3>' . esc_html__('Web of Science', 'bu-scopus-research-dashboard') . '</h3></div><div class="bu-rd-area-pills">';
        foreach ((array) ($areas['wos'] ?? array()) as $item) {
            echo '<span class="bu-rd-area-pill">' . esc_html((string) $item) . '</span>';
        }
        echo '</div></div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_dashboard_footer() {
        echo '<div class="bu-rd-footer">';
        echo '<div class="bu-rd-footer-line1">Developed &amp; Maintained by <a href="https://www.linkedin.com/in/ashutosh-mishra-b13513209" target="_blank" rel="noopener noreferrer">Ashutosh Mishra</a></div>';
        echo '<div class="bu-rd-footer-line2">&copy; 2026 Bennett University. All Rights Reserved.</div>';
        echo '</div>';
    }

    private function render_extra_sections($extras) {
        if (!is_array($extras) || empty($extras)) return;

        $wos = isset($extras['wos']) && is_array($extras['wos']) ? $extras['wos'] : array();
        $areas = isset($extras['research_areas']) && is_array($extras['research_areas']) ? $extras['research_areas'] : array();
        $sdg = isset($extras['sdg']) && is_array($extras['sdg']) ? $extras['sdg'] : array();

        echo '<div class="bu-rd-card">';
        echo '<h2>' . esc_html__('External & SDG Metrics', 'bu-scopus-research-dashboard') . '</h2>';

        if (!empty($wos)) {
            echo '<div class="bu-rd-mini-grid">';
            echo '<div class="bu-rd-extra-pill"><strong>Web of Science — Total Publications</strong><span>' . esc_html(number_format_i18n((int) ($wos['total_publications'] ?? 0))) . '</span></div>';
            echo '<div class="bu-rd-extra-pill"><strong>Web of Science — Total Citations</strong><span>' . esc_html(number_format_i18n((int) ($wos['total_citations'] ?? 0))) . '</span></div>';
            echo '<div class="bu-rd-extra-pill"><strong>Web of Science — h-index</strong><span>' . esc_html(number_format_i18n((int) ($wos['h_index'] ?? 0))) . '</span></div>';
            echo '</div>';
        }

        if (!empty($areas)) {
            echo '<div class="bu-rd-area-grid">';
            echo '<div class="bu-rd-area-box"><h3>Scopus</h3><ul>';
            foreach (($areas['scopus'] ?? array()) as $item) {
                echo '<li>' . esc_html((string) $item) . '</li>';
            }
            echo '</ul></div>';
            echo '<div class="bu-rd-area-box"><h3>Web of Science</h3><ul>';
            foreach (($areas['wos'] ?? array()) as $item) {
                echo '<li>' . esc_html((string) $item) . '</li>';
            }
            echo '</ul></div>';
            echo '</div>';
        }

        if (!empty($sdg)) {
            echo '<div class="bu-rd-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('SDG Goal', 'bu-scopus-research-dashboard') . '</th><th>' . esc_html__('Documents', 'bu-scopus-research-dashboard') . '</th></tr></thead><tbody>';
            foreach ($sdg as $row) {
                echo '<tr><td>' . esc_html((string) ($row['goal'] ?? '')) . '</td><td>' . esc_html(number_format_i18n((int) ($row['documents'] ?? 0))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';
    }

    private function progress_key($job_id) {
        return 'bu_scopus_rd_progress_' . sanitize_key((string) $job_id);
    }

    private function set_progress($job_id, $percent, $message, $done = false, $error = '') {
        $job_id = sanitize_key((string) $job_id);
        if ($job_id === '') return;
        set_transient($this->progress_key($job_id), array(
            'percent' => max(0, min(100, (int) $percent)),
            'message' => (string) $message,
            'done'    => (bool) $done,
            'error'   => (string) $error,
            'time'    => time(),
        ), MINUTE_IN_SECONDS * 10);
    }

    private function extract_bennett_author_names($resp, $target_afid) {
        $target_afid = preg_replace('/\D+/', '', (string) $target_afid);
        if ($target_afid === '' || !is_array($resp)) return array();

        $match = array();

        $author_groups = $resp['item']['bibrecord']['head']['author-group'] ?? ($resp['author-group'] ?? array());
        if (isset($author_groups['author']) || isset($author_groups['affiliation'])) {
            $author_groups = array($author_groups);
        }

        if (is_array($author_groups)) {
            foreach ($author_groups as $group) {
                if (!is_array($group)) continue;
                $group_afids = array();

                foreach (array('affiliation', 'affiliation-current') as $affKey) {
                    if (empty($group[$affKey])) continue;
                    $affs = $group[$affKey];
                    if (isset($affs['@id']) || isset($affs['@afid'])) $affs = array($affs);
                    foreach ((array) $affs as $aff) {
                        if (!is_array($aff)) continue;
                        foreach (array('@id', '@afid', 'affiliation-id', 'ce:afid', '$') as $k) {
                            if (!empty($aff[$k])) {
                                preg_match_all('/\d+/', (string) $aff[$k], $m);
                                foreach (($m[0] ?? array()) as $num) $group_afids[] = $num;
                            }
                        }
                    }
                }

                $is_bennett_group = in_array($target_afid, $group_afids, true);
                if (!$is_bennett_group) continue;

                $authors = $group['author'] ?? array();
                if (isset($authors['@auid']) || isset($authors['ce:indexed-name'])) $authors = array($authors);
                foreach ((array) $authors as $author) {
                    $name = $this->extract_author_name_from_abstract($author);
                    if ($name !== '') $match[$name] = true;
                }
            }
        }

        if (empty($match) && !empty($resp['authors']['author']) && is_array($resp['authors']['author'])) {
            foreach ($resp['authors']['author'] as $author) {
                $name = $this->extract_author_name_from_abstract($author);
                if ($name === '') continue;
                $afids = array();
                foreach (array('affiliation', 'affiliation-current') as $k) {
                    if (empty($author[$k])) continue;
                    $affs = $author[$k];
                    if (isset($affs['@id']) || isset($affs['@afid'])) $affs = array($affs);
                    foreach ((array) $affs as $aff) {
                        if (!is_array($aff)) continue;
                        foreach (array('@id', '@afid', 'affiliation-id', 'ce:afid', '$') as $ak) {
                            if (!empty($aff[$ak])) {
                                preg_match_all('/\d+/', (string) $aff[$ak], $m);
                                foreach (($m[0] ?? array()) as $num) $afids[] = $num;
                            }
                        }
                    }
                }
                if (in_array($target_afid, $afids, true)) $match[$name] = true;
            }
        }

        return array_keys($match);
    }


    private function enrich_publication_rows_with_authors($rows, $api_key, $target_afid) {
        if (!is_array($rows) || empty($rows) || $api_key === '') return $rows;
        $out = array();
        $limit = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) { $out[] = $row; continue; }
            $eid = (string) ($row['__EID'] ?? '');
            if ($eid !== '' && $limit < 25) {
                $bundle = $this->get_cached_author_bundle($eid, $api_key, $target_afid);
                if (!empty($bundle['authors'])) {
                    $row['Authors'] = implode(', ', $bundle['authors']);
                }
                if (!empty($bundle['authors_html'])) {
                    $row['__AUTHORS_HTML'] = $bundle['authors_html'];
                }
                $limit++;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function get_cached_author_bundle($eid, $api_key, $target_afid) {
        $key = 'bu_scopus_author_bundle_' . md5((string) $eid . '|' . (string) $target_afid);
        $cached = get_transient($key);
        if (is_array($cached) && !empty($cached)) return $cached;

        $headers = array(
            'X-ELS-APIKey' => $api_key,
            'Accept'       => 'application/json',
        );
        $normalized_eid = $this->normalize_eid($eid);
        $scopus_id = preg_replace('/^2-s2\.0-/', '', $normalized_eid);
        $json = $this->api_get_json('https://api.elsevier.com/content/abstract/scopus_id/' . rawurlencode($scopus_id) . '?view=FULL&httpAccept=application/json', $headers);
        if (!is_array($json) || empty($json['abstracts-retrieval-response'])) {
            $json = $this->api_get_json('https://api.elsevier.com/content/abstract/eid/' . rawurlencode($normalized_eid) . '?view=FULL&httpAccept=application/json', $headers);
        }
        $bundle = array('authors' => array(), 'authors_html' => '');
        if (is_array($json) && !empty($json['abstracts-retrieval-response'])) {
            $resp = $json['abstracts-retrieval-response'];
            $authors = array();
            if (!empty($resp['authors']['author']) && is_array($resp['authors']['author'])) {
                foreach ($resp['authors']['author'] as $a) {
                    $name = $this->extract_author_name_from_abstract($a);
                    if ($name !== '') $authors[] = $name;
                }
            }
            $authors = array_values(array_unique(array_filter($authors)));
            $bennett_authors = $this->extract_bennett_author_names($resp, $target_afid);
            $bundle = array(
                'authors' => $authors,
                'authors_html' => $this->authors_html($authors, $bennett_authors),
            );
        }
        set_transient($key, $bundle, 12 * HOUR_IN_SECONDS);
        return $bundle;
    }

    private function extract_abstract_text($resp) {
        if (!is_array($resp)) return '';

        $candidates = array(
            $resp['coredata']['dc:description'] ?? '',
            $resp['item']['bibrecord']['head']['abstracts'] ?? '',
            $resp['head']['abstracts'] ?? '',
            $resp['abstracts'] ?? '',
            $resp['item']['bibrecord']['head']['citation-title'] ?? '',
        );

        foreach ($candidates as $candidate) {
            $text = '';
            if (is_string($candidate)) {
                $text = $candidate;
            } elseif (is_array($candidate)) {
                $walker = function($value) use (&$walker, &$text) {
                    if (!empty($text)) return;
                    if (is_string($value)) {
                        $clean = trim(wp_strip_all_tags($value));
                        if ($clean !== '') {
                            $text = $clean;
                        }
                        return;
                    }
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            $walker($v);
                            if (!empty($text)) return;
                        }
                    }
                };
                $walker($candidate);
            }
            $text = trim(wp_strip_all_tags((string) $text));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function authors_html($authors, $bennett_authors = array()) {
        $authors = is_array($authors) ? $authors : array();
        $bennett = array_fill_keys($bennett_authors, true);
        $parts = array();

        foreach ($authors as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;

            if (isset($bennett[$name])) {
                $parts[] = '<span class="bu-rd-bennett-author">' . esc_html($name) . ' <small>(Bennett University)</small></span>';
            } else {
                $parts[] = '<span>' . esc_html($name) . '</span>';
            }
        }

        return implode(', ', $parts);
    }
    private function api_get_json($url, $headers = array()) {
        $args = array(
            'timeout' => 60,
            'headers' => $headers,
        );

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) return null;

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return null;

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') return null;

        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }


    private function admin_css() {
        return '
        .bu-rd-wrap{max-width:1380px;margin:0 auto}
        .bu-rd-front-wrap{padding:8px 0}
        .bu-rd-front-notice{background:#fff7ed;border:1px solid #fdba74;border-radius:14px;padding:12px 16px;margin:16px 0;color:#9a3412}
        .bu-rd-header-wrap{text-align:center;margin:12px 0 18px}
        .bu-rd-logo{max-width:400px;width:100%;height:auto}.bu-rd-front-wrap .bu-rd-logo{max-width:300px}

        .bu-rd-card,
        .mis-toolbar-card{
            background:linear-gradient(180deg,#f8fafc 0%,#eef3f8 100%);
            border:1px solid #d7dee8;
            border-radius:22px;
            padding:16px;
            margin:16px 0;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.85),0 14px 34px rgba(15,23,42,.08);
        }

        .bu-rd-aff-grid{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
        .bu-rd-aff-name{font-size:28px;font-weight:800;color:#111827;line-height:1.15}
        .bu-rd-aff-meta{color:#475569;margin-top:6px;font-size:14px}
        .bu-rd-badge{min-width:170px;background:linear-gradient(135deg,#111827,#334155);color:#fff;padding:14px 16px;border-radius:18px;text-align:center;box-shadow:0 10px 22px rgba(15,23,42,.18)}
        .bu-rd-badge span{display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.9}
        .bu-rd-badge strong{display:block;font-size:28px;line-height:1.1;margin-top:6px}

        .mis-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:36px;
            padding:8px 12px;
            border:1px solid #111827;
            border-radius:14px;
            background:#111;
            color:#fff !important;
            text-decoration:none;
            font-weight:700;
            cursor:pointer;
            min-width:118px;
            box-shadow:0 8px 18px rgba(15,23,42,.14);
        }
        .mis-btn:hover{background:#000;color:#fff !important}
        .mis-btn.secondary{background:#fff;color:#111 !important;border-color:#cbd5e1}
        .mis-btn.secondary:hover{background:#f8fafc;color:#111 !important}

        .bu-rd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}
        .bu-rd-mini-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px}
        .bu-rd-grid .bu-rd-kpi,
        .bu-rd-mini-grid .bu-rd-kpi{
            position:relative;
            overflow:hidden;
            border-radius:18px;
            padding:12px 10px 12px;
            min-height:96px;
            color:#fff;
            border:1px solid rgba(255,255,255,0.16);
            isolation:isolate;
            cursor:pointer;
            box-shadow:
                inset 0 2px 6px rgba(255,255,255,0.22),
                inset 0 -10px 18px rgba(0,0,0,0.34),
                inset 6px 6px 12px rgba(255,255,255,0.04),
                inset -8px -10px 16px rgba(0,0,0,0.20),
                0 10px 22px rgba(0,0,0,0.28),
                0 16px 34px rgba(0,0,0,0.18);
            transition:transform .25s ease, box-shadow .25s ease;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }
        .bu-rd-grid .bu-rd-kpi:hover,
        .bu-rd-mini-grid .bu-rd-kpi:hover{
            transform:translateY(-3px) scale(1.01);
        }
        .bu-rd-grid .bu-rd-kpi::before,
        .bu-rd-mini-grid .bu-rd-kpi::before{
            content:"";
            position:absolute;
            top:-34%;
            left:-8%;
            width:120%;
            height:78%;
            background:linear-gradient(to bottom,rgba(255,255,255,0.70) 0%,rgba(255,255,255,0.34) 22%,rgba(255,255,255,0.12) 50%,rgba(255,255,255,0.02) 78%,rgba(255,255,255,0) 100%);
            border-radius:0 0 55% 55%;
            transform:rotate(-4deg);
            z-index:0;
            pointer-events:none;
            filter:blur(.4px);
            mix-blend-mode:screen;
        }
        .bu-rd-grid .bu-rd-kpi::after,
        .bu-rd-mini-grid .bu-rd-kpi::after{
            content:"";
            position:absolute;
            top:16px;
            left:14px;
            width:34%;
            height:66%;
            background:linear-gradient(to right,rgba(255,255,255,0.16),rgba(255,255,255,0.06),rgba(255,255,255,0.01),transparent);
            border-radius:22px;
            filter:blur(2px);
            z-index:0;
            pointer-events:none;
        }

        .bu-rd-kpi-static{cursor:default}
        .bu-rd-kpi-static:hover{transform:none}

        .mis-kpi-chip{
            position:absolute;
            right:10px;
            bottom:10px;
            width:34px;
            height:34px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:13px;
            font-size:15px;
            color:#fff;
            background:linear-gradient(145deg,rgba(255,255,255,0.30),rgba(255,255,255,0.10) 35%,rgba(0,0,0,0.12) 100%);
            border:1px solid rgba(255,255,255,0.25);
            box-shadow:inset 0 2px 6px rgba(255,255,255,0.26),inset 0 -7px 12px rgba(0,0,0,0.24),0 8px 15px rgba(0,0,0,0.22);
            backdrop-filter:blur(3px);
            text-shadow:0 2px 8px rgba(0,0,0,0.35);
            pointer-events:none;
            z-index:1;
        }

        .kpi-label{
            position:relative;
            z-index:1;
            font-size:12px;
            font-weight:700;
            margin-bottom:8px;
            letter-spacing:.1px;
            color:#fff;
            line-height:1.25;
            min-height:26px;
            max-width:calc(100% - 54px);
        }
        .kpi-value{
            position:relative;
            z-index:1;
            font-size:19px;
            font-weight:800;
            margin-bottom:4px;
            text-shadow:0 3px 10px rgba(0,0,0,0.35);
            color:#fff;
            line-height:1.05;
            word-break:break-word;
            max-width:calc(100% - 56px);
            padding-right:2px;
        }
        .mis-kpi-foot{
            position:relative;
            z-index:1;
            display:flex;
            justify-content:flex-start;
            gap:10px;
            margin-top:4px;
            padding-right:40px;
        }
        .mis-kpi-foot span{
            color:rgba(255,255,255,0.85);
            font-size:10px;
            font-weight:600;
            text-transform:uppercase;
            letter-spacing:.35px;
        }

        .border1{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#ef4444 0%,#991b1b 45%,#450a0a 100%)}
        .border2{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#2563eb 0%,#1e3a8a 45%,#0b1020 100%)}
        .border3{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#16a34a 0%,#166534 45%,#052e16 100%)}
        .border4{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#f97316 0%,#9a3412 45%,#431407 100%)}
        .border5{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#9333ea 0%,#581c87 45%,#2e1065 100%)}
        .border6{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#db2777 0%,#9d174d 45%,#4b1038 100%)}
        .border7{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#14b8a6 0%,#115e59 45%,#042f2e 100%)}
        .border8{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#ea580c 0%,#9a3412 45%,#431407 100%)}
        .border9{background:radial-gradient(circle at top left,rgba(255,255,255,0.18),transparent 28%),linear-gradient(145deg,#475569 0%,#334155 45%,#020617 100%)}

        .card-wide{grid-column:auto}
        .bu-rd-mini-kpi{min-height:90px;padding:12px}
        .bu-rd-mini-kpi .kpi-label{min-height:auto;font-size:13px}
        .bu-rd-mini-kpi .kpi-value{font-size:18px}

        .bu-rd-chart-wrap{position:relative;height:340px}
        #collab{margin-top:20px}
        #collab>h2{margin-top:0}
        @media (min-width:992px){#bpt-world{height:500px !important}}
        @media (max-width:576px){#bpt-world{height:360px !important}}

        .bu-rd-modal{position:fixed;inset:0;background:rgba(15,23,42,.56);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px}
        .bu-rd-modal-inner{position:relative;background:#fff;border-radius:24px;width:min(1240px,96vw);max-height:90vh;overflow:auto;padding:22px 22px 18px;box-shadow:0 18px 40px rgba(15,23,42,.25)}
        .bu-rd-detail-modal-inner{width:min(820px,96vw)}
        .bu-rd-close{position:absolute;right:12px;top:10px;border:none;background:#fff;font-size:20px;line-height:1;cursor:pointer;color:#111827;width:28px;height:28px;border-radius:999px;box-shadow:0 4px 12px rgba(15,23,42,.12);padding:0}
        .bu-rd-filter-panel{background:linear-gradient(180deg,#f8fafc 0%,#eef3f8 100%);border:1px solid #d7dee8;border-radius:20px;padding:16px;margin:16px 0 18px}
        .bu-rd-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
        .bu-rd-filters .field label{display:block;font-weight:700;margin-bottom:6px;color:#0f172a}
        .bu-rd-filters input,.bu-rd-filters select{width:100%;min-height:42px;border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;background:#fff}
        .bu-rd-filter-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        .bu-rd-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
        .bu-rd-summary-card{border-radius:16px;padding:14px 16px;color:#fff;font-weight:800}
        .bu-rd-summary-card div,.bu-rd-summary-card strong{color:#fff !important;font-weight:800}
        .bu-rd-summary-card strong{display:block;font-size:22px;line-height:1.2;margin-top:6px}
        .sum-red{background:linear-gradient(135deg,#ef4444,#f97316)}
        .sum-blue{background:linear-gradient(135deg,#2563eb,#06b6d4)}
        .sum-green{background:linear-gradient(135deg,#16a34a,#10b981)}
        .sum-purple{background:linear-gradient(135deg,#7c3aed,#ec4899)}
        .sum-orange{background:linear-gradient(135deg,#f97316,#f59e0b)}
        .sum-teal{background:linear-gradient(135deg,#0f766e,#14b8a6)}

        .bu-rd-table-wrap{overflow:auto}
        .bu-rd-table-wrap table{min-width:760px}
        .bu-rd-clickable{cursor:pointer;font-weight:700;color:#1d4ed8}
        .bu-rd-abstract-box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:14px;white-space:pre-wrap}
        .bu-rd-bennett-author{color:#dc2626;font-weight:800}
        .bu-rd-bennett-author small{font-size:11px}
        .bu-rd-plumx-box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;overflow:auto;margin-top:10px}

        .bu-rd-loader-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.58);display:flex;align-items:center;justify-content:center;z-index:100001;padding:20px}
        .bu-rd-loader-box{width:min(420px,94vw);background:#fff;border-radius:24px;padding:24px;box-shadow:0 24px 60px rgba(15,23,42,.35);text-align:center}
        .bu-rd-loader-box h3{margin:8px 0 6px;font-size:22px;color:#0f172a}
        .bu-rd-loader-box p{margin:0 0 16px;color:#475569}
        .bu-rd-loader-progress{height:14px;background:#e2e8f0;border-radius:999px;overflow:hidden;position:relative}
        .bu-rd-loader-progress i{display:block;height:100%;width:0;background:linear-gradient(90deg,#ef4444,#f97316,#facc15,#22c55e,#06b6d4,#3b82f6,#a855f7);background-size:220% 100%;animation:bu-rd-shine 1.2s linear infinite;border-radius:999px}
        .bu-rd-loader-meta{margin-top:12px;font-size:22px;font-weight:800;color:#111827;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap}.bu-rd-loader-time-label{font-size:13px;color:#334155;font-weight:800}.bu-rd-loader-time-label b{font-size:16px;color:#111827}
        .bu-rd-loader-orbs{display:flex;justify-content:center;gap:10px}
        .bu-rd-loader-orbs span{width:14px;height:14px;border-radius:999px;display:block;animation:bu-rd-bounce 1s infinite ease-in-out}
        .bu-rd-loader-orbs span:nth-child(1){background:#ef4444}
        .bu-rd-loader-orbs span:nth-child(2){background:#3b82f6;animation-delay:.12s}
        .bu-rd-loader-orbs span:nth-child(3){background:#22c55e;animation-delay:.24s}
        @keyframes bu-rd-bounce{0%,80%,100%{transform:translateY(0);opacity:.55}40%{transform:translateY(-8px);opacity:1}}
        @keyframes bu-rd-shine{0%{background-position:0% 0}100%{background-position:200% 0}}

        .bu-rd-extra-pill{background:#fff;border:1px solid #d7dee8;border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;gap:8px;box-shadow:0 8px 18px rgba(15,23,42,.06)}
        .bu-rd-extra-pill strong{font-size:13px;color:#334155}
        .bu-rd-extra-pill span{font-size:24px;font-weight:800;color:#111827}
        .bu-rd-area-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:18px 0}
        .bu-rd-area-box{border:1px solid #d7dee8;border-radius:24px;padding:18px;box-shadow:0 12px 28px rgba(15,23,42,.10)}
        .bu-rd-area-box-scopus{background:linear-gradient(135deg,#eff6ff,#dbeafe 40%,#e0f2fe)}
        .bu-rd-area-box-wos{background:linear-gradient(135deg,#fdf4ff,#fae8ff 40%,#ffe4e6)}
        .bu-rd-area-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .bu-rd-area-icon{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(255,255,255,.75);box-shadow:0 6px 14px rgba(15,23,42,.10);font-size:18px}
        .bu-rd-area-box h3{margin:0;color:#0f172a;font-size:20px;font-weight:900}
        .bu-rd-area-pills{display:flex;flex-wrap:wrap;gap:10px}
        .bu-rd-area-pill{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#fff;border:1px solid rgba(15,23,42,.08);box-shadow:0 6px 12px rgba(15,23,42,.06);font-weight:800;color:#111827}

        .kpi-label-compact{font-size:11px;line-height:1.15}
        .kpi-value-medium{font-size:15px;line-height:1.2;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
        .kpi-value-compact{font-size:12px;line-height:1.2;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
        .card-wide{grid-column:auto !important}
        .bu-rd-small-btn{min-height:32px;min-width:108px;padding:6px 10px;border-radius:10px;font-size:12px}
        .bu-rd-table-wrap th,.bu-rd-table-wrap td,#bu-rd-detail-meta p,#bu-rd-detail-meta strong,#bu-rd-detail-meta a,.bu-rd-abstract-box,.bu-rd-card h2,.bu-rd-card h3,.bu-rd-card p,.bu-rd-area-box h3,.bu-rd-area-pill,.bu-rd-filters label,.bu-rd-filters input,.bu-rd-filters select,.bu-rd-footer,.bu-rd-sdg-title{color:#000;font-weight:800}.bu-rd-rich-authors span{font-weight:800}
        .bu-rd-wos-grid .kpi-label,.bu-rd-wos-grid .kpi-value,.bu-rd-wos-grid .mis-kpi-chip,.bu-rd-wos-grid .mis-kpi-foot span{color:#fff !important;font-weight:800;text-shadow:0 2px 8px rgba(0,0,0,.35)}
        .bu-rd-title-cell{cursor:pointer}
        .bu-rd-title-cell:hover{color:#0f3bb8}
        .bu-rd-chart-wrap{height:340px}
        .bu-rd-sdg-wrap{height:560px}
        .bu-rd-subject-wrap{height:640px}
        .bu-rd-footer{margin:18px 0 8px;text-align:center;font-size:14px;font-weight:900;background:linear-gradient(90deg,#fee2e2,#ffedd5,#fef3c7,#dcfce7,#dbeafe,#ede9fe);padding:14px 16px;border-radius:18px}.bu-rd-footer-line1,.bu-rd-footer-line2{color:#111827;font-weight:900}.bu-rd-footer-line2{margin-top:6px;font-size:13px}.bu-rd-footer a{color:#7c2d12;font-weight:900;text-decoration:underline}
        .bu-rd-manual-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
        .bu-rd-manual-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
        .bu-rd-manual-grid label{display:block;margin-bottom:6px;color:#111827;font-weight:800}
        .bu-rd-manual-grid input,.bu-rd-manual-grid textarea,#bu-rd-sdg-editor input{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;color:#111827;font-weight:700}
        .bu-rd-flex-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.bu-rd-loader-actions{margin-top:14px;display:flex;justify-content:center}.bu-rd-loader-cancel{min-width:96px;min-height:34px;padding:6px 10px}
        .bu-rd-overview-card{background:linear-gradient(135deg,#111827 0%,#1e293b 35%,#0f172a 100%);border-color:#0f172a;color:#fff}
        .bu-rd-overview-top{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:flex-start}
        .bu-rd-overview-kicker{font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#fbbf24;margin-bottom:8px}
        .bu-rd-overview-copy h2{margin:0 0 8px;color:#fff !important;font-size:30px;line-height:1.12}
        .bu-rd-overview-copy p{margin:0;color:rgba(255,255,255,.88) !important;max-width:820px}
        .bu-rd-overview-badges{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .bu-rd-status-pill{display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;font-size:12px;font-weight:900;box-shadow:0 10px 20px rgba(0,0,0,.18)}
        .bu-rd-status-pill-fresh{background:#dcfce7;color:#166534}
        .bu-rd-status-pill-warm{background:#fef3c7;color:#92400e}
        .bu-rd-status-pill-stale{background:#fee2e2;color:#991b1b}
        .bu-rd-status-pill-neutral{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.14)}
        .bu-rd-overview-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:18px}
        .bu-rd-overview-box{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:16px;backdrop-filter:blur(6px)}
        .bu-rd-overview-box strong{display:block;font-size:28px;line-height:1.05;color:#fff}
        .bu-rd-overview-box span{display:block;margin-top:7px;color:rgba(255,255,255,.85);font-size:12px;font-weight:700}
        .bu-rd-quick-actions{display:flex;gap:12px;flex-wrap:wrap}
        .bu-rd-quick-chip{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:180px;padding:12px 14px;border-radius:16px;border:1px solid #d7dee8;background:#fff;box-shadow:0 10px 20px rgba(15,23,42,.08);cursor:pointer;font-weight:800;color:#0f172a}
        .bu-rd-quick-chip:hover{transform:translateY(-2px);box-shadow:0 14px 28px rgba(15,23,42,.12)}
        .bu-rd-quick-chip span{font-size:13px;text-align:left}
        .bu-rd-quick-chip strong{font-size:18px;color:#1d4ed8}
        .bu-rd-insight-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
        .bu-rd-insight-card{background:#fff;border:1px solid #d7dee8;border-radius:20px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
        .bu-rd-insight-card span{display:block;font-size:12px;color:#475569;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
        .bu-rd-insight-card strong{display:block;margin:10px 0 8px;font-size:24px;line-height:1.15;color:#0f172a}
        .bu-rd-insight-card small{display:block;color:#334155;font-size:12px;font-weight:700}
        .bu-rd-analytics-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:16px 0}
        .bu-rd-medium-chart{height:360px}
        .bu-rd-publication-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
        .bu-rd-publication-card{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px solid #d7dee8;border-radius:20px;padding:16px;box-shadow:0 14px 26px rgba(15,23,42,.08)}
        .bu-rd-publication-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
        .bu-rd-publication-meta span{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#1e3a8a;font-size:11px;font-weight:800}
        .bu-rd-publication-card h3{margin:0 0 10px;font-size:17px;line-height:1.35;color:#0f172a !important}
        .bu-rd-publication-authors{margin:0 0 14px;color:#334155 !important;font-size:13px;line-height:1.45;min-height:38px}
        .bu-rd-publication-foot{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-top:1px dashed #cbd5e1;padding-top:12px}
        .bu-rd-publication-foot strong{font-size:13px;color:#111827}
        .bu-rd-publication-foot span{font-size:12px;color:#047857;font-weight:800}
        .bu-rd-publication-card .bu-rd-title-cell{display:block}

        @media (max-width:1024px){
            .bu-rd-filters{grid-template-columns:repeat(2,minmax(0,1fr))}
            .bu-rd-chart-wrap{height:300px}
            .bu-rd-analytics-grid{grid-template-columns:1fr}
        }

        .bu-rd-front-wrap .bu-rd-logo{max-width:220px}
        .bu-rd-chart-wrap{position:relative;min-height:320px}
        .bu-rd-wos-grid .mis-kpi,.bu-rd-wos-grid .bu-rd-kpi{min-height:120px}
        @media (max-width:640px){
            .bu-rd-aff-name{font-size:22px}
            .kpi-value{font-size:17px}
            .bu-rd-badge strong{font-size:24px}
            .bu-rd-filters{grid-template-columns:1fr}
            .bu-rd-filter-actions .mis-btn{width:100%}
            .bu-rd-chart-wrap{height:260px}
            .bu-rd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
            .bu-rd-mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
            .card-wide{grid-column:auto}
            .bu-rd-logo{max-width:220px}
            .bu-rd-overview-copy h2{font-size:24px}
            .bu-rd-overview-metrics{grid-template-columns:1fr 1fr}
            .bu-rd-overview-box{padding:14px}
            .bu-rd-quick-chip{width:100%}
            .bu-rd-publication-foot{flex-direction:column}
        }';
    }
}

BU_Scopus_Research_Dashboard::instance();

endif;
