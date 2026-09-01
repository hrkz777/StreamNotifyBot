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

## 外部API設定

YouTubeアカウントの登録には、YouTube Data API v3を有効化したAPIキーが必要です。APIキーはリポジトリへ記録せず、ローカルでは`.env.local`、本番ではサーバーの環境変数へ設定してください。

```dotenv
YOUTUBE_API_KEY=your-api-key
```

APIキーにはYouTube Data API v3だけを許可するAPI制限を設定してください。アプリケーションはキーがURLや通常のログへ残らないよう、`X-Goog-Api-Key`ヘッダーで送信します。

## ドキュメント

- [要件定義書](Documents/要件定義書.md)
- [基本設計書](Documents/基本設計書.md)
- [データ設計書](Documents/データ設計書.md)
- [初期構想メモ](やりたいこと.md)

## 開発補助ツール

- [GitHub Issue Bot テンプレートの配置手順](Tools/GitHubApps/README.md)

本番への配置方法と Cron の設定手順は、関連機能の実装に合わせて追記します。
