# Forgot Password Email System - Setup Guide

## Overview
This comprehensive forgot password system provides secure email-based password reset functionality for the Aabha Contraceptive System.

## Files Created/Modified

### 1. Core Files
- **`api/forgot_password.php`** - API endpoint for password reset requests
- **`reset_password.php`** - Password reset form page  
- **`index.php`** - Updated login page with forgot password modal
- **`Includes/email_config.php`** - Email configuration settings
- **`db/create_password_reset_table.sql`** - Database table creation script

## Setup Instructions

### Step 1: Database Setup
1. Run the SQL script to create the password reset tokens table:
   ```sql
   -- Execute this in SQL Server Management Studio
   -- File: db/create_password_reset_table.sql
   ```

2. Verify the table was created:
   ```sql
   SELECT * FROM password_reset_tokens;
   ```

### Step 2: Email Configuration

#### Option A: Local Development (PHP mail)
1. For XAMPP/WAMP, ensure mail() function is enabled in `php.ini`
2. Configure sendmail settings if needed
3. Keep `smtp_enabled = false` in `email_config.php`

#### Option B: Gmail SMTP (Recommended for Production)
1. Enable 2-Factor Authentication on Gmail account
2. Generate App Password: Google Account > Security > App passwords  
3. Update `Includes/email_config.php`:
   ```php
   'smtp_enabled' => true,
   'smtp_username' => 'your-email@gmail.com',
   'smtp_password' => 'your-16-char-app-password',
   ```

#### Option C: Other SMTP Services
- **SendGrid**: `smtp.sendgrid.net:587`
- **Mailgun**: `smtp.mailgun.org:587`  
- **Amazon SES**: `email-smtp.region.amazonaws.com:587`

### Step 3: Security Configuration
1. Update `email_config.php` settings:
   ```php
   'debug_mode' => false, // Set to false in production
   'token_expiry_hours' => 1, // Adjust as needed
   'max_reset_attempts' => 3, // Rate limiting
   ```

2. Ensure proper file permissions:
   - API directory: Read/Execute only
   - Email config: Read only for web server

## Features

### Security Features
- ✅ Rate limiting (3 attempts per day per email)
- ✅ Token expiration (1 hour default)
- ✅ One-time use tokens
- ✅ Email enumeration protection
- ✅ SQL injection protection
- ✅ XSS protection with htmlspecialchars()

### User Experience
- ✅ Modern responsive design
- ✅ Real-time password validation
- ✅ Clear error/success messages
- ✅ Password visibility toggles
- ✅ Loading states and animations

### Email Features
- ✅ Professional HTML email templates
- ✅ Mobile-responsive design
- ✅ Security warnings and instructions
- ✅ Branded appearance
- ✅ Detailed user information

## Testing Instructions

### Test 1: Basic Functionality
1. Go to login page: `http://localhost/Aabha/`
2. Click "Forgot Password?" link
3. Enter a valid email address from users table
4. Check if email is sent (check logs for local mail)
5. Click reset link in email
6. Set new password and test login

### Test 2: Security Tests
1. **Invalid Email**: Try with non-existent email
2. **Rate Limiting**: Try 4+ reset requests in one day
3. **Expired Token**: Wait 1+ hours and try using old link
4. **Used Token**: Try using same link twice
5. **Invalid Token**: Modify token in URL

### Test 3: Error Handling
1. **Database Disconnection**: Stop SQL Server temporarily
2. **Invalid JSON**: Send malformed API requests
3. **Missing Fields**: Send incomplete form data

## Troubleshooting

### Common Issues

#### Email Not Sending
```bash
# Check PHP error logs
tail -f /path/to/php_error.log

# Test mail() function
php -r "echo mail('test@example.com', 'Test', 'Test message') ? 'OK' : 'FAIL';"
```

#### Database Errors
- Verify SQL Server connection in `db_connect.php`
- Check if `password_reset_tokens` table exists
- Ensure proper foreign key relationship with `users` table

#### Token Issues
- Check token expiry times in database
- Verify token generation (should be 64 characters)
- Check for URL encoding issues

### Debug Mode
Enable debug mode in `email_config.php`:
```php
'debug_mode' => true,
'log_emails' => true,
```

Check error logs for detailed information:
```bash
# Windows (XAMPP)
tail -f C:\xampp\apache\logs\error.log

# Check application logs
tail -f C:\xampp\htdocs\Aabha\error_log.txt
```

## Production Deployment

### Pre-Production Checklist
- [ ] Database table created
- [ ] SMTP credentials configured  
- [ ] Debug mode disabled
- [ ] Rate limiting enabled
- [ ] SSL/HTTPS enabled
- [ ] Error logging configured
- [ ] Email templates tested
- [ ] Security tests passed

### Performance Optimization
1. **Database Indexes**: Already created in SQL script
2. **Token Cleanup**: Set up scheduled task to remove expired tokens
3. **Email Queue**: Consider email queue for high volume
4. **Caching**: Implement caching for repeated database queries

## API Documentation

### Forgot Password Endpoint
```http
POST /Aabha/api/forgot_password.php
Content-Type: application/json

{
    "email": "user@example.com"
}
```

#### Success Response
```json
{
    "success": true,
    "message": "Password reset link has been sent to your email address."
}
```

#### Error Response
```json
{
    "success": false,
    "message": "Invalid email format"
}
```

## Maintenance

### Regular Tasks
1. **Clean up expired tokens** (run weekly):
   ```sql
   DELETE FROM password_reset_tokens 
   WHERE expiry < DATEADD(hour, -24, GETDATE()) OR used = 1;
   ```

2. **Monitor reset attempts** (check logs monthly)
3. **Update email templates** as needed
4. **Review security settings** quarterly

## Support
For issues or questions:
1. Check error logs first
2. Verify database connections
3. Test email configuration
4. Review security settings
5. Contact system administrator

---

**Last Updated**: <?php echo date('Y-m-d H:i:s'); ?>
**Version**: 1.0.0