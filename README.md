# Ramiz License Client

A Laravel package for Ramiz license verification.

---

## Installation

```bash
composer require ramiz/license-client
```

Publish the config:
```bash
php artisan vendor:publish --tag=sl-sync
```

---

## Step 1 — Register the installation

On first install, run:
```bash
php artisan ramiz:register
```

This contacts the Ramiz licensing portal and records your installation.
The administrator will review and send you credentials.

---

## Step 2 — Add credentials to .env

After receiving credentials from the administrator:

```env
APP_SYNC_NODE=your-installation-id
APP_SYNC_TOKEN=your-secret-key
APP_SYNC_CHANNEL=your-product-slug
```

---

## Step 3 — Protect your routes

In `bootstrap/app.php` or `Kernel.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Ramiz\LicenseClient\Middleware\LicenseMiddleware::class);
})
```

Or apply to specific route groups:
```php
Route::middleware('ramiz.license')->group(function () {
    // protected routes
});
```

---

## Step 4 — Add to scheduler

In `routes/console.php`:
```php
Schedule::command('ramiz:heartbeat')->hourly();
```

---

## Commands

| Command | Description |
|---|---|
| `php artisan ramiz:register` | Register this installation |
| `php artisan ramiz:status` | Check current license status |
| `php artisan ramiz:heartbeat` | Send manual heartbeat ping |

---

## Security Layers

| Layer | Description |
|---|---|
| 1 | Portal URL hardcoded and obfuscated — not configurable |
| 2 | Portal signs every response — MITM attacks detected |
| 3 | Credentials use non-obvious .env key names |
| 4 | Source compiled with IonCube — unreadable bytecode |

