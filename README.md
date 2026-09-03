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
## ドキュメント

- [要件定義書](Documents/要件定義書.md)
- [基本設計書](Documents/基本設計書.md)
- [データ設計書](Documents/データ設計書.md)
- [初期構想メモ](やりたいこと.md)

## 開発補助ツール

- [GitHub Issue Bot テンプレートの配置手順](Tools/GitHubApps/README.md)

本番への配置方法と Cron の設定手順は、関連機能の実装に合わせて追記します。
