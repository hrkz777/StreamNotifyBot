# StreamNotifyBot プロジェクト構成

## エントリポイント

| パス | 用途 |
| --- | --- |
| `public/index.php` | HTTPリクエストのフロントコントローラー |
| `bin/console` | Cron、運用、保守用のSymfony Console |
| `Dockerfile` | PHP 8.5.9とApacheによる本番相当イメージ |
| `compose.yaml` | アプリケーションとMariaDB 10.5.29のローカル構成 |
| `.github/workflows/ci.yaml` | Pull RequestでDocker環境の品質確認を行うGitHub Actions |

## 主要ディレクトリ

| パス | 責務 |
| --- | --- |
| `src/Presentation/Http` | 公開HTTPエンドポイント |
| `src/Presentation/Admin` | 認証が必要な管理画面 |
| `src/Presentation/Webhook` | 配信プラットフォームのWebhook受信 |
| `src/Presentation/Console` | Cronと運用コマンド |
| `src/Application` | ユースケースと処理の調整 |
| `src/Application/Catalog` | 配信者登録などカタログ操作のユースケース |
| `src/Domain` | 業務モデルと外部実装に依存しない境界 |
| `src/Infrastructure` | Doctrine DBAL、外部API、Discordなどの実装 |
| `src/Domain/Catalog` | 所属区分、配信者、対応プラットフォームのモデルとRepository境界 |
| `src/Infrastructure/Persistence` | Doctrine DBALによるRepository実装 |
| `src/Infrastructure/Platform` | Platform Adapterの選択と外部アカウント解決 |
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
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose config --quiet
docker compose up --build --detach --wait
```

Pull Requestでは `.github/workflows/ci.yaml` がDocker環境を起動し、上記の品質確認、依存関係監査、HTTPヘルスチェック、本番イメージの内容確認を自動実行します。

`vendor/`、`var/`、`public/assets/`は生成物であり直接編集しません。
