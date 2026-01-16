# キャッシュクリアと修正確認の方法

## 修正内容の確認方法

### 1. ファイルの修正確認
以下のコマンドで修正が適用されているか確認できます：

```bash
grep -n "イベントが表示されませんでした" /home/devwp/wp1/schedule-display/schedule-display.php
grep -n "イベントが表示されませんでした" /home/devwp/wp1/schedule-display-plugin.php
```

両方のファイルに `1307行目` に修正が含まれていればOKです。

### 2. WordPressのキャッシュクリア

#### 方法1: WordPress管理画面から
1. WordPress管理画面にログイン
2. 「設定」→「スケジュール表示」に移動
3. 「キャッシュをクリア」ボタンをクリック

#### 方法2: ブラウザのキャッシュクリア
1. ブラウザで `Ctrl+Shift+R` (Windows/Linux) または `Cmd+Shift+R` (Mac) で強制リロード
2. または、開発者ツールを開いて（F12）、ネットワークタブで「キャッシュの無効化」にチェックを入れる

#### 方法3: PHPのオペコードキャッシュ（OPcache）をクリア
WordPressがDockerコンテナで動いている場合：

```bash
# コンテナ内でOPcacheをクリア
docker exec -it <wordpress-container-name> php -r "opcache_reset();"
```

または、WordPressの設定ファイル（wp-config.php）に以下を追加：

```php
// 開発中のみ：OPcacheを無効化
if (defined('WP_DEBUG') && WP_DEBUG) {
    opcache_reset();
}
```

### 3. 実際に使用されているファイルの確認

WordPressのプラグインディレクトリを確認：

```bash
# WordPressのプラグインディレクトリを探す
find /var/www/html -name "schedule-display.php" 2>/dev/null
# または
find /home -name "schedule-display.php" -path "*/wp-content/plugins/*" 2>/dev/null
```

### 4. 修正が反映されているか確認

ブラウザで以下を確認：

1. **ページを強制リロード**（Ctrl+Shift+R または Cmd+Shift+R）
2. **1月16日のセルを確認**
   - 黄色の警告ボックスが表示されれば修正が反映されています
   - 警告ボックスに「⚠️ イベントが表示されませんでした」と表示されます
3. **ページのソースを確認**（右クリック→「ページのソースを表示」）
   - `イベントが表示されませんでした` という文字列を検索（Ctrl+F）
   - 見つかれば修正が反映されています

### 5. デバッグモードの確認

WordPress管理画面で：
1. 「設定」→「スケジュール表示」に移動
2. 「デバッグモード」にチェックが入っているか確認
3. チェックを入れて保存
4. ページを再読み込み

### 6. ファイルの最終更新時刻を確認

```bash
ls -la /home/devwp/wp1/schedule-display/schedule-display.php
ls -la /home/devwp/wp1/schedule-display-plugin.php
```

最新のタイムスタンプが表示されていれば、ファイルは更新されています。

## トラブルシューティング

### 修正が反映されない場合

1. **WordPressのプラグインディレクトリを確認**
   - 実際に使用されているファイルが `/home/devwp/wp1/` 以外の場所にある可能性があります

2. **ファイルの権限を確認**
   ```bash
   ls -la /home/devwp/wp1/schedule-display/schedule-display.php
   ```
   - 読み取り可能（`-r--` または `-rw-`）であることを確認

3. **PHPのエラーログを確認**
   ```bash
   # WordPressのエラーログを確認
   tail -f /var/log/php/error.log
   # または
   docker logs <wordpress-container-name> 2>&1 | grep -i error
   ```

4. **WordPressのデバッグモードを有効化**
   `wp-config.php` に以下を追加：
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
