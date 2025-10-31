# Testing Issues (RESOLVED)

## ✅ RESOLVED: Test Environment Configuration Issue

### Problem
All WebTestCase and KernelTestCase tests fail with the error:
```
LogicException: You cannot create the client used in functional tests if the "framework.test" config is not set to true.
```

OR

```
LogicException: Could not find service "test.service_container". Try updating the "framework.test" config to "true".
```

### Current Status
- Configuration file `/backend/config/packages/framework.yaml` has `when@test:` block with `test: true` ✅
- Configuration file `/backend/config/packages/test/framework.yaml` has `test: true` ✅
- PHPUnit configuration sets `APP_ENV=test` correctly ✅
- Console command `debug:config framework --env=test` shows `test: true` ✅
- Kernel environment parameter shows `kernel.environment=test` ✅

BUT tests still fail to recognize the test environment configuration.

### Affected Test Files
- `backend/tests/Functional/Api/WebhookCallbackFlowTest.php` (9 tests)
- `backend/tests/Functional/Api/OcrWebhookControllerTest.php` (13 tests)
- `backend/tests/Integration/SearchIndexingFlowTest.php` (15 tests)
- All other WebTestCase and KernelTestCase based tests

### Attempted Fixes
1. ✅ Created `/backend/config/packages/test/framework.yaml` with explicit `test: true`
2. ✅ Cleared test cache: `APP_ENV=test php bin/console cache:clear --env=test`
3. ✅ Removed and rebuilt cache: `rm -rf var/cache/test && cache:warmup`
4. ✅ Verified Symfony version (7.3.4) supports `when@test` syntax
5. ✅ Verified phpunit.dist.xml sets `APP_ENV=test`

### Potential Causes
1. **Kernel Bootstrap Timing**: The kernel might be instantiated before PHPUnit sets the APP_ENV variable
2. **Container Compilation**: The service container may be compiling in dev mode instead of test mode
3. **Dotenv Loading**: The .env file might be overriding the APP_ENV after PHPUnit sets it
4. **Custom Kernel Behavior**: There might be custom kernel logic affecting test environment detection

### Workaround
Unit tests that don't require WebTestCase or KernelTestCase work correctly. For integration testing of webhook functionality, consider:
1. Using manual HTTP requests with real backend server
2. Creating standalone integration test runner outside PHPUnit
3. Fixing the test environment configuration (preferred)

### Next Steps to Investigate
1. Check if tests work when run directly from host machine (not in Docker)
2. Verify .env.test file is being loaded correctly
3. Add debug logging to Kernel.php to track when environment is detected
4. Check if symfony/test-pack bundle is properly installed
5. Review docker-compose.yml environment variable configuration
6. Compare with a fresh Symfony 7.3 installation to identify configuration differences

### Test Coverage
Despite the execution issue, the test files have been created with comprehensive coverage:

**WebhookCallbackFlowTest.php** (9 tests):
- Complete webhook flow (OCR complete → webhook → backend update → indexing)
- Failed OCR processing handling
- Invalid signature rejection
- Missing signature rejection
- Idempotency verification
- Document not found handling
- Malformed JSON handling
- Missing required fields handling
- Progress update webhooks

All tests follow TDD principles and are ready to execute once the environment issue is resolved.

## ✅ RESOLUTION IMPLEMENTED

### Root Cause
The issue was in `tests/bootstrap.php`. The Symfony `Dotenv::bootEnv()` method was loading the `.env` file which contains `APP_ENV=dev`, and this was overriding the `$_ENV['APP_ENV']` variable AFTER PHPUnit had set `$_SERVER['APP_ENV'] = 'test'`.

Since Symfony's Kernel class uses `getenv('APP_ENV')` (which reads from `$_ENV`) instead of `$_SERVER['APP_ENV']`, the kernel was booting in 'dev' mode even though PHPUnit had configured 'test' mode.

### Solution
Modified `backend/tests/bootstrap.php` to explicitly set `$_ENV['APP_ENV']` and use `putenv()` to ensure the test environment is preserved both before and after `bootEnv()` is called:

```php
// Before bootEnv()
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    $_ENV['APP_ENV'] = 'test';
    putenv('APP_ENV=test');
}

// Load .env file
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Ensure test env persists after bootEnv()
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    $_ENV['APP_ENV'] = 'test';
    putenv('APP_ENV=test');
}
```

### Additional Setup Required
Created test database and ran migrations:
```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

### Test Results
✅ All tests now execute correctly in test environment
✅ Test container service is available
✅ Framework test configuration is properly loaded

Current test status for WebhookCallbackFlowTest (9 tests):
- 4 tests passing
- 5 tests with failures/errors that need fixing (expected - tests are working correctly, finding real issues)
