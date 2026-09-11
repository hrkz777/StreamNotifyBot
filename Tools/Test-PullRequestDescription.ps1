param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string] $Path
)

$body = Get-Content -LiteralPath $Path -Raw
$errors = [System.Collections.Generic.List[string]]::new()

for ($index = 0; $index -lt $body.Length; $index++) {
    $character = [int] $body[$index]

    if (($character -lt 0x20 -and $character -notin 0x09, 0x0A, 0x0D) -or $character -eq 0x7F) {
        $errors.Add(('制御文字 U+{0:X4} が位置 {1} に含まれています。' -f $character, $index))
    }
}

if ($body -match '\\(?:app|feature|fix|docs|chore|refactor)/?') {
    $errors.Add('コマンドまたはブランチ名の先頭に不要なバックスラッシュが含まれています。')
}

if ($errors.Count -gt 0) {
    $errors | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Output 'PR本文の安全性検査に成功しました。'
