<#
.SYNOPSIS
    Copies the encrypted nuskJO backup archives from the server to this machine (off-site copy).

.DESCRIPTION
    Runs daily from Windows Task Scheduler (see docs/BACKUP.md). Steps:
      1. Fetch SHA256SUMS from the server's archive folder.
      2. Download every archive missing here, verify its SHA-256, then keep it.
      3. Tell the server it was pulled (php artisan backup:mark <snapshot> --pulled).
      4. Rotate locally with the same rules as the server (7 daily, 4 weekly, 6 monthly
         for scheduled snapshots; latest 5 per event reason, up to 90 days).
      5. Remind once a month to test decryption (-TestDecrypt).
    Archives stay encrypted with age; the private key never touches this disk except
    for the moment of a decryption test.

    Works with Windows PowerShell 5.1 and PowerShell 7. Uses the built-in OpenSSH
    client (ssh.exe, scp.exe) and tar.exe.

.EXAMPLE
    $env:NUSKJO_BACKUP_HOST = 'my-server-alias'   # an alias from ~/.ssh/config
    .\pull-offsite.ps1

.EXAMPLE
    .\pull-offsite.ps1 -TestDecrypt -Identity C:\temp\nuskjo-backup.key   # then delete the key file
#>
[CmdletBinding()]
param(
    [string]$SshHost = $env:NUSKJO_BACKUP_HOST,
    [string]$RemoteDir = 'backups/nuskjo/archive',
    [string]$Dest = (Join-Path $env:USERPROFILE 'Backups\nuskJO\archive'),
    [string]$Container = 'nuskjo-app',
    [string]$Project = 'nuskjo',
    [string]$Now = '',
    [switch]$DryRun,
    [switch]$RotateOnly,
    [switch]$TestDecrypt,
    [string]$Identity = '',
    [string]$AgeExe = 'age.exe'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $Dest -Parent
$LogFile = Join-Path $Root 'pull.log'
$StampFile = Join-Path $Root 'last-decrypt-test.txt'
$NamePattern = '^' + [regex]::Escape($Project) + '-(\d{8}T\d{6}Z)-(.+)\.tar\.gz\.age$'

function Write-Log([string]$Message) {
    $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'), $Message
    Write-Host $line
    if (-not $DryRun) { Add-Content -Path $LogFile -Value $line -Encoding UTF8 }
}

function Get-IsoWeekKey([datetime]$Date) {
    # ISO 8601 week (the Thursday of the same week decides the year), without .NET Core's ISOWeek
    $dow = (([int]$Date.DayOfWeek) + 6) % 7
    $thursday = $Date.Date.AddDays(3 - $dow)
    $week = [math]::Floor(($thursday.DayOfYear - 1) / 7) + 1
    return '{0}-W{1:D2}' -f $thursday.Year, [int]$week
}

function Get-Archives {
    Get-ChildItem -Path $Dest -File -Filter '*.tar.gz.age' | ForEach-Object {
        $m = [regex]::Match($_.Name, $NamePattern)
        if ($m.Success) {
            [pscustomobject]@{
                Name   = $_.Name
                Stamp  = $m.Groups[1].Value
                Reason = $m.Groups[2].Value
                Date   = [datetime]::ParseExact($m.Groups[1].Value, "yyyyMMdd'T'HHmmss'Z'", $null)
            }
        }
    } | Sort-Object Stamp -Descending
}

function Invoke-Rotation {
    $nowUtc = if ($Now) { [datetime]::Parse($Now).ToUniversalTime() } else { (Get-Date).ToUniversalTime() }
    $cutoff = $nowUtc.AddDays(-90)
    $all = @(Get-Archives)
    if ($all.Count -eq 0) { return }

    $keep = @{}
    $keep[$all[0].Name] = $true
    $days = @{}; $weeks = @{}; $months = @{}; $events = @{}

    foreach ($a in $all) {
        if ($a.Reason -like 'scheduled*') {
            $d = $a.Stamp.Substring(0, 8); $w = Get-IsoWeekKey $a.Date; $mo = $a.Stamp.Substring(0, 6)
            if (-not $days.ContainsKey($d)) { $days[$d] = 1; if ($days.Count -le 7) { $keep[$a.Name] = $true } }
            if (-not $weeks.ContainsKey($w)) { $weeks[$w] = 1; if ($weeks.Count -le 4) { $keep[$a.Name] = $true } }
            if (-not $months.ContainsKey($mo)) { $months[$mo] = 1; if ($months.Count -le 6) { $keep[$a.Name] = $true } }
        } else {
            $kind = 'other'
            foreach ($r in @('pre-migrate', 'pre-reset', 'pre-restore', 'manual', 'drill')) {
                if ($a.Reason -eq $r -or $a.Reason -like "$r-*") { $kind = $r; break }
            }
            $events[$kind] = 1 + [int]$events[$kind]
            if ($events[$kind] -le 5 -and $a.Date -ge $cutoff) { $keep[$a.Name] = $true }
        }
    }

    foreach ($a in $all) {
        if ($keep.ContainsKey($a.Name)) {
            Write-Output "KEEP $($a.Name)"
        } else {
            Write-Output "DELETE $($a.Name)"
            if (-not $DryRun) { Remove-Item -LiteralPath (Join-Path $Dest $a.Name) -Force }
        }
    }
}

New-Item -ItemType Directory -Path $Dest -Force | Out-Null

if ($TestDecrypt) {
    if (-not $Identity -or -not (Test-Path $Identity)) { throw 'Pass -Identity <key file> (delete it afterwards).' }
    $latest = @(Get-Archives | Where-Object { $_.Reason -like 'scheduled*' })[0]
    if (-not $latest) { $latest = @(Get-Archives)[0] }
    if (-not $latest) { throw 'No archive here yet.' }
    $listing = & cmd.exe /c "`"$AgeExe`" -d -i `"$Identity`" `"$(Join-Path $Dest $latest.Name)`" | tar.exe -tzf -"
    if ($LASTEXITCODE -ne 0 -or -not ($listing -match 'manifest\.json')) { throw "Decryption test FAILED for $($latest.Name)" }
    Set-Content -Path $StampFile -Value (Get-Date).ToUniversalTime().ToString('o') -Encoding ASCII
    Write-Log "decrypt test OK: $($latest.Name) ($(@($listing).Count) entries). Now delete the key file."
    return
}

if ($RotateOnly) { Invoke-Rotation; return }

if (-not $SshHost) { throw 'Set NUSKJO_BACKUP_HOST (an SSH alias) or pass -SshHost.' }

# 1) the server's list
$sumsFile = Join-Path $Root 'SHA256SUMS.remote'
& scp.exe -q -B "${SshHost}:$RemoteDir/SHA256SUMS" $sumsFile
if ($LASTEXITCODE -ne 0) { throw 'Could not fetch SHA256SUMS from the server.' }

$remote = @{}
foreach ($line in Get-Content $sumsFile) {
    $m = [regex]::Match($line, '^([0-9a-f]{64})\s+(\S+)$')
    if ($m.Success -and [regex]::IsMatch($m.Groups[2].Value, $NamePattern)) { $remote[$m.Groups[2].Value] = $m.Groups[1].Value }
}

# 2) + 3) download what is missing, verify, record
$pulled = 0
foreach ($name in ($remote.Keys | Sort-Object)) {
    $target = Join-Path $Dest $name
    if (Test-Path $target) {
        if ((Get-FileHash -Algorithm SHA256 $target).Hash.ToLower() -eq $remote[$name]) { continue }
        Write-Log "hash mismatch for local $name, downloading again"
    }
    if ($DryRun) { Write-Log "would pull $name"; continue }

    $part = "$target.part"
    & scp.exe -q -B "${SshHost}:$RemoteDir/$name" $part
    if ($LASTEXITCODE -ne 0) { throw "Download failed: $name" }
    $hash = (Get-FileHash -Algorithm SHA256 $part).Hash.ToLower()
    if ($hash -ne $remote[$name]) {
        Remove-Item -LiteralPath $part -Force
        throw "SHA-256 mismatch after download: $name"
    }
    Move-Item -LiteralPath $part -Destination $target -Force

    $snapshot = [regex]::Match($name, $NamePattern).Groups[1].Value + '-' + [regex]::Match($name, $NamePattern).Groups[2].Value
    & ssh.exe -o BatchMode=yes $SshHost "docker exec -u www-data $Container php artisan backup:mark $snapshot --pulled" | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Log "warning: could not mark $snapshot as pulled" }

    Write-Log "pulled $name"
    $pulled++
}

# 4) local rotation
Invoke-Rotation | Where-Object { $_ -like 'DELETE *' } | ForEach-Object { Write-Log "rotated out $($_.Substring(7))" }

# 5) monthly reminder to prove the key still decrypts
$stampAge = if (Test-Path $StampFile) { ((Get-Date).ToUniversalTime() - [datetime]::Parse((Get-Content $StampFile -Raw).Trim()).ToUniversalTime()).TotalDays } else { 999 }
if ($stampAge -gt 30) {
    Write-Warning 'Monthly check due: run pull-offsite.ps1 -TestDecrypt -Identity <key file from your password manager>, then delete the key file.'
}

Write-Log "done: pulled $pulled, $(@(Get-Archives).Count) archives here"
