# Optimus Portal

A comprehensive Shipping Line Management System built with Symfony 7.2 and modern web technologies.

## Overview

Optimus Portal is an enterprise-grade web application designed to streamline shipping line operations, container management, and electronic delivery order (EDO) processing. The system provides a complete workflow management solution for brokers, consignees, shipping administrators, and terminal teams.

## Features

### Core Functionality
- **Electronic Delivery Order (EDO) Management** - Generate, track, and manage EDOs with automated workflows
- **Manifest & NOA Processing** - Complete manifest workflow with Notice of Arrival (NOA) generation
- **Container Tracking** - Real-time container status monitoring and dwell time tracking
- **Payment Processing** - Dual currency support (USD/PHP) with automated billing and receipt generation
- **User Hierarchy Management** - Multi-level user organization with role-based access control

### User Roles
- **Broker** - Manifest management, EDO requests, payment submissions
- **Consignee** - Broker relationships, manifest access, payment tracking
- **Shipping Admin** - User management, broker/consignee oversight
- **Accounting** - Payment verification, billing management, financial reporting
- **Terminal Team** - Pre-advice requests, container allocation, dwell time monitoring
- **System Admin** - System configuration, terminal management, global settings
- **Evaluator** - Accreditation review and approval

### Advanced Features
- **Bulk Import** - CSV-based bulk import for NOAs and manifests
- **Dwell Time Monitoring** - Automated alerts for container dwell time thresholds
- **Audit Trail** - Comprehensive activity logging and audit reports
- **Notification System** - Email, in-app, and push notifications
- **PWA Support** - Progressive Web App with offline capabilities
- **Multi-Currency** - USD and PHP support with real-time exchange rates
- **Document Generation** - Automated PDF generation for EDOs, receipts, and reports

## Technology Stack

### Backend
- **Framework:** Symfony 7.2
- **PHP:** 8.3+
- **Database:** MySQL/MariaDB
- **ORM:** Doctrine
- **Authentication:** Symfony Security Component

### Frontend
- **UI Framework:** FlyonUI 2.4.1
- **CSS Framework:** Tailwind CSS 3.4
- **Icons:** Iconify (Tabler Icons)
- **JavaScript:** Vanilla JS with modern ES6+
- **Build Tools:** PostCSS, Tailwind CLI

### Additional Technologies
- **PDF Generation:** Dompdf
- **QR Codes:** QR Code generation for EDOs
- **Push Notifications:** Web Push API
- **Email:** Symfony Mailer
- **Caching:** Symfony Cache Component

## Requirements

- PHP 8.3 or higher
- Composer 2.x
- Node.js 18+ and npm
- MySQL 8.0+ or MariaDB 10.6+
- Apache/Nginx web server
- GMP PHP extension (for push notifications)

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/jdflores22/optimus-portal.git
cd optimus-portal
```

### 2. Install PHP Dependencies

```bash
composer install
```

### 3. Install Node Dependencies

```bash
npm install
```

### 4. Configure Environment

Copy the example environment file and configure your settings:

```bash
cp .env.example .env
```

Edit `.env` and configure:
- Database connection (`DATABASE_URL`)
- Mailer settings (`MAILER_DSN`)
- App secret (`APP_SECRET`)
- Other environment-specific settings

### 5. Database Setup

```bash
# Create database
php bin/console doctrine:database:create

# Run migrations
php bin/console doctrine:migrations:migrate

# Load fixtures (optional, for development)
php bin/console doctrine:fixtures:load
```

### 6. Build Assets

```bash
# Build CSS with Tailwind
npm run build:css

# Or watch for changes during development
npm run watch:css
```

### 7. Generate VAPID Keys (for Push Notifications)

```bash
php bin/console app:generate-vapid-keys
```

### 8. Set Permissions

```bash
# Linux/Mac
chmod -R 777 var/cache var/log public/uploads storage

