# StreamNotifyBot プロジェクト構成

## エントリポイント

| パス | 用途 |
| --- | --- |
| `public/index.php` | HTTPリクエストのフロントコントローラー |
| `bin/console` | Cron、運用、保守用のSymfony Console |
| `Dockerfile` | PHP 8.5.9とApacheによる本番相当イメージ |
| `compose.yaml` | アプリケーションとMariaDB 10.5.29のローカル構成 |

## 主要ディレクトリ

| パス | 責務 |
| --- | --- |
| `src/Presentation/Http` | 公開HTTPエンドポイント |
| `src/Presentation/Admin` | 認証が必要な管理画面 |
| `src/Presentation/Webhook` | 配信プラットフォームのWebhook受信 |
| `src/Presentation/Console` | Cronと運用コマンド |
| `src/Application` | ユースケースと処理の調整 |
| `src/Domain` | 業務モデルと外部実装に依存しない境界 |
| `src/Infrastructure` | Doctrine DBAL、外部API、Discordなどの実装 |
| `config` | Symfonyと依存Bundleの設定 |
| `migrations` | Doctrine Migrationsのマイグレーション |
| `templates` | Twigテンプレート |
| `assets` | AssetMapperが管理するCSSとJavaScript |
| `tests/Unit` | 外部I/Oを使用しない単体テスト |
| `tests/Integration` | MariaDBなど境界との結合テスト |
| `tests/Functional` | Symfony Kernelを起動する機能テスト |

## 依存方向

```text
Presentation -> Application -> Domain
Infrastructure -------------> Domain
```

DomainはSymfonyとDoctrineへ依存しません。PresentationからInfrastructureを直接呼ばず、ApplicationとDomainの境界を介します。

## 検証コマンド

```text
composer lint
composer analyse
composer test
composer check
php bin/console app:database:check
```

`vendor/`、`var/`、`public/assets/`は生成物であり直接編集しません。
