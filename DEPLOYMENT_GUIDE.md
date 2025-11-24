# Deployment Guide

Your Inventory System is now ready to be hosted online! 

Since we have added a `Dockerfile`, you can deploy this application to almost any modern cloud provider.

## Recommended Option: Render (Free Tier Available)

1.  **Push your code to GitHub** (if you haven't already).
2.  Go to [dashboard.render.com](https://dashboard.render.com/).
3.  Click **New +** and select **Web Service**.
4.  Connect your GitHub repository.
5.  Render will automatically detect the `Dockerfile`.
6.  **Environment Variables:**
    You can set these in the Render dashboard to override the defaults (recommended for security):
    *   `PG_HOST`
    *   `PG_PASSWORD`
    *   `FIREBASE_API_KEY`
    *   etc.
7.  Click **Create Web Service**.

## Option 2: Railway

1.  Go to [railway.app](https://railway.app/).
2.  Click **New Project** -> **Deploy from GitHub repo**.
3.  Select your repository.
4.  Railway will detect the Dockerfile and build it automatically.

## Option 3: DigitalOcean App Platform

1.  Go to DigitalOcean Dashboard -> **Apps**.
2.  **Create App** -> Select GitHub.
3.  It will detect the Dockerfile.
4.  Deploy.

## Important Notes

*   **Database:** Your application is already configured to connect to your **Supabase PostgreSQL** database (`db.fbuzapvujmjecrnhbzuc.supabase.co`). This means your data is already in the cloud!
*   **Security:** It is highly recommended to change your database password and update the `PG_PASSWORD` environment variable in your hosting provider settings, rather than keeping it in `config.php`.
