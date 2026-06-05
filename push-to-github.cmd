@echo off
setlocal
chcp 65001 >nul
title Radio SaaS - GitHub'a yukle
set "GH=C:\Program Files\GitHub CLI\gh.exe"

echo ============================================================
echo   Radio SaaS Platform - GitHub'a yukleme
echo ============================================================
echo.

if not exist "%GH%" (
  echo [HATA] GitHub CLI bulunamadi: %GH%
  echo Lutfen once kurun:  winget install GitHub.cli
  pause
  exit /b 1
)

echo [1/3] GitHub oturumu kontrol ediliyor...
"%GH%" auth status >nul 2>&1
if errorlevel 1 (
  echo      Oturum yok. Tarayicida giris acilacak.
  echo      - GitHub.com  /  HTTPS  /  Yes  /  Login with a web browser
  echo      - Cikan kodu kopyalayip tarayicida Authorize'a basin.
  echo.
  "%GH%" auth login
  if errorlevel 1 (
    echo [HATA] Giris tamamlanamadi.
    pause
    exit /b 1
  )
)

echo.
echo [2/3] Depo olusturuluyor ve push ediliyor...
cd /d "%~dp0"
"%GH%" repo create radio-saas-platform --private --source=. --remote=origin --push
if errorlevel 1 (
  echo.
  echo Depo zaten olabilir. Mevcut origin'e push deneniyor...
  git push -u origin main
)

echo.
echo [3/3] Tamamlandi. Depo adresi:
"%GH%" repo view --json url --jq .url 2>nul
echo.
echo Pencereyi kapatabilirsiniz.
pause
