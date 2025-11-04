# DocVault Backend

**Symfony-based REST API for document management, authentication, and OCR orchestration**

[![CI Status](https://github.com/private-doc-vault/docvault-backend/actions/workflows/ci.yml/badge.svg)](https://github.com/private-doc-vault/docvault-backend/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Local Setup](#local-setup)
- [Running Tests](#running-tests)
- [Building Docker Image](#building-docker-image)
- [API Endpoints](#api-endpoints)
- [Environment Variables](#environment-variables)
- [Development](#development)
- [Contributing](#contributing)

## Overview

DocVault Backend is a Symfony 7.3 REST API that provides document management, user authentication, OCR orchestration, and search capabilities. It serves as the central coordination layer for the DocVault document archiving system.

### Key Features

- **Document Management**: Upload, organize, tag, and retrieve documents
- **Authentication**: JWT + session-based auth with role-based access control (RBAC)
- **OCR Orchestration**: Async document processing with OCR service integration
- **Search**: Full-text search via Meilisearch integration
- **Webhook Receiver**: Handles OCR completion/failure/progress notifications
- **Async Processing**: Symfony Messenger for background tasks
- **Audit Trail**: Comprehensive logging and audit capabilities

## Tech Stack

- **Framework**: Symfony 7.3
- **Language**: PHP 8.4
- **Database**: PostgreSQL 16
- **Cache/Queue**: Redis 7
- **Search**: Meilisearch 1.5
- **Testing**: PHPUnit 10
- **Authentication**: LexikJWTAuthenticationBundle
- **API Docs**: Nelmio API Doc Bundle (OpenAPI/Swagger)

## Prerequisites

- **PHP** 8.4+
- **Composer** 2.6+
- **PostgreSQL** 16+ (or Docker)
- **Redis** 7+ (or Docker)
- **Meilisearch** 1.5+ (or Docker)
- **Symfony CLI** (optional but recommended)

## Local Setup

### Option 1: Using Docker (Recommended)

Use the [infrastructure repository](https://github.com/private-doc-vault/docvault-infrastructure) for Docker-based setup:

```bash
git clone --recursive https://github.com/private-doc-vault/docvault-infrastructure.git
cd docvault-infrastructure
./setup.sh
docker-compose -f docker-compose.dev.yml up -d
```

### Option 2: Local PHP Environment

1. **Clone the repository:**

```bash
git clone https://github.com/private-doc-vault/docvault-backend.git
cd docvault-backend
```

2. **Install dependencies:**

```bash
composer install
```

3. **Configure environment:**

```bash
cp .env .env.local
```

Edit `.env.local` and set:
```env
APP_ENV=dev
APP_SECRET=<generate-with-openssl-rand-hex-32>
DATABASE_URL="postgresql://user:pass@localhost:5432/docvault?serverVersion=16"
REDIS_URL=redis://localhost:6379
MEILISEARCH_URL=http://localhost:7700
MEILISEARCH_API_KEY=<your-meilisearch-key>
OCR_SERVICE_URL=http://localhost:8000
OCR_WEBHOOK_SECRET=<generate-with-openssl-rand-hex-32>
JWT_SECRET_KEY=<generate-with-openssl-rand-hex-32>
JWT_PASSPHRASE=<choose-strong-passphrase>
```

4. **Generate JWT keys:**

```bash
php bin/console lexik:jwt:generate-keypair
```

5. **Run database migrations:**

```bash
php bin/console doctrine:migrations:migrate
```

6. **Load fixtures (optional):**

```bash
php bin/console doctrine:fixtures:load
```

7. **Start the development server:**

```bash
# Using Symfony CLI (recommended)
symfony server:start

# Or using PHP built-in server
php -S localhost:8000 -t public/
```

The API will be available at `http://localhost:8000/api`.

## Running Tests

### Full Test Suite

```bash
composer test
```

Or directly with PHPUnit:

```bash
vendor/bin/phpunit
```

### Run Specific Test Files

```bash
vendor/bin/phpunit tests/Functional/Api/DocumentApiTest.php
```

### Run with Coverage

```bash
vendor/bin/phpunit --coverage-html coverage/
```

View coverage report by opening `coverage/index.html` in a browser.

### Test Categories

- **Unit Tests**: `tests/Unit/` - Service and utility tests
- **Functional Tests**: `tests/Functional/` - API endpoint tests
- **Integration Tests**: `tests/Integration/` - Database and external service tests

## Building Docker Image

### Local Build

```bash
docker build -t docvault-backend:local .
```

### Multi-Platform Build

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t docvault-backend:latest .
```

### Run Docker Container

```bash
docker run -d \
  -p 9000:9000 \
  -e DATABASE_URL="postgresql://user:pass@postgres:5432/docvault" \
  -e REDIS_URL="redis://redis:6379" \
  -v $(pwd)/storage:/var/www/html/storage \
  docvault-backend:local
```

## API Endpoints

### Authentication

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/auth/register` | Register new user | No |
| POST | `/api/auth/login` | Login and get JWT token | No |
| POST | `/api/auth/refresh` | Refresh JWT token | Yes (Refresh Token) |
| POST | `/api/auth/logout` | Logout and invalidate token | Yes |

### Documents

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/api/documents` | List documents with pagination | Yes |
| GET | `/api/documents/{id}` | Get document details | Yes |
| POST | `/api/documents` | Upload new document | Yes |
| PUT | `/api/documents/{id}` | Update document metadata | Yes |
| DELETE | `/api/documents/{id}` | Delete document | Yes |
| GET | `/api/documents/{id}/download` | Download document file | Yes |
| POST | `/api/documents/{id}/reprocess` | Trigger OCR reprocessing | Yes |

### Search

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/api/search` | Search documents (full-text) | Yes |
| GET | `/api/search/suggest` | Get search suggestions | Yes |

### Tags

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/api/tags` | List all tags | Yes |
| POST | `/api/tags` | Create new tag | Yes |
| PUT | `/api/tags/{id}` | Update tag | Yes |
| DELETE | `/api/tags/{id}` | Delete tag | Yes |

### Admin

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/api/admin/stats` | Get system statistics | Yes (Admin) |
| GET | `/api/admin/users` | List users | Yes (Admin) |
| POST | `/api/admin/users/{id}/roles` | Update user roles | Yes (Admin) |

### Webhooks (Internal)

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/webhooks/ocr-status` | OCR service webhook | HMAC signature |

### API Documentation

Interactive API documentation is available at:
- **Swagger UI**: `http://localhost:8000/api/doc` (development only)

## Environment Variables

### Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `prod`, `dev` |
| `APP_SECRET` | Symfony secret key | `<random-32-char-hex>` |
| `DATABASE_URL` | PostgreSQL connection | `postgresql://user:pass@localhost:5432/docvault` |
| `REDIS_URL` | Redis connection | `redis://localhost:6379` |
| `JWT_SECRET_KEY` | JWT signing key | `<random-32-char-hex>` |
| `JWT_PASSPHRASE` | JWT passphrase | `<strong-passphrase>` |

### Optional Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MEILISEARCH_URL` | Meilisearch URL | `http://localhost:7700` |
| `MEILISEARCH_API_KEY` | Meilisearch API key | - |
| `OCR_SERVICE_URL` | OCR service URL | `http://localhost:8000` |
| `OCR_WEBHOOK_SECRET` | Webhook HMAC secret | - |
| `CORS_ALLOW_ORIGIN` | CORS allowed origins | `^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$` |
| `MESSENGER_TRANSPORT_DSN` | Message queue DSN | `doctrine://default` |

### Generating Secrets

```bash
# Generate APP_SECRET, JWT_SECRET_KEY, OCR_WEBHOOK_SECRET
openssl rand -hex 32

# Generate JWT_PASSPHRASE
openssl rand -base64 32
```

## Development

### Project Structure

```
.
├── bin/                    # Console commands
├── config/                 # Configuration files
│   ├── packages/          # Bundle configuration
│   ├── routes/            # Route definitions
│   └── services.yaml      # Service container config
├── migrations/            # Database migrations
├── public/                # Web root
│   └── index.php         # Front controller
├── src/
│   ├── Command/          # Console commands
│   ├── Controller/       # HTTP controllers
│   │   ├── Api/         # API controllers
│   │   └── Web/         # Web controllers (legacy)
│   ├── Entity/           # Doctrine entities
│   ├── EventSubscriber/  # Event subscribers
│   ├── MessageHandler/   # Async message handlers
│   ├── Repository/       # Doctrine repositories
│   ├── Security/         # Security components
│   │   └── Voter/       # Permission voters
│   ├── Service/          # Business logic services
│   └── Kernel.php        # Application kernel
├── storage/              # File storage (not in git)
│   ├── documents/        # Uploaded documents
│   └── temp/            # Temporary files
├── tests/                # Test suite
│   ├── Functional/      # API tests
│   ├── Integration/     # Integration tests
│   └── Unit/            # Unit tests
├── var/                  # Cache, logs (not in git)
├── composer.json         # PHP dependencies
├── Dockerfile           # Docker image definition
└── phpunit.xml.dist     # PHPUnit configuration
```

### Common Commands

#### Database

```bash
# Create database
php bin/console doctrine:database:create

# Run migrations
php bin/console doctrine:migrations:migrate

# Generate migration from entity changes
php bin/console make:migration

# Load fixtures
php bin/console doctrine:fixtures:load
```

#### Cache

```bash
# Clear cache
php bin/console cache:clear

# Warm up cache
php bin/console cache:warmup
```

#### Messenger (Async Processing)

```bash
# Consume messages from async queue
php bin/console messenger:consume async -vv

# Consume with limits
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M

# View failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

#### Maintenance

```bash
# Clean up orphaned files
php bin/console app:cleanup-orphaned-files

# Clean stuck processing tasks
php bin/console app:cleanup-stuck-tasks

# Rebuild search index
php bin/console app:reindex-documents
```

### Code Quality

```bash
# PHP CS Fixer (code style)
vendor/bin/php-cs-fixer fix

# PHPStan (static analysis)
vendor/bin/phpstan analyse

# Security check
symfony security:check
```

### Debugging

Enable debug mode in `.env.local`:
```env
APP_ENV=dev
APP_DEBUG=1
```

View logs:
```bash
tail -f var/log/dev.log
```

### Creating New Endpoints

1. **Create controller:**
```bash
php bin/console make:controller Api/MyController
```

2. **Add route annotations** in controller
3. **Implement business logic** in services
4. **Add tests** in `tests/Functional/Api/`
5. **Update API documentation** with annotations

## Contributing

Please read the [Contributing Guide](https://github.com/private-doc-vault/docvault-infrastructure/blob/main/CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.

### Development Workflow

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make changes and add tests
4. Run tests: `composer test`
5. Commit changes: `git commit -m "feat: add my feature"`
6. Push to branch: `git push origin feature/my-feature`
7. Create a Pull Request

## License

This project is licensed under the MIT License.

## Related Repositories

- [DocVault Infrastructure](https://github.com/private-doc-vault/docvault-infrastructure) - Docker orchestration
- [DocVault Frontend](https://github.com/private-doc-vault/docvault-frontend) - React SPA
- [DocVault OCR Service](https://github.com/private-doc-vault/docvault-ocr-service) - OCR processing

## Support

For issues and questions:
- Open an issue in this repository
- Check the [Infrastructure Documentation](https://github.com/private-doc-vault/docvault-infrastructure)
- Review the [CLAUDE.md](https://github.com/private-doc-vault/docvault-infrastructure/blob/main/CLAUDE.md) for AI assistant guidance