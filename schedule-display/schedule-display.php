<?php
/**
 * Plugin Name: Schedule Display (スケジュール表示)
 * Plugin URI: https://example.com
 * Description: Googleカレンダーの予定を一覧表示するプラグイン（ICS形式対応）
 * Version: 1.0.0
 * Author: Development Team
 * License: GPL v2 or later
 */

// セキュリティチェック
if (!defined('ABSPATH')) {
    exit;
}

// プラグインディレクトリのパス
define('SCHEDULE_DISPLAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCHEDULE_DISPLAY_PLUGIN_URL', plugin_dir_url(__FILE__));

// メインクラス
class Schedule_Display {
    
    private static $instance = null;
    private $ics_parser;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->ics_parser = new Schedule_ICS_Parser();
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_shortcode('schedule_display', array($this, 'render_schedule'));
        
        // 埋め込み用テンプレート（優先度を高く設定）
        add_filter('template_include', array($this, 'embed_template'), 99);
        add_action('wp_head', array($this, 'embed_head_style'), 999);
        add_action('wp_footer', array($this, 'embed_footer_script'), 999);
        
        // 管理画面の設定メニュー
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // 固定ページの自動作成（初回のみ）
        register_activation_hook(__FILE__, array('Schedule_Display', 'activation'));
        add_action('admin_init', array($this, 'check_schedule_page'));
    }
    
    public function init() {
        // 初期化処理
    }
    
    public function check_schedule_page() {
        // 管理画面で固定ページが存在するかチェック（初回作成用）
        $page = get_page_by_path('schedule');
        if (!$page) {
            $this->create_schedule_page();
        }
    }
    
    public static function activation() {
        $instance = self::get_instance();
        $instance->create_schedule_page();
        // キャッシュをクリア
        flush_rewrite_rules();
    }
    
    public function embed_template($template) {
        // /schedule ページの場合のみ埋め込み用テンプレートを適用
        if (is_page('schedule')) {
            $embed_template = SCHEDULE_DISPLAY_PLUGIN_DIR . 'schedule-embed-template.php';
            if (file_exists($embed_template)) {
                // テンプレートを読み込む前に必要な関数を読み込み
                if (!function_exists('wp_head')) {
                    require_once(ABSPATH . 'wp-includes/plugin.php');
                }
                return $embed_template;
            }
        }
        return $template;
    }
    
    public function embed_head_style() {
        if (!is_page('schedule')) {
            return;
        }
        ?>
        <style>
            /* 埋め込み用：ヘッダー・フッター・サイドバーを非表示 */
            body.page-template-embed #header,
            body.page-template-embed #footer,
            body.page-template-embed .site-header,
            body.page-template-embed .site-footer,
            body.page-template-embed aside,
            body.page-template-embed .sidebar,
            body.page-template-embed nav,
            body.page-template-embed header:not(.entry-header),
            body.page-template-embed footer:not(.entry-footer) {
                display: none !important;
            }
            
            /* 管理バーも非表示（iframe埋め込み時） */
            body.page-template-embed #wpadminbar {
                display: none !important;
            }
            
            /* メインコンテンツのみ表示 */
            body.page-template-embed {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            body.page-template-embed .site-content,
            body.page-template-embed main,
            body.page-template-embed .content-area {
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            
            /* エントリーヘッダーも非表示 */
            body.page-template-embed .entry-header {
                display: none !important;
            }
        </style>
        <?php
    }
    
    public function embed_footer_script() {
        if (!is_page('schedule')) {
            return;
        }
        ?>
        <script>
            // iframe埋め込み時の高さ自動調整（オプション）
            if (window.parent !== window) {
                function adjustHeight() {
                    var height = document.body.scrollHeight;
                    window.parent.postMessage({
                        type: 'schedule-embed-height',
                        height: height
                    }, '*');
                }
                
                // 読み込み完了後に高さを送信
                if (document.readyState === 'complete') {
                    adjustHeight();
                } else {
                    window.addEventListener('load', adjustHeight);
                }
                
                // リサイズ時にも調整
                window.addEventListener('resize', adjustHeight);
            }
        </script>
        <?php
    }
    
    public function create_schedule_page() {
        // 既にページが存在するかチェック
        $page = get_page_by_path('schedule');
        if ($page) {
            return $page->ID;
        }
        
        // 固定ページを作成
        $page_data = array(
            'post_title'    => 'スケジュール',
            'post_name'     => 'schedule',
            'post_content'  => '[schedule_display]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => get_current_user_id() ?: 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed'
        );
        
        $page_id = wp_insert_post($page_data, true);
        
        if (is_wp_error($page_id)) {
            return false;
        }
        
        return $page_id;
    }
    
    public function clear_all_cache() {
        global $wpdb;
        // スケジュール関連のTransientキャッシュをすべて削除
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_schedule_events_%',
                '_transient_timeout_schedule_events_%'
            )
        );
    }
    
    public function enqueue_styles() {
        // スケジュールページのみ読み込む
        if (is_page('schedule') || has_shortcode(get_post()->post_content ?? '', 'schedule_display')) {
            wp_enqueue_style(
                'schedule-display-style',
                SCHEDULE_DISPLAY_PLUGIN_URL . 'assets/style.css',
                array(),
                '1.0.2'
            );
            wp_enqueue_script(
                'schedule-display-script',
                SCHEDULE_DISPLAY_PLUGIN_URL . 'assets/script.js',
                array(),
                '1.0.1',
                true
            );
            
            // 設定値をJavaScriptに渡す
            $modal_settings = array(
                'showLocation' => get_option('schedule_modal_show_location', 0),
                'showDescription' => get_option('schedule_modal_show_description', 1)
            );
            wp_localize_script('schedule-display-script', 'scheduleModalSettings', $modal_settings);
            
            // カスタムスタイルを出力
            $this->output_custom_styles();
        }
    }
    
    private function output_custom_styles() {
        $theme = get_option('schedule_theme', 'default');
        $theme_presets = $this->get_theme_presets();
        $custom_css = '';
        $style_parts = array('main_heading', 'month_heading', 'date', 'weekday', 'time', 'title', 'description');
        $background_parts = array('container_bg', 'card_bg', 'card_border', 'card_hover');
        
        // テキストスタイルの適用
        foreach ($style_parts as $part) {
            $size = '';
            $weight = '';
            $color = '';
            
            if ($theme === 'custom') {
                // カスタムモード：個別設定を使用
                $size = get_option("schedule_style_{$part}_size", '');
                $weight = get_option("schedule_style_{$part}_weight", '');
                $color = get_option("schedule_style_{$part}_color", '');
            } elseif (isset($theme_presets[$theme]['styles'][$part])) {
                // プリセットテーマ：テーマの設定を使用
                $preset = $theme_presets[$theme]['styles'][$part];
                $size = $preset['size'] ?? '';
                $weight = $preset['weight'] ?? '';
                $color = $preset['color'] ?? '';
            }
            
            if (empty($size) && empty($weight) && empty($color)) {
                continue; // 設定がない場合はスキップ
            }
            
            $styles = array();
            if (!empty($size)) {
                $styles[] = "font-size: {$size}";
            }
            if (!empty($weight)) {
                $styles[] = "font-weight: {$weight}";
            }
            if (!empty($color)) {
                $styles[] = "color: {$color}";
            }
            
            if (!empty($styles)) {
                $selector = $this->get_style_selector($part);
                $custom_css .= "{$selector} { " . implode('; ', $styles) . "; }\n";
            }
        }
        
        // 背景・ボーダースタイルの適用
        $container_bg = '';
        $card_bg = '';
        $card_border = '';
        $card_hover = '';
        
        if ($theme === 'custom') {
            // カスタムモード：個別設定を使用
            $container_bg = get_option('schedule_style_container_bg', '');
            $card_bg = get_option('schedule_style_card_bg', '');
            $card_border = get_option('schedule_style_card_border', '');
            $card_hover = get_option('schedule_style_card_hover', '');
        } elseif (isset($theme_presets[$theme]['styles'])) {
            // プリセットテーマ：テーマの設定を使用
            $preset_styles = $theme_presets[$theme]['styles'];
            $container_bg = $preset_styles['container_bg'] ?? '';
            $card_bg = $preset_styles['card_bg'] ?? '';
            $card_border = $preset_styles['card_border'] ?? '';
            $card_hover = $preset_styles['card_hover'] ?? '';
        }
        
        // コンテナ背景
        if (!empty($container_bg)) {
            $custom_css .= ".schedule-container { background-color: {$container_bg} !important; padding: 20px; border-radius: 8px; }\n";
        }
        
        // カード背景・ボーダー
        if (!empty($card_bg)) {
            $custom_css .= ".schedule-item { background-color: {$card_bg} !important; }\n";
        }
        if (!empty($card_border)) {
            $custom_css .= ".schedule-item { border-color: {$card_border} !important; }\n";
        }
        if (!empty($card_hover)) {
            $custom_css .= ".schedule-item:hover { background-color: {$card_hover} !important; }\n";
        }
        
        if (!empty($custom_css)) {
            echo '<style type="text/css" id="schedule-display-custom-styles">' . "\n";
            echo $custom_css;
            echo '</style>' . "\n";
        }
    }
    
    private function get_style_selector($part) {
        $selectors = array(
            'main_heading' => '.schedule-heading',
            'month_heading' => '.schedule-month-heading',
            'date' => '.schedule-date .date',
            'weekday' => '.schedule-date .weekday',
            'time' => '.schedule-time',
            'title' => '.schedule-title',
            'description' => '.schedule-modal-description-text'
        );
        return isset($selectors[$part]) ? $selectors[$part] : '';
    }
    
    public function add_admin_menu() {
        add_options_page(
            'スケジュール表示設定',
            'スケジュール表示',
            'manage_options',
            'schedule-display',
            array($this, 'admin_page')
        );
    }
    
    public function register_settings() {
        register_setting('schedule_display_settings', 'schedule_ics_url');
        register_setting('schedule_display_settings', 'schedule_ics_url_color', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color'
        ));
        register_setting('schedule_display_settings', 'schedule_ics_url_1');
        register_setting('schedule_display_settings', 'schedule_ics_url_1_color', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color'
        ));
        register_setting('schedule_display_settings', 'schedule_ics_url_2');
        register_setting('schedule_display_settings', 'schedule_ics_url_2_color', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color'
        ));
        register_setting('schedule_display_settings', 'schedule_ics_url_3');
        register_setting('schedule_display_settings', 'schedule_ics_url_3_color', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color'
        ));
        register_setting('schedule_display_settings', 'schedule_days_ahead', array(
            'default' => 60,
            'sanitize_callback' => 'absint'
        ));
        register_setting('schedule_display_settings', 'schedule_exclude_patterns', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('schedule_display_settings', 'schedule_debug_mode', array(
            'default' => '0',
            'sanitize_callback' => 'absint'
        ));
        register_setting('schedule_display_settings', 'schedule_hide_month_heading', array(
            'default' => '0',
            'sanitize_callback' => 'absint'
        ));
        register_setting('schedule_display_settings', 'schedule_hide_main_heading', array(
            'default' => '0',
            'sanitize_callback' => 'absint'
        ));
        
        register_setting('schedule_display_settings', 'schedule_display_mode', array(
            'default' => 'list',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        register_setting('schedule_display_settings', 'schedule_calendar_start_day', array(
            'default' => '0',
            'sanitize_callback' => 'absint'
        ));
        
        // データ取得方式（ICS/API）
        register_setting('schedule_display_settings', 'schedule_data_source', array(
            'default' => 'ics',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        // Google Calendar API設定
        register_setting('schedule_display_settings', 'schedule_gcal_api_key', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('schedule_display_settings', 'schedule_gcal_calendar_id', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('schedule_display_settings', 'schedule_gcal_calendar_id_1', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('schedule_display_settings', 'schedule_gcal_calendar_id_2', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('schedule_display_settings', 'schedule_gcal_calendar_id_3', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        register_setting('schedule_display_settings', 'schedule_theme', array(
            'default' => 'default',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        // 詳細画面の表示設定
        register_setting('schedule_display_settings', 'schedule_modal_show_location', array(
            'default' => '0',
            'sanitize_callback' => 'absint'
        ));
        register_setting('schedule_display_settings', 'schedule_modal_show_description', array(
            'default' => '1',
            'sanitize_callback' => 'absint'
        ));
        
        // スタイル設定を登録
        $style_settings = array(
            'main_heading', 'month_heading', 'date', 'weekday', 'time', 'title', 'description',
            'container_bg', 'card_bg', 'card_border', 'card_hover'
        );
        foreach ($style_settings as $setting) {
            if (in_array($setting, array('container_bg', 'card_bg', 'card_border', 'card_hover'))) {
                // 背景色・ボーダー色設定（透明も許可するため、カスタムサニタイザーを使用）
                register_setting('schedule_display_settings', "schedule_style_{$setting}", array(
                    'default' => '',
                    'sanitize_callback' => array($this, 'sanitize_color_or_transparent')
                ));
            } else {
                // テキストスタイル設定
                register_setting('schedule_display_settings', "schedule_style_{$setting}_size", array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
                register_setting('schedule_display_settings', "schedule_style_{$setting}_weight", array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
                register_setting('schedule_display_settings', "schedule_style_{$setting}_color", array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_hex_color'
                ));
            }
        }
    }
    
    private function get_theme_presets() {
        return array(
            'default' => array(
                'name' => 'デフォルト',
                'description' => 'シンプルで読みやすい標準テーマ（白背景）',
                'styles' => array(
                    'main_heading' => array('size' => '24px', 'weight' => '600', 'color' => '#333333'),
                    'month_heading' => array('size' => '20px', 'weight' => '600', 'color' => '#333333'),
                    'date' => array('size' => '18px', 'weight' => '600', 'color' => '#333333'),
                    'weekday' => array('size' => '14px', 'weight' => '600', 'color' => '#666666'),
                    'time' => array('size' => '16px', 'weight' => '700', 'color' => '#333333'),
                    'title' => array('size' => '18px', 'weight' => '600', 'color' => '#333333'),
                    'description' => array('size' => '14px', 'weight' => '400', 'color' => '#333333'),
                    'container_bg' => '#ffffff',
                    'card_bg' => '#ffffff',
                    'card_border' => '#e0e0e0',
                    'card_hover' => '#f9f9f9'
                )
            ),
            'modern' => array(
                'name' => 'モダン',
                'description' => '洗練されたモダンなデザイン（ライトグレー背景）',
                'styles' => array(
                    'main_heading' => array('size' => '28px', 'weight' => '700', 'color' => '#1a1a1a'),
                    'month_heading' => array('size' => '22px', 'weight' => '700', 'color' => '#2c3e50'),
                    'date' => array('size' => '20px', 'weight' => '700', 'color' => '#34495e'),
                    'weekday' => array('size' => '13px', 'weight' => '500', 'color' => '#7f8c8d'),
                    'time' => array('size' => '17px', 'weight' => '700', 'color' => '#3498db'),
                    'title' => array('size' => '19px', 'weight' => '600', 'color' => '#2c3e50'),
                    'description' => array('size' => '15px', 'weight' => '400', 'color' => '#555555'),
                    'container_bg' => '#f5f7fa',
                    'card_bg' => '#ffffff',
                    'card_border' => '#d1d5db',
                    'card_hover' => '#e5e7eb'
                )
            ),
            'business' => array(
                'name' => 'ビジネス',
                'description' => 'プロフェッショナルなビジネス向けデザイン（ダークモード）',
                'styles' => array(
                    'main_heading' => array('size' => '26px', 'weight' => '700', 'color' => '#ffffff'),
                    'month_heading' => array('size' => '21px', 'weight' => '700', 'color' => '#ffffff'),
                    'date' => array('size' => '19px', 'weight' => '700', 'color' => '#ffffff'),
                    'weekday' => array('size' => '14px', 'weight' => '600', 'color' => '#cbd5e0'),
                    'time' => array('size' => '17px', 'weight' => '700', 'color' => '#90cdf4'),
                    'title' => array('size' => '18px', 'weight' => '700', 'color' => '#ffffff'),
                    'description' => array('size' => '14px', 'weight' => '400', 'color' => '#e2e8f0'),
                    'container_bg' => '#1e293b',
                    'card_bg' => '#334155',
                    'card_border' => '#475569',
                    'card_hover' => '#475569'
                )
            ),
            'casual' => array(
                'name' => 'カジュアル',
                'description' => '親しみやすいカジュアルなデザイン（温かみのある背景）',
                'styles' => array(
                    'main_heading' => array('size' => '26px', 'weight' => '600', 'color' => '#78350f'),
                    'month_heading' => array('size' => '21px', 'weight' => '600', 'color' => '#ea580c'),
                    'date' => array('size' => '19px', 'weight' => '600', 'color' => '#c2410c'),
                    'weekday' => array('size' => '14px', 'weight' => '500', 'color' => '#f59e0b'),
                    'time' => array('size' => '17px', 'weight' => '700', 'color' => '#dc2626'),
                    'title' => array('size' => '19px', 'weight' => '600', 'color' => '#ea580c'),
                    'description' => array('size' => '15px', 'weight' => '400', 'color' => '#78350f'),
                    'container_bg' => '#fff7ed',
                    'card_bg' => '#ffffff',
                    'card_border' => '#fed7aa',
                    'card_hover' => '#ffedd5'
                )
            ),
            'dark' => array(
                'name' => 'ダークモード',
                'description' => 'ダークモード（暗い背景）',
                'styles' => array(
                    'main_heading' => array('size' => '26px', 'weight' => '700', 'color' => '#f1f5f9'),
                    'month_heading' => array('size' => '21px', 'weight' => '700', 'color' => '#e2e8f0'),
                    'date' => array('size' => '19px', 'weight' => '700', 'color' => '#f1f5f9'),
                    'weekday' => array('size' => '14px', 'weight' => '500', 'color' => '#94a3b8'),
                    'time' => array('size' => '17px', 'weight' => '700', 'color' => '#60a5fa'),
                    'title' => array('size' => '19px', 'weight' => '600', 'color' => '#e2e8f0'),
                    'description' => array('size' => '14px', 'weight' => '400', 'color' => '#cbd5e0'),
                    'container_bg' => '#0f172a',
                    'card_bg' => '#1e293b',
                    'card_border' => '#334155',
                    'card_hover' => '#334155'
                )
            ),
            'transparent' => array(
                'name' => '透明背景',
                'description' => '背景が透明なテーマ',
                'styles' => array(
                    'main_heading' => array('size' => '26px', 'weight' => '700', 'color' => '#1a1a1a'),
                    'month_heading' => array('size' => '21px', 'weight' => '700', 'color' => '#2c3e50'),
                    'date' => array('size' => '19px', 'weight' => '700', 'color' => '#333333'),
                    'weekday' => array('size' => '14px', 'weight' => '600', 'color' => '#666666'),
                    'time' => array('size' => '17px', 'weight' => '700', 'color' => '#2563eb'),
                    'title' => array('size' => '19px', 'weight' => '600', 'color' => '#1e40af'),
                    'description' => array('size' => '14px', 'weight' => '400', 'color' => '#333333'),
                    'container_bg' => 'transparent',
                    'card_bg' => 'rgba(255, 255, 255, 0.7)',
                    'card_border' => '#e5e7eb',
                    'card_hover' => 'rgba(255, 255, 255, 0.9)'
                )
            )
        );
    }
    
    public function sanitize_color_or_transparent($value) {
        if (empty($value)) {
            return '';
        }
        
        // 透明キーワードを許可
        $value = trim($value);
        if (in_array(strtolower($value), array('transparent', 'none'))) {
            return 'transparent';
        }
        
        // rgba形式を許可（例: rgba(0,0,0,0), rgba(255,255,255,0.7)）
        if (preg_match('/^rgba?\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/i', $value)) {
            return $value;
        }
        
        // 通常のカラーコード（hex形式）
        return sanitize_hex_color($value);
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // キャッシュクリア処理
        if (isset($_POST['clear_cache']) && check_admin_referer('schedule_clear_cache')) {
            $this->clear_all_cache();
            echo '<div class="notice notice-success"><p>キャッシュをクリアしました。</p></div>';
        }
        
        // カラーピッカーのスクリプトを読み込む
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('jquery');
        ?>
        <script>
        jQuery(document).ready(function($) {
            // カラーピッカーを初期化（すべてのwp-color-pickerクラスに対して）
            $('.wp-color-picker').wpColorPicker({
                defaultColor: '#4caf50',
                change: function(event, ui) {
                    // カラー変更時の処理（必要に応じて）
                },
                clear: function() {
                    // クリア時の処理（デフォルト色に戻す）
                    $(this).val('#4caf50');
                }
            });
            
            // テーマ選択時の動作
            $('#schedule_theme').on('change', function() {
                var theme = $(this).val();
                if (theme === 'custom') {
                    $('#schedule-custom-style-settings').show();
                } else {
                    $('#schedule-custom-style-settings').hide();
                }
            });
            
            // データ取得方式選択時の動作
            function toggleDataSourceSettings() {
                var dataSource = $('input[name="schedule_data_source"]:checked').val();
                if (dataSource === 'ics') {
                    $('#schedule-ics-settings').show();
                    $('#schedule-api-settings').hide();
                } else if (dataSource === 'api') {
                    $('#schedule-ics-settings').hide();
                    $('#schedule-api-settings').show();
                }
            }
            
            // 初期表示時の設定
            toggleDataSourceSettings();
            
            // ラジオボタン変更時の動作
            $('input[name="schedule_data_source"]').on('change', function() {
                toggleDataSourceSettings();
            });
        });
        </script>
        <div class="wrap">
            <h1>スケジュール表示設定</h1>
            
            <div style="margin: 20px 0; padding: 15px; background: #fff; border-left: 4px solid #2271b1;">
                <h2 style="margin-top: 0;">キャッシュ管理</h2>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('schedule_clear_cache'); ?>
                    <input type="hidden" name="clear_cache" value="1">
                    <button type="submit" class="button button-secondary">キャッシュをクリア</button>
                </form>
                <p class="description" style="margin-top: 10px;">
                    スケジュールデータのキャッシュを手動でクリアします。データが更新されない場合は、このボタンをクリックしてください。<br>
                    （通常は1時間ごとに自動更新されます）
                </p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('schedule_display_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_data_source">データ取得方式</label>
                        </th>
                        <td>
                            <?php $data_source = get_option('schedule_data_source', 'ics'); ?>
                            <label>
                                <input type="radio" 
                                       name="schedule_data_source" 
                                       value="ics" 
                                       id="schedule_data_source_ics"
                                       <?php checked($data_source, 'ics'); ?> />
                                ICS方式
                            </label>
                            <label style="margin-left: 20px;">
                                <input type="radio" 
                                       name="schedule_data_source" 
                                       value="api" 
                                       id="schedule_data_source_api"
                                       <?php checked($data_source, 'api'); ?> />
                                Google Calendar API方式
                            </label>
                            <p class="description">
                                データ取得方式を選択してください。ICS方式は既存の仕様のまま動作します。<br>
                                Google Calendar API方式では、より詳細なイベント情報（イベント色など）を取得できます。
                            </p>
                        </td>
                    </tr>
                </table>
                
                <!-- ICS方式の設定 -->
                <div id="schedule-ics-settings" class="schedule-source-settings" style="<?php echo ($data_source === 'ics') ? '' : 'display: none;'; ?>">
                <h2>ICS方式の設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_ics_url">Googleカレンダー ICS URL</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="schedule_ics_url" 
                                   name="schedule_ics_url" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url', '')); ?>" 
                                   class="regular-text"
                                   placeholder="https://calendar.google.com/calendar/ical/.../basic.ics" 
                                   style="width: 70%; margin-right: 10px;" />
                            <input type="text" 
                                   id="schedule_ics_url_color" 
                                   name="schedule_ics_url_color" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_color', '')); ?>" 
                                   class="wp-color-picker"
                                   data-default-color="#4caf50"
                                   style="width: 100px;" />
                            <p class="description">
                                Googleカレンダーの「設定と共有」→「カレンダーの統合」から取得したICS形式のURLを入力してください。<br>
                                右側の入力欄に背景色を指定できます（例：#4caf50）。未指定の場合はデフォルト色（緑）が使用されます。<br>
                                <strong>重要：</strong>手入力の予定のみを表示したい場合は、手入力用のカレンダー（例：matsu@object-lab.co.jp）のICS URLを指定してください。<br>
                                複数のカレンダーを統合したURLを使うと、日本の祝日なども表示されます。<br>
                                <strong>注意：</strong>タイトル（SUMMARY）がある予定のみが表示されます。<br>
                                <strong>複数カレンダー対応：</strong>下記のICS URL 1-3も使用可能です。リンク未入力の場合は無視されます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_ics_url_1">ICS URL 1</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="schedule_ics_url_1" 
                                   name="schedule_ics_url_1" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_1', '')); ?>" 
                                   class="regular-text"
                                   placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"
                                   style="width: 70%; margin-right: 10px;" />
                            <input type="text" 
                                   id="schedule_ics_url_1_color" 
                                   name="schedule_ics_url_1_color" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_1_color', '')); ?>" 
                                   class="wp-color-picker"
                                   data-default-color="#4caf50"
                                   style="width: 100px;" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。リンク未入力の場合は無視されます。右側の入力欄に背景色を指定できます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_ics_url_2">ICS URL 2</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="schedule_ics_url_2" 
                                   name="schedule_ics_url_2" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_2', '')); ?>" 
                                   class="regular-text"
                                   placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"
                                   style="width: 70%; margin-right: 10px;" />
                            <input type="text" 
                                   id="schedule_ics_url_2_color" 
                                   name="schedule_ics_url_2_color" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_2_color', '')); ?>" 
                                   class="wp-color-picker"
                                   data-default-color="#4caf50"
                                   style="width: 100px;" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。リンク未入力の場合は無視されます。右側の入力欄に背景色を指定できます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_ics_url_3">ICS URL 3</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="schedule_ics_url_3" 
                                   name="schedule_ics_url_3" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_3', '')); ?>" 
                                   class="regular-text"
                                   placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"
                                   style="width: 70%; margin-right: 10px;" />
                            <input type="text" 
                                   id="schedule_ics_url_3_color" 
                                   name="schedule_ics_url_3_color" 
                                   value="<?php echo esc_attr(get_option('schedule_ics_url_3_color', '')); ?>" 
                                   class="wp-color-picker"
                                   data-default-color="#4caf50"
                                   style="width: 100px;" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。リンク未入力の場合は無視されます。右側の入力欄に背景色を指定できます。</p>
                        </td>
                    </tr>
                </table>
                </div>
                
                <!-- Google Calendar API方式の設定 -->
                <div id="schedule-api-settings" class="schedule-source-settings" style="<?php echo ($data_source === 'api') ? '' : 'display: none;'; ?>">
                <h2>Google Calendar API方式の設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_gcal_api_key">APIキー</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_gcal_api_key" 
                                   name="schedule_gcal_api_key" 
                                   value="<?php echo esc_attr(get_option('schedule_gcal_api_key', '')); ?>" 
                                   class="regular-text"
                                   placeholder="AIzaSy..." />
                            <p class="description">
                                Google Cloud Consoleで作成したAPIキーを入力してください。<br>
                                <strong>注意：</strong>公開カレンダーのみが取得対象です。APIキーは適切に管理してください。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_gcal_calendar_id">カレンダーID（メイン）</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_gcal_calendar_id" 
                                   name="schedule_gcal_calendar_id" 
                                   value="<?php echo esc_attr(get_option('schedule_gcal_calendar_id', '')); ?>" 
                                   class="regular-text"
                                   placeholder="primary または example@gmail.com" />
                            <p class="description">
                                取得するカレンダーのIDを入力してください。<br>
                                例：<code>primary</code>（プライマリカレンダー）、<code>example@gmail.com</code>（メールアドレス形式）、またはカスタムカレンダーID
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_gcal_calendar_id_1">カレンダーID 1</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_gcal_calendar_id_1" 
                                   name="schedule_gcal_calendar_id_1" 
                                   value="<?php echo esc_attr(get_option('schedule_gcal_calendar_id_1', '')); ?>" 
                                   class="regular-text"
                                   placeholder="example1@gmail.com" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。未入力の場合は無視されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_gcal_calendar_id_2">カレンダーID 2</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_gcal_calendar_id_2" 
                                   name="schedule_gcal_calendar_id_2" 
                                   value="<?php echo esc_attr(get_option('schedule_gcal_calendar_id_2', '')); ?>" 
                                   class="regular-text"
                                   placeholder="example2@gmail.com" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。未入力の場合は無視されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_gcal_calendar_id_3">カレンダーID 3</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_gcal_calendar_id_3" 
                                   name="schedule_gcal_calendar_id_3" 
                                   value="<?php echo esc_attr(get_option('schedule_gcal_calendar_id_3', '')); ?>" 
                                   class="regular-text"
                                   placeholder="example3@gmail.com" />
                            <p class="description">複数のカレンダーを統合表示する場合に使用します。未入力の場合は無視されます。</p>
                        </td>
                    </tr>
                </table>
                </div>
                
                <!-- 共通設定 -->
                <h2>共通設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_days_ahead">表示日数（直近何日分）</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="schedule_days_ahead" 
                                   name="schedule_days_ahead" 
                                   value="<?php echo esc_attr(get_option('schedule_days_ahead', 60)); ?>" 
                                   min="7" 
                                   max="365" 
                                   step="1" />
                            <p class="description">デフォルト: 60日</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_exclude_patterns">除外するタイトル（オプション）</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_exclude_patterns" 
                                   name="schedule_exclude_patterns" 
                                   value="<?php echo esc_attr(get_option('schedule_exclude_patterns', '')); ?>" 
                                   class="regular-text"
                                   placeholder="元日,成人の日,銀行休業日（カンマ区切り）" />
                            <p class="description">
                                除外したい予定のタイトルをカンマ区切りで入力してください。<br>
                                例：日本の祝日を除外する場合「元日,成人の日,銀行休業日」など<br>
                                空欄の場合は除外しません。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_hide_main_heading">メイン見出し表示設定</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="schedule_hide_main_heading" 
                                       name="schedule_hide_main_heading" 
                                       value="1"
                                       <?php checked(get_option('schedule_hide_main_heading', 0), 1); ?> />
                                「スケジュール」見出しを非表示にする
                            </label>
                            <p class="description">
                                チェックを入れると、ページ上部の「スケジュール」という見出し（h2）を非表示にします。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_hide_month_heading">月見出し表示設定</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="schedule_hide_month_heading" 
                                       name="schedule_hide_month_heading" 
                                       value="1"
                                       <?php checked(get_option('schedule_hide_month_heading', 0), 1); ?> />
                                月見出しを非表示にする
                            </label>
                            <p class="description">
                                チェックを入れると、「2026年1月」などの月見出しを非表示にします。<br>
                                チェックを外すと、月ごとに見出しが表示されます。<br>
                                <strong>注意：</strong>月見出しを非表示にする場合、明細行には月日（例：1月14日）が表示されます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_debug_mode">デバッグモード</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="schedule_debug_mode" 
                                       name="schedule_debug_mode" 
                                       value="1"
                                       <?php checked(get_option('schedule_debug_mode', 0), 1); ?> />
                                有効にする（取得データを表示ページに表示）
                            </label>
                            <p class="description">
                                チェックを入れると、スケジュール表示ページに取得した生データとパース結果が表示されます。<br>
                                <strong>注意：</strong>本番環境では無効にしてください。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="schedule_display_mode">表示方式</label>
                        </th>
                        <td>
                            <select id="schedule_display_mode" name="schedule_display_mode" style="width: 300px;">
                                <option value="list" <?php selected(get_option('schedule_display_mode', 'list'), 'list'); ?>>
                                    リスト表示
                                </option>
                                <option value="calendar" <?php selected(get_option('schedule_display_mode', 'list'), 'calendar'); ?>>
                                    カレンダー表示
                                </option>
                            </select>
                            <p class="description">
                                スケジュールの表示方法を選択します。<br>
                                <strong>リスト表示：</strong>月別にリスト形式で表示します。<br>
                                <strong>カレンダー表示：</strong>カレンダー形式で表示します。
                            </p>
                        </td>
                    </tr>
                    <tr id="schedule-calendar-settings" style="display: <?php echo get_option('schedule_display_mode', 'list') === 'calendar' ? 'table-row' : 'none'; ?>;">
                        <th scope="row">
                            <label for="schedule_calendar_start_day">カレンダーの開始曜日</label>
                        </th>
                        <td>
                            <select id="schedule_calendar_start_day" name="schedule_calendar_start_day" style="width: 300px;">
                                <option value="0" <?php selected(get_option('schedule_calendar_start_day', 0), 0); ?>>
                                    日曜日
                                </option>
                                <option value="1" <?php selected(get_option('schedule_calendar_start_day', 0), 1); ?>>
                                    月曜日
                                </option>
                            </select>
                            <p class="description">
                                カレンダー表示の場合、週の開始曜日を選択します。
                            </p>
                        </td>
                    </tr>
                </table>
                
                <script>
                jQuery(document).ready(function($) {
                    // 表示方式変更時にカレンダー設定を表示/非表示
                    $('#schedule_display_mode').on('change', function() {
                        var displayMode = $(this).val();
                        if (displayMode === 'calendar') {
                            $('#schedule-calendar-settings').show();
                        } else {
                            $('#schedule-calendar-settings').hide();
                        }
                    });
                });
                </script>
                
                <h2 style="margin-top: 30px;">スタイル設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_theme">テーマ選択</label>
                        </th>
                        <td>
                            <select id="schedule_theme" name="schedule_theme" style="width: 400px;">
                                <?php 
                                $current_theme = get_option('schedule_theme', 'default');
                                $theme_presets = $this->get_theme_presets();
                                foreach ($theme_presets as $theme_key => $theme_data) : 
                                ?>
                                    <option value="<?php echo esc_attr($theme_key); ?>" <?php selected($current_theme, $theme_key); ?>>
                                        <?php echo esc_html($theme_data['name']); ?> - <?php echo esc_html($theme_data['description']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom" <?php selected($current_theme, 'custom'); ?>>
                                    カスタム - 個別に設定
                                </option>
                            </select>
                            <p class="description">
                                テーマを選択すると、各パーツのスタイルが自動的に適用されます。<br>
                                「カスタム」を選択すると、下記の個別設定が有効になります。
                            </p>
                        </td>
                    </tr>
                </table>
                
                <div id="schedule-custom-style-settings" style="display: <?php echo $current_theme === 'custom' ? 'block' : 'none'; ?>; margin-top: 20px;">
                    <h3 style="margin-top: 20px;">カスタム設定</h3>
                    <p class="description">テーマが「カスタム」の場合のみ適用されます。各パーツのフォントサイズ、太さ、色をカスタマイズできます。</p>
                    <table class="form-table">
                        <?php 
                        $style_parts = array(
                            'main_heading' => 'メイン見出し（「スケジュール」）',
                            'month_heading' => '月見出し（「2026年1月」など）',
                            'date' => '日付',
                            'weekday' => '曜日',
                            'time' => '時間',
                            'title' => 'タイトル（MT10など）',
                            'description' => '説明（ポップアップ内）'
                        );
                        
                        foreach ($style_parts as $key => $label) :
                            $size = get_option("schedule_style_{$key}_size", '');
                            $weight = get_option("schedule_style_{$key}_weight", '');
                            $color = get_option("schedule_style_{$key}_color", '');
                        ?>
                    <tr>
                        <th scope="row" colspan="2" style="padding-top: 20px; border-top: 1px solid #ddd;">
                            <strong><?php echo esc_html($label); ?></strong>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-left: 40px;">
                            <label for="schedule_style_<?php echo esc_attr($key); ?>_size">フォントサイズ</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_style_<?php echo esc_attr($key); ?>_size" 
                                   name="schedule_style_<?php echo esc_attr($key); ?>_size" 
                                   value="<?php echo esc_attr($size); ?>" 
                                   placeholder="例: 18px, 1.2em"
                                   style="width: 200px;" />
                            <p class="description" style="margin-top: 5px;">例：18px, 16px, 1.2em など</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-left: 40px;">
                            <label for="schedule_style_<?php echo esc_attr($key); ?>_weight">フォントウェイト</label>
                        </th>
                        <td>
                            <select id="schedule_style_<?php echo esc_attr($key); ?>_weight" 
                                    name="schedule_style_<?php echo esc_attr($key); ?>_weight"
                                    style="width: 200px;">
                                <option value="">デフォルト</option>
                                <option value="100" <?php selected($weight, '100'); ?>>100 (Thin)</option>
                                <option value="200" <?php selected($weight, '200'); ?>>200 (Extra Light)</option>
                                <option value="300" <?php selected($weight, '300'); ?>>300 (Light)</option>
                                <option value="400" <?php selected($weight, '400'); ?>>400 (Normal)</option>
                                <option value="500" <?php selected($weight, '500'); ?>>500 (Medium)</option>
                                <option value="600" <?php selected($weight, '600'); ?>>600 (Semi Bold)</option>
                                <option value="700" <?php selected($weight, '700'); ?>>700 (Bold)</option>
                                <option value="800" <?php selected($weight, '800'); ?>>800 (Extra Bold)</option>
                                <option value="900" <?php selected($weight, '900'); ?>>900 (Black)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-left: 40px;">
                            <label for="schedule_style_<?php echo esc_attr($key); ?>_color">色</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="schedule_style_<?php echo esc_attr($key); ?>_color" 
                                   name="schedule_style_<?php echo esc_attr($key); ?>_color" 
                                   value="<?php echo esc_attr($color); ?>" 
                                   placeholder="#333333"
                                   class="color-picker"
                                   style="width: 200px;" />
                            <p class="description" style="margin-top: 5px;">例：#333333, #666, rgb(51, 51, 51) など</p>
                        </td>
                    </tr>
                        <?php endforeach; ?>
                        
                        <tr>
                            <th scope="row" colspan="2" style="padding-top: 20px; border-top: 1px solid #ddd;">
                                <strong>背景・ボーダー設定</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row" style="padding-left: 40px;">
                                <label for="schedule_style_container_bg">全体背景色</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="schedule_style_container_bg" 
                                       name="schedule_style_container_bg" 
                                       value="<?php echo esc_attr(get_option('schedule_style_container_bg', '')); ?>" 
                                       placeholder="#ffffff"
                                       class="color-picker"
                                       style="width: 200px;" />
                                <p class="description" style="margin-top: 5px;">
                                    スケジュールコンテナ全体の背景色<br>
                                    <strong>透明にする場合：</strong>「transparent」または「rgba(0,0,0,0)」と入力してください
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding-left: 40px;">
                                <label for="schedule_style_card_bg">カード背景色</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="schedule_style_card_bg" 
                                       name="schedule_style_card_bg" 
                                       value="<?php echo esc_attr(get_option('schedule_style_card_bg', '')); ?>" 
                                       placeholder="#ffffff または transparent"
                                       class="color-picker"
                                       style="width: 200px;" />
                                <p class="description" style="margin-top: 5px;">
                                    各スケジュール項目（カード）の背景色<br>
                                    <strong>透明にする場合：</strong>「transparent」または「rgba(0,0,0,0)」と入力してください。半透明の場合は「rgba(255,255,255,0.7)」のように指定できます
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding-left: 40px;">
                                <label for="schedule_style_card_border">カードボーダー色</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="schedule_style_card_border" 
                                       name="schedule_style_card_border" 
                                       value="<?php echo esc_attr(get_option('schedule_style_card_border', '')); ?>" 
                                       placeholder="#e0e0e0 または transparent"
                                       class="color-picker"
                                       style="width: 200px;" />
                                <p class="description" style="margin-top: 5px;">
                                    各スケジュール項目（カード）のボーダー色<br>
                                    <strong>透明にする場合：</strong>「transparent」または「rgba(0,0,0,0)」と入力してください
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding-left: 40px;">
                                <label for="schedule_style_card_hover">ホバー時の背景色</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="schedule_style_card_hover" 
                                       name="schedule_style_card_hover" 
                                       value="<?php echo esc_attr(get_option('schedule_style_card_hover', '')); ?>" 
                                       placeholder="#f9f9f9 または transparent"
                                       class="color-picker"
                                       style="width: 200px;" />
                                <p class="description" style="margin-top: 5px;">
                                    マウスオーバー時の背景色<br>
                                    <strong>透明にする場合：</strong>「transparent」または「rgba(0,0,0,0)」と入力してください。半透明の場合は「rgba(255,255,255,0.9)」のように指定できます
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#schedule_theme').on('change', function() {
                        var theme = $(this).val();
                        if (theme === 'custom') {
                            $('#schedule-custom-style-settings').show();
                        } else {
                            $('#schedule-custom-style-settings').hide();
                        }
                    });
                });
                </script>
                
                <h2 style="margin-top: 30px;">詳細画面の表示設定</h2>
                <p class="description" style="margin-bottom: 20px;">予定の詳細画面（モーダル）に表示する項目を選択できます。</p>
                <table class="form-table">
                    <tr>
                        <th scope="row" style="padding-top: 15px; padding-bottom: 15px;">タイトル</th>
                        <td style="padding-top: 15px; padding-bottom: 15px;">
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_title" value="1" checked disabled />
                                表示（必須）
                            </label>
                            <p class="description" style="margin-top: 8px; margin-bottom: 0;">タイトルは常に表示されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-top: 15px; padding-bottom: 15px;">日付</th>
                        <td style="padding-top: 15px; padding-bottom: 15px;">
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_date" value="1" checked disabled />
                                表示（必須）
                            </label>
                            <p class="description" style="margin-top: 8px; margin-bottom: 0;">日付は常に表示されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-top: 15px; padding-bottom: 15px;">時間</th>
                        <td style="padding-top: 15px; padding-bottom: 15px;">
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_time" value="1" checked disabled />
                                表示（必須）
                            </label>
                            <p class="description" style="margin-top: 8px; margin-bottom: 0;">時間は常に表示されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-top: 15px; padding-bottom: 15px;">場所</th>
                        <td style="padding-top: 15px; padding-bottom: 15px;">
                            <?php 
                            $show_location = get_option('schedule_modal_show_location', 0);
                            ?>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_location" value="1" <?php checked($show_location, 1); ?> />
                                表示
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_location" value="0" <?php checked($show_location, 0); ?> />
                                非表示
                            </label>
                            <p class="description" style="margin-top: 8px; margin-bottom: 0;">初期値：非表示</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-top: 15px; padding-bottom: 15px;">説明</th>
                        <td style="padding-top: 15px; padding-bottom: 15px;">
                            <?php 
                            $show_description = get_option('schedule_modal_show_description', 1);
                            ?>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_description" value="1" <?php checked($show_description, 1); ?> />
                                表示
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="schedule_modal_show_description" value="0" <?php checked($show_description, 0); ?> />
                                非表示
                            </label>
                            <p class="description" style="margin-top: 8px; margin-bottom: 0;">初期値：表示</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function render_schedule($atts) {
        // 見出し非表示設定を先に取得（shortcode_attsより前）
        $hide_main_heading = isset($atts['hide_main_heading']) ? absint($atts['hide_main_heading']) : absint(get_option('schedule_hide_main_heading', 0));
        $hide_month_heading = isset($atts['hide_month_heading']) ? absint($atts['hide_month_heading']) : absint(get_option('schedule_hide_month_heading', 0));
        
        // 表示方式と開始曜日を取得
        $display_mode = isset($atts['display_mode']) ? sanitize_text_field($atts['display_mode']) : get_option('schedule_display_mode', 'list');
        $calendar_start_day = isset($atts['calendar_start_day']) ? absint($atts['calendar_start_day']) : absint(get_option('schedule_calendar_start_day', 0));
        
        $atts = shortcode_atts(array(
            'days' => get_option('schedule_days_ahead', 60),
            'ics_url' => get_option('schedule_ics_url', ''),
            'exclude_patterns' => get_option('schedule_exclude_patterns', ''),
            'hide_month_heading' => $hide_month_heading,
            'hide_main_heading' => $hide_main_heading,
            'display_mode' => $display_mode,
            'calendar_start_day' => $calendar_start_day
        ), $atts);
        
        // データ取得方式を取得
        $data_source = get_option('schedule_data_source', 'ics');
        
        $days = absint($atts['days']);
        $exclude_patterns = !empty($atts['exclude_patterns']) ? $atts['exclude_patterns'] : get_option('schedule_exclude_patterns', '');
        
        // デバッグモードのチェック
        $debug_mode = get_option('schedule_debug_mode', 0);
        
        // データ取得方式に応じてイベントを取得
        $all_events = array();
        $debug_info = '';
        $debug_infos = array();
        $events_by_url = array();
        
        if ($data_source === 'api') {
            // Google Calendar API方式
            $api_key = get_option('schedule_gcal_api_key', '');
            if (empty($api_key)) {
                return '<div class="schedule-error">Google Calendar APIキーが設定されていません。管理画面で設定してください。</div>';
            }
            
            // カレンダーIDを取得
            $calendar_ids = array();
            $main_calendar_id = get_option('schedule_gcal_calendar_id', '');
            if (!empty($main_calendar_id)) {
                $calendar_ids[] = $main_calendar_id;
            }
            for ($i = 1; $i <= 3; $i++) {
                $calendar_id = get_option("schedule_gcal_calendar_id_{$i}", '');
                if (!empty($calendar_id)) {
                    $calendar_ids[] = $calendar_id;
                }
            }
            
            if (empty($calendar_ids)) {
                return '<div class="schedule-error">カレンダーIDが設定されていません。管理画面で設定してください。</div>';
            }
            
            // 各カレンダーからイベントを取得
            foreach ($calendar_ids as $calendar_id) {
                $events = $this->get_gcal_events($calendar_id, $api_key, $days, $exclude_patterns, $debug_mode);
                
                if (is_wp_error($events)) {
                    if ($debug_mode) {
                        $debug_infos[] = '<div class="schedule-debug">カレンダーID: ' . esc_html($calendar_id) . ' - エラー: ' . esc_html($events->get_error_message()) . '</div>';
                        $events_by_url[$calendar_id] = array('error' => $events->get_error_message(), 'events' => array());
                    }
                    continue;
                }
                
                if (!empty($events)) {
                    $all_events = array_merge($all_events, $events);
                    if ($debug_mode) {
                        $events_by_url[$calendar_id] = $events;
                    }
                } else {
                    if ($debug_mode) {
                        $events_by_url[$calendar_id] = array();
                    }
                }
            }
        } else {
            // ICS方式（既存の処理）
            $ics_urls = array();
            
            // ショートコードで指定されたURL
            if (!empty($atts['ics_url'])) {
                $ics_urls[] = $atts['ics_url'];
            }
            
            // 設定画面のURL（既存のschedule_ics_url）
            $main_ics_url = get_option('schedule_ics_url', '');
            if (!empty($main_ics_url)) {
                $ics_urls[] = $main_ics_url;
            }
            
            // 追加のICS URL（schedule_ics_url_1, 2, 3）
            for ($i = 1; $i <= 3; $i++) {
                $url = get_option("schedule_ics_url_{$i}", '');
                if (!empty($url)) {
                    $ics_urls[] = $url;
                }
            }
            
            // 重複を除去
            $ics_urls = array_unique($ics_urls);
            
            if (empty($ics_urls)) {
                return '<div class="schedule-error">ICS URLが設定されていません。管理画面で設定してください。</div>';
            }
            
            foreach ($ics_urls as $ics_url) {
                $events = $this->ics_parser->get_events($ics_url, $days, $exclude_patterns, $debug_mode);
                
                if (is_wp_error($events)) {
                    if ($debug_mode) {
                        $debug_infos[] = '<div class="schedule-debug">ICS URL: ' . esc_html($ics_url) . ' - エラー: ' . esc_html($events->get_error_message()) . '</div>';
                        $events_by_url[$ics_url] = array('error' => $events->get_error_message(), 'events' => array());
                    }
                    continue;
                }
                
                if ($debug_mode && isset($events['_debug'])) {
                    $debug_infos[] = $events['_debug'];
                    unset($events['_debug']);
                }
                
                if (!empty($events)) {
                    $all_events = array_merge($all_events, $events);
                    if ($debug_mode) {
                        $events_by_url[$ics_url] = $events;
                    }
                } else {
                    if ($debug_mode) {
                        $events_by_url[$ics_url] = array();
                    }
                }
            }
        }
        
        // デバッグ情報を統合
        if ($debug_mode) {
            // テスト用：デバッグモードが有効であることを確認
            $source_label = ($data_source === 'api') ? 'Calendar IDs' : 'ICS URLs';
            error_log("DEBUG: Debug mode is enabled. Data source: {$data_source}, {$source_label} count: " . count($events_by_url));
            
            // 設定されているすべてのURL/IDの情報を先頭に追加
            $configured_urls_info = '<div class="schedule-debug" style="margin-top: 20px; padding: 15px; background: #e3f2fd; border: 1px solid #2196f3; border-radius: 8px;">';
            if ($data_source === 'api') {
                $configured_urls_info .= '<h3 style="margin-top: 0; color: #1976d2;">📋 設定されているカレンダーID一覧</h3>';
            } else {
                $configured_urls_info .= '<h3 style="margin-top: 0; color: #1976d2;">📋 設定されているICS URL一覧</h3>';
            }
            $configured_urls_info .= '<ul style="margin: 10px 0; padding-left: 20px;">';
            
            if ($data_source === 'api') {
                // API方式：カレンダーID
                $main_calendar_id = get_option('schedule_gcal_calendar_id', '');
                $configured_urls_info .= '<li><strong>カレンダーID（メイン）:</strong> ' . (!empty($main_calendar_id) ? esc_html($main_calendar_id) : '<span style="color: #999;">(未設定)</span>') . '</li>';
                
                for ($i = 1; $i <= 3; $i++) {
                    $calendar_id = get_option("schedule_gcal_calendar_id_{$i}", '');
                    $configured_urls_info .= '<li><strong>カレンダーID ' . $i . ':</strong> ' . (!empty($calendar_id) ? esc_html($calendar_id) : '<span style="color: #999;">(未設定)</span>') . '</li>';
                }
                
                $configured_urls_info .= '</ul>';
                $configured_urls_info .= '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">実際に使用されたカレンダーID: ' . count($events_by_url) . '件</p>';
                $configured_urls_info .= '</div>';
                
                // 各カレンダーIDごとのイベント一覧を追加
                $events_list_info = '<div class="schedule-debug" style="margin-top: 20px; padding: 15px; background: #f3e5f5; border: 1px solid #9c27b0; border-radius: 8px;">';
                $events_list_info .= '<h3 style="margin-top: 0; color: #7b1fa2;">📅 各カレンダーIDごとのイベント一覧</h3>';
                
                $main_calendar_id = get_option('schedule_gcal_calendar_id', '');
                if (!empty($main_calendar_id) && isset($events_by_url[$main_calendar_id])) {
                    $events_list_info .= $this->format_events_list_for_debug('カレンダーID（メイン）', $main_calendar_id, $events_by_url[$main_calendar_id]);
                } else {
                    $events_list_info .= $this->format_events_list_for_debug('カレンダーID（メイン）', $main_calendar_id, array());
                }
                
                for ($i = 1; $i <= 3; $i++) {
                    $calendar_id = get_option("schedule_gcal_calendar_id_{$i}", '');
                    if (!empty($calendar_id) && isset($events_by_url[$calendar_id])) {
                        $events_list_info .= $this->format_events_list_for_debug('カレンダーID ' . $i, $calendar_id, $events_by_url[$calendar_id]);
                    } elseif (!empty($calendar_id)) {
                        $events_list_info .= $this->format_events_list_for_debug('カレンダーID ' . $i, $calendar_id, array());
                    } else {
                        $events_list_info .= $this->format_events_list_for_debug('カレンダーID ' . $i, '', array());
                    }
                }
                
                $events_list_info .= '</div>';
            } else {
                // ICS方式：既存の処理
                // Googleカレンダー ICS URL（メイン）
                $main_ics_url = get_option('schedule_ics_url', '');
                $configured_urls_info .= '<li><strong>Googleカレンダー ICS URL:</strong> ' . (!empty($main_ics_url) ? esc_html($main_ics_url) : '<span style="color: #999;">(未設定)</span>') . '</li>';
                
                // ICS URL 1, 2, 3
                for ($i = 1; $i <= 3; $i++) {
                    $url = get_option("schedule_ics_url_{$i}", '');
                    $configured_urls_info .= '<li><strong>ICS URL ' . $i . ':</strong> ' . (!empty($url) ? esc_html($url) : '<span style="color: #999;">(未設定)</span>') . '</li>';
                }
                
                $configured_urls_info .= '</ul>';
                $configured_urls_info .= '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">実際に使用されたURL: ' . count($events_by_url) . '件</p>';
                $configured_urls_info .= '</div>';
                
                // 各ICS URLごとのイベント一覧を追加
                $events_list_info = '<div class="schedule-debug" style="margin-top: 20px; padding: 15px; background: #f3e5f5; border: 1px solid #9c27b0; border-radius: 8px;">';
                $events_list_info .= '<h3 style="margin-top: 0; color: #7b1fa2;">📅 各ICS URLごとのイベント一覧</h3>';
                
                // Googleカレンダー ICS URL（メイン）
                $main_ics_url = get_option('schedule_ics_url', '');
                if (!empty($main_ics_url) && isset($events_by_url[$main_ics_url])) {
                    $events_list_info .= $this->format_events_list_for_debug('Googleカレンダー ICS URL', $main_ics_url, $events_by_url[$main_ics_url]);
                } else {
                    $events_list_info .= $this->format_events_list_for_debug('Googleカレンダー ICS URL', $main_ics_url, array());
                }
                
                // ICS URL 1, 2, 3
                for ($i = 1; $i <= 3; $i++) {
                    $url = get_option("schedule_ics_url_{$i}", '');
                    if (!empty($url) && isset($events_by_url[$url])) {
                        $events_list_info .= $this->format_events_list_for_debug('ICS URL ' . $i, $url, $events_by_url[$url]);
                    } elseif (!empty($url)) {
                        $events_list_info .= $this->format_events_list_for_debug('ICS URL ' . $i, $url, array());
                    } else {
                        $events_list_info .= $this->format_events_list_for_debug('ICS URL ' . $i, '', array());
                    }
                }
                
                $events_list_info .= '</div>';
            }
            
            // 各ICSのデバッグ情報と結合
            if (!empty($debug_infos)) {
                $debug_info = $configured_urls_info . $events_list_info . implode('', $debug_infos);
            } else {
                $debug_info = $configured_urls_info . $events_list_info;
            }
            
            // デバッグモードが有効であることを明示的に表示
            $debug_info = '<div class="schedule-debug" style="margin-top: 20px; padding: 15px; background: #ffebee; border: 2px solid #f44336; border-radius: 8px;"><strong style="color: #c62828;">🔍 デバッグモード: 有効</strong><br><small>デバッグ情報のテスト表示</small></div>' . $debug_info;
            
            // テスト用：デバッグ情報の長さを確認
            error_log("DEBUG: Debug info length: " . strlen($debug_info));
        } else {
            $debug_info = ''; // デバッグモードが無効の場合は空文字列
            error_log("DEBUG: Debug mode is disabled");
        }
        
        // 日付順でソート
        usort($all_events, function($a, $b) {
            $date_a = isset($a['datetime']) && $a['datetime'] instanceof DateTime 
                ? $a['datetime']->getTimestamp() 
                : strtotime($a['date']);
            $date_b = isset($b['datetime']) && $b['datetime'] instanceof DateTime 
                ? $b['datetime']->getTimestamp() 
                : strtotime($b['date']);
            return $date_a - $date_b;
        });
        
        $events = $all_events;
        
        if (is_wp_error($events)) {
            $error_msg = '<div class="schedule-error">スケジュールの取得に失敗しました: ' . esc_html($events->get_error_message()) . '</div>';
            
            // デバッグ情報を追加
            if ($debug_mode) {
                $error_msg .= '<div class="schedule-debug"><h3>デバッグ情報（エラー時）</h3><pre>' . esc_html(print_r($events, true)) . '</pre></div>';
            }
            
            return $error_msg;
        }
        
        if (empty($events)) {
            $empty_msg = '<div class="schedule-empty">表示期間内に予定はありません。</div>';
            
            // デバッグ情報を追加
            if ($debug_mode && !empty($debug_info)) {
                $empty_msg .= $debug_info;
            }
            
            return $empty_msg;
        }
        
        // 見出し非表示設定を取得（既に取得済み）
        // $hide_main_heading と $hide_month_heading は既に設定済み
        
        // 表示方式に応じて表示を切り替え
        // デバッグ用：表示方式を確認
        if ($debug_mode) {
            $debug_info .= '<div class="schedule-debug" style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px;">';
            $debug_info .= '<strong>表示方式:</strong> ' . esc_html($display_mode) . '<br>';
            $debug_info .= '<strong>イベント数:</strong> ' . count($events) . '件<br>';
            $debug_info .= '<strong>開始曜日設定:</strong> ' . ($calendar_start_day == 1 ? '月曜日' : '日曜日') . '<br>';
            $debug_info .= '</div>';
        }
        
        if ($display_mode === 'calendar') {
            // カレンダー表示
            $days = absint($atts['days']);
            return $this->render_calendar($events, $hide_main_heading, $calendar_start_day, $debug_info, $debug_mode, $days);
        } else {
            // リスト表示（既存のロジック）
            return $this->render_list($events, $hide_main_heading, $hide_month_heading, $debug_info, $debug_mode);
        }
    }
    
    private function render_list($events, $hide_main_heading, $hide_month_heading, $debug_info, $debug_mode) {
        // イベントを年月でグループ化
        $grouped_events = array();
        foreach ($events as $event) {
            $datetime = isset($event['datetime']) ? $event['datetime'] : new DateTime($event['date']);
            $year_month = $datetime->format('Y年n月');
            if (!isset($grouped_events[$year_month])) {
                $grouped_events[$year_month] = array();
            }
            
            // 月見出し非表示時は日付表示を「月日」形式に変更
            if ($hide_month_heading && isset($event['datetime'])) {
                $event['date_display'] = $datetime->format('n月j日');
            }
            
            $grouped_events[$year_month][] = $event;
        }
        
        // 各月内で、同じ日付のイベントを開始時間でソート
        foreach ($grouped_events as $year_month => &$month_events) {
            usort($month_events, function($a, $b) {
                // 日付で比較
                $date_a = isset($a['datetime']) ? $a['datetime'] : new DateTime($a['date']);
                $date_b = isset($b['datetime']) ? $b['datetime'] : new DateTime($b['date']);
                $date_compare = $date_a->format('Y-m-d') <=> $date_b->format('Y-m-d');
                
                // 同じ日付の場合は開始時間で比較
                if ($date_compare === 0) {
                    $time_a = isset($a['datetime']) ? $a['datetime']->format('H:i') : (isset($a['time']) ? explode(' - ', $a['time'])[0] : '23:59');
                    $time_b = isset($b['datetime']) ? $b['datetime']->format('H:i') : (isset($b['time']) ? explode(' - ', $b['time'])[0] : '23:59');
                    return $time_a <=> $time_b;
                }
                
                return $date_compare;
            });
        }
        unset($month_events); // 参照を解除
        
        ob_start();
        ?>
        <div class="schedule-container">
            <?php if (!$hide_main_heading) : ?>
                <h2 class="schedule-heading">スケジュール</h2>
            <?php endif; ?>
            <?php 
            $event_index = 0;
            $first_month = true;
            foreach ($grouped_events as $year_month => $month_events) : 
            ?>
                <div class="schedule-month-group">
                    <?php if (!$hide_month_heading) : ?>
                        <h3 class="schedule-month-heading"><?php echo esc_html($year_month); ?></h3>
                    <?php endif; ?>
                    <div class="schedule-list"<?php echo ($hide_month_heading && !$first_month) ? ' style="margin-top: 12px;"' : ''; ?>>
                        <?php foreach ($month_events as $event) : ?>
                            <?php
                            // タイトルを取得（空の場合は「（タイトルなし）」として表示）
                            $event_title = isset($event['title']) ? trim($event['title']) : '';
                            if (empty($event_title)) {
                                $event_title = '（タイトルなし）';
                            }
                            
                            // イベント色を取得（API方式の場合はeventColorHex、ICS方式の場合はdisplayBackgroundColor）
                            $event_color_hex = '#4caf50'; // デフォルト色（緑）
                            if (isset($event['eventColorHex'])) {
                                $event_color_hex = $event['eventColorHex'];
                            } elseif (isset($event['displayBackgroundColor']) && !empty($event['displayBackgroundColor'])) {
                                $event_color_hex = $event['displayBackgroundColor'];
                            }
                            
                            // 背景色を取得（ICS方式用、既存仕様を維持）
                            $display_bg_color = isset($event['displayBackgroundColor']) ? $event['displayBackgroundColor'] : '';
                            $title_style = '';
                            // 背景色を設定（空の場合はCSSのデフォルト色を使用）
                            if (!empty($display_bg_color)) {
                                $title_style .= ' background-color: ' . esc_attr($display_bg_color) . ';';
                                // 背景色がある場合のみ前景色を白に固定
                                $title_style .= ' color: #ffffff;';
                            }
                            // パディングとボーダーラディウスを追加
                            if (!empty($display_bg_color)) {
                                $title_style .= ' padding: 2px 6px; border-radius: 3px; display: inline-block;';
                            }
                            
                            // タイトル文字の色は背景色がある場合は白、ない場合はデフォルト（黒）
                            $title_text_color = !empty($display_bg_color) ? '#ffffff' : 'inherit';
                            $title_display = '<span style="color: ' . esc_attr($title_text_color) . ';">' . esc_html($event_title) . '</span>';
                            ?>
                            <div class="schedule-item" 
                                 data-event-index="<?php echo esc_attr($event_index); ?>"
                                 data-event-date="<?php echo esc_attr($event['date_display']); ?>"
                                 data-event-weekday="<?php echo esc_attr($event['weekday']); ?>"
                                 data-event-time="<?php echo esc_attr($event['time'] ?? ''); ?>"
                                 data-event-title="<?php echo esc_attr($event_title); ?>"
                                 data-event-location="<?php echo esc_attr($event['location'] ?? ''); ?>"
                                 data-event-description="<?php echo esc_attr($event['description'] ?? ''); ?>"
                                 style="cursor: pointer;">
                                <div class="schedule-date">
                                    <span class="date"><?php echo esc_html($event['date_display']); ?></span>
                                    <span class="weekday"><?php echo esc_html($event['weekday']); ?></span>
                                </div>
                                <?php if (!empty($event['time'])) : ?>
                                    <div class="schedule-time"><?php echo esc_html($event['time']); ?></div>
                                <?php endif; ?>
                                <div class="schedule-title"<?php echo !empty($title_style) ? ' style="' . $title_style . '"' : ''; ?>><?php echo $title_display; ?></div>
                            </div>
                        <?php 
                        $event_index++;
                        endforeach; 
                        ?>
                    </div>
                </div>
            <?php 
            $first_month = false;
            endforeach; 
            ?>
        </div>
        
        <!-- ポップアップモーダル -->
        <div id="schedule-modal" class="schedule-modal" style="display: none;">
            <div class="schedule-modal-overlay"></div>
            <div class="schedule-modal-content">
                <button class="schedule-modal-close" aria-label="閉じる">&times;</button>
                <div class="schedule-modal-header">
                    <h3 class="schedule-modal-title" id="schedule-modal-title"></h3>
                </div>
                <div class="schedule-modal-body">
                    <div class="schedule-modal-info">
                        <div class="schedule-modal-info-row">
                            <span class="schedule-modal-label">日付:</span>
                            <span class="schedule-modal-value" id="schedule-modal-date"></span>
                        </div>
                        <div class="schedule-modal-info-row" id="schedule-modal-time-row" style="display: none;">
                            <span class="schedule-modal-label">時間:</span>
                            <span class="schedule-modal-value" id="schedule-modal-time"></span>
                        </div>
                        <div class="schedule-modal-info-row" id="schedule-modal-location-row" style="display: none;">
                            <span class="schedule-modal-label">場所:</span>
                            <span class="schedule-modal-value" id="schedule-modal-location"></span>
                        </div>
                    </div>
                    <div class="schedule-modal-description" id="schedule-modal-description-wrapper" style="display: none;">
                        <div class="schedule-modal-label" style="margin-bottom: 8px; margin-top: 16px;">説明:</div>
                        <div class="schedule-modal-description-text" id="schedule-modal-description"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 吹き出しリスト（3つ目以降のイベント用） -->
        <div id="schedule-event-popup" class="schedule-event-popup" style="display: none;">
            <div class="schedule-event-popup-content">
                <div class="schedule-event-popup-header">
                    <span class="schedule-event-popup-date" id="schedule-event-popup-date"></span>
                    <button class="schedule-event-popup-close" aria-label="閉じる">&times;</button>
                </div>
                <div class="schedule-event-popup-list" id="schedule-event-popup-list">
                    <!-- イベントリストがここに動的に追加されます -->
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();
        
        // デバッグ情報を追加
        if ($debug_mode && !empty($debug_info)) {
            $output .= $debug_info;
        }
        
        return $output;
    }
    
    private function generate_calendar_month($date, $events_by_date, $start_day, $debug_mode = false, $display_start_date = null, $display_end_date = null) {
        $year = (int)$date->format('Y');
        $month = (int)$date->format('n');
        
        // 月の最初の日と最後の日
        $first_day = new DateTime("{$year}-{$month}-01");
        $last_day = clone $first_day;
        $last_day->modify('last day of this month');
        
        // カレンダーの開始日（前月の最後の週を含める）
        $calendar_start = clone $first_day;
        $first_weekday = (int)$first_day->format('w'); // 0=日曜日, 6=土曜日
        
        // 開始曜日の調整（0=日曜日, 1=月曜日）
        if ($start_day == 1) {
            // 月曜日始まりの場合
            $first_weekday = ($first_weekday == 0) ? 6 : $first_weekday - 1; // 日曜日を6に変換
            $offset = $first_weekday;
        } else {
            // 日曜日始まりの場合
            $offset = $first_weekday;
        }
        
        $calendar_start->modify("-{$offset} days");
        
        // 本日を含む週以前の週は表示しない
        if ($display_start_date !== null) {
            $today = clone $display_start_date;
            $today->setTime(0, 0, 0);
            
            // 今日が含まれる週の開始日を計算
            $today_weekday = (int)$today->format('w');
            if ($start_day == 1) {
                // 月曜日始まりの場合
                $today_weekday = ($today_weekday == 0) ? 6 : $today_weekday - 1;
                $week_start_offset = $today_weekday;
            } else {
                // 日曜日始まりの場合
                $week_start_offset = $today_weekday;
            }
            $week_start = clone $today;
            $week_start->modify("-{$week_start_offset} days");
            
            // カレンダーの開始日が今日の週の開始日より前の場合は、今日の週の開始日から開始
            if ($calendar_start < $week_start) {
                $calendar_start = clone $week_start;
            }
        }
        
        // カレンダーの終了日（月を跨ぐ週は表示しない。その月の最後の日で終了）
        // 表示終了日が指定されている場合は、その月の最後の日と表示終了日のうち早い方を終了日とする
        $month_end = clone $last_day;
        $month_end->setTime(23, 59, 59);
        
        if ($display_end_date !== null) {
            // 表示終了日とその月の最後の日のうち、早い方を終了日とする
            $calendar_end = ($display_end_date < $month_end) ? clone $display_end_date : clone $month_end;
        } else {
            // 表示終了日が指定されていない場合は、その月の最後の日で終了
            $calendar_end = clone $month_end;
        }
        
        // 曜日ラベル
        $weekday_labels = $start_day == 1 
            ? array('月', '火', '水', '木', '金', '土', '日')
            : array('日', '月', '火', '水', '木', '金', '土');
        
        ob_start();
        ?>
        <div class="schedule-calendar">
            <h3 class="schedule-calendar-month-title"><?php echo esc_html($date->format('Y年n月')); ?></h3>
            <table class="schedule-calendar-table">
                <thead>
                    <tr>
                        <?php foreach ($weekday_labels as $label) : ?>
                            <th class="schedule-calendar-weekday"><?php echo esc_html($label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $current_calendar_date = clone $calendar_start;
                    $week_count = 0;
                    $day_count = 0;
                    $current_month = $date->format('Y-m'); // 表示対象の月
                    $in_week = false; // 週の開始フラグ
                    
                    while ($current_calendar_date <= $calendar_end) :
                        $date_key = $current_calendar_date->format('Y-m-d');
                        $is_current_month = ($current_calendar_date->format('Y-m') == $current_month);
                        
                        // 月を跨ぐ週は表示しない：その月の範囲を超えた日付に達したらループを終了
                        if (!$is_current_month && $current_calendar_date > $last_day) {
                            // 週が開始されている場合は閉じる
                            if ($in_week) {
                                echo '</tr>';
                            }
                            break;
                        }
                        
                        // 週の開始を判定（日曜日始まりの場合は日曜日、月曜日始まりの場合は月曜日）
                        $current_weekday = (int)$current_calendar_date->format('w'); // 0=日曜日, 1=月曜日, ..., 6=土曜日
                        $is_week_start = false;
                        
                        if ($start_day == 1) {
                            // 月曜日始まりの場合
                            $is_week_start = ($current_weekday == 1 || ($day_count == 0));
                        } else {
                            // 日曜日始まりの場合
                            $is_week_start = ($current_weekday == 0 || ($day_count == 0));
                        }
                        
                        if ($is_week_start) :
                            if ($week_count > 0) {
                                echo '</tr>';
                            }
                            echo '<tr class="schedule-calendar-week">';
                            $week_count++;
                            $in_week = true;
                        endif;
                        
                        $is_today = ($date_key == date('Y-m-d'));
                        
                        // イベントを取得（配列であることを確認）
                        $day_events = array();
                        if (isset($events_by_date[$date_key]) && is_array($events_by_date[$date_key])) {
                            $day_events = $events_by_date[$date_key];
                        }
                        
                        // デバッグ用：特定の日付のイベント取得を確認
                        if ($debug_mode && $date_key == '2026-01-16') {
                            error_log("Calendar Debug: date_key={$date_key}, day_events count=" . count($day_events) . ", events_by_date keys=" . implode(', ', array_keys($events_by_date)));
                            if (!empty($day_events)) {
                                error_log("Calendar Debug: First event structure: " . print_r($day_events[0], true));
                            }
                        }
                        
                        $cell_classes = array('schedule-calendar-day');
                        if (!$is_current_month) {
                            $cell_classes[] = 'schedule-calendar-day-other-month';
                        }
                        if ($is_today) {
                            $cell_classes[] = 'schedule-calendar-day-today';
                        }
                        if (!empty($day_events) && is_array($day_events) && count($day_events) > 0) {
                            $cell_classes[] = 'schedule-calendar-day-has-events';
                        }
                        ?>
                        <td class="<?php echo esc_attr(implode(' ', $cell_classes)); ?>" data-date="<?php echo esc_attr($date_key); ?>">
                            <div class="schedule-calendar-day-number"><?php echo esc_html($current_calendar_date->format('j')); ?></div>
                            <?php if (!empty($day_events) && is_array($day_events) && count($day_events) > 0) : ?>
                                <div class="schedule-calendar-day-events">
                                    <?php 
                                    $event_display_count = 0;
                                    $skipped_reasons = array(); // スキップされた理由を記録
                                    $hidden_events = array(); // 3つ目以降のイベント（吹き出しで表示）
                                    $calendar_date_display = $current_calendar_date->format('n月j日');
                                    $calendar_weekday = $this->get_japanese_weekday($current_calendar_date->format('w'));
                                    
                                    foreach ($day_events as $event_index => $event) : 
                                        // イベントデータの検証
                                        if (!is_array($event)) {
                                            $skipped_reasons[] = "イベント #{$event_index}: 配列ではありません (型: " . gettype($event) . ")";
                                            continue; // 配列でない場合はスキップ
                                        }
                                        
                                        // 予定が存在する場合はタイトルを表示する
                                        // titleが存在するか確認
                                        if (!isset($event['title'])) {
                                            $event_keys = implode(', ', array_keys($event));
                                            $skipped_reasons[] = "イベント #{$event_index}: titleが存在しません (キー: {$event_keys})";
                                            continue; // titleが存在しない場合はスキップ
                                        }
                                        
                                        // タイトルを取得（空の場合は「（タイトルなし）」として表示）
                                        $event_title = trim($event['title']);
                                        if (empty($event_title)) {
                                            $event_title = '（タイトルなし）';
                                        }
                                        
                                        // 4件以上の場合、3行目を「+N件」として纏める
                                        // 1-2件：そのまま表示
                                        // 3件：3行目まで表示
                                        // 4件以上：1-2行目に表示、3行目に「+N件」
                                        $total_events = count($day_events);
                                        
                                        if ($total_events >= 4) {
                                            // 4件以上の場合
                                            if ($event_display_count >= 2) {
                                                // 3つ目以降のイベントを配列に保存（3行目に「+N件」として表示）
                                                $hidden_events[] = array(
                                                    'title' => $event_title,
                                                    'date' => $calendar_date_display,
                                                    'weekday' => $calendar_weekday,
                                                    'time' => isset($event['time']) ? $event['time'] : '',
                                                    'location' => isset($event['location']) ? $event['location'] : '',
                                                    'description' => isset($event['description']) ? $event['description'] : ''
                                                );
                                                continue;
                                            }
                                        } else {
                                            // 3件以下の場合、3行目まで表示
                                            if ($event_display_count >= 3) {
                                                // 4つ目以降（通常は発生しないが、念のため）
                                                $hidden_events[] = array(
                                                    'title' => $event_title,
                                                    'date' => $calendar_date_display,
                                                    'weekday' => $calendar_weekday,
                                                    'time' => isset($event['time']) ? $event['time'] : '',
                                                    'location' => isset($event['location']) ? $event['location'] : '',
                                                    'description' => isset($event['description']) ? $event['description'] : ''
                                                );
                                                continue;
                                            }
                                        }
                                        
                                        $event_display_count++;
                                        
                                        // ツールチップ用のテキスト（開始時間+"~ " & タイトル）
                                        $tooltip_text = $event_title;
                                        if (isset($event['time']) && !empty($event['time'])) {
                                            $time_parts = explode(' - ', $event['time']);
                                            $start_time = $time_parts[0];
                                            $tooltip_text = $start_time . '~ ' . $event_title;
                                        }
                                        
                                        // イベント色を取得（API方式の場合はeventColorHex、ICS方式の場合は既存の処理）
                                        $event_color_hex = '#4caf50'; // デフォルト色（緑）
                                        
                                        if (isset($event['eventColorHex'])) {
                                            // API方式：eventColorHexを直接使用
                                            $event_color_hex = $event['eventColorHex'];
                                        } else {
                                            // ICS方式：既存の処理（ICS URLの設定色を優先、次にICSのCOLOR情報）
                                            $display_bg_color = '';
                                            
                                            // まず、ICS URLに対応する背景色設定を取得
                                            if (isset($event['ics_url'])) {
                                                $ics_url = $event['ics_url'];
                                                // どのICS URL設定に対応するか判定
                                                $main_ics_url = get_option('schedule_ics_url', '');
                                                if ($ics_url === $main_ics_url) {
                                                    $display_bg_color = get_option('schedule_ics_url_color', '');
                                                } else {
                                                    for ($i = 1; $i <= 3; $i++) {
                                                        $url = get_option("schedule_ics_url_{$i}", '');
                                                        if ($ics_url === $url) {
                                                            $display_bg_color = get_option("schedule_ics_url_{$i}_color", '');
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            // ICS URLの設定色がない場合は、ICSのCOLOR情報を使用
                                            if (empty($display_bg_color) && isset($event['displayBackgroundColor'])) {
                                                $display_bg_color = $event['displayBackgroundColor'];
                                            }
                                            
                                            // それでも色がない場合はデフォルト色（緑）を使用
                                            if (empty($display_bg_color)) {
                                                $display_bg_color = '#4caf50';
                                            }
                                            
                                            // 色コードを正規化（#が付いていない場合は追加）
                                            if (strpos($display_bg_color, '#') !== 0) {
                                                $display_bg_color = '#' . $display_bg_color;
                                            }
                                            // 3桁のHEXカラーを6桁に変換
                                            if (strlen($display_bg_color) === 4) {
                                                $display_bg_color = '#' . $display_bg_color[1] . $display_bg_color[1] . $display_bg_color[2] . $display_bg_color[2] . $display_bg_color[3] . $display_bg_color[3];
                                            }
                                            // 有効なHEXカラーかチェック
                                            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $display_bg_color)) {
                                                $display_bg_color = '#4caf50'; // 無効な場合はデフォルト色
                                            }
                                            
                                            $event_color_hex = $display_bg_color;
                                        }
                                        
                                        // 背景色を取得（ICS方式用、既存仕様を維持）
                                        $display_bg_color = '';
                                        if (isset($event['displayBackgroundColor']) && !isset($event['eventColorHex'])) {
                                            // ICS方式の場合のみ背景色を使用
                                            $display_bg_color = $event['displayBackgroundColor'];
                                            // ICS URLの設定色を優先
                                            if (isset($event['ics_url'])) {
                                                $ics_url = $event['ics_url'];
                                                $main_ics_url = get_option('schedule_ics_url', '');
                                                if ($ics_url === $main_ics_url) {
                                                    $url_color = get_option('schedule_ics_url_color', '');
                                                    if (!empty($url_color)) {
                                                        $display_bg_color = $url_color;
                                                    }
                                                } else {
                                                    for ($i = 1; $i <= 3; $i++) {
                                                        $url = get_option("schedule_ics_url_{$i}", '');
                                                        if ($ics_url === $url) {
                                                            $url_color = get_option("schedule_ics_url_{$i}_color", '');
                                                            if (!empty($url_color)) {
                                                                $display_bg_color = $url_color;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // 色コードを正規化
                                        if (!empty($display_bg_color)) {
                                            if (strpos($display_bg_color, '#') !== 0) {
                                                $display_bg_color = '#' . $display_bg_color;
                                            }
                                            if (strlen($display_bg_color) === 4) {
                                                $display_bg_color = '#' . $display_bg_color[1] . $display_bg_color[1] . $display_bg_color[2] . $display_bg_color[2] . $display_bg_color[3] . $display_bg_color[3];
                                            }
                                            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $display_bg_color)) {
                                                $display_bg_color = '#4caf50';
                                            }
                                        }
                                        
                                        // eventColorHexも正規化
                                        if (strpos($event_color_hex, '#') !== 0) {
                                            $event_color_hex = '#' . $event_color_hex;
                                        }
                                        if (strlen($event_color_hex) === 4) {
                                            $event_color_hex = '#' . $event_color_hex[1] . $event_color_hex[1] . $event_color_hex[2] . $event_color_hex[2] . $event_color_hex[3] . $event_color_hex[3];
                                        }
                                        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $event_color_hex)) {
                                            $event_color_hex = '#4caf50';
                                        }
                                        
                                        $style_attr = 'cursor: pointer;';
                                        // 前景色は常に白に固定
                                        $style_attr .= ' color: #ffffff;';
                                        // 背景色を設定（ICS方式の場合のみ）
                                        if (!empty($display_bg_color)) {
                                            $style_attr .= ' background-color: ' . esc_attr($display_bg_color) . ';';
                                        }
                                        
                                        // タイトルを表示
                                        $title_display = esc_html(mb_substr($event_title, 0, 10));
                                        if (mb_strlen($event_title) > 10) {
                                            $title_display .= '...';
                                        }
                                        ?>
                                        <div class="schedule-calendar-event" 
                                             data-event-index="<?php echo esc_attr($event_display_count - 1); ?>"
                                             data-event-date="<?php echo esc_attr($calendar_date_display); ?>"
                                             data-event-weekday="<?php echo esc_attr($calendar_weekday); ?>"
                                             data-event-time="<?php echo esc_attr(isset($event['time']) ? $event['time'] : ''); ?>"
                                             data-event-title="<?php echo esc_attr($event_title); ?>"
                                             data-event-location="<?php echo esc_attr(isset($event['location']) ? $event['location'] : ''); ?>"
                                             data-event-description="<?php echo esc_attr(isset($event['description']) ? $event['description'] : ''); ?>"
                                             style="<?php echo $style_attr; ?>"
                                             title="<?php echo esc_attr($tooltip_text); ?>">
                                            <?php echo $title_display; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($hidden_events) > 0) : ?>
                                        <div class="schedule-calendar-event-more" 
                                             data-date-key="<?php echo esc_attr($date_key); ?>"
                                             data-events='<?php echo esc_attr(json_encode($hidden_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'
                                             style="cursor: pointer;"
                                             title="クリックして詳細を表示">
                                            +<?php echo count($hidden_events); ?>件
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($event_display_count == 0) : ?>
                                        <!-- デバッグ: イベントが表示されなかった理由（常に表示） -->
                                        <div style="font-size: 9px; color: red; padding: 4px; background: yellow; border: 1px solid red; margin-top: 2px;">
                                            <strong>⚠️ イベントが表示されませんでした</strong><br>
                                            日付: <?php echo esc_html($date_key); ?><br>
                                            イベント数: <?php echo count($day_events); ?>件<br>
                                            <?php if (!empty($skipped_reasons)) : ?>
                                                スキップ理由:<br>
                                                <?php foreach ($skipped_reasons as $reason) : ?>
                                                    • <?php echo esc_html($reason); ?><br>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                最初のイベント: <?php echo is_array($day_events[0] ?? null) ? '配列' : '配列ではない (' . gettype($day_events[0] ?? null) . ')'; ?><br>
                                                <?php if (isset($day_events[0]) && is_array($day_events[0])) : ?>
                                                    キー: <?php echo esc_html(implode(', ', array_keys($day_events[0]))); ?><br>
                                                    title: <?php echo isset($day_events[0]['title']) ? esc_html($day_events[0]['title']) : '存在しない'; ?><br>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php
                        $current_calendar_date->modify('+1 day');
                        $day_count++;
                    endwhile;
                    // ループが正常に終了した場合（breakで終了しなかった場合）は、最後の週を閉じる
                    if ($in_week) {
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_calendar($events, $hide_main_heading, $calendar_start_day, $debug_info, $debug_mode, $days_ahead = 60) {
        // イベントを日付でインデックス化
        $events_by_date = array();
        foreach ($events as $event) {
            // イベントの日付を取得
            $datetime = null;
            if (isset($event['datetime']) && $event['datetime'] instanceof DateTime) {
                $datetime = clone $event['datetime']; // クローンを作成して元のオブジェクトを変更しない
            } elseif (isset($event['date'])) {
                // date文字列からDateTimeオブジェクトを作成
                try {
                    $datetime = new DateTime($event['date']);
                } catch (Exception $e) {
                    if ($debug_mode) {
                        error_log("Calendar Debug: Failed to create DateTime from: " . $event['date']);
                    }
                    continue; // 日付が無効な場合はスキップ
                }
            } else {
                if ($debug_mode) {
                    error_log("Calendar Debug: Event has no datetime or date. Event keys: " . (isset($event) && is_array($event) ? implode(', ', array_keys($event)) : 'N/A'));
                }
                continue; // 日付が取得できない場合はスキップ
            }
            
            // タイムゾーンを確認（必要に応じてJSTに変換）
            $current_tz = $datetime->getTimezone()->getName();
            if ($current_tz !== 'Asia/Tokyo') {
                try {
                    $datetime->setTimezone(new DateTimeZone('Asia/Tokyo'));
                } catch (Exception $e) {
                    if ($debug_mode) {
                        error_log("Calendar Debug: Failed to set timezone to Asia/Tokyo. Current: " . $current_tz);
                    }
                    // タイムゾーン変換に失敗した場合はそのまま使用
                }
            }
            
            // 日付キーを作成（Y-m-d形式、JST基準）
            $date_key = $datetime->format('Y-m-d');
            if (!isset($events_by_date[$date_key])) {
                $events_by_date[$date_key] = array();
            }
            $events_by_date[$date_key][] = $event;
            
            // デバッグ用：特定の日付のマッピングを確認
            if ($debug_mode && in_array($date_key, array('2026-01-16', '2026-01-23', '2026-01-30', '2026-02-04', '2026-02-18'))) {
                error_log("Calendar Debug: Mapped event to date_key={$date_key}, title=" . (isset($event['title']) ? $event['title'] : 'N/A') . ", datetime=" . $datetime->format('Y-m-d H:i:s T'));
            }
        }
        
        // 各日付内で、イベントを開始時間でソート
        foreach ($events_by_date as $date_key => &$day_events) {
            usort($day_events, function($a, $b) {
                // datetimeで比較（開始時間）
                $datetime_a = isset($a['datetime']) ? $a['datetime'] : new DateTime($a['date']);
                $datetime_b = isset($b['datetime']) ? $b['datetime'] : new DateTime($b['date']);
                
                // 同じ日付の場合は開始時間で比較
                if ($datetime_a->format('Y-m-d') === $datetime_b->format('Y-m-d')) {
                    $time_a = $datetime_a->format('H:i');
                    $time_b = $datetime_b->format('H:i');
                    // 時間がない場合は最後に配置
                    if (empty($time_a) || $time_a === '00:00') {
                        $time_a = '23:59';
                    }
                    if (empty($time_b) || $time_b === '00:00') {
                        $time_b = '23:59';
                    }
                    return $time_a <=> $time_b;
                }
                
                return $datetime_a <=> $datetime_b;
            });
        }
        unset($day_events); // 参照を解除
        
        // カレンダー表示：設定画面の日数に基づいて表示期間を決定
        $start_date = new DateTime();
        $start_date->setTime(0, 0, 0);
        
        // 終了日を計算（設定の日数分）
        $end_date = clone $start_date;
        $end_date->modify("+{$days_ahead} days");
        
        // 終了日が週の途中であればその週はすべて表示
        $end_weekday = (int)$end_date->format('w'); // 0=日曜日, 6=土曜日
        if ($calendar_start_day == 1) {
            // 月曜日始まりの場合
            $end_weekday = ($end_weekday == 0) ? 6 : $end_weekday - 1; // 日曜日を6に変換
            $remaining_days = 6 - $end_weekday;
        } else {
            // 日曜日始まりの場合
            $remaining_days = 6 - $end_weekday;
        }
        $end_date->modify("+{$remaining_days} days");
        $end_date->setTime(23, 59, 59);
        
        // カレンダーを生成（開始月から終了月まで）
        $calendars = array();
        $current_date = clone $start_date;
        $current_date->modify('first day of this month'); // 月の最初の日に設定
        
        $end_month = clone $end_date;
        $end_month->modify('last day of this month');
        $end_month->setTime(23, 59, 59);
        
        // 月ごとにカレンダーを生成
        $temp_date = clone $current_date;
        while ($temp_date <= $end_month) {
            $year_month = $temp_date->format('Y-m');
            if (!isset($calendars[$year_month])) {
                $calendars[$year_month] = $this->generate_calendar_month($temp_date, $events_by_date, $calendar_start_day, $debug_mode, $start_date, $end_date);
            }
            $temp_date->modify('+1 month');
        }
        
        ob_start();
        ?>
        <div class="schedule-container schedule-calendar-container">
            <?php if (!$hide_main_heading) : ?>
                <h2 class="schedule-heading">スケジュール</h2>
            <?php endif; ?>
            <?php 
            $event_index = 0;
            foreach ($calendars as $year_month => $calendar_html) : 
            ?>
                <div class="schedule-calendar-month">
                    <?php echo $calendar_html; ?>
                </div>
            <?php 
            endforeach; 
            ?>
        </div>
        
        <!-- ポップアップモーダル -->
        <div id="schedule-modal" class="schedule-modal" style="display: none;">
            <div class="schedule-modal-overlay"></div>
            <div class="schedule-modal-content">
                <button class="schedule-modal-close" aria-label="閉じる">&times;</button>
                <div class="schedule-modal-header">
                    <h3 class="schedule-modal-title" id="schedule-modal-title"></h3>
                </div>
                <div class="schedule-modal-body">
                    <div class="schedule-modal-info">
                        <div class="schedule-modal-info-row">
                            <span class="schedule-modal-label">日付:</span>
                            <span class="schedule-modal-value" id="schedule-modal-date"></span>
                        </div>
                        <div class="schedule-modal-info-row" id="schedule-modal-time-row" style="display: none;">
                            <span class="schedule-modal-label">時間:</span>
                            <span class="schedule-modal-value" id="schedule-modal-time"></span>
                        </div>
                        <div class="schedule-modal-info-row" id="schedule-modal-location-row" style="display: none;">
                            <span class="schedule-modal-label">場所:</span>
                            <span class="schedule-modal-value" id="schedule-modal-location"></span>
                        </div>
                    </div>
                    <div class="schedule-modal-description" id="schedule-modal-description-wrapper" style="display: none;">
                        <div class="schedule-modal-label" style="margin-bottom: 8px; margin-top: 16px;">説明:</div>
                        <div class="schedule-modal-description-text" id="schedule-modal-description"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 吹き出しリスト（3つ目以降のイベント用） -->
        <div id="schedule-event-popup" class="schedule-event-popup" style="display: none;">
            <div class="schedule-event-popup-content">
                <div class="schedule-event-popup-header">
                    <span class="schedule-event-popup-date" id="schedule-event-popup-date"></span>
                    <button class="schedule-event-popup-close" aria-label="閉じる">&times;</button>
                </div>
                <div class="schedule-event-popup-list" id="schedule-event-popup-list">
                    <!-- イベントリストがここに動的に追加されます -->
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();
        
        // デバッグ情報を追加（デバッグモードが有効な場合のみ）
        if ($debug_mode && !empty($debug_info)) {
            $output .= $debug_info;
        }
        
        return $output;
    }
    
    private function get_japanese_weekday($w) {
        $weekdays = array('日', '月', '火', '水', '木', '金', '土');
        return '(' . $weekdays[$w] . ')';
    }
    
    /**
     * 各ICS URLごとのイベント一覧をフォーマット（デバッグ用）
     */
    private function format_events_list_for_debug($label, $url, $events) {
        ob_start();
        ?>
        <div style="margin: 15px 0; padding: 12px; background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;">
            <h4 style="margin: 0 0 10px 0; color: #7b1fa2; font-size: 14px;">
                <?php echo esc_html($label); ?>
                <?php if (!empty($url)) : ?>
                    <span style="font-size: 11px; color: #666; font-weight: normal;">(<?php echo esc_html($url); ?>)</span>
                <?php else : ?>
                    <span style="font-size: 11px; color: #999; font-weight: normal;">(未設定)</span>
                <?php endif; ?>
            </h4>
            <?php if (isset($events['error'])) : ?>
                <p style="margin: 5px 0; color: red; font-size: 12px;">❌ エラー: <?php echo esc_html($events['error']); ?></p>
            <?php elseif (empty($events) || count($events) === 0) : ?>
                <p style="margin: 5px 0; color: #999; font-size: 12px;">📭 イベントなし</p>
            <?php else : ?>
                <p style="margin: 5px 0 10px 0; font-size: 12px; color: #666;">取得イベント数: <strong><?php echo count($events); ?></strong>件</p>
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 6px; text-align: left; border-bottom: 1px solid #ddd; width: 100px;">日付</th>
                            <th style="padding: 6px; text-align: left; border-bottom: 1px solid #ddd; width: 80px;">時間</th>
                            <th style="padding: 6px; text-align: left; border-bottom: 1px solid #ddd;">タイトル</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $display_count = 0;
                        $max_display = 50; // 最大50件まで表示
                        foreach ($events as $event) : 
                            if ($display_count >= $max_display) break;
                            $display_count++;
                            $event_date = isset($event['date']) ? $event['date'] : '';
                            $event_time = isset($event['time']) ? $event['time'] : '';
                            $event_title = isset($event['title']) ? $event['title'] : '（タイトルなし）';
                        ?>
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 6px; color: #333;"><?php echo esc_html($event_date); ?></td>
                            <td style="padding: 6px; color: #666;"><?php echo esc_html($event_time ?: '終日'); ?></td>
                            <td style="padding: 6px; color: #333;"><?php echo esc_html($event_title); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($events) > $max_display) : ?>
                        <tr>
                            <td colspan="3" style="padding: 6px; text-align: center; color: #999; font-size: 11px;">
                                ... 他 <?php echo count($events) - $max_display; ?> 件
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// ICSパーサークラス
class Schedule_ICS_Parser {
    
    private $cache_duration = 3600; // 1時間キャッシュ
    
    public function get_events($ics_url, $days_ahead = 60, $exclude_patterns = '', $debug_mode = false) {
        // デバッグ情報を収集
        $debug_info = array(
            'ics_url' => $ics_url,
            'days_ahead' => $days_ahead,
            'exclude_patterns' => $exclude_patterns,
            'raw_ics_length' => 0,
            'raw_ics_preview' => '',
            'parsed_events_count' => 0,
            'raw_events' => array(),
            'final_events_count' => 0
        );
        
        // キャッシュチェック（デバッグモードの場合はキャッシュを無視）
        if (!$debug_mode) {
            $cache_key = 'schedule_events_' . md5($ics_url . $days_ahead . $exclude_patterns);
            $cached = get_transient($cache_key);
            
            if (false !== $cached) {
                return $cached;
            }
        }
        
        // ICSファイル取得
        $response = wp_remote_get($ics_url, array(
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            if ($debug_mode) {
                $debug_info['error'] = $response->get_error_message();
            }
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_response', 'ICSデータが空です');
        }
        
        // デバッグ情報：生ICSデータ
        $debug_info['raw_ics_length'] = strlen($body);
        $debug_info['raw_ics_preview'] = substr($body, 0, 2000); // 最初の2000文字
        
        // ICSパース（ICS URL情報を渡す）
        $events = $this->parse_ics($body, $days_ahead, $exclude_patterns, $debug_info, $ics_url);
        
        // デバッグ情報を追加
        if ($debug_mode) {
            $events['_debug'] = $this->format_debug_info($debug_info);
        }
        
        // キャッシュ保存（デバッグモードの場合は保存しない）
        if (!$debug_mode) {
            $cache_key = 'schedule_events_' . md5($ics_url . $days_ahead . $exclude_patterns);
            set_transient($cache_key, $events, $this->cache_duration);
        }
        
        return $events;
    }
    
    private function format_debug_info($debug_info) {
        ob_start();
        ?>
        <div class="schedule-debug" style="margin-top: 30px; padding: 20px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; font-family: monospace; font-size: 12px;">
            <h3 style="margin-top: 0; color: #333;">🔍 デバッグ情報</h3>
            
            <h4>基本情報</h4>
            <ul>
                <li><strong>ICS URL:</strong> <?php echo esc_html($debug_info['ics_url']); ?></li>
                <?php if (isset($debug_info['calendar_name']) && !empty($debug_info['calendar_name'])) : ?>
                <li><strong>カレンダー名:</strong> <?php echo esc_html($debug_info['calendar_name']); ?></li>
                <?php endif; ?>
                <li><strong>表示日数:</strong> <?php echo esc_html($debug_info['days_ahead']); ?>日</li>
                <li><strong>除外パターン:</strong> <?php echo esc_html($debug_info['exclude_patterns'] ?: '(なし)'); ?></li>
                <li><strong>生ICSデータサイズ:</strong> <?php echo esc_html(number_format($debug_info['raw_ics_length'])); ?> バイト</li>
                <li><strong>パース前イベント数:</strong> <?php echo esc_html($debug_info['parsed_events_count']); ?></li>
                <li><strong>最終表示イベント数:</strong> <?php echo esc_html($debug_info['final_events_count']); ?></li>
            </ul>
            
            <?php if (!empty($debug_info['raw_ics_preview'])) : ?>
            <h4>生ICSデータ（最初の2000文字）</h4>
            <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; max-height: 400px; overflow-y: auto;"><?php echo esc_html($debug_info['raw_ics_preview']); ?></pre>
            <?php endif; ?>
            
            <?php if (!empty($debug_info['raw_events'])) : ?>
            <h4>パース前の全イベントデータ（最初の10件）</h4>
            <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; max-height: 600px; overflow-y: auto;"><?php echo esc_html(print_r(array_slice($debug_info['raw_events'], 0, 10), true)); ?></pre>
            <?php endif; ?>
            
            <?php if (isset($debug_info['error'])) : ?>
            <h4 style="color: red;">エラー情報</h4>
            <pre style="background: #fff3cd; padding: 10px; border: 1px solid #ffc107; color: #856404;"><?php echo esc_html($debug_info['error']); ?></pre>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function parse_ics($ics_content, $days_ahead, $exclude_patterns = '', &$debug_info = null, $ics_url = '') {
        $events = array();
        $current_event = null;
        $lines = explode("\n", $ics_content);
        
        $start_date = new DateTime();
        $end_date = new DateTime();
        $end_date->modify("+{$days_ahead} days");
        
        // 除外パターンを配列に変換
        $exclude_list = array();
        if (!empty($exclude_patterns)) {
            $patterns = explode(',', $exclude_patterns);
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (!empty($pattern)) {
                    $exclude_list[] = $pattern;
                }
            }
        }
        
        // カレンダーレベルの色情報を取得（VCALENDARレベル）
        $calendar_color = '';
        $in_vcalendar = false;
        
        // 継続行を結合（ICS形式の処理）
        $normalized_lines = array();
        $current_continuation = '';
        foreach ($lines as $line) {
            // 継続行の判定（行頭がスペースまたはタブ）
            if (preg_match('/^[ \t]/', $line)) {
                // 継続行：先頭のスペース/タブを削除して結合
                if (!empty($current_continuation)) {
                    $current_continuation .= substr($line, 1);
                }
            } else {
                // 新しい行：前の行を保存
                if (!empty($current_continuation)) {
                    $normalized_lines[] = rtrim($current_continuation);
                }
                $current_continuation = rtrim($line);
            }
        }
        // 最後の行を追加
        if (!empty($current_continuation)) {
            $normalized_lines[] = rtrim($current_continuation);
        }
        
        // 正規化された行を処理
        foreach ($normalized_lines as $line) {
            // VCALENDAR開始
            if ($line === 'BEGIN:VCALENDAR') {
                $in_vcalendar = true;
                continue;
            }
            
            // VCALENDAR終了
            if ($line === 'END:VCALENDAR') {
                $in_vcalendar = false;
                continue;
            }
            
            // カレンダーレベルのCOLOR情報を取得
            if ($in_vcalendar && preg_match('/^([^:]+):(.*)$/', $line, $matches)) {
                $property_raw = trim($matches[1]);
                $value = isset($matches[2]) ? $matches[2] : '';
                $property = strtoupper($property_raw);
                
                // COLORプロパティを確認
                if (empty($calendar_color)) {
                    if ($property === 'COLOR') {
                        $calendar_color = trim($value);
                    } elseif ($property === 'X-APPLE-CALENDAR-COLOR') {
                        $calendar_color = trim($value);
                    } elseif ($property === 'X-WR-CALNAME') {
                        // カレンダー名も保存（デバッグ用）
                        if ($debug_info !== null) {
                            $debug_info['calendar_name'] = trim($value);
                        }
                    }
                }
            }
            
            // イベント開始
            if ($line === 'BEGIN:VEVENT') {
                $current_event = array();
                continue;
            }
            
            // イベント終了
            if ($line === 'END:VEVENT' && $current_event !== null) {
                // デバッグ情報：パース前のイベントデータを保存
                if ($debug_info !== null) {
                    $debug_info['parsed_events_count']++;
                    if (count($debug_info['raw_events']) < 20) { // 最初の20件のみ保存
                        $debug_info['raw_events'][] = $current_event;
                    }
                }
                
                // カレンダーの色情報とICS URL情報を渡す
                // イベントデータにICS URL情報を追加
                if (!empty($ics_url)) {
                    $current_event['ics_url'] = $ics_url;
                }
                $event_data = $this->process_event($current_event, $start_date, $end_date, $exclude_list, $calendar_color);
                if ($event_data !== null) {
                    // RRULEがある場合は配列が返される
                    if (is_array($event_data) && isset($event_data[0])) {
                        // 繰り返しイベントが展開された場合
                        foreach ($event_data as $expanded_event) {
                            $events[] = $expanded_event;
                        }
                    } else {
                        // 単一イベントの場合
                        $events[] = $event_data;
                    }
                }
                $current_event = null;
                continue;
            }
            
            // プロパティ解析
            if ($current_event !== null && preg_match('/^([^:]+):(.*)$/', $line, $matches)) {
                $property_raw = trim($matches[1]);
                $value = isset($matches[2]) ? $matches[2] : '';
                
                // プロパティ名とパラメータを分離
                $property = strtoupper($property_raw);
                $params = array();
                if (strpos($property, ';') !== false) {
                    $parts = explode(';', $property);
                    $property = $parts[0];
                    // パラメータを解析（例：TZID=Asia/Tokyo）
                    for ($i = 1; $i < count($parts); $i++) {
                        if (strpos($parts[$i], '=') !== false) {
                            list($param_name, $param_value) = explode('=', $parts[$i], 2);
                            $params[strtoupper($param_name)] = $param_value;
                        }
                    }
                }
                
                // TZIDパラメータがある場合、値を保存（タイムゾーン情報として使用）
                if (isset($params['TZID'])) {
                    $current_event[$property . '_TZID'] = $params['TZID'];
                }
                
                // 値を保存（上書きする - 継続行は既に結合済み）
                $current_event[$property] = $value;
            }
        }
        
        // 日付順でソート
        usort($events, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        // デバッグ情報：最終イベント数を更新
        if ($debug_info !== null) {
            $debug_info['final_events_count'] = count($events);
        }
        
        return $events;
    }
    
    private function process_event($event_data, $start_date, $end_date, $exclude_list = array(), $calendar_color = '') {
        // DTSTART解析（TZIDパラメータを考慮）
        $dtstart_tzid = isset($event_data['DTSTART_TZID']) ? $event_data['DTSTART_TZID'] : null;
        $dtstart = $this->parse_datetime($event_data['DTSTART'] ?? '', $dtstart_tzid);
        if (!$dtstart) {
            return null;
        }
        
        // RRULE（繰り返しイベント）がある場合は展開
        if (!empty($event_data['RRULE'])) {
            return $this->expand_recurring_event($event_data, $dtstart, $start_date, $end_date, $exclude_list, $calendar_color);
        }
        
        // 表示期間内かチェック（単一イベントの場合）
        if ($dtstart < $start_date || $dtstart > $end_date) {
            return null;
        }
        
        // 単一イベントの場合は create_event_data を使用
        return $this->create_event_data($event_data, $dtstart, $exclude_list, $calendar_color);
    }
    
    /**
     * 繰り返しイベント（RRULE）を展開する
     */
    private function expand_recurring_event($event_data, $dtstart, $start_date, $end_date, $exclude_list = array(), $calendar_color = '') {
        $rrule_str = $event_data['RRULE'];
        $rrule = $this->parse_rrule($rrule_str);
        
        // ICS URL情報を保持（event_dataに含まれている場合）
        $ics_url = isset($event_data['ics_url']) ? $event_data['ics_url'] : null;
        
        if (!$rrule) {
            // RRULEが解析できない場合は単一イベントとして処理
            if ($dtstart >= $start_date && $dtstart <= $end_date) {
                // ICS URL情報を保持
                if ($ics_url !== null) {
                    $event_data['ics_url'] = $ics_url;
                }
                return $this->create_event_data($event_data, $dtstart, $exclude_list, $calendar_color);
            }
            return null;
        }
        
        // EXDATE（除外日）を取得
        $exdates = array();
        if (!empty($event_data['EXDATE'])) {
            $exdate_strs = is_array($event_data['EXDATE']) ? $event_data['EXDATE'] : array($event_data['EXDATE']);
            foreach ($exdate_strs as $exdate_str) {
                $exdate = $this->parse_datetime($exdate_str, $event_data['DTSTART_TZID'] ?? null);
                if ($exdate) {
                    $exdates[] = $exdate->format('Y-m-d');
                }
            }
        }
        
        // 繰り返しインスタンスを生成
        $expanded_events = array();
        $current_date = clone $dtstart;
        $max_instances = 365; // 最大生成数（無限ループ防止）
        $instance_count = 0;
        
        // 繰り返しの終了条件
        $until = null;
        if (!empty($rrule['UNTIL'])) {
            $until = $this->parse_datetime($rrule['UNTIL'], $event_data['DTSTART_TZID'] ?? null);
        }
        $count = isset($rrule['COUNT']) ? (int)$rrule['COUNT'] : null;
        
        // 表示期間の終了日またはUNTIL/COUNTで制限
        $limit_date = $end_date;
        if ($until && $until < $limit_date) {
            $limit_date = $until;
        }
        
        while ($current_date <= $limit_date && $instance_count < $max_instances) {
            // 表示期間内かチェック
            if ($current_date >= $start_date && $current_date <= $end_date) {
                // EXDATE（除外日）でない場合
                $date_key = $current_date->format('Y-m-d');
                if (!in_array($date_key, $exdates)) {
                    // ICS URL情報を保持
                    if ($ics_url !== null) {
                        $event_data['ics_url'] = $ics_url;
                    }
                    $event_instance = $this->create_event_data($event_data, $current_date, $exclude_list, $calendar_color);
                    if ($event_instance !== null) {
                        $expanded_events[] = $event_instance;
                    }
                }
            }
            
            // 次の繰り返し日を計算
            $next_date = $this->calculate_next_recurrence($current_date, $rrule, $dtstart);
            if (!$next_date || $next_date <= $current_date) {
                break; // 進まなくなったら終了
            }
            $current_date = $next_date;
            $instance_count++;
            
            // COUNT制限チェック
            if ($count !== null && $instance_count >= $count) {
                break;
            }
        }
        
        return !empty($expanded_events) ? $expanded_events : null;
    }
    
    /**
     * RRULE文字列を解析する
     */
    private function parse_rrule($rrule_str) {
        $rrule = array();
        $parts = explode(';', $rrule_str);
        
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $part, 2);
            $key = strtoupper(trim($key));
            
            switch ($key) {
                case 'FREQ':
                    $rrule['FREQ'] = strtoupper($value);
                    break;
                case 'INTERVAL':
                    $rrule['INTERVAL'] = (int)$value;
                    break;
                case 'COUNT':
                    $rrule['COUNT'] = (int)$value;
                    break;
                case 'UNTIL':
                    $rrule['UNTIL'] = $value;
                    break;
                case 'BYDAY':
                    $rrule['BYDAY'] = explode(',', $value);
                    break;
                case 'BYMONTHDAY':
                    $rrule['BYMONTHDAY'] = array_map('intval', explode(',', $value));
                    break;
                case 'BYMONTH':
                    $rrule['BYMONTH'] = array_map('intval', explode(',', $value));
                    break;
            }
        }
        
        if (empty($rrule['FREQ'])) {
            return null;
        }
        
        // INTERVALのデフォルト値
        if (!isset($rrule['INTERVAL'])) {
            $rrule['INTERVAL'] = 1;
        }
        
        return $rrule;
    }
    
    /**
     * 次の繰り返し日を計算する
     */
    private function calculate_next_recurrence($current_date, $rrule, $original_start) {
        $freq = $rrule['FREQ'];
        $interval = $rrule['INTERVAL'] ?? 1;
        $next_date = clone $current_date;
        
        switch ($freq) {
            case 'DAILY':
                $next_date->modify("+{$interval} days");
                break;
                
            case 'WEEKLY':
                if (!empty($rrule['BYDAY'])) {
                    // 特定の曜日のみ（例：BYDAY=MO,WE,FR）
                    $next_date = $this->calculate_next_byday($current_date, $rrule['BYDAY'], $interval);
                } else {
                    // 元の曜日を保持
                    $next_date->modify("+{$interval} weeks");
                }
                break;
                
            case 'MONTHLY':
                if (!empty($rrule['BYMONTHDAY'])) {
                    // 特定の日のみ（例：BYMONTHDAY=15）
                    $next_date = $this->calculate_next_bymonthday($current_date, $rrule['BYMONTHDAY'], $interval);
                } else {
                    // 同じ日付の次の月
                    $next_date->modify("+{$interval} months");
                }
                break;
                
            case 'YEARLY':
                $next_date->modify("+{$interval} years");
                break;
                
            default:
                // 不明なFREQの場合はDAILYとして処理
                $next_date->modify("+{$interval} days");
                break;
        }
        
        return $next_date;
    }
    
    /**
     * BYDAY指定の次の日を計算
     */
    private function calculate_next_byday($current_date, $byday_array, $interval) {
        $weekdays = array('SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6);
        $current_weekday = (int)$current_date->format('w');
        
        // 次の該当日を探す
        $next_date = clone $current_date;
        $next_date->modify('+1 day');
        $found = false;
        $max_days = 14; // 最大2週間探す
        
        for ($i = 0; $i < $max_days; $i++) {
            $weekday = (int)$next_date->format('w');
            foreach ($byday_array as $day) {
                $day = strtoupper(trim($day));
                // +2MO などの形式にも対応
                if (preg_match('/^(\d+)?([A-Z]{2})$/', $day, $matches)) {
                    $day_name = $matches[2];
                    if (isset($weekdays[$day_name]) && $weekdays[$day_name] == $weekday) {
                        $found = true;
                        break 2;
                    }
                }
            }
            $next_date->modify('+1 day');
        }
        
        if ($found && $interval > 1) {
            // INTERVALが1より大きい場合は、INTERVAL週分進める
            $next_date->modify('+' . ($interval - 1) . ' weeks');
        }
        
        return $found ? $next_date : null;
    }
    
    /**
     * BYMONTHDAY指定の次の日を計算
     */
    private function calculate_next_bymonthday($current_date, $bymonthday_array, $interval) {
        $next_date = clone $current_date;
        $current_day = (int)$current_date->format('j');
        
        // 現在の月内で次の該当日を探す
        $current_month = (int)$current_date->format('n');
        $found = false;
        
        foreach ($bymonthday_array as $day) {
            if ($day > 0 && $day >= $current_day) {
                // 今月の該当日
                $test_date = clone $current_date;
                $test_date->setDate((int)$test_date->format('Y'), (int)$test_date->format('n'), $day);
                if ($test_date >= $current_date) {
                    $next_date = $test_date;
                    $found = true;
                    break;
                }
            }
        }
        
        if (!$found) {
            // 次の月へ
            $next_date->modify('+1 month');
            $next_date->setDate((int)$next_date->format('Y'), (int)$next_date->format('n'), $bymonthday_array[0]);
        }
        
        if ($interval > 1) {
            $next_date->modify('+' . ($interval - 1) . ' months');
        }
        
        return $next_date;
    }
    
    /**
     * イベントデータを作成する（共通処理）
     */
    private function create_event_data($event_data, $dtstart, $exclude_list = array(), $calendar_color = '') {
        // DTEND解析（時間指定の場合、TZIDパラメータを考慮）
        $duration = null;
        if (!empty($event_data['DTEND'])) {
            $dtend_tzid = isset($event_data['DTEND_TZID']) ? $event_data['DTEND_TZID'] : (isset($event_data['DTSTART_TZID']) ? $event_data['DTSTART_TZID'] : null);
            $dtend_original = $this->parse_datetime($event_data['DTEND'], $dtend_tzid);
            if ($dtend_original) {
                $duration = $dtstart->diff($dtend_original);
            }
        } elseif (!empty($event_data['DURATION'])) {
            // DURATIONプロパティがある場合
            $duration = $this->parse_duration($event_data['DURATION']);
        }
        
        // DTENDを計算（durationから）
        $dtend = null;
        if ($duration) {
            $dtend = clone $dtstart;
            if ($duration->days > 0) {
                $dtend->modify('+' . $duration->days . ' days');
            }
            if ($duration->h > 0) {
                $dtend->modify('+' . $duration->h . ' hours');
            }
            if ($duration->i > 0) {
                $dtend->modify('+' . $duration->i . ' minutes');
            }
            if ($duration->s > 0) {
                $dtend->modify('+' . $duration->s . ' seconds');
            }
        }
        
        // タイトル取得（予定が存在する場合はタイトルを表示する）
        $title = isset($event_data['SUMMARY']) ? $event_data['SUMMARY'] : '';
        if (empty($title)) {
            $title = isset($event_data['DESCRIPTION']) ? $event_data['DESCRIPTION'] : '';
            if (empty($title)) {
                $title = isset($event_data['LOCATION']) ? $event_data['LOCATION'] : '';
            }
        }
        
        // 改行を除去してからトリム
        $title = trim(str_replace(array("\r\n", "\r", "\n"), ' ', $title));
        $title = preg_replace('/\s+/', ' ', $title);
        $title = str_replace(array('\\\\', '\\,', '\\;'), array('\\', ',', ';'), $title);
        $title = stripcslashes($title);
        $title = urldecode($title);
        
        if (empty($title)) {
            $title = '（タイトルなし）';
        }
        
        // 「Busy」という単独の値は無視
        if (trim($title) === 'Busy' || trim($title) === 'busy' || trim($title) === 'BUSY') {
            return null;
        }
        
        // 除外パターンチェック
        if (!empty($exclude_list)) {
            foreach ($exclude_list as $pattern) {
                if (stripos($title, $pattern) !== false) {
                    return null;
                }
            }
        }
        
        // 日付表示用
        $date_display = $dtstart->format('j日');
        $weekday = $this->get_japanese_weekday($dtstart->format('w'));
        
        // 時間表示（終日でない場合）
        $time = '';
        if ($dtend && !$this->is_all_day($event_data['DTSTART'])) {
            $start_time = $dtstart->format('H:i');
            $end_time = $dtend->format('H:i');
            $time = $start_time . ' - ' . $end_time;
        }
        
        // 説明（DESCRIPTION）を取得
        $description = isset($event_data['DESCRIPTION']) ? $event_data['DESCRIPTION'] : '';
        $description = trim(str_replace(array("\r\n", "\r", "\n"), ' ', $description));
        $description = preg_replace('/\s+/', ' ', $description);
        $description = str_replace(array('\\\\', '\\,', '\\;'), array('\\', ',', ';'), $description);
        $description = stripcslashes($description);
        $description = urldecode($description);
        
        // 場所（LOCATION）を取得
        $location = isset($event_data['LOCATION']) ? $event_data['LOCATION'] : '';
        $location = trim(str_replace(array("\r\n", "\r", "\n"), ' ', $location));
        $location = preg_replace('/\s+/', ' ', $location);
        $location = str_replace(array('\\\\', '\\,', '\\;'), array('\\', ',', ';'), $location);
        $location = stripcslashes($location);
        $location = urldecode($location);
        
        // 色情報の取得（イベント固有 → カレンダー → デフォルトの順）
        $display_background_color = '';
        $event_color = '';
        if (isset($event_data['COLOR'])) {
            $event_color = trim($event_data['COLOR']);
        } elseif (isset($event_data['X-APPLE-CALENDAR-COLOR'])) {
            $event_color = trim($event_data['X-APPLE-CALENDAR-COLOR']);
        } elseif (isset($event_data['X-COLOR'])) {
            $event_color = trim($event_data['X-COLOR']);
        }
        
        if (empty($event_color) && !empty($calendar_color)) {
            $display_background_color = $calendar_color;
        } elseif (!empty($event_color)) {
            $display_background_color = $event_color;
        }
        
        // 色コードを正規化
        if (!empty($display_background_color)) {
            if (strpos($display_background_color, '#') !== 0) {
                $display_background_color = '#' . $display_background_color;
            }
            if (strlen($display_background_color) === 4) {
                $display_background_color = '#' . $display_background_color[1] . $display_background_color[1] . $display_background_color[2] . $display_background_color[2] . $display_background_color[3] . $display_background_color[3];
            }
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $display_background_color)) {
                $display_background_color = '';
            }
        }
        
        $result = array(
            'date' => $dtstart->format('Y-m-d'),
            'date_display' => $date_display,
            'weekday' => $weekday,
            'time' => $time,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'displayBackgroundColor' => $display_background_color,
            'datetime' => $dtstart
        );
        
        // ICS URL情報があれば保持（繰り返しイベント展開時など）
        if (isset($event_data['ics_url'])) {
            $result['ics_url'] = $event_data['ics_url'];
        }
        
        return $result;
    }
    
    /**
     * DURATION文字列を解析する
     */
    private function parse_duration($duration_str) {
        // P1D, PT2H30M などの形式
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $duration_str, $matches)) {
            $days = isset($matches[1]) ? (int)$matches[1] : 0;
            $hours = isset($matches[2]) ? (int)$matches[2] : 0;
            $minutes = isset($matches[3]) ? (int)$matches[3] : 0;
            $seconds = isset($matches[4]) ? (int)$matches[4] : 0;
            
            // DateIntervalオブジェクトを作成
            return new DateInterval("P{$days}DT{$hours}H{$minutes}M{$seconds}S");
        }
        
        return null;
    }
    
    private function parse_datetime($datetime_str, $tzid = null) {
        // デフォルトタイムゾーンは日本時間（JST）
        $default_timezone = new DateTimeZone('Asia/Tokyo');
        
        // TZIDパラメータが指定されている場合はそれを優先
        if (!empty($tzid)) {
            try {
                $source_timezone = new DateTimeZone($tzid);
            } catch (Exception $e) {
                $source_timezone = $default_timezone;
            }
        } else {
            $source_timezone = $default_timezone;
        }
        
        // WordPressのタイムゾーン設定を取得（表示用）
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset');
        
        // 表示用タイムゾーンオブジェクトを作成
        if (!empty($timezone_string)) {
            try {
                $display_timezone = new DateTimeZone($timezone_string);
            } catch (Exception $e) {
                $display_timezone = $default_timezone;
            }
        } elseif ($gmt_offset !== false && $gmt_offset != 0) {
            // GMTオフセットからタイムゾーンを作成
            $offset_hours = (int)$gmt_offset;
            $offset_str = sprintf('%s%02d00', $offset_hours >= 0 ? '+' : '-', abs($offset_hours));
            try {
                $display_timezone = new DateTimeZone($offset_str);
            } catch (Exception $e) {
                $display_timezone = $default_timezone;
            }
        } else {
            $display_timezone = $default_timezone;
        }
        
        // タイムゾーン情報を含む場合（時間指定イベント）
        if (preg_match('/^(\d{8})T(\d{6})(Z|[+-]\d{4})?$/', $datetime_str, $matches)) {
            $date_str = $matches[1];
            $time_str = $matches[2];
            $tz_str = $matches[3] ?? null;
            
            if ($tz_str === null) {
                // タイムゾーン情報がない場合、TZIDパラメータまたはデフォルトタイムゾーンを使用
                $datetime = DateTime::createFromFormat('Ymd His', $date_str . ' ' . $time_str, $source_timezone);
                if ($datetime && $source_timezone->getName() !== $display_timezone->getName()) {
                    // 表示用タイムゾーンに変換
                    $datetime->setTimezone($display_timezone);
                }
                return $datetime ? $datetime : null;
            } elseif ($tz_str === 'Z') {
                // UTC（Z）の場合はUTCとして解析してから表示用タイムゾーンに変換
                $datetime = DateTime::createFromFormat('Ymd His', $date_str . ' ' . $time_str, new DateTimeZone('UTC'));
                if ($datetime) {
                    $datetime->setTimezone($display_timezone);
                }
                return $datetime ? $datetime : null;
            } else {
                // タイムゾーン情報がある場合（例：+0900, -0500）
                try {
                    // オフセット形式（+0900など）でタイムゾーンを作成
                    $tz_offset = new DateTimeZone($tz_str);
                    $datetime = DateTime::createFromFormat('Ymd His', $date_str . ' ' . $time_str, $tz_offset);
                    if ($datetime) {
                        // 表示用タイムゾーンに変換
                        $datetime->setTimezone($display_timezone);
                    }
                    return $datetime ? $datetime : null;
                } catch (Exception $e) {
                    // タイムゾーン解析に失敗した場合、TZIDまたはデフォルトタイムゾーンで試す
                    $datetime = DateTime::createFromFormat('Ymd His', $date_str . ' ' . $time_str, $source_timezone);
                    if ($datetime && $source_timezone->getName() !== $display_timezone->getName()) {
                        $datetime->setTimezone($display_timezone);
                    }
                    return $datetime ? $datetime : null;
                }
            }
        }
        
        // 終日イベント（YYYYMMDD形式）
        if (preg_match('/^(\d{8})$/', $datetime_str)) {
            $datetime = DateTime::createFromFormat('Ymd', $datetime_str, $display_timezone);
            if ($datetime) {
                $datetime->setTime(0, 0, 0);
            }
            return $datetime;
        }
        
        return null;
    }
    
    private function is_all_day($dtstart_str) {
        // 終日イベントは8桁の日付のみ
        return preg_match('/^\d{8}$/', $dtstart_str);
    }
    
    private function get_japanese_weekday($w) {
        $weekdays = array('日', '月', '火', '水', '木', '金', '土');
        return '(' . $weekdays[$w] . ')';
    }
    
    /**
     * Google Calendar APIからイベントを取得
     */
    private function get_gcal_events($calendar_id, $api_key, $days_ahead = 60, $exclude_patterns = '', $debug_mode = false) {
        if (empty($api_key) || empty($calendar_id)) {
            return new WP_Error('missing_params', 'APIキーまたはカレンダーIDが設定されていません');
        }
        
        // キャッシュチェック（デバッグモードの場合はキャッシュを無視）
        $cache_key = 'schedule_gcal_events_' . md5($calendar_id . $api_key . $days_ahead . $exclude_patterns);
        if (!$debug_mode) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }
        
        // カラーパレットを取得
        $colors = $this->get_gcal_colors($api_key, $debug_mode);
        if (is_wp_error($colors)) {
            if ($debug_mode) {
                error_log('Google Calendar API: colors.get failed - ' . $colors->get_error_message());
            }
            // カラー取得に失敗してもイベント取得は続行（デフォルト色を使用）
            $colors = array();
        }
        
        // 日付範囲を計算
        $time_min = date('c'); // 現在時刻（ISO 8601形式）
        $time_max = date('c', strtotime("+{$days_ahead} days")); // days_ahead日後
        
        // events.list APIを呼び出し
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events';
        $url .= '?key=' . urlencode($api_key);
        $url .= '&timeMin=' . urlencode($time_min);
        $url .= '&timeMax=' . urlencode($time_max);
        $url .= '&singleEvents=true'; // 繰り返しイベントを展開
        $url .= '&orderBy=startTime';
        $url .= '&maxResults=2500'; // 最大取得件数
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            if ($debug_mode) {
                error_log('Google Calendar API: events.list failed - ' . $response->get_error_message());
            }
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = 'JSON解析エラー: ' . json_last_error_msg();
            if ($debug_mode) {
                error_log('Google Calendar API: ' . $error_msg);
            }
            return new WP_Error('json_error', $error_msg);
        }
        
        // エラーチェック
        if (isset($data['error'])) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'APIエラーが発生しました';
            if ($debug_mode) {
                error_log('Google Calendar API: ' . $error_msg);
            }
            return new WP_Error('api_error', $error_msg);
        }
        
        // イベントを変換
        $events = array();
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                // タイトルがないイベントはスキップ
                if (empty($item['summary'])) {
                    continue;
                }
                
                // 除外パターンチェック
                if (!empty($exclude_patterns)) {
                    $patterns = array_map('trim', explode(',', $exclude_patterns));
                    foreach ($patterns as $pattern) {
                        if (stripos($item['summary'], $pattern) !== false) {
                            continue 2; // このイベントをスキップ
                        }
                    }
                }
                
                $event = $this->convert_gcal_event_to_schedule_format($item, $colors, $calendar_id);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }
        
        // キャッシュに保存（1時間 = 3600秒）
        if (!$debug_mode) {
            set_transient($cache_key, $events, 3600);
        }
        
        return $events;
    }
    
    /**
     * Google Calendar APIからカラーパレットを取得
     */
    private function get_gcal_colors($api_key, $debug_mode = false) {
        $cache_key = 'schedule_gcal_colors_' . md5($api_key);
        if (!$debug_mode) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }
        
        $url = 'https://www.googleapis.com/calendar/v3/colors';
        $url .= '?key=' . urlencode($api_key);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'JSON解析エラー: ' . json_last_error_msg());
        }
        
        // エラーチェック
        if (isset($data['error'])) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'APIエラーが発生しました';
            return new WP_Error('api_error', $error_msg);
        }
        
        $colors = isset($data['event']) ? $data['event'] : array();
        
        // キャッシュに保存（24時間）
        if (!$debug_mode) {
            set_transient($cache_key, $colors, 86400);
        }
        
        return $colors;
    }
    
    /**
     * Google Calendar APIのイベントデータをスケジュール表示用フォーマットに変換
     */
    private function convert_gcal_event_to_schedule_format($gcal_event, $colors, $calendar_id) {
        // タイトル
        $title = isset($gcal_event['summary']) ? trim($gcal_event['summary']) : '';
        if (empty($title)) {
            return null;
        }
        
        // 日時情報
        $dtstart = null;
        $dtend = null;
        $is_all_day = false;
        
        if (isset($gcal_event['start']['dateTime'])) {
            // 時刻指定イベント
            $dtstart = new DateTime($gcal_event['start']['dateTime']);
            if (isset($gcal_event['end']['dateTime'])) {
                $dtend = new DateTime($gcal_event['end']['dateTime']);
            }
        } elseif (isset($gcal_event['start']['date'])) {
            // 終日イベント
            $dtstart = new DateTime($gcal_event['start']['date']);
            if (isset($gcal_event['end']['date'])) {
                $dtend = new DateTime($gcal_event['end']['date']);
                // 終日イベントのendは翌日の00:00なので、1日引く
                $dtend->modify('-1 day');
            }
            $is_all_day = true;
        } else {
            return null; // 日時情報がない場合はスキップ
        }
        
        // タイムゾーンをJSTに変換
        $dtstart->setTimezone(new DateTimeZone('Asia/Tokyo'));
        if ($dtend) {
            $dtend->setTimezone(new DateTimeZone('Asia/Tokyo'));
        }
        
        // 日付表示
        $date_display = $dtstart->format('Y年n月j日');
        $weekday = $this->get_japanese_weekday($dtstart->format('w'));
        
        // 時間表示
        $time = '';
        if (!$is_all_day && $dtend) {
            $start_time = $dtstart->format('H:i');
            $end_time = $dtend->format('H:i');
            $time = $start_time . ' - ' . $end_time;
        }
        
        // 説明
        $description = isset($gcal_event['description']) ? trim($gcal_event['description']) : '';
        
        // 場所
        $location = isset($gcal_event['location']) ? trim($gcal_event['location']) : '';
        
        // イベント色を取得
        $event_color_hex = '#4caf50'; // デフォルト色（緑）
        if (isset($gcal_event['colorId']) && !empty($gcal_event['colorId'])) {
            $color_id = $gcal_event['colorId'];
            if (isset($colors[$color_id]) && isset($colors[$color_id]['background'])) {
                $event_color_hex = $colors[$color_id]['background'];
            }
        }
        
        // 結果を返す
        return array(
            'date' => $dtstart->format('Y-m-d'),
            'date_display' => $date_display,
            'weekday' => $weekday,
            'time' => $time,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'eventColorHex' => $event_color_hex, // イベント色（HEX形式）
            'datetime' => $dtstart,
            'calendar_id' => $calendar_id // カレンダーIDを保持（デバッグ用）
        );
    }
}

// プラグイン初期化
function schedule_display_init() {
    Schedule_Display::get_instance();
}
add_action('plugins_loaded', 'schedule_display_init');
