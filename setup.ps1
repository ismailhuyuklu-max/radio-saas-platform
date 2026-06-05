param()

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$rootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$frontendDir = Join-Path $rootDir 'frontend'
$composeFile = Join-Path $rootDir 'docker-compose.prod.yml'

function Write-Stage($message) {
  Write-Host ""
  Write-Host "[$(Get-Date -Format HH:mm:ss)] $message"
}

function Assert-LastExitCode($message) {
  if ($LASTEXITCODE -ne 0) { throw "$message (exit code: $LASTEXITCODE)" }
}

function Wait-ContainerHealth($containerName, $timeoutSeconds = 120) {
  $elapsed = 0
  while ($true) {
    $status = docker inspect -f "{{.State.Health.Status}}" $containerName 2>$null
    if ($status -eq 'healthy') { return }
    if ($status -eq 'unhealthy') { throw "Container $containerName became unhealthy." }
    if ($elapsed -ge $timeoutSeconds) { throw "Timed out waiting for $containerName to become healthy." }
    Start-Sleep -Seconds 2
    $elapsed += 2
  }
}

foreach ($tool in 'npm.cmd', 'docker') {
  if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "$tool is required but was not found in PATH." }
}

Write-Stage 'Cleaning previous Docker resources'
docker compose -f $composeFile down --volumes --remove-orphans
Assert-LastExitCode 'Could not clean previous Docker resources'

Write-Stage 'Installing frontend dependencies and building bundle'
Push-Location $frontendDir
try {
  if (Test-Path 'package-lock.json') { npm.cmd ci } else { npm.cmd install }
  Assert-LastExitCode 'Frontend dependency installation failed'
  npm.cmd run build
  Assert-LastExitCode 'Frontend build failed'
} finally {
  Pop-Location
}

Write-Stage 'Starting infrastructure containers'
docker compose -f $composeFile up --build -d postgres minio minio-init php-fpm worker nginx liquidsoap
Assert-LastExitCode 'Could not start Docker stack'

Write-Stage 'Waiting for PostgreSQL and MinIO'
Wait-ContainerHealth 'radio-postgres'
Wait-ContainerHealth 'radio-minio'

Write-Stage 'Running database migrations'
docker compose -f $composeFile run --rm migrate
Assert-LastExitCode 'Database migrations failed'

Write-Stage 'Waiting for frontend and gateway'
$gatewayReady = $false
for ($i = 0; $i -lt 30; $i++) {
  try {
    Invoke-WebRequest -Uri 'http://localhost:8080/' -UseBasicParsing -TimeoutSec 2 | Out-Null
    Invoke-WebRequest -Uri 'http://localhost:8080/healthz' -UseBasicParsing -TimeoutSec 2 | Out-Null
    $gatewayReady = $true
    break
  } catch {
    Start-Sleep -Seconds 2
  }
}
if (-not $gatewayReady) { throw 'Frontend and gateway did not become ready.' }

Write-Stage 'Running backend integration test suite'
docker compose -f $composeFile exec -T php-fpm php bin/test-suite.php
Assert-LastExitCode 'Backend integration test suite failed'

Write-Host ''
Write-Host 'Local deployment completed'
Write-Host 'Frontend and API Gateway: http://localhost:8080'
Write-Host 'MinIO API: http://localhost:9000'
Write-Host 'MinIO Console: http://localhost:9001'
Write-Host '[SUCCESS] Local stack is ready - Login: admin / 123456'
