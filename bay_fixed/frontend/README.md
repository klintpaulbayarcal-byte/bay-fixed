# Bay frontend (React + TypeScript + Vite + Tailwind)

This is a React conversion of the cafe system UI. It now includes the login screen, signup screen, and the cafe order menu as React pages.

Quick start

1. Install dependencies

```bash
cd frontend
npm install
```

2. Local dev (Vite)

```bash
npm run dev
```

3. Build for production

```bash
npm run build
```

Supabase integration

- Create a Supabase project and copy `SUPABASE_URL` and `SUPABASE_ANON_KEY`.
- Create a `.env` in `frontend/` with:

```
VITE_API_BASE_URL=http://localhost/bay
VITE_SUPABASE_URL=your-url
VITE_SUPABASE_ANON_KEY=your-anon-key
```

- `VITE_API_BASE_URL` points the React app to your existing PHP backend in production or when the frontend is deployed separately.
- If you host the PHP files on the same domain as the frontend, leave it empty and the app will use the current site origin.
- Use `@supabase/supabase-js` when you are ready to connect the UI to a real Supabase database.

Wiring example (done in this scaffold)

- `/` opens the login screen.
- `/signup` opens the registration screen.
- `/cafe` opens the cafe ordering screen.
- `src/App.tsx` now contains the React version of your current cafe UI, including menu cards, cart, login, and signup flows.
- Login and signup submit to `auth_api.php`.
- The cafe menu loads from `products_api.php`.
- Checkout submits to `place_order.php`.

If you want the pages to use Supabase later, replace the PHP API calls with Supabase queries and auth.

Deploy to Vercel

- Push the repo to GitHub and import the `bay` repo into Vercel.
- Set the environment variables `VITE_API_BASE_URL`, `VITE_SUPABASE_URL`, and `VITE_SUPABASE_ANON_KEY` in the Vercel project settings.
- Vercel will auto-deploy the `frontend` folder (set Root to `frontend` in the import settings) and provide a staging URL.

Important for production login

- Do not set `VITE_API_BASE_URL` to `http://localhost/...` in Vercel. That value only works on your own machine.
- Set `VITE_API_BASE_URL` to your publicly reachable PHP backend URL, for example `https://api.yourdomain.com/bay`.
- In your PHP host environment, set `CORS_ALLOWED_ORIGINS` to include your frontend origin (for example `https://finale-web.vercel.app`).
- Keep `credentials: include` requests enabled and ensure backend responses include `Access-Control-Allow-Credentials: true`.

Notes

- Dev proxy: `vite.config.ts` includes a `/api` proxy to `http://localhost` so you can call your PHP endpoints under `/api/...` during development.
- This scaffold is intentionally minimal; I can continue and wire Supabase auth, example API calls to your PHP backend, and CI (Vercel) config if you want.

Vercel deployment (automated)

- I added `frontend/vercel.json` to configure a static SPA build and a GitHub Actions workflow at `.github/workflows/deploy-vercel.yml` that builds `frontend` and deploys to Vercel.
- To enable automatic deploys you must:
	1. Push this repository to GitHub.
	2. In the GitHub repo settings, add the following secrets: `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID`.
	3. Import the repo into Vercel or create a new project and set the **Root Directory** to `frontend`.
	4. In Vercel project settings set `VITE_SUPABASE_URL` and `VITE_SUPABASE_ANON_KEY` environment variables.

Manual deploy (one-time) using Vercel CLI

```bash
npm i -g vercel
cd frontend
vercel login
vercel --prod
```

The CLI will ask for the project/org; choose or create a project and then add environment variables in the Vercel dashboard.

Security note: Do not share your `VERCEL_TOKEN` or Supabase anon key in chat. Add them as secrets in GitHub/Vercel dashboards.
