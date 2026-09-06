# StreamNotifyBot

配信プラットフォームの Webhook と API ポーリングから配信情報を取得し、Discord Webhook へ通知する PHP アプリケーションです。

## 動作環境

- PHP 8.5.9
- Symfony 7.4 LTS
- Doctrine DBAL 4.4 系
- MariaDB 10.5 系（開発・自動テストでは 10.5.29）
- Apache 2.4 系
- レンタルサーバーの Cron から起動する PHP CLI コマンド

本番サーバー固有のハードウェア構成やサービス名には依存しません。共有レンタルサーバーでも動作できるよう、Cron の実行時間、同時実行数、ポーリング間隔、外部 API の利用量を設定可能にします。

## ローカル開発

ローカル開発では PHP、Composer、MariaDB を開発マシンへ直接インストールせず、Docker Compose を使用します。

```powershell
docker compose up --build -d
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec app composer check
docker compose exec app php bin/console app:database:check
```

開発用DBと自動テスト用DBは別のMariaDBコンテナとDockerボリュームへ分離されています。初回起動後とマイグレーション追加後は、両方へマイグレーションを適用してください。

`compose.yaml` の認証情報はローカル開発専用です。本番では使用せず、実行環境から秘密情報を注入してください。

### 管理画面UIプレビュー

Docker Composeの起動後、[http://127.0.0.1:8080/admin](http://127.0.0.1:8080/admin) で管理画面を確認できます。

現在は画面構成とブラウザー上の操作を確認するためのUIモックです。表示データ、フォームの保存、外部APIとの接続はモックであり、認証もまだ接続されていません。本番環境へ公開しないでください。

配信者、通知設定、運用設定、プラットフォームの各画面では、入力したモックデータをブラウザーのLocal Storageへ保存します。入力内容はサーバーやデータベースへ送信されず、配信者は「モックデータを消去」、通知設定は設定ごとの「削除」から消去できます。運用設定では確定済みの絶対上下限と項目間制約を画面上でも検証します。プラットフォーム画面の状態とAPI使用率は表示確認用であり、実際の外部接続状態や使用量ではありません。

通知設定では、動画投稿、配信開始前、配信中、配信終了ごとに複数のWebhook URL入力欄を追加・削除できます。空欄の通知種別は送信しない設定として扱います。テスト送信は画面上のシミュレーションであり、Discordへの通信は行いません。Webhook URLを含む入力値は暗号化されないため、実際の秘密情報は入力しないでください。

## 外部API設定

YouTubeアカウントの登録には、YouTube Data API v3を有効化したAPIキーが必要です。APIキーはリポジトリへ記録せず、ローカルでは`.env.local`、本番ではサーバーの環境変数へ設定してください。

```dotenv
YOUTUBE_API_KEY=your-api-key
YOUTUBE_WEBSUB_SECRET=generate-a-random-secret-of-at-least-32-characters
DEFAULT_URI=https://your-public-host.example
```

APIキーにはYouTube Data API v3だけを許可するAPI制限を設定してください。アプリケーションはキーがURLや通常のログへ残らないよう、`X-Goog-Api-Key`ヘッダーで送信します。

`DEFAULT_URI`には外部のGoogle HubからHTTPSで到達できる公開URLを指定します。WebSubのコールバックURLは購読IDごとに生成されます。`YOUTUBE_WEBSUB_SECRET`は32文字以上199文字以下の空白を含まないASCII乱数とし、通知本文の署名検証に使用します。

Twitchアカウントの登録には、Twitch Developer Consoleで登録したアプリケーションのClient IDとClient Secretが必要です。ローカルでは`.env.local`、本番ではサーバーの環境変数へ設定してください。

```dotenv
TWITCH_CLIENT_ID=your-client-id
TWITCH_CLIENT_SECRET=your-client-secret
```

Client Secretと取得したApp Access TokenはURLへ含めず、Symfony開発プロファイラの収集対象外であるHTTP transportから送信します。

TwitCastingアカウントの登録には、TwitCasting Developer APIで登録したアプリケーションのClient IDとClient Secretが必要です。

```dotenv
TWITCASTING_CLIENT_ID=your-client-id
TWITCASTING_CLIENT_SECRET=your-client-secret
```

資格情報はアプリケーション単位のBasic認証に使用し、URLやSymfony開発プロファイラへ記録しません。

## 秘密情報の暗号化鍵

保存する秘密情報には、SodiumのXChaCha20-Poly1305認証付き暗号を使用します。暗号化鍵そのものを環境変数へ設定せず、リポジトリ、Web公開ディレクトリ、Dockerイメージの外に配置した鍵リングファイルの絶対パスを`SECRET_KEY_RING_FILE`へ指定してください。

鍵リングは次のJSON形式です。`value`には32バイトの暗号学的に安全な乱数をBase64でエンコードした値を指定します。以下のプレースホルダーは実際の鍵ではなく、そのままでは使用できません。

```json
{
    "current_key_id": "key-2026-01",
    "keys": [
        {
            "id": "key-2026-01",
            "value": "<BASE64_ENCODED_32_BYTE_KEY>"
        }
    ]
}
```

鍵IDには1～64文字のASCII英数字、ピリオド、アンダースコア、ハイフンだけを使用できます。新規暗号化には`current_key_id`の鍵を使用し、過去の鍵は既存データの復号が不要になるまで`keys`へ残します。鍵ファイルはPHP実行ユーザーだけが読み取れる権限にし、Dockerでは読み取り専用のSecretまたは外部マウントとして配置してください。
## ドキュメント

- [要件定義書](Documents/要件定義書.md)
- [基本設計書](Documents/基本設計書.md)
- [データ設計書](Documents/データ設計書.md)
- [初期構想メモ](やりたいこと.md)

## 開発補助ツール

- [GitHub Issue Bot テンプレートの配置手順](Tools/GitHubApps/README.md)

## 運用コマンド

### Webhook購読更新Cron

期限に到達したWebhook購読は、次のSymfony Consoleコマンドで更新します。

```shell
php bin/console app:webhook-subscriptions:renew --env=prod
```

外部Cronの実行間隔はサーバー側で設定してください。1回の処理件数、最大実行時間、再試行、バックオフ、リース時間、ジョブの有効状態は、データベースの`subscription_renewal`ジョブ方針を使用します。重複起動時は期限付きリースによって同じ行の並行処理を防止します。
