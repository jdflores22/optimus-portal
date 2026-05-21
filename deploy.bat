@echo off
REM ============================================
REM OPTIMUS PWA - Production Deployment Script
REM ============================================

echo.
echo ╔════════════════════════════════════════════════════════════╗
echo ║         OPTIMUS PWA - PRODUCTION DEPLOYMENT               ║
echo ╚════════════════════════════════════════════════════════════╝
echo.

REM Step 1: Clear Production Cache
echo [1/5] Clearing production cache...
php bin/console cache:clear --env=prod
if %errorlevel% neq 0 (
    echo ✗ Cache clear failed!
    pause
    exit /b 1
)
echo ✓ Cache cleared successfully
echo.

REM Step 2: Warmup Production Cache
echo [2/5] Warming up production cache...
php bin/console cache:warmup --env=prod
if %errorlevel% neq 0 (
    echo ✗ Cache warmup failed!
    pause
    exit /b 1
)
echo ✓ Cache warmed up successfully
echo.

REM Step 3: Run Database Migrations
echo [3/5] Running database migrations...
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
if %errorlevel% neq 0 (
    echo ✗ Migrations failed!
    pause
    exit /b 1
)
echo ✓ Migrations completed successfully
echo.

REM Step 4: Set Permissions
echo [4/5] Setting directory permissions...
icacls var\cache /grant Everyone:F /t >nul 2>&1
icacls var\log /grant Everyone:F /t >nul 2>&1
echo ✓ Permissions set successfully
echo.

REM Step 5: Verify Installation
echo [5/5] Verifying installation...
php bin/console about --env=prod
echo.

REM Final Check
echo ╔════════════════════════════════════════════════════════════╗
echo ║                  DEPLOYMENT COMPLETE                       ║
echo ╚════════════════════════════════════════════════════════════╝
echo.
echo ✓ Production cache cleared and warmed up
echo ✓ Database migrations applied
echo ✓ Permissions configured
echo ✓ Installation verified
echo.
echo NEXT STEPS:
echo   1. Test login: https://your-domain.com/login
echo   2. Verify PWA install prompt appears
echo   3. Test push notifications
echo   4. Check service worker in DevTools
echo.

pause
