# 🏪 Inventory Management System

A modern, full-featured inventory management system with multi-store support, role-based permissions, and cloud database integration.

## 🌟 Key Features

### 🔐 **User Management & Permissions**
- Role-based access control (Admin, Manager, Cashier, User)
- Granular permissions system (view, add, edit, delete)
- Store-specific access control for users
- Activity logging and audit trails

### 🏬 **Multi-Store Management**
- Manage multiple store locations
- Interactive store map with geolocation
- Regional analytics dashboard
- Store-specific inventory tracking
- Performance monitoring per location

### 📦 **Inventory Management**
- Real-time stock tracking
- Product categorization
- Barcode scanning support
- Stock audit history
- Low stock alerts
- Expiry date tracking

### 💰 **Point of Sale (POS)**
- Touch-friendly POS interface
- Real-time inventory checking
- Multiple payment methods
- Receipt generation
- Sales reporting

### 📊 **Reports & Analytics**
- Sales reports
- Inventory reports
- Demand forecasting
- Regional performance analytics
- Custom date range filtering

### �️ **Store Mapping**
- Interactive Leaflet map
- Geocoding support
- Store location visualization
- Regional grouping
- Performance heatmap

## 🚀 Quick Start

### Prerequisites
- PHP 7.4 or higher
- PostgreSQL (or use Supabase cloud database)
- Composer (for dependencies)

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/tanveyhong/InventoryManagement.git
cd InventoryManagement
```

2. **Install dependencies**
```bash
composer install
```

3. **Configure database**
- Copy `config.example.php` to `config.php`
- Update database credentials in `config.php`

4. **Run database migrations**
```bash
# Import the schema
psql -U your_username -d your_database -f docs/postgresql_schema.sql
```

5. **Start the development server**
```bash
php -S localhost:8080
```

6. **Access the system**
Open your browser and navigate to:
```
http://localhost:8080
```

### Default Login Credentials
- **Username**: admin
- **Password**: (set during initial setup)

## 🗂️ Project Structure

```
InventorySystem/
├── 📁 modules/
│   ├── 📁 stock/           # Inventory management
│   ├── 📁 stores/          # Store management
│   ├── 📁 users/           # User management
│   ├── 📁 pos/             # Point of Sale
│   ├── 📁 reports/         # Reporting system
│   ├── 📁 alerts/          # Alert system
│   └── 📁 forecasting/     # Demand forecasting
├── 📁 includes/
│   ├── dashboard_header.php  # Navigation header
│   └── permission_helpers.php # Permission utilities
├── 📁 assets/
│   ├── 📁 css/            # Stylesheets
│   └── 📁 js/             # JavaScript files
├── 📁 docs/               # Documentation
├── index.php              # Main dashboard
├── config.php             # Configuration
├── functions.php          # Helper functions
├── sql_db.php            # Database class
└── README.md             # This file
```

## 🔧 Configuration

### Database Configuration (config.php)

**For Supabase (Cloud PostgreSQL):**
```php
define('PG_HOST', 'db.your-project.supabase.co');
define('PG_PORT', '5432');
define('PG_DATABASE', 'postgres');
define('PG_USERNAME', 'postgres');
define('PG_PASSWORD', 'your-password');
define('PG_SSL_MODE', 'require');
```

**For Local PostgreSQL:**
```php
define('PG_HOST', 'localhost');
define('PG_PORT', '5432');
define('PG_DATABASE', 'inventory_system');
define('PG_USERNAME', 'postgres');
define('PG_PASSWORD', 'your-password');
define('PG_SSL_MODE', 'prefer');
```

## 📋 Database Schema

### Core Tables
- `users` - User accounts and authentication
- `user_permissions` - Granular permission assignments
- `user_store_access` - Store-specific user access
- `stores` - Store locations and information
- `products` - Inventory items
- `regions` - Geographic regions for stores
- `categories` - Product categorization
- `stock_movements` - Inventory transactions
- `sales` - POS transactions
- `activities` - User activity logs

### Permission System
The system uses a granular permission model:
- `can_view_inventory`
- `can_add_inventory`
- `can_edit_inventory`
- `can_delete_inventory`
- `can_view_stores`
- `can_add_stores`
- `can_edit_stores`
- `can_delete_stores`
- `can_view_reports`
- `can_use_pos`
- `can_manage_pos`
- `can_view_users`
- `can_manage_users`
- `can_configure_system`

### Role Defaults
- **Admin**: All permissions
- **Manager**: All except system configuration
- **Cashier**: POS operations and view permissions
- **User**: View-only permissions

## 🔐 Security Features

- Password hashing with PHP password_hash()
- Session-based authentication
- CSRF protection
- SQL injection prevention (parameterized queries)
- Role-based access control
- Activity logging
- Store-level access isolation

## 🛠️ Development

### Running the Development Server
```bash
cd InventoryManagement
php -S localhost:8080
```

Then open `http://localhost:8080` in your browser.

### Database Migrations
Schema files are located in `docs/`:
- `postgresql_schema.sql` - Main database schema
- `stores_enhanced_schema.sql` - Store management extensions

