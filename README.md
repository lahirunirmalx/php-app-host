# PHP Basic Starter + Wasmer

This example shows how to run a bare-bones **PHP** script on **Wasmer Edge** using the built-in development server.

## Demo

`https://<your-subdomain>.wasmer.app/` (deploy to get your own URL)

## How it Works

The entrypoint `app/index.php` is intentionally simple:

* Builds an associative array containing a greeting, a few numbers, and `phpversion()`.
* Iterates over the array, printing each key/value pair with tab separators.

Swap this logic for your own endpoints or templates—Wasmer simply executes the PHP script for each request.

## Running Locally

```bash
php -t app -S 127.0.0.1:8080
```

Visit `http://127.0.0.1:8080/` to see the sample output. Edit `app/index.php` and reload the page to test changes.

## Deploying to Wasmer (Overview)

1. Package the `app/` directory (and any additional PHP files) with your deployment.
2. Configure the start command to serve the directory, e.g. `php -t app -S 0.0.0.0:$PORT`.
3. Deploy to Wasmer Edge and open `https://<your-subdomain>.wasmer.app/`.
