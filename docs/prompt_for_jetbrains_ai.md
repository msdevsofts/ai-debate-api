あなたは熟練のPHP/Laravelバックエンドエンジニアです。

新規に作成した Discord アプリケーションと連携する「マルチAIディベートシステム」のバックエンドを構築してください。

## **1. システム概要**

ユーザーが /discuss コマンドを投げると、システムが議題ごとの専用スレッドを自動生成し、Dify経由で複数のローカルAI（Gemma, Phi, Llama, GPT-OSS-Q2）を呼び出して議論させ、最後にGeminiがまとめます。

**【重要】各AIは個別のDiscord Botアカウントとしてサーバーに参加しており、自律的にメンションを飛ばし合って議論を進めます。**

## **2. アーキテクチャと環境**

* **構成:** Laravel 13 (v13.x) / PHP 8.5+
* **アーキテクチャ:** DDD（ドメイン駆動設計）を採用し、Domain, Application, Infrastructure, Presentation のレイヤーに分割。
* **インフラ:** 2vCPU / 2GB RAM (Proxmox LXC)。
* **キュー:** MySQL database ドライバを使用。リソース枯渇を防ぐため、並列実行を避け直列で処理します。

## **3. ネットワーク構成と環境変数 (.env)**

複数のBotを制御するため、.env には以下のような個別設定を持たせます。

* **DB:** 127.0.0.1 / ai_debate / debate_app / debate_app
* **Dify:** http://10.10.1.10/v1
* **Discord設定 (複数Bot分):**
    * DISCORD_PUBLIC_KEY_GEMINI = "..." / DISCORD_BOT_TOKEN_GEMINI = "..."
    * DISCORD_PUBLIC_KEY_LLAMA = "..." / DISCORD_BOT_TOKEN_LLAMA = "..."
    * DISCORD_PUBLIC_KEY_GEMMA = "..." / DISCORD_BOT_TOKEN_GEMMA = "..."
    * DISCORD_PUBLIC_KEY_PHI = "..." / DISCORD_BOT_TOKEN_PHI = "..."
    * DISCORD_PUBLIC_KEY_GPT_OSS_Q2 = "..." / DISCORD_BOT_TOKEN_GPT_OSS_Q2 = "..."

## **4. コア機能要件**

既存のコードベースを理解し、以下の要件に基づいた拡張や修正を行ってください。

### **4.1 クエリ文字列による動的署名検証と即時応答 (Interactions Endpoint)**

* `/api/discord/interactions` エンドポイントでは、クエリ文字列 `?bot=...` を取得し、対応する .env の `DISCORD_PUBLIC_KEY_***` を用いてリクエスト署名（Ed25519）を検証する `VerifyDiscordSignature` ミドルウェアを適用しています。
* **【3秒ルール回避】** `/discuss` コマンドを受け取った際は、処理のタイムアウトを防ぐため、即座に `['type' => 5]` (DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE) を返却します。
* その後、`StartDebateJob` をディスパッチし、非同期でスレッド作成と最初の議論（ProcessDebateTurn）を開始します。

### **4.2 メッセージイベントによる対話 (Message Endpoint)**

* `/api/discord/messages` エンドポイント（`DiscordMessageController`）では、Botへのメンションを検知して議論を継続させます。
* メッセージ内に `<@BotID>` が含まれている場合、そのBotを `targetAi` として特定し、`ProcessDebateTurn` ジョブをディスパッチします。

### **4.3 思考プロセスの削除と救済ロジック**

* Difyから返却される回答から、正規表現を用いて `<think>...</think>`、`(think)...`、および `[ENDTHINKFLAG]` 以前の文字列を物理的に削除します（`DifyApiAdapter` 内で実装）。
* 削除の結果、文字列が空になった場合は、削除前の生のデータの末尾を抽出し、「（思考中...）」といった注釈付きで送信するフォールバックを `DiscordMessageFormatter` で行います。

### **4.4 個別トークンでの発言と、IDベースのメンション検知**

* 議論スレッドへの投稿は、発言するAIに対応する `DISCORD_BOT_TOKEN_***` を動的に使用して Discord REST API 経由で送信します。
* 送信メッセージ内に他のAIへのメンションが含まれている場合、次に発言すべき AI を特定し、連鎖的に `ProcessDebateTurn` ジョブをディスパッチします。

## **5. コーディング規約**

* PHP 8.5 以降の機能（ReadOnlyクラス、型指定の強化など）を活用した、モダンでクリーンなコードを維持してください。
* テストは `PHPUnit` を使用し、FeatureテストとUnitテストの両面から品質を担保します。
* 既存の DDD レイヤー構造（Domain, Application, Infrastructure, Presentation）を尊重し、各クラスの責務を明確に分離してください。
