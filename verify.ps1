param()

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$rootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$frontendDir = Join-Path $rootDir 'frontend'
$composeFile = Join-Path $rootDir 'docker-compose.prod.yml'

function Run-Step {
  param([string]$Name, [scriptblock]$Action)
  Write-Host ""
  Write-Host "[$(Get-Date -Format HH:mm:ss)] $Name"
  & $Action
  Write-Host "[PASS] $Name"
}

function Assert-LastExitCode {
  param([string]$Message)
  if ($LASTEXITCODE -ne 0) { throw "$Message (exit code: $LASTEXITCODE)" }
}

function Wait-HttpOk {
  param([string]$Uri, [int]$TimeoutSeconds = 180)
  foreach ($attempt in 1..$TimeoutSeconds) {
    try {
      $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 3
      if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) { return }
    } catch {}
    Start-Sleep -Seconds 1
  }
  throw "HTTP endpoint erisilemedi: $Uri"
}

Run-Step 'Onkosullar kontrol ediliyor' {
  foreach ($tool in 'php', 'docker', 'npm.cmd') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "$tool PATH icinde bulunamadi." }
  }
  docker version | Out-Null
  Assert-LastExitCode 'Docker calismiyor'
}

Run-Step 'Frontend lint calistiriliyor' {
  Push-Location $frontendDir
  try {
    npm.cmd run lint
    Assert-LastExitCode 'Frontend lint basarisiz'
  } finally { Pop-Location }
}

Run-Step 'Frontend bagimlilik guvenligi denetleniyor' {
  Push-Location $frontendDir
  try {
    npm.cmd audit --audit-level=moderate
    Assert-LastExitCode 'Frontend audit basarisiz'
  } finally { Pop-Location }
}

Run-Step 'Frontend build calistiriliyor' {
  Push-Location $frontendDir
  try {
    npm.cmd run build
    Assert-LastExitCode 'Frontend build basarisiz'
  } finally { Pop-Location }
}

Run-Step 'PHP syntax denetimi calistiriliyor' {
  Get-ChildItem (Join-Path $rootDir 'backend') -Recurse -Filter '*.php' | ForEach-Object {
    php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax hatasi: $($_.FullName)" }
  }
}

Run-Step 'Docker stack kuruluyor' {
  docker compose -f $composeFile up --build -d postgres minio minio-init php-fpm worker nginx liquidsoap
  Assert-LastExitCode 'Docker stack kurulumu basarisiz'
}

Run-Step 'Veritabani migrasyonlari calistiriliyor' {
  docker compose -f $composeFile run --rm migrate
  Assert-LastExitCode 'Veritabani migrasyonlari basarisiz'
}

Run-Step 'HTTP endpointleri bekleniyor' {
  Wait-HttpOk 'http://localhost:8080/healthz'
  Wait-HttpOk 'http://localhost:8080/'
}

Run-Step 'Backend smoke suite calistiriliyor' {
  docker compose -f $composeFile exec -T php-fpm php bin/test-suite.php
  Assert-LastExitCode 'Backend smoke suite basarisiz'
}

Run-Step 'Container durumlari dogrulaniyor' {
  $running = @(docker compose -f $composeFile ps --status running --services)
  Assert-LastExitCode 'Container durumlari okunamadi'
  foreach ($service in 'postgres', 'minio', 'php-fpm', 'worker', 'nginx', 'liquidsoap') {
    if ($running -notcontains $service) { throw "Container calismiyor: $service" }
  }
}

Run-Step 'HTTP endpointleri kontrol ediliyor' {
  Wait-HttpOk 'http://localhost:8080/healthz'
  Wait-HttpOk 'http://localhost:8080/'
}

Write-Host ""
Write-Host '[SUCCESS] Tum dogrulamalar basarili.'