### Debugging
- PHP error logs: `logs/php_errors.log`
- Activity logs: Database `activities` table
- Enable debug mode in `config.php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📦 Dependencies

- **PHP Extensions**: PDO, PDO_PGSQL, JSON, MBString
- **Frontend**: Font Awesome 6, Leaflet.js (for maps)
- **Database**: PostgreSQL 12+

## 🌍 Browser Support

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## 📝 License

This project is licensed under the MIT License.

## 👥 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📞 Support

For issues and questions:
- Open an issue on GitHub
- Contact: [Your contact information]

## 🚧 Roadmap

- [ ] Mobile app integration
- [ ] API documentation
- [ ] Advanced analytics dashboard
- [ ] Multi-currency support
- [ ] Integration with accounting software
- [ ] Automated reordering system

---

**Note**: This system uses cloud PostgreSQL (Supabase) by default. No local PostgreSQL installation required!
- `synced_at` - Last sync timestamp
- `source_store` - Original store identifier
- `sync_status` - Synchronization status

### Sync Queue Structure
```json
{
  "id": "sync_unique_id",
  "sql": "INSERT INTO products...",
  "params": [...],
  "timestamp": 1640995200,
  "store_id": "STORE_001"
}
```

## 🔄 Synchronization Process

### 1. **Local Operations**
- All CRUD operations happen on local SQLite first
- Operations are queued for sync automatically
- Immediate response to users (no network delays)

### 2. **Background Sync**
- Automatic sync based on time intervals or queue size
- Push local changes to central server
- Pull updates from central server
- Conflict resolution using configurable strategies

### 3. **Conflict Resolution**
- **Last Write Wins** - Default strategy
- **Store Priority** - Configurable store priorities
- **Manual Review** - Flag conflicts for review

## 📊 POS Terminal Features

### Product Search
- Real-time search by name, SKU, or barcode
- Product suggestions and autocomplete
- Stock level validation
- Category filtering

### Shopping Cart
- Add/remove items with quantity controls
- Apply discounts (percentage or fixed amount)
- Tax calculations (configurable rates)
- Customer information capture

### Payment Processing
- Multiple payment methods (cash, card, mobile)
- Receipt generation
- Transaction history
- Refund processing

### Offline Operation
- Works completely offline
- All data stored locally
- Sync when connection restored
- Queue status indicators

## 🎛️ Sync Dashboard

### Real-time Monitoring
- Connection status indicators
- Sync queue size and age
- Database statistics
- Recent sync logs

### Manual Controls
- Force immediate sync
- Test central connection
- Clear sync queue
- View detailed statistics

### Table Synchronization
- Per-table sync status
- Record counts and percentages
- Conflict indicators
- Last sync timestamps

## 🔧 Advanced Configuration

### Environment-specific Settings
```php
// Development
$config['development'] = [
    'primary' => ['driver' => 'sqlite', 'path' => 'database/dev.db'],
    'secondary' => null  // No central server in dev
];

// Production
$config['production'] = [
    'primary' => ['driver' => 'sqlite', 'path' => 'database/store_001.db'],
    'secondary' => [
        'driver' => 'pgsql',
        'host' => 'central.company.com',
        'database' => 'inventory_central'
    ]
];
```

### Redis Configuration (Optional)
```php
define('REDIS_ENABLED', true);
define('REDIS_HOST', 'localhost');
define('REDIS_PORT', 6379);
define('REDIS_PREFIX', 'inventory:');
```

### Sync Strategies
```php
// Conflict resolution strategies
define('CONFLICT_RESOLUTION', 'last_write_wins'); // last_write_wins, store_priority, manual
define('STORE_PRIORITY', 1);                      // Higher number = higher priority
define('ENABLE_CONFLICT_LOGGING', true);          // Log all conflicts
```

## 🔍 Monitoring & Logging

### Sync Logs
- All sync operations logged with timestamps
- Error tracking and debugging information
- Performance metrics and statistics
- Configurable log levels

### Database Monitoring
- Connection status tracking
- Query performance monitoring
- Storage usage statistics
- Automated health checks

## 🛡️ Security Features

### Database Security
- Prepared statements prevent SQL injection
- Connection encryption (TLS/SSL)
- Access control and permissions
- Audit logging for all operations

### Authentication
- Session-based authentication
- Password hashing (bcrypt)
- Role-based access control
- Session timeout handling

## 🚀 Performance Optimizations

### Local Database
- SQLite WAL mode for better concurrency
- Optimized indexes for common queries
- Connection pooling and reuse
- Query result caching

### Synchronization
- Batch processing for efficiency
- Compression for network transfer
- Delta sync (only changed records)
- Retry mechanisms with backoff

## 📈 Scaling Considerations

### Multi-Store Deployment
- One local database per store/terminal
- Central server handles aggregation
- Load balancing for high availability
- Horizontal scaling with sharding

### High Availability
- Database replication and failover
- Redis clustering for pub/sub
- Load balancer health checks
- Automated backup and recovery

## 🔧 CLI Tools

### Sync Management
```bash
# Perform full synchronization
php sync_manager.php full-sync

# Check sync statistics
php sync_manager.php stats

# Test central connection
php sync_manager.php test-connection

# View recent logs
php sync_manager.php logs
```

### Database Operations
```bash
# Explore local database
php database/explore.php

# Initialize new store database
php database/setup.php --store STORE_002

# Backup local database
php database/backup.php --output backups/
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For support and questions:
- Check the documentation
- Review sync logs in `/logs/sync.log`
- Use the web-based database explorer
- Monitor the sync dashboard

## 🎯 Future Enhancements

- [ ] Mobile app for inventory management
- [ ] Barcode generation and printing
- [ ] Advanced analytics and reporting
- [ ] Integration with accounting systems
- [ ] Machine learning for demand forecasting
- [ ] Multi-currency support
- [ ] Advanced user permissions
- [ ] API endpoints for third-party integration

---

**Built with ❤️ for modern retail operations**#   I n v e n t o r y M a n a g e m e n t 
 

 
