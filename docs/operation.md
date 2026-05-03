# 運用ドキュメント

## 1. 環境構築
### 1.1. 必要要件
- PHP 8.5+
- MySQL
- Redis (キュー用推奨、MySQLでも可)
- Dify APIキー
- Discord Botトークン・公開鍵

### 1.2. セットアップ手順
1. リポジトリをクローン。
2. `composer install` を実行。
3. `.env` を設定（DifyおよびDiscordの設定）。
4. `php artisan migrate` を実行。

## 2. 実行・監視
### 2.1. キューワーカーの起動
AIの応答には時間がかかるため、タイムアウト設定を長めにしてワーカーを起動してください。また、リソース節約のため並列実行数を制限することを推奨します。

```bash
php artisan queue:work --timeout=300
```

### 2.2. ログの確認
システムの状態は `storage/logs/laravel.log` に出力されます。
- APIリクエストのペイロード
- Jobのディスパッチ状況
- エラー発生時のスタックトレース

## 3. Discordの設定
### 3.1. Interactions Endpoint
Discord Developer Portal で以下のURLを Interactions Endpoint として登録してください。
`https://your-domain.com/api/discord/interactions`

### 3.2. スラッシュコマンドの登録
以下のコマンドをBotに登録してください。
- `/discuss [topic] [model]`: ディベートを開始
- `/intervene [target] [message]`: 議論に介入

### 3.3. Message Content Intent
メンションによる介入機能を有効にするには、Discord Developer Portal で **Message Content Intent** を有効にする必要があります。

## 4. トラブルシューティング
- **AIが応答しない:** Dify APIのクォータまたはステータスを確認してください。
- **Discordにメッセージが投稿されない:** Botのチャンネル作成・メッセージ送信権限を確認してください。
- **処理が途中で止まる:** キューワーカーが停止していないか確認してください。
