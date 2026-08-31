# GitHub Issue Bot テンプレート

## 目的

`Invoke-StreamNotifyBotIssueBot.ps1.template` は、GitHub App名義で `gh` のIssueおよびラベル操作を行う、StreamNotifyBot専用PowerShellスクリプトのテンプレートです。

テンプレートにはGitHub Appの秘密鍵、JWT、インストールトークンを含めません。秘密鍵はリポジトリ外に保存してください。

## 配置先

設定済みスクリプトは、実行する利用者のユーザープロファイルを基準とする次の場所へ配置します。

```text
%USERPROFILE%\.codex\github-apps\Invoke-StreamNotifyBotIssueBot.ps1
```

PowerShellでは、配置先を次のように取得できます。

```powershell
$issueBotPath = Join-Path $env:USERPROFILE ".codex\github-apps\Invoke-StreamNotifyBotIssueBot.ps1"
```

## 配置手順

1. `Invoke-StreamNotifyBotIssueBot.ps1.template` を上記の配置先へコピーし、ファイル名を `Invoke-StreamNotifyBotIssueBot.ps1` にする
2. コピー先のファイルだけを編集し、次のプレースホルダーを実際の値へ置き換える
3. GitHub Appの秘密鍵をリポジトリ外へ配置する
4. `status` コマンドで、GitHub Appのインストール先と権限を確認する

| プレースホルダー | 設定内容 |
| --- | --- |
| `<GITHUB_APP_ID>` | GitHub AppのApp ID。秘密鍵やClient Secretではない |
| `<GITHUB_ACCOUNT_LOGIN>` | GitHub AppをインストールしたGitHubアカウントまたはOrganization |
| `<GITHUB_REPOSITORY_NAME>` | 既定の対象リポジトリ名。Owner名を含めない |
| `<GITHUB_APP_PRIVATE_KEY_PATH>` | リポジトリ外に保存したPEM秘密鍵の絶対パス |
| `<GITHUB_CLI_PATH>` | `gh.exe` の絶対パス |

プレースホルダーが残っている場合、スクリプトはGitHubへ接続する前に停止します。

## 設定例

以下は形式を示す例であり、実際の秘密情報ではありません。

```powershell
$appId = "123456"
$accountLogin = "example-owner"
$repositoryName = "example-repository"
$keyPath = Join-Path $env:LOCALAPPDATA "GitHubApps\example-issue-bot\private-key.pem"
$ghPath = "C:\Program Files\GitHub CLI\gh.exe"
```

秘密鍵の内容をスクリプトへ直接貼り付けないでください。

## 確認方法

配置と設定が完了した後、次のコマンドで接続先と権限を確認します。

```powershell
$issueBotPath = Join-Path $env:USERPROFILE ".codex\github-apps\Invoke-StreamNotifyBotIssueBot.ps1"
& $issueBotPath status
```

このコマンドは、Actor、アカウント、対象リポジトリ、Issue権限、Pull Request権限を表示します。秘密鍵、JWT、インストールトークンは表示しません。

## 操作範囲

- `issue`: `gh issue` へ引数を転送する
- `label`: `gh label` へ引数を転送する
- `status`: GitHub Appの接続先と権限を表示する
- `pr`: `checks`、`diff`、`list`、`status`、`view` の読み取り操作だけを許可する

`--repo owner/repository` または `-R owner/repository` で対象リポジトリを指定できます。Ownerは設定した `<GITHUB_ACCOUNT_LOGIN>` と一致する必要があります。

## セキュリティ上の注意

- 設定済みスクリプトと秘密鍵をリポジトリへ追加しない
- 秘密鍵、JWT、インストールトークンをログや回答へ出力しない
- GitHub Appには必要最小限のリポジトリと権限だけを付与する
- Pull Requestの書き込み操作とGit操作にはこのスクリプトを使用しない
- Issue操作は、ユーザーから明示的に依頼された場合だけ実行する
