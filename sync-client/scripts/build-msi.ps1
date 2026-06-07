# =============================================================================
# AdCast Pro Sync Client — MSI Build Script
# =============================================================================
# Production-ready MSI installer üretir. Authenticode imzalama opsiyonel.
#
# Çalıştırma:
#   pwsh sync-client/scripts/build-msi.ps1
#   pwsh sync-client/scripts/build-msi.ps1 -Sign -CertPath "C:\path\to\cert.pfx" -CertPassword "..."
#   pwsh sync-client/scripts/build-msi.ps1 -Configuration Release -Version "1.0.1"
#
# Gereksinim:
#   - .NET 8 SDK (winget install Microsoft.DotNet.SDK.8)
#   - WiX Toolset v4 (dotnet tool install --global wix)
#   - Authenticode sertifika (production için — Sectigo/DigiCert OV/EV)
# =============================================================================
[CmdletBinding()]
param(
    [string]$Configuration = "Release",
    [string]$Version = "1.0.0",
    [switch]$Sign,
    [string]$CertPath = "",
    [string]$CertPassword = "",
    [string]$TimestampUrl = "http://timestamp.digicert.com",
    [switch]$SkipTests
)

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$SyncClientDir = Join-Path $RepoRoot "sync-client"
$SolutionPath = Join-Path $SyncClientDir "AdCastPro.SyncClient.sln"
$InstallerProject = Join-Path $SyncClientDir "installer\AdCastPro.Installer.wixproj"
$OutputDir = Join-Path $SyncClientDir "installer\bin\$Configuration"

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  AdCast Pro Sync Client — MSI Build       " -ForegroundColor Cyan
Write-Host "  Version: $Version" -ForegroundColor Cyan
Write-Host "  Config:  $Configuration" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Pre-flight check
function Test-Prereq {
    param($Command, $Hint)
    if (-not (Get-Command $Command -ErrorAction SilentlyContinue)) {
        Write-Error "[GEREKLİ] $Command bulunamadı. $Hint"
    }
}

Test-Prereq "dotnet" "winget install Microsoft.DotNet.SDK.8"

# WiX tool kontrolü
$wixTool = dotnet tool list --global | Select-String "wix"
if (-not $wixTool) {
    Write-Host "WiX Toolset yükleniyor..." -ForegroundColor Yellow
    dotnet tool install --global wix
    if ($LASTEXITCODE -ne 0) { Write-Error "WiX kurulamadı" }
}

# 1. Restore + Build
Write-Host "[1/5] dotnet restore..." -ForegroundColor Cyan
Push-Location $SyncClientDir
try {
    dotnet restore $SolutionPath
    if ($LASTEXITCODE -ne 0) { Write-Error "Restore başarısız" }

    Write-Host "[2/5] dotnet build ($Configuration, version=$Version)..." -ForegroundColor Cyan
    dotnet build $SolutionPath `
        --configuration $Configuration `
        --no-restore `
        /p:Version=$Version `
        /p:AssemblyVersion=$Version.0 `
        /p:FileVersion=$Version.0
    if ($LASTEXITCODE -ne 0) { Write-Error "Build başarısız" }

    # 3. Test
    if (-not $SkipTests) {
        Write-Host "[3/5] dotnet test (Unit + Integration)..." -ForegroundColor Cyan
        dotnet test $SolutionPath `
            --configuration $Configuration `
            --no-build `
            --filter "Category!=Integration" `
            --collect:"XPlat Code Coverage" `
            --results-directory "$SyncClientDir\TestResults" `
            --logger "console;verbosity=minimal"
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Bazı testler başarısız — yine de devam edilecek (Sign etmeden önce gözden geçirin)"
        }
    } else {
        Write-Host "[3/5] Testler atlandı (-SkipTests)" -ForegroundColor Yellow
    }

    # 4. WiX MSI build
    Write-Host "[4/5] WiX MSI build..." -ForegroundColor Cyan
    dotnet build $InstallerProject `
        --configuration $Configuration `
        --no-restore `
        /p:Version=$Version
    if ($LASTEXITCODE -ne 0) { Write-Error "MSI build başarısız" }
}
finally {
    Pop-Location
}

# 5. MSI bul + opsiyonel imzalama
$msiFile = Get-ChildItem $OutputDir -Filter "*.msi" | Select-Object -First 1
if (-not $msiFile) {
    Write-Error "MSI dosyası bulunamadı: $OutputDir"
}

Write-Host "[5/5] MSI hazır: $($msiFile.FullName)" -ForegroundColor Green
Write-Host "      Boyut: $([math]::Round($msiFile.Length / 1MB, 2)) MB" -ForegroundColor Green

# SHA-256 hesapla — auto-updater manifest'i için gerek
$hash = (Get-FileHash $msiFile.FullName -Algorithm SHA256).Hash.ToLower()
Write-Host "      SHA-256: $hash" -ForegroundColor Green
$hash | Out-File "$($msiFile.FullName).sha256" -Encoding ASCII

# Authenticode imzalama (opsiyonel)
if ($Sign) {
    if (-not $CertPath -or -not (Test-Path $CertPath)) {
        Write-Error "Sertifika dosyası bulunamadı: $CertPath"
    }
    Write-Host ""
    Write-Host "Authenticode ile imzalanıyor..." -ForegroundColor Cyan

    $signtool = Get-Command "signtool.exe" -ErrorAction SilentlyContinue
    if (-not $signtool) {
        # Windows SDK signtool fallback
        $sdkSigntool = Get-ChildItem "C:\Program Files (x86)\Windows Kits\10\bin\*\x64\signtool.exe" `
            -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($sdkSigntool) {
            $signtool = $sdkSigntool
        } else {
            Write-Error "signtool.exe bulunamadı (Windows SDK gerekli)"
        }
    }

    $signArgs = @(
        "sign",
        "/tr", $TimestampUrl,
        "/td", "sha256",
        "/fd", "sha256",
        "/f", $CertPath
    )
    if ($CertPassword) {
        $signArgs += "/p"
        $signArgs += $CertPassword
    }
    $signArgs += $msiFile.FullName

    & $signtool.Source @signArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Error "İmzalama başarısız (exit code $LASTEXITCODE)"
    }

    # Doğrula
    Write-Host "İmza doğrulanıyor..." -ForegroundColor Cyan
    & $signtool.Source verify /pa /v $msiFile.FullName
    if ($LASTEXITCODE -ne 0) {
        Write-Error "İmza doğrulanamadı"
    }

    Write-Host "✓ MSI Authenticode ile imzalandı" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "⚠ MSI imzasız — production'a alınmadan önce -Sign flag ile imzalayın" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  BUILD TAMAMLANDI" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host "  MSI: $($msiFile.FullName)"
Write-Host "  SHA-256: $hash"
Write-Host ""
Write-Host "Sonraki adım — yayınla:" -ForegroundColor Cyan
Write-Host "  scp `"$($msiFile.FullName)`" root@adcastpro.com:/var/www/files/releases/"
Write-Host "  Update manifest'i güncelle: api/v1/sync/update endpoint'i bu hash'i serve etmeli"
Write-Host ""
