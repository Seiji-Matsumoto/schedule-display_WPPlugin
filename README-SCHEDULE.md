# スケジュール表示機能 - セットアップガイド

## 概要

Googleカレンダーの予定を一覧表示するWordPressプラグインです。
既存サイトに iframe で埋め込むことができます。

## 機能

- GoogleカレンダーのICS（iCal）形式から予定を取得
- 直近60日間（設定可）の予定を一覧表示
- **タイトル（SUMMARY）がある予定のみ表示**（空の予定は除外）
- iframe埋め込み対応（ヘッダー・フッター最小化）
- レスポンシブ対応
- キャッシュ機能（1時間）

## セットアップ手順

### 1. プラグインの有効化

1. WordPress管理画面にログイン
2. 「プラグイン」→「インストール済みプラグイン」を開く
3. 「Schedule Display (休業情報表示)」を有効化
4. 有効化時に自動的に `/schedule` 固定ページが作成されます

### 2. Googleカレンダーの設定

1. Googleカレンダーを開く
2. 設定（歯車アイコン）→「設定と共有」を選択
3. 「カレンダーの統合」セクションを開く
4. 「パブリックiCal形式」のURLをコピー
   - 例: `https://calendar.google.com/calendar/ical/xxxxx%40group.calendar.google.com/public/basic.ics`

### 3. プラグイン設定

1. WordPress管理画面で「設定」→「スケジュール表示」を開く
2. 「Googleカレンダー ICS URL」に上記でコピーしたURLを貼り付け
3. 「表示日数」を設定（デフォルト: 60日）
4. 「変更を保存」をクリック

### 4. 表示確認

1. ブラウザで `http://localhost:8081/schedule` にアクセス
2. 休業情報が表示されることを確認

## 埋め込み方法

### iframe埋め込み

既存サイトのHTMLに以下を追加：

```html
<iframe 
    src="https://your-domain.com/schedule" 
    width="100%" 
    height="900" 
    frameborder="0"
    scrolling="auto">
</iframe>
```

### レスポンシブ対応の埋め込み（高さ自動調整）

親ページに以下のJavaScriptを追加：

```html
<script>
window.addEventListener('message', function(event) {
    if (event.data.type === 'schedule-embed-height') {
        var iframe = document.querySelector('iframe[src*="/schedule"]');
        if (iframe) {
            iframe.style.height = event.data.height + 'px';
        }
    }
});
</script>

<iframe 
    src="https://your-domain.com/schedule" 
    width="100%" 
    height="900" 
    frameborder="0"
    scrolling="no"
    id="schedule-iframe">
</iframe>
```

## ショートコード

任意のページで `[schedule_display]` ショートコードを使用できます。

オプション:
- `days`: 表示日数（デフォルト: 設定画面の値）
- `ics_url`: ICS URL（デフォルト: 設定画面の値）

例:
```
[schedule_display days="30"]
[schedule_display days="90" ics_url="https://calendar.google.com/calendar/ical/.../basic.ics"]
```

## カスタマイズ

### CSSのカスタマイズ

プラグインのCSSは `wp-content/plugins/schedule-display/assets/style.css` にあります。
子テーマの `style.css` で上書き可能です。

### テンプレートのカスタマイズ

埋め込み用テンプレートは `wp-content/plugins/schedule-display/templates/embed-page.php` にあります。
必要に応じて編集してください。

## トラブルシューティング

### スケジュールが表示されない

1. ICS URLが正しいか確認
2. Googleカレンダーが「公開」設定になっているか確認
3. **予定にタイトル（SUMMARY）が設定されているか確認**（タイトルがない予定は表示されません）
4. キャッシュをクリア（Transient APIのキャッシュは1時間で自動更新）
5. ブラウザのコンソールでエラーを確認

### キャッシュを手動でクリア

WordPress管理画面で以下のプラグインを使用するか、データベースの `wp_options` テーブルから `_transient_schedule_events_*` を削除してください。

または、以下のコードを `functions.php` に追加：

```php
// スケジュールキャッシュをクリア（開発用）
add_action('admin_init', function() {
    if (isset($_GET['clear_schedule_cache'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_schedule_events_%'");
        wp_redirect(admin_url('options-general.php?page=schedule-display&cache_cleared=1'));
        exit;
    }
});
```

## ファイル構成

```
wp-content/plugins/schedule-display/
├── schedule-display.php    # メインプラグインファイル
├── assets/
│   └── style.css          # スタイルシート
└── templates/
    └── embed-page.php     # 埋め込み用テンプレート
```

## 開発環境

- WordPress: 最新版（php8.3-apache）
- PHP: 8.3
- データベース: MariaDB 11

## 注意事項

- Googleカレンダーは公開設定が必要です（ICS URL取得のため）
- **タイトル（SUMMARY）がない予定は表示されません**
- 個人情報はカレンダーに登録しないでください
- イベントタイトルは公開情報として扱われます
- キャッシュは1時間ごとに自動更新されます

## Phase 2（将来実装予定）

- Simple Calendarプラグインとの統合
- 複数カレンダー対応
- より高度なカスタマイズオプション
