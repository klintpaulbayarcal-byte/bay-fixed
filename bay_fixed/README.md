# Bay / Klint's Cafe

The final site entry is `http://localhost/bay/`, which redirects to the built React frontend at `frontend/dist/index.html#/`.

## Run

1. Start Apache and MySQL in XAMPP.
2. Build the React app:

```powershell
cd C:\xampp\htdocs\bay\frontend
npm run build
```

3. Open the site:

```text
http://localhost/bay/
```

If you need the direct build URL, use:

```text
http://localhost/bay/frontend/dist/index.html#/
```

## Hosting Checklist

### XAMPP

Post-deploy smoke tests
- I added a `health.php` endpoint at the project root to quickly validate the backend after deploy. It is token-gated via the `HEALTH_TOKEN` env var (optional).
- Two test scripts are included for post-deploy verification:
    - `scripts/post_deploy_test.sh` (bash)
    - `scripts/post_deploy_test.ps1` (PowerShell)

Usage examples (after deploying backend and setting `VITE_API_BASE_URL` in Vercel):

Linux / macOS (bash):
```bash
./scripts/post_deploy_test.sh https://api.yourdomain.com https://your-app.vercel.app YOUR_HEALTH_TOKEN
```

Windows PowerShell:
```powershell
.
\scripts\post_deploy_test.ps1 -ApiBase "https://api.yourdomain.com" -Origin "https://your-app.vercel.app" -HealthToken "YOUR_HEALTH_TOKEN"
```

These scripts run:
1. `GET /health.php` (optionally with token)
2. OPTIONS preflight to `/auth_api.php` to validate CORS
3. Login test with `jai / 212121` and save session cookie
4. Call the protected `order_status_api.php?mode=queue` endpoint using the session
5. Fetch `/products_api.php`

If any step fails, the script exits non-zero and prints a helpful error.

### cPanel / Shared Hosting
- Upload the full project folder, including `frontend/dist`.
- Set the document root or site path to point to the project root.
- Confirm PHP has permission to create tables in the target database.
- Set `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, and `DB_PORT` if your host does not use localhost defaults.
- On HTTPS production hosts, PHP sessions now use `SameSite=None` and `Secure` so the Vercel frontend can keep the login cookie.
- Verify the host allows PHP sessions and cookie storage.
- Rebuild the frontend locally before upload whenever UI code changes.
- Test login with `jireh / faith` for admin and `jai / 212121` for staff after deployment.

### Vercel (frontend)
- **Quick:** This repo includes a root `vercel.json` that tells Vercel to build the `frontend` workspace as a static Vite site. See [vercel.json](vercel.json#L1-L20).
- **How to deploy:** Connect the repository to Vercel and use the default import flow. The provided `vercel.json` will make Vercel run the build for `frontend/package.json`.
- **Environment variable:** Set `VITE_API_BASE_URL` in your Vercel Project Settings to point to your API (e.g. `https://api.yourdomain.com`). The frontend reads this at build/runtime for API calls.
- **Alternative build settings (manual):** In Vercel's Build & Output settings, you may set:
    - Build Command: `npm --prefix frontend run build`
    - Output Directory: `frontend/dist`
- **Notes:** The PHP backend is not runnable on Vercel by default (it expects an Apache/PHP host). For a complete site you can either host the PHP backend elsewhere and point `VITE_API_BASE_URL` to it, or migrate the backend to serverless/Node or a containerized deployment.
# Web System - User Registration & Admin Management

A complete PHP-based web system with user registration, login, and admin dashboard functionality.

## Setup Instructions

### 1. Create Database
Run the SQL schema from `sql_schema.sql`:
```sql
CREATE DATABASE IF NOT EXISTS web_system;
USE web_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    date_registered TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Insert Admin User
After creating the table, manually insert one admin user:
```sql
INSERT INTO users (fullname, email, username, password, role) 
VALUES ('Administrator', 'admin@example.com', 'jireh', '$2y$10$...', 'admin');
```

*Or use PHP password_hash():*
```sql
INSERT INTO users (fullname, email, username, password, role) 
VALUES ('Admin User', 'admin@example.com', 'admin', '$2y$10$...', 'admin');
```

## File Structure

### User-Facing Pages
- **signup.html** - Registration form with validation
- **login.html** - Login form
- **menu.html** - User dashboard after login

### Admin Pages
- **admin_dashboard.php** - View all users in a table with EDIT button
- **edit_user.php** - Edit user details and role
- **update_user.php** - Backend for user updates

### Backend Scripts
- **register.php** - Handles user registration with password hashing
- **login.php** - Authenticates user, redirects based on role
- **logout.php** - Destroys session and redirects to login

### Database
- **sql_schema.sql** - Database and table creation script

## Features

✓ User Registration with validation
✓ Password hashing using password_hash()
✓ Login authentication with password_verify()
✓ Role-based redirect (user -> cafe.php, admin -> admin_dashboard.php)
✓ Admin dashboard to view all registered users
✓ Edit user details (fullname, email, username, role)
✓ Session management
✓ Responsive Bootstrap UI

## Access Points

- **New Users**: `/web_system/signup.html`
- **Existing Users**: `/web_system/login.html`
- **Admin Credentials**: username: `jireh`, password: `faith`
- **Staff Credentials (example)**: username: `jai`, password: `212121`
