# Inventory Management System

A comprehensive PHP-based inventory management system with modern features including offline support, demand forecasting, POS integration, and smart alerts.

## Features

### Core Modules

1. **User Registration and Access Module**
   - User registration and login
   - Role-based access control (Admin, Manager, User)
   - Profile management
   - Remember me functionality

2. **Offline Support Module**
   - Offline data synchronization
   - Local caching system
   - Conflict resolution
   - Progressive web app capabilities

3. **Store Mapping & Management Module**
   - Multi-store support
   - Store hierarchy management
   - Location tracking
   - Manager assignments

4. **Demand Forecasting Module**
   - Historical sales analysis
   - Trend prediction
   - Seasonal pattern recognition
   - Reorder point calculations

5. **POS Integration Module**
   - Sales transaction recording
   - Real-time inventory updates
   - Receipt generation
   - Payment method tracking

6. **Stock Tracking Module**
   - Real-time inventory levels
   - Stock movements logging
   - Batch and expiry tracking
   - Multi-location inventory

7. **Reporting & Analytics Module**
   - Sales reports
   - Inventory reports
   - Performance analytics
   - Export functionality

8. **Smart Alert Module**
   - Low stock notifications
   - Expiry date warnings
   - Custom alert rules
   - Email notifications

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Modern web browser with JavaScript enabled

## Installation

### 1. Database Setup

1. Create a MySQL database named `inventory_system`
2. Import the database schema:
   ```sql
   mysql -u root -p inventory_system < docs/schema.sql
   ```

### 2. Configuration

1. Copy and configure the database settings in `config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'inventory_system');
   define('DB_USERNAME', 'your_username');
   define('DB_PASSWORD', 'your_password');
   ```

2. Set up file permissions:
   ```bash
   chmod 755 storage/
   chmod 755 storage/logs/
   chmod 755 storage/cache/
   chmod 755 storage/exports/
   ```

3. Configure security settings:
   - Change the `ENCRYPT_KEY` in `config.php`
   - Update email settings for notifications
   - Set up POS API credentials if applicable

### 3. Default Login

- **Username:** admin
- **Password:** admin123
- **Email:** admin@inventory.com

**Important:** Change the default admin credentials immediately after installation.

## Directory Structure

```
inventory-system/
│
├── index.php                   # Dashboard / home page
├── config.php                  # App settings (DB, API keys, constants)
├── db.php                      # Database connection
├── functions.php               # Common helper functions
│
├── modules/                    # All major modules live here
│   ├── users/                  # User Registration and Access Module
│   ├── offline/                # Offline Support Module
│   ├── stores/                 # Store Mapping & Management Module
│   ├── forecasting/            # Demand Forecasting Module
│   ├── pos/                    # POS Integration Module
│   ├── stock/                  # Stock Tracking Module
│   ├── reports/                # Reporting & Analytics Module
│   └── alerts/                 # Smart Alert Module
│
├── assets/                     # Static assets
│   ├── css/
│   ├── js/
│   └── images/
│
├── storage/                    # Logs, exports, cache
│   ├── logs/
│   ├── cache/
│   └── exports/
│
└── docs/                       # Documentation & SQL schema
    ├── schema.sql
    └── README.md
```

## Key Features

### Offline Support
- Works without internet connection
- Automatic data synchronization when online
- Conflict resolution for concurrent edits
- Local data caching

### Demand Forecasting
- Predictive analytics based on historical data
- Trend analysis and seasonal patterns
- Automatic reorder point calculations
- Visual forecast charts

### Multi-Store Management
- Centralized inventory across multiple locations
- Store-specific reporting
- Inter-store transfers
- Location-based stock tracking

### Smart Alerts
- Customizable alert rules
- Low stock notifications
- Expiry date warnings
- Email notification system

### Security Features
- Password hashing with bcrypt
- CSRF protection
- SQL injection prevention
- Session security
- Role-based access control

## API Integration

### POS System Integration
The system supports integration with external POS systems through:
- REST API endpoints
- Real-time inventory updates
- Sales data synchronization
- Transaction logging

### Configuration
Update POS settings in `config.php`:
```php
define('POS_API_ENDPOINT', 'https://your-pos-system.com/api/');
define('POS_API_KEY', 'your-api-key');
```

## Maintenance

### Regular Tasks

1. **Database Backup**
   ```bash
   mysqldump -u root -p inventory_system > backup_$(date +%Y%m%d).sql
   ```

2. **Log Rotation**
   - Logs are stored in `storage/logs/`
   - Set up log rotation to prevent disk space issues

3. **Cache Cleanup**
   ```php
   // Access via web: /modules/offline/cache_handler.php?action=cleanup
   ```

### Performance Optimization

1. Enable database query caching
2. Set up Redis for session storage
3. Use CDN for static assets
4. Optimize database indexes

## Troubleshooting

### Common Issues

1. **Database Connection Failed**
   - Check database credentials in `config.php`
   - Ensure MySQL service is running
   - Verify database exists

2. **Permission Denied Errors**
   - Check file permissions on storage directories
   - Ensure web server has write access

3. **Offline Sync Issues**
   - Clear browser cache
   - Check JavaScript console for errors
   - Verify network connectivity

### Debug Mode
Enable debug mode in `config.php` for development:
```php
define('DEBUG_MODE', true);
```

## Support

For support and questions:
- Check the troubleshooting section
- Review error logs in `storage/logs/`
- Ensure all system requirements are met

## License

This inventory management system is provided as-is for educational and commercial use. Please ensure proper security measures are in place before deploying to production.

## Security Considerations

1. **Production Deployment**
   - Disable debug mode
   - Use HTTPS
   - Regular security updates
   - Strong passwords
   - Regular backups

2. **File Permissions**
   - Restrict access to config files
   - Secure log and cache directories
   - Regular permission audits

3. **Database Security**
   - Use least privilege principle
   - Regular password updates
   - Monitor for suspicious activity

## Version History

- **v1.0.0** - Initial release with core functionality
  - User management
  - Basic inventory tracking
  - Store management
  - Offline support
  - Demand forecasting
  - Smart alerts
  - Reporting system