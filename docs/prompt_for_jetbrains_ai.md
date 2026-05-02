あなたは熟練のPHP/Laravelバックエンドエンジニアです。

新規に作成した Discord アプリケーションと連携する「マルチAIディベートシステム」のバックエンドを構築してください。

## **1. システム概要**

ユーザーが /discuss コマンドを投げると、システムが議題ごとの専用スレッドを自動生成し、Dify経由で複数のローカルAI（Gemma, Phi, Llama, GPT-OSS-Q2）を呼び出して議論させ、最後にGeminiがまとめます。

**【重要】各AIは個別のDiscord Botアカウントとしてサーバーに参加しており、自律的にメンションを飛ばし合って議論を進めます。**

## **2. アーキテクチャと環境**

* **構成:** Laravel 13 (v13.7.0) / PHP 8.5
* **重要:** 現在ディレクトリが空のため、**artisan ファイルを含む Laravel の標準的なフォルダ構造すべて**を生成してください。
* **DDD（ドメイン駆動設計）:** Domain, Application, Infrastructure, Presentation のレイヤーに分割。
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

以下の高度な機能を実装してください。

### **4.1 クエリ文字列による動的署名検証と即時応答 (Interactions Endpoint)**

* URLのクエリ文字列 ?bot=... を取得し、対応する .env の DISCORD_PUBLIC_KEY_*** を用いてリクエスト署名（Ed25519）を検証するミドルウェアを実装してください。
* **【重要: 3秒ルール回避】** /discuss コマンド（type: 2）を受け取った際は、処理のタイムアウトを防ぐため、**絶対に同期的にスレッド作成や重い処理を行わず、まず即座に ['type' => 5] (DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE) を JSON で返却してください。**
* トピック名などのデータと「どのBot宛か」を引数として、実際の「スレッド作成〜初回のディベートジョブディスパッチ」を行う非同期ジョブ（例: InitializeDebateSessionJob）をキューに積んでください。

### **4.2 思考プロセスの削除と救済ロジック**

* Difyから返却される $data['answer'] から、正規表現を用いて <think>...</think>、(think)...、および [ENDTHINKFLAG] 以前の文字列を物理的に削除してください。
* 削除の結果、文字列が空（empty）になった場合は、削除前の生のデータの末尾100〜300文字程度を抽出し、「（思考中...）」といった注釈付きで送信するフォールバック（救済措置）を実装し、空メッセージによるDiscord APIエラーを防いでください。

### **4.3 個別トークンでの発言と、IDベースのメンション検知**

* **重要:** 議論スレッドへメッセージを投稿する際は、Webhookのなりすましではなく、発言するAIに対応する DISCORD_BOT_TOKEN_*** を動的に使用して Discord REST API 経由で送信してください。
* 送信する文字列（$cleanedAnswer）の中に、<@数字> 形式のメンションが含まれているか正規表現でスキャンしてください。
* 検知したIDをマッピング配列（例: ['111' => 'Llama', '222' => 'Gemma', '333' => 'GPT-OSS-Q2']）と照合し、次に発言すべき $targetAi を特定してください。
* 特定できた場合は、次の ProcessDebateTurnUseCase ジョブをディスパッチしてください。Geminiの結論発言時などでメンションが無かった場合は、自動的に処理を終了します。

## **5. 出力物**

1. composer.json (Pest を含む) および artisan ファイル一式
2. DDD 各レイヤーのコード（Entity, UseCase, Repository, Adapter, Controller, Job, Middleware）
3. マイグレーションファイル（セッション用 + ジョブ用）
4. Pest による ProcessDebateTurnUseCase のユニットテスト

PHP 8.5 の Property Hooks や Asymmetric Visibility を積極的に使用した、モダンでクリーンなコードを期待します。抽象的な説明は省き、具体的なコードを提示してください。
