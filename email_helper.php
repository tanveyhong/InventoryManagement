<?php
/**
 * Email Helper Functions
 * Sends emails using PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Send an email
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email body (HTML supported)
 * @param string $altBody Plain text alternative body
 * @param array $attachments Array of file paths to attach
 * @return bool True on success, false on failure
 */
function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
    $config = require __DIR__ . '/email_config.php';
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->SMTPAuth   = $config['smtp']['auth'];
        $mail->Username   = $config['smtp']['username'];
        $mail->Password   = $config['smtp']['password'];
        $mail->SMTPSecure = $config['smtp']['encryption'];
        $mail->Port       = $config['smtp']['port'];
        
        // Enable verbose debug output for troubleshooting (set to 0 in production)
        $mail->SMTPDebug = 0; // 0 = off, 1 = client, 2 = client and server messages
        
        // Recipients
        $mail->setFrom($config['from']['address'], $config['from']['name']);
        $mail->addAddress($to);
        $mail->addReplyTo($config['reply_to']['address'], $config['reply_to']['name']);
        
        // Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        // Store error for display
        $_SESSION['email_error'] = $mail->ErrorInfo;
        return false;
    }
}

/**
 * Send password reset email
 * 
 * @param string $email User email
 * @param string $username User username
 * @param string $resetLink Reset link URL
 * @return bool True on success, false on failure
 */
function sendPasswordResetEmail($email, $username, $resetLink) {
    $subject = "Password Reset - Inventory Management System";
    
    // HTML body
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔐 Password Reset Request</h1>
            </div>
            <div class="content">
                <p>Hello <strong>' . htmlspecialchars($username) . '</strong>,</p>
                
                <p>You requested to reset your password for your Inventory Management System account.</p>
                
                <p>Click the button below to reset your password:</p>
                
                <p style="text-align: center;">
                    <a href="' . htmlspecialchars($resetLink) . '" class="button">Reset My Password</a>
                </p>
                
                <p>Or copy and paste this link into your browser:</p>
                <p style="background: white; padding: 10px; border: 1px solid #ddd; word-break: break-all;">
                    ' . htmlspecialchars($resetLink) . '
                </p>
                
                <div class="warning">
                    <strong>⚠️ Important:</strong> This link will expire in 1 hour for security reasons.
                </div>
                
                <p>If you did not request this password reset, please ignore this email. Your password will remain unchanged.</p>
                
                <p>Best regards,<br>
                <strong>Inventory Management Team</strong></p>
            </div>
            <div class="footer">
                <p>This is an automated message, please do not reply to this email.</p>
                <p>&copy; ' . date('Y') . ' Inventory Management System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    // Plain text alternative
    $textBody = "Hello $username,\n\n";
    $textBody .= "You requested to reset your password for your Inventory Management System account.\n\n";
    $textBody .= "Click the link below to reset your password:\n";
    $textBody .= "$resetLink\n\n";
    $textBody .= "This link will expire in 1 hour.\n\n";
    $textBody .= "If you did not request this, please ignore this email.\n\n";
    $textBody .= "Best regards,\nInventory Management Team";
    
    return sendEmail($email, $subject, $htmlBody, $textBody);
}
