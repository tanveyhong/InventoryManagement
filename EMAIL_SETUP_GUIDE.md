# Email Configuration Guide

## Setup Instructions for Password Recovery Email

### Option 1: Gmail (Recommended for Testing)

1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate App Password**:
   - Go to: https://myaccount.google.com/apppasswords
   - Select "Mail" and your device
   - Copy the generated 16-character password

3. **Update `email_config.php`**:
   ```php
   'smtp' => [
       'host' => 'smtp.gmail.com',
       'port' => 587,
       'encryption' => 'tls',
       'auth' => true,
       'username' => 'your-actual-email@gmail.com',  // Your Gmail address
       'password' => 'xxxx xxxx xxxx xxxx',           // 16-char App Password
   ],
   
   'from' => [
       'address' => 'your-actual-email@gmail.com',    // Same as username
       'name' => 'Inventory Management System'
   ],
   ```

### Option 2: Outlook/Hotmail

```php
'smtp' => [
    'host' => 'smtp-mail.outlook.com',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'your-email@outlook.com',
    'password' => 'your-password',
],
```

### Option 3: SendGrid (Best for Production)

1. Sign up at https://sendgrid.com (Free tier: 100 emails/day)
2. Create an API Key
3. Update config:

```php
'smtp' => [
    'host' => 'smtp.sendgrid.net',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'apikey',                    // Literal string "apikey"
    'password' => 'SG.your-actual-api-key',    // Your SendGrid API key
],
```

## Testing

1. **Update credentials** in `email_config.php`
2. Go to: `http://localhost:8080/modules/users/forgot_password.php`
3. Enter your email address
4. Check your inbox (and spam folder)

## Troubleshooting

### "Failed to send email"
- **Check credentials** in `email_config.php`
- **Gmail users**: Make sure you're using App Password, not regular password
- **Firewall**: Ensure port 587 is not blocked
- **Check logs**: Errors are logged via `error_log()`

### Email not received
- **Check spam folder**
- **Verify email address** is correct in database
- **Gmail**: Check "Less secure apps" setting (though App Password is recommended)

### "SMTP connect() failed"
- **Check host and port** settings
- **Try different encryption**: Switch between 'tls' (port 587) and 'ssl' (port 465)
- **Network issue**: Some ISPs block SMTP ports

## Production Recommendations

For production environments, consider:

1. **SendGrid** or **Mailgun** (dedicated email services)
2. **Amazon SES** (if using AWS)
3. **Custom SMTP server** (for large-scale applications)

## Security Notes

- ⚠️ **Never commit** `email_config.php` with real credentials to Git
- Add to `.gitignore`: `email_config.php`
- Use environment variables for production
- Enable 2FA and use App Passwords when possible
