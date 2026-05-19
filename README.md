# Eloquente Catering System (ECS)

A premium, full-stack catering management platform. This system handles everything from landing pages and custom menu building to real-time client-staff communication and booking management.

## 🛠️ Tech Stack

*   **Backend**: Laravel 12 (PHP 8.2+)
*   **Frontend**: React 19 (Inertia.js), Tailwind CSS
*   **Database**: PostgreSQL (Supabase)
*   **Real-Time**: Laravel Reverb (WebSockets)
*   **Build Tool**: Vite 7+

---

## 🚀 One-Time Setup (Windows)

This project is designed to be **portable**. It includes a local PHP folder, so you don't need to install PHP globally on your computer.

1.  **Open PowerShell** in the project folder.
2.  **Enable Local PHP & Composer**:
    ```powershell
    $env:PATH = ".\php;" + $env:PATH
    ```
3.  **Ensure PostgreSQL Extensions are Enabled**:
    Open `php/php.ini` and ensure these lines are **NOT** commented out (remove the `;`):
    ```ini
    extension=pdo_pgsql
    extension=pgsql
    extension=openssl
    extension=curl
    ```
4.  **Install Dependencies**:
    ```powershell
    php composer.phar install
    npm install
    ```
5.  **Setup Environment**:
    *   Create a `.env` file from `.env.example`.
    *   Add your **Supabase PostgreSQL** credentials (see [Database Setup](#-database-setup)).
    *   Add **Reverb Credentials** for Chat.
6.  **Initialize Database**:
    ```powershell
    php artisan key:generate
    php artisan migrate --seed
    ```

---

## ⚡ Daily Startup Routine

Every time you start working, follow these steps in a new PowerShell window:

1.  **Activate PHP Path**:
    ```powershell
    $env:PATH = ".\php;" + $env:PATH
    ```
2.  **Clear Caches (If needed)**:
    ```powershell
    php artisan optimize:clear
    ```
3.  **Run the System**:
    ```powershell
    composer run dev
    ```
4.  **Sync PayMongo Webhook (in a separate terminal)**:
    ```powershell
    $env:PATH = ".\php;" + $env:PATH
    php artisan paymongo:webhook-sync
    ```
    This automatically starts ngrok (if not already running), detects the public URL, disables old webhooks, creates a new one, and updates your `.env` with the webhook secret. You **no longer need to manually create webhooks in the PayMongo Dashboard**.

> [!IMPORTANT]
> `composer run dev` starts **4 essential processes**:
> 1. **Laravel Web Server** (http://127.0.0.1:8080)
> 2. **Vite Dev Server** (Hot Module Replacement)
> 3. **Laravel Reverb** (WebSocket Server for Chat)
> 4. **Queue Worker** (Processes Emails & Notifications)

---

## 🗄️ Database Setup (Supabase)

We use **Supabase** for the shared live database. Because Supabase uses a pooler, you must use **Port 6543** for Transaction Mode.

**In your `.env`:**
```env
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com # Your Supabase Host
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres.your-project-ref
DB_PASSWORD=your-password
DB_SSLMODE=require
```

**Supabase Configuration Note:**
In `config/database.php`, the app is configured to disable server-side prepared statements to maintain compatibility with the Supabase pooler:
```php
'options' => [
    PDO::ATTR_EMULATE_PREPARES => true,
    PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
]
```

---

## 💬 Real-Time Chat & Notifications

The chat system uses **Laravel Reverb**. 

1.  Ensure `BROADCAST_CONNECTION=reverb` in `.env`.
2.  If the chat bubble says "Disconnected," verify that port `8085` is open.
3.  Client side uses `ChatBubble.jsx`.
4.  Staff side uses `StaffMessaging.jsx` inside the Marketing Dashboard.

---

## PayMongo Payments

Payments now use real PayMongo hosted Checkout Sessions instead of the old mock checkout.

Required `.env` values:

```env
PAYMONGO_BASE_URL=https://api.paymongo.com
PAYMONGO_CHECKOUT_ENDPOINT=/v1/checkout_sessions
PAYMONGO_PUBLIC_KEY=pk_test_...
PAYMONGO_SECRET_KEY=sk_test_...
PAYMONGO_WEBHOOK_SECRET=...  # Auto-managed by paymongo:webhook-sync
PAYMONGO_CURRENCY=PHP
PAYMONGO_PAYMENT_METHOD_TYPES=card,gcash,paymaya
PAYMONGO_CA_BUNDLE=storage/app/cacert.pem
PAYMONGO_WEBHOOK_TOLERANCE=300
NGROK_PATH=C:\Users\YOUR_USER\Downloads\ngrok-v3-stable-windows-amd64\ngrok.exe
```

After changing these values, run:

```powershell
php artisan config:clear
```

### 🤝 For Group Members (Testing Setup)

If you are a group member running this project on your machine, you must do the following to test payments:

1. **Use the Same Keys:** Get the `PAYMONGO_PUBLIC_KEY` and `PAYMONGO_SECRET_KEY` from the project lead and place them in your `.env`. (It is highly recommended that everyone uses the same test account so all test data is in one place).
2. **Download Ngrok:** 
   - Download Ngrok from `https://ngrok.com/download`.
   - Extract `ngrok.exe` to a folder (e.g., `Downloads\ngrok-v3-stable-windows-amd64`).
   - Update `NGROK_PATH` in your `.env` to point to the exact location of your `ngrok.exe`.
3. **Run the Sync Command:** Before you start testing payments on your machine, run `php artisan paymongo:webhook-sync` in a new terminal. 

> [!WARNING]
> **Sharing One Account:** Because you are all using the same PayMongo account, the sync command will automatically disable old webhooks and point the new one to *your* computer. This means **only the last person who ran the sync command will receive payment updates**. If your teammate is currently testing payments, let them finish before you run the sync command on your machine!

### Automatic Webhook Setup (Recommended)

Instead of manually running ngrok and creating webhooks in the PayMongo Dashboard, run:

```powershell
php artisan paymongo:webhook-sync
```

This command:
1. Starts ngrok automatically (or detects a running instance)
2. Discovers the new public HTTPS URL
3. Disables any old PayMongo webhooks pointing to previous ngrok URLs
4. Creates a new webhook for the current ngrok URL
5. Updates `PAYMONGO_WEBHOOK_SECRET` in your `.env` automatically
6. Clears the config cache

You no longer need to visit the PayMongo Dashboard to manage webhooks.

### Manual Webhook Setup (Alternative)

If you prefer manual setup, run ngrok:

```powershell
& "C:\Users\Joshua Aquino\Downloads\ngrok-v3-stable-windows-amd64\ngrok.exe" http 8080
```

Then use the HTTPS forwarding URL plus `/webhook/paymongo` in the PayMongo Dashboard.

Full testing instructions are in `paymongo-testing.md`.

---

## 🔑 Default Accounts (Post-Seed)

**Password for all:** `password123`

| Role | Username |
| :--- | :--- |
| **Admin** | `admin` |
| **Marketing** | `marketing` |
| **Accounting** | `accounting` |
| **Client** | `client` |

---

## 📂 Key Files & Directories

*   `app/Http/Controllers/PaymentController.php`: Creates PayMongo Checkout Sessions for the 10/70/20 milestone flow.
*   `app/Http/Controllers/PayMongoWebhookController.php`: Verifies PayMongo webhook signatures and marks paid milestones.
*   `app/Services/PayMongoService.php`: PayMongo API client for hosted checkout creation.
*   `resources/js/Pages/client/MenuGallery.jsx`: Main menu exploration for clients.
*   `resources/js/Components/client/MenuBuilder.jsx`: Custom package builder.
*   `app/Services/BusinessRulesService.php`: Core logic for booking availability and pax limits.
*   `mavhandoff.md`: Current PayMongo integration handoff and next-step notes.
*   `paymongo-testing.md`: Step-by-step PayMongo checkout and webhook testing guide.

---

## Staff Dashboard Routes

*   **Marketing**: `/dashboard/marketing`
*   **Accounting**: `/dashboard/accounting`
*   **Admin**: `/dashboard/admin`

The related staff APIs now use `/api/marketing/...` and `/api/accounting/...`.

---

## 🛠️ Troubleshooting

*   **"Could not find driver"**: Ensure `pdo_pgsql` is enabled in `php/php.ini`.
*   **"Prepared statement already exists"**: Ensure you are using **Port 6543** and not 5432.
*   **White Screen on Login**: Run `php artisan config:clear` and `php artisan route:clear`.
*   **Vite Manifest Missing**: Run `npm run build` once to generate assets if `composer run dev` is not being used.
*   **PayMongo cURL error 60**: Ensure `storage/app/cacert.pem` exists and `.env` has `PAYMONGO_CA_BUNDLE=storage/app/cacert.pem`, then run `php artisan config:clear`.
*   **Ngrok not recognized**: Run ngrok by full path from Downloads, or add `ngrok.exe` to your Windows PATH.
*   **PayMongo webhook returns 401**: Confirm `PAYMONGO_WEBHOOK_SECRET` exactly matches the webhook signing secret in the PayMongo Dashboard, then clear config.

---
*Developed for Eloquente Catering System.*
