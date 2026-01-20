<?php
/**
 * Template Name: 埋め込み用スケジュールページ
 * Description: iframe埋め込み用の最小テンプレート
 */

// セキュリティチェック
if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title('|', true, 'right'); ?><?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('page-template-embed'); ?>>
    <div class="schedule-embed-wrapper">
        <main class="schedule-main-content">
            <?php
            while (have_posts()) :
                the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <div class="entry-content">
                        <?php
                        // デバッグ用：ショートコードの存在確認
                        $has_shortcode = false;
                        $content = get_the_content();
                        if (!empty($content) && has_shortcode($content, 'schedule_display')) {
                            $has_shortcode = true;
                            the_content();
                        } else {
                            // ショートコードが含まれていない場合は自動的に追加
                            echo do_shortcode('[schedule_display]');
                        }
                        
                        // デバッグ用：ショートコードの実行確認
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            echo '<!-- DEBUG: Shortcode found: ' . ($has_shortcode ? 'yes' : 'no') . ' -->';
                            echo '<!-- DEBUG: Content length: ' . strlen($content) . ' -->';
                        }
                        
                        wp_link_pages(array(
                            'before' => '<div class="page-links">' . esc_html__('Pages:', 'textdomain'),
                            'after'  => '</div>',
                        ));
                        ?>
                    </div>
                </article>
                <?php
            endwhile;
            ?>
        </main>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
