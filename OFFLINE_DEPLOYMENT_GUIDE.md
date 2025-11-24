# Offline Module Deployment Checklist

To ensure the offline functionality works correctly on your live website, please verify the following:

## 1. HTTPS is Required 🔒
Service Workers **only** work on secure connections (HTTPS).
- **Localhost:** Works on `http://localhost` (exception for development).
- **Live Site:** Must be `https://yourdomain.com`.
  - If you use `http://yourdomain.com`, the Service Worker will fail to register, and offline mode will not work.

## 2. Server Configuration ⚙️
The Service Worker file (`modules/offline/service_worker.php`) sends a special header:
```php
header('Service-Worker-Allowed: /');
```
This allows the Service Worker (which lives in a subdirectory) to control the entire app.
- Ensure your web server (Apache/Nginx) allows PHP to set headers.
- Ensure no output (whitespace, HTML) is sent before this header in the PHP file (the file I created is clean).

## 3. Folder Structure file_folder
The system relies on the relative position of files.
- Keep the `modules/offline/` folder structure intact.
- If you move the app to a subdirectory (e.g., `example.com/inventory/`), it will still work automatically because we used relative paths (`../../`).

## 4. Browser Support 🌐
- Works in all modern browsers (Chrome, Firefox, Safari, Edge).
- **Private/Incognito Mode:** Service Workers are often disabled or cleared immediately in private browsing windows. Test in a normal window.

## 5. Caching Behavior 💾
- The current setup caches pages as you visit them ("Network First").
- If you update your code, users might still see the old cached version until they close and reopen the tab, or until the Service Worker updates (we added auto-update logic to help with this).
