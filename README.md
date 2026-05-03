# <img src="docs/images/logo.png" width="40" alt="Logo"> AI Debate API (マルチAIディベートシステム)
<p align="center">
  <img src="docs/images/banner.png" width="800" alt="Banner">
</p>

複数のAIモデル（Dify経由）がDiscord上でディベートを行うためのバックエンドシステムです。

## 1. システム構成
- **Backend:** Laravel 11
- **AI Integration:** Dify API
- **Communication:** Discord (Interactions & Webhooks)
- **Supported Models:** Gemini, Gemma, Phi, Llama, GPT-OSS-Q2

## 2. サーバー要件
- PHP 8.5+
- MySQL (Queue/Database ドライバ用)
- libsodium (Discord 署名検証用、推奨)

## 3. セットアップ

### 3.1. 環境設定 (.env)
`.env.example` を参考に、以下の設定を行ってください。

- **Dify設定:** `DIFY_API_BASE_URL`, `DIFY_API_KEY`
- **Discord設定:** 各Botのトークン、公開鍵、アプリID（クライアントID）

### 3.2. インストール
```bash
composer install
php artisan migrate
```

### 3.3. キューワーカーの実行 (重要)
リソース（2GB RAM）を保護し、AIの長時間生成に対応するため、以下の設定でワーカーを起動してください。

```bash
# --timeout: Jobごとの最大実行時間 (20分)
# --concurrency: 並列実行を避け、1に設定 (リソース節約のため)
php artisan queue:work --timeout=1200
```

## 4. Discord 設定

### 4.1. Interactions Endpoint URL
Discord Developer Portal の各アプリケーションの **Interactions Endpoint URL** に以下を設定してください。

`https://your-domain.com/api/discord/interactions`

※ 署名検証（`VerifyDiscordSignature` ミドルウェア）が有効になっている必要があります。

### 4.2. メッセージ送信 (Outgoing)
システムからのメッセージは Webhook または Discord API を通じて各Botとして送信されます。

### 4.3. ユーザーの介入 (/intervene)
進行中の議論に人間が介入し、特定のAIに指示を出すことができます。

- **コマンド:** `/intervene`
- **引数:**
  - `target`: 指示を出す対象のAI（各BotのアプリケーションIDまたはモデル名）
  - `message`: AIへの具体的な指示内容

このコマンドを使用すると、指定されたAIが指示内容を考慮して次の発言を行います。

## 5. 開発者向け情報

### 5.1. 主要なクラス
- `App\Presentation\Http\Controllers\DiscordInteractionController`: Discordからのインタラクションを受け取る
- `App\Application\UseCases\StartDebateUseCase`: ディベートの開始処理
- `App\Application\UseCases\ProcessDebateTurnUseCase`: ディベートの各ターンの進行処理
- `App\Infrastructure\Adapters\DifyApiAdapter`: Dify APIとの通信を担当

### 5.2. テストの実行
```bash
php artisan test
```

## 6. ライセンス
The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
