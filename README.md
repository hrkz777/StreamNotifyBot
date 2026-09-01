# StreamNotifyBot

配信プラットフォームの Webhook と API ポーリングから配信情報を取得し、Discord Webhook へ通知する PHP アプリケーションです。

## 動作環境

- PHP 8.5.9
- MariaDB 10.5.29 以降（互換性下限）
- 本番環境では保守期間内の MariaDB 10.11 LTS 以降を推奨。MariaDB 10.5 系は保守終了済み
- レンタルサーバーの Cron から起動する PHP CLI コマンド

## ドキュメント

- [要件定義書](Documents/要件定義書.md)
- [基本設計書](Documents/基本設計書.md)
- [データ設計書](Documents/データ設計書.md)
- [初期構想メモ](やりたいこと.md)

## 開発補助ツール

- [GitHub Issue Bot テンプレートの配置手順](Tools/GitHubApps/README.md)

フレームワーク、セットアップ方法、運用手順は、設計と実装の確定後に追記します。
