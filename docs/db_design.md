---
このドキュメントは JetBrains AI (Junie) によって作成されました。

## 1. データベース概要
ディベートのセッション情報を管理するためにMySQLを使用します。主に非同期処理の状態管理とDiscordチャンネルとの紐付けに使用されます。

## 2. テーブル定義

### 2.1. `debate_sessions`
ディベートの各セッションを管理するテーブル。

| カラム名 | 型 | NULL | デフォルト | 説明 |
| :--- | :--- | :---: | :--- | :--- |
| `id` | bigint(20) unsigned | NO | | 主キー |
| `topic` | varchar(255) | NO | | ディベートの議題 |
| `discord_channel_id` | varchar(255) | YES | NULL | 自動作成されたDiscordチャンネルのID |
| `initial_ai` | varchar(255) | YES | NULL | 最初に発言するAI（現在はGemini固定） |
| `current_turn` | int(11) | NO | 0 | 現在の経過ターン数 |
| `max_turns` | int(11) | NO | 10 | 最大ターン数 |
| `dify_conversation_id` | varchar(255) | YES | NULL | Dify APIの会話ID（文脈維持用） |
| `status` | varchar(255) | NO | 'running' | セッションの状態 (`running`, `completed`, `failed`) |
| `created_at` | timestamp | YES | NULL | 作成日時 |
| `updated_at` | timestamp | YES | NULL | 更新日時 |

## 3. インデックス
- `id`: PRIMARY KEY
- `discord_channel_id`: セッション特定のための検索インデックス（推奨）

## 4. 状態遷移
1. **running**: ディベート進行中。
2. **completed**: 最大ターン到達または司会AIの判断により正常終了。
3. **failed**: APIエラー等により継続不能となった状態。

※ `status` が `completed` であっても、人間による介入があった場合は `running` に戻り議論が再開されることがあります。
