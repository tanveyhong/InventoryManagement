<?php
/**
 * Email Configuration
 * Configure SMTP settings for sending emails
 */

return [
    // SMTP Configuration
    'smtp' => [
        'host' => 'smtp.gmail.com',  // For Gmail. Change for other providers
        'port' => 587,                // 587 for TLS, 465 for SSL
        'encryption' => 'tls',        // 'tls' or 'ssl'
        'auth' => true,
        'username' => 'senpaifruit@gmail.com',  // Your email address
        'password' => 'irqf ewyx mmwo drpm',     // Your app password (not regular password)
    ],
    
    // From Address
    'from' => [
        'address' => 'senpaifruit@gmail.com',
        'name' => 'Inventory Management System'
    ],
    
    // Reply To
    'reply_to' => [
        'address' => 'support@inventorysystem.com',
        'name' => 'Support Team'
    ],
    
    // Other popular SMTP providers:
    // 
    // Gmail:
    // - host: smtp.gmail.com
    // - port: 587 (TLS) or 465 (SSL)
    // - Note: Enable "Less secure app access" or use App Password
    // 
    // Outlook/Hotmail:
    // - host: smtp-mail.outlook.com or smtp.office365.com
    // - port: 587
    // 
    // Yahoo:
    // - host: smtp.mail.yahoo.com
    // - port: 587 or 465
    // 
    // SendGrid:
    // - host: smtp.sendgrid.net
    // - port: 587
    // - username: apikey
    // - password: your-api-key
    //
    // Mailgun:
    // - host: smtp.mailgun.org
    // - port: 587
];
