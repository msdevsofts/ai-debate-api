# 詳細設計書

## 1. アプリケーションアーキテクチャ
本システムは以下の4つのレイヤーで構成されています。

- **Presentation:** 外部インターフェース（Discord API, HTTP Controller, Jobs）
- **Application:** ビジネスロジックの調整（UseCases）
- **Domain:** システムの核心ロジック（Entities, Enums, Repositories Interfaces）
- **Infrastructure:** 外部システムとの連携実装（Adapters, Eloquent Repositories）

## 2. 主要コンポーネント
### 2.1. Controllers
- `DiscordInteractionController`: Discordからのスラッシュコマンド（`/discuss`, `/intervene`）の受信を担当。
- `DiscordMessageController`: Discordからのメッセージ転送（メンションによる介入）の受信を担当。

### 2.2. UseCases
- `StartDebateUseCase`: セッションの初期化、チャンネル作成、最初のAI発言のトリガーを担当。
- `ProcessDebateTurnUseCase`: 指定されたAIへの問い合わせ、回答のクレンジング、Discordへの投稿、次の発言者の選定を担当。

### 2.3. Adapters
- `DifyApiAdapter`: Dify APIとの通信、思考プロセス（`<think>`タグ等）の除去、レスポンスの正規化を担当。
- `DiscordApiAdapter`: Discord APIを用いたメッセージ投稿、チャンネル作成、インタラクションレスポンスの更新を担当。

## 3. 処理詳細
### 3.1. 発言者の決定アルゴリズム
1. 司会AI（Gemini）から開始。
2. 司会AI以外の参加AI（Gemma, Phi, Llama, GPT-OSS-Q2）を順番にローテーション。
3. AIの回答内にメンション（例: `@Gemma`）が含まれる場合、それを優先して次の発言者とする。
4. 最大ターン数に達した場合、または司会AIが結論を出す判定を行った場合に終了。

### 3.2. AI回答のクレンジング
Dify経由のAI、特に推論モデル（DeepSeek等）が思考プロセスを出力する場合があるため、以下の処理を行います。
- `<think>...</think>` タグの除去。
- `(think)` マーカーの除去。
- `[ENDTHINKFLAG]` 以前のコンテンツの削除。
- 改行コードの正規化。

## 4. 非同期処理
AIの応答生成には時間がかかるため、LaravelのQueueを利用して非同期に処理します。
- `StartDebateJob`: ディベート開始処理のバックグラウンド実行。
- `ProcessDebateTurn`: 各ターンの生成処理のバックグラウンド実行（ターン間に10秒のディレイを挿入）。

---
このドキュメントは JetBrains AI (Junie) によって作成されました。
