# build-deploy.ps1 — builds a hosting-ready package of the grading system.
# Run from the grading_system folder:  powershell -ExecutionPolicy Bypass -File build-deploy.ps1
# Output: deploy/grading_system/  +  deploy/grading-deploy.zip  (upload the ZIP, extract on the host)
#
# Why this exists: the working folder is ~3.1 GB / ~10k files, but ~3 GB of that
# is students/app (Flutter source + build outputs: APKs, intermediates) which a
# PHP host must never receive. Logs, backups, and dev-only files are also skipped.

$ErrorActionPreference = 'Stop'
$root  = $PSScriptRoot
$stage = Join-Path $root 'deploy\grading_system'
$zip   = Join-Path $root 'deploy\grading-deploy.zip'

if (Test-Path $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null
if (Test-Path -LiteralPath $zip) { Remove-Item -Force -LiteralPath $zip }

# Directories to skip (full paths so robocopy matches exactly)
$xd = @(
    (Join-Path $root 'deploy'),
    (Join-Path $root 'students\app'),          # 3 GB Flutter source + build/ (APKs, intermediates) — NOT for PHP hosting
    (Join-Path $root '.vscode'),
    (Join-Path $root 'horizon-react-1.0.0'),   # unused React template (5 MB), not referenced by any PHP file
    (Join-Path $root 'logs'),                  # 30 MB runtime logs — recreated on the host
    (Join-Path $root 'db\backups'),
    (Join-Path $root 'db\faculty_backups'),
    (Join-Path $root 'db\previous_database'),
    # Font-Awesome source/build extras — runtime only needs css/ + webfonts/
    (Join-Path $root 'Font-Awesome-master\less'),
    (Join-Path $root 'Font-Awesome-master\scss'),
    (Join-Path $root 'Font-Awesome-master\svgs'),
    (Join-Path $root 'Font-Awesome-master\sprites'),
    (Join-Path $root 'Font-Awesome-master\js'),
    (Join-Path $root 'Font-Awesome-master\js-packages'),
    (Join-Path $root 'Font-Awesome-master\metadata'),
    (Join-Path $root 'Font-Awesome-master\otfs')
)

# Files to skip
$xf = @(
    'composer.phar',     # dev tool, large
    '.rnd',              # openssl seed, dev-only
    '*.log',             # runtime logs
    '*.pre-migration*',  # local DB backup copies
    '*.cache.dill*'      # dart build cache (in case it sits outside build/)
)

& robocopy $root $stage /E /XD $xd /XF $xf /NFL /NDL /NJH /NJS | Out-Null
if ($LASTEXITCODE -gt 7) { throw "robocopy failed with exit code $LASTEXITCODE" }

$files = (Get-ChildItem -LiteralPath $stage -Recurse -File).Count
$mb = [math]::Round(((Get-ChildItem -LiteralPath $stage -Recurse -File | Measure-Object -Property Length -Sum).Sum) / 1MB, 1)
Write-Output "Staged: $files files, $mb MB"

Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -CompressionLevel Optimal
$zipMb = [math]::Round((Get-Item -LiteralPath $zip).Length / 1MB, 1)
Write-Output ("ZIP created: $zip ($zipMb MB). Upload this ONE file via cPanel File Manager, then Extract it on the host.")
