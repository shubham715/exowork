# EXOWORK application structure

This repository is one deployable Laravel application with a React SPA.

## Main directories

```text
app/
|-- app/                    Laravel domain and backend code
|   |-- Http/Controllers/   HTTP and API controllers
|   |-- Models/             Eloquent models
|   |-- Policies/           record-level authorization
|   |-- Services/           business workflows
|   |-- Actions/            focused write operations
|   |-- Jobs/               queued work
|   |-- Events/             domain/broadcast events
|   `-- Notifications/      database, mail and broadcast notifications
|-- database/
|   |-- migrations/         schema history
|   |-- factories/          test factories
|   `-- seeders/            development/reference data
|-- resources/
|   |-- js/                 React application source
|   |   |-- components/     shared UI
|   |   |-- layouts/        portal layouts
|   |   `-- pages/          route-level screens by role
|   `-- views/app.blade.php single HTML shell for React
|-- routes/
|   |-- web.php             React SPA fallback only
|   |-- api.php             versionable JSON endpoints
|   `-- console.php         scheduled commands
|-- public/                 web root and compiled Vite assets
|-- storage/                logs and protected/generated files
|-- tests/                  feature and unit tests
|-- package.json            frontend dependencies and scripts
`-- composer.json           backend dependencies
```

## Route ownership

- React Router owns public and portal page URLs such as `/candidate/dashboard`.
- Laravel owns `/api/*`, `/webhooks/*`, `/up`, authentication, authorization,
  validation, persistence and files.
- `routes/web.php` returns the React shell for direct browser visits and page
  refreshes. It intentionally excludes API and webhook paths.

## Local development

```bash
composer install
npm install
php artisan serve
npm run dev
```

The default Laravel URL is `http://127.0.0.1:8000`. Vite supplies frontend
assets during development.

## Production build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

Configure the domain document root to this repository's `public` directory.
The production server does not need a running Node process; deploy the generated
`public/build` directory with the application.

Never commit `.env`, application keys, uploaded documents, `vendor`, or
`node_modules`.
