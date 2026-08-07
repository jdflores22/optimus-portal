# Optimus V2

OPTIMUS Shipping Portal rebuilt on:

- ASP.NET Core 7 Web API
- React 18 + Vite + TypeScript + MUI
- Redux Toolkit
- MySQL 8 (Pomelo EF Core)
- Redis 7 (optional in Phase 0)
- JWT + Refresh Token

## Phase 0 — Foundation (current)

Includes:
- Clean Architecture solution
- JWT login / refresh / logout
- Authenticated `/api/hello`
- Serilog + health checks + Swagger
- Geo seed (Region / Province / City / Barangay)
- React login shell + foundation dashboard

## Quick start

### 1) Database

XAMPP MySQL (default Phase 0 config):

```sql
CREATE DATABASE IF NOT EXISTS optimus_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Or start Docker services (requires Docker Desktop):

```bash
docker compose up -d
```

Then update `ConnectionStrings` in `src/Optimus.Api/appsettings.Development.json` to port `3307` / user `optimus`.

### 2) API

```bash
cd src/Optimus.Api
dotnet run
```

API: http://localhost:5080  
Swagger: http://localhost:5080/swagger  
Health: http://localhost:5080/health

Default admin:
- Email: `admin@optimus.local`
- Password: `Admin123!`

### 3) Frontend

```bash
cd frontend/optimus-web
npm install
npm run dev
```

App: http://localhost:5173

## Solution layout

```text
optimus-v2/
├── src/
│   ├── Optimus.Api/
│   ├── Optimus.Application/
│   ├── Optimus.Domain/
│   ├── Optimus.Infrastructure/
│   └── Optimus.Shared/
├── frontend/optimus-web/
├── tests/
└── docker-compose.yml
```

## Docs

Planning docs live in the parent repo `docs/`:
- `OPTIMUS-RECREATION-PLAN.md`
- `OPTIMUS-RECREATION-DESIGN.md`
- `OPTIMUS-RECREATION-TASKS.md`