# Windows - ensure write permissions for these directories
```

### 9. Start Development Server

```bash
symfony server:start
# or
php -S localhost:8000 -t public
```

Visit `http://localhost:8000` in your browser.

## Configuration

### Exchange Rate API

Configure your exchange rate API in `.env`:

```env
EXCHANGE_RATE_API_KEY=your_api_key_here
```

### Email Configuration

Configure SMTP settings in `.env`:

```env
MAILER_DSN=smtp://user:pass@smtp.example.com:587
MAILER_FROM=noreply@example.com
```

### File Storage

By default, files are stored locally in `public/uploads/`. For production, configure S3 storage in `config/services.yaml`.

## Usage

### Default Admin Account

After loading fixtures, you can log in with:
- **Email:** admin@example.com
- **Password:** admin123

**Important:** Change these credentials immediately in production!

### Creating Users

Users can be created through:
1. Self-registration (Broker/Consignee)
2. Admin user management interface
3. User hierarchy invitations

### Workflow Overview

1. **Manifest Creation** - Shipping admin creates manifest with NOA
2. **Consignee Declaration** - Broker declares consignee for containers
3. **Payment Submission** - Broker submits payment for manifest access
4. **Payment Verification** - Accounting verifies and approves payment
5. **EDO Generation** - System generates EDO after payment approval
6. **EDO Payment** - Broker pays for EDO release
7. **EDO Release** - Terminal team releases EDO after payment verification

## Development

### Running Tests

```bash
# Run all tests
php bin/phpunit

# Run specific test suite
php bin/phpunit tests/Unit
php bin/phpunit tests/Integration
```

### Code Quality

```bash
# PHP CS Fixer
vendor/bin/php-cs-fixer fix

# PHPStan
vendor/bin/phpstan analyse
```

### Database Migrations

```bash
# Create new migration
php bin/console make:migration

# Execute migrations
php bin/console doctrine:migrations:migrate
```

### Clear Cache

```bash
php bin/console cache:clear
```

## Deployment

### Production Checklist

- [ ] Set `APP_ENV=prod` in `.env`
- [ ] Configure production database
- [ ] Set up proper file permissions
- [ ] Configure web server (Apache/Nginx)
- [ ] Enable HTTPS/SSL
- [ ] Configure email service
- [ ] Set up backup strategy
- [ ] Configure monitoring and logging
- [ ] Change default admin credentials
- [ ] Review security settings

### Build for Production

```bash
# Install dependencies (no dev)
composer install --no-dev --optimize-autoloader

# Build assets
npm run build:css

# Clear and warm up cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## Project Structure

```
optimus-portal/
├── assets/              # Frontend assets (CSS, JS)
├── bin/                 # Console commands
├── config/              # Configuration files
├── migrations/          # Database migrations
├── public/              # Web root
│   ├── css/            # Compiled CSS
│   ├── js/             # JavaScript files
│   ├── images/         # Static images
│   └── uploads/        # User uploads (gitignored)
├── src/
│   ├── Controller/     # Controllers
│   ├── Entity/         # Doctrine entities
│   ├── Repository/     # Doctrine repositories
│   ├── Service/        # Business logic services
│   ├── Security/       # Security components
│   └── ...
├── templates/          # Twig templates
├── tests/              # Test files
└── var/                # Cache and logs (gitignored)
```

## API Documentation

API documentation is available at `/api-docs` when running the application.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Security

If you discover a security vulnerability, please email security@example.com instead of using the issue tracker.

## License

This project is proprietary software. All rights reserved.

## Support

For support and questions:
- Email: support@example.com
- Documentation: [Link to docs]
- Issue Tracker: https://github.com/jdflores22/optimus-portal/issues

## Acknowledgments

- Built with [Symfony](https://symfony.com/)
- UI powered by [FlyonUI](https://flyonui.com/)
- Icons by [Iconify](https://iconify.design/)

---

**Version:** 1.0.0  
**Last Updated:** May 2026
