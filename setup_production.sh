#!/bin/bash

# Production Setup Script for OPTIMUS PWA
# Run this on your production server after uploading files

echo "╔════════════════════════════════════════════════════════════╗"
echo "║         OPTIMUS PWA - Production Setup Script            ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if we're in the right directory
if [ ! -f "composer.json" ]; then
    echo -e "${RED}Error: composer.json not found. Please run this script from the project root.${NC}"
    exit 1
fi

echo "Step 1: Checking PHP version..."
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo -e "${GREEN}✓${NC} PHP Version: $PHP_VERSION"
echo ""

echo "Step 2: Installing/Updating Composer dependencies..."
if [ -f "composer.phar" ]; then
    php composer.phar install --no-dev --optimize-autoloader
else
    composer install --no-dev --optimize-autoloader
fi
echo ""

echo "Step 3: Checking .env.prod file..."
if [ ! -f ".env.prod" ]; then
    echo -e "${YELLOW}⚠${NC} .env.prod file not found!"
    echo ""
    echo "Please create .env.prod with your production configuration."
    echo "See PRODUCTION_FIX_DATABASE_URL.md for instructions."
    echo ""
    read -p "Do you want to create .env.prod now? (y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "Creating .env.prod from template..."
        cat > .env.prod << 'EOF'
APP_ENV=prod
APP_SECRET=CHANGE_ME_32_CHARACTERS_LONG
APP_DEBUG=0

# Update with your Hostinger database credentials
DATABASE_URL="mysql://u910121167_user:password@localhost:3306/u910121167_db?serverVersion=8.0&charset=utf8mb4"

MAILER_DSN=smtp://info@agricheck.ph:%40AgriCheck2025%40@smtp.hostinger.com:587
MAILER_FROM_EMAIL=info@agricheck.ph
MAILER_FROM_NAME="OPTIMUS"

FILE_ENCRYPTION_KEY=CHANGE_ME_32_CHARACTERS_LONG
STORAGE_ADAPTER=local
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

VAPID_PUBLIC_KEY=BC8q3CcmhbCa5yhAP7X-pTi0ek2dnCE_v37j8-WIvdNYvjOTl95xZG-htbS8l6oqIlkdIOoxudxUE5-WxHt65vw
VAPID_PRIVATE_KEY=u1pJDAubxC-CPtGKAZGQgB2unDiegEmF018ejLXc0f0
VAPID_SUBJECT=mailto:info@yourdomain.com
ENABLE_PUSH_NOTIFICATIONS=true

DEFAULT_URI=https://yourdomain.com
EOF
        echo -e "${GREEN}✓${NC} Created .env.prod template"
        echo -e "${YELLOW}⚠${NC} Please edit .env.prod and update:"
        echo "  - APP_SECRET (generate with: php -r \"echo bin2hex(random_bytes(16));\")"
        echo "  - DATABASE_URL (your Hostinger database credentials)"
        echo "  - FILE_ENCRYPTION_KEY (generate with: php -r \"echo bin2hex(random_bytes(16));\")"
        echo "  - VAPID_SUBJECT (your domain email)"
        echo "  - DEFAULT_URI (your domain URL)"
        echo ""
        exit 1
    else
        echo "Exiting. Please create .env.prod manually."
        exit 1
    fi
else
    echo -e "${GREEN}✓${NC} .env.prod file found"
fi
echo ""

echo "Step 4: Setting file permissions..."
chmod 600 .env.prod
chmod -R 775 var/
chmod -R 775 public/uploads/
echo -e "${GREEN}✓${NC} Permissions set"
echo ""

echo "Step 5: Clearing cache..."
php bin/console cache:clear --env=prod --no-debug
echo -e "${GREEN}✓${NC} Cache cleared"
echo ""

echo "Step 6: Warming up cache..."
php bin/console cache:warmup --env=prod --no-debug
if [ $? -ne 0 ]; then
    echo -e "${RED}✗${NC} Cache warmup failed!"
    echo "Please check your .env.prod configuration, especially DATABASE_URL"
    exit 1
fi
echo -e "${GREEN}✓${NC} Cache warmed up"
echo ""

echo "Step 7: Running database migrations..."
read -p "Run database migrations? (y/n) " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php bin/console doctrine:migrations:migrate --env=prod --no-interaction
    echo -e "${GREEN}✓${NC} Migrations completed"
else
    echo -e "${YELLOW}⚠${NC} Skipped migrations"
fi
echo ""

echo "Step 8: Checking system status..."
php bin/console about --env=prod
echo ""

echo "╔════════════════════════════════════════════════════════════╗"
echo "║                  Setup Complete!                          ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "Next steps:"
echo "1. Test your application in a browser"
echo "2. Check error logs if something doesn't work: var/log/prod.log"
echo "3. Test login with your user credentials"
echo "4. Test PWA installation on mobile device"
echo ""
echo "Useful commands:"
echo "  - Clear cache: php bin/console cache:clear --env=prod"
echo "  - View logs: tail -f var/log/prod.log"
echo "  - Check status: php bin/console about --env=prod"
echo ""
