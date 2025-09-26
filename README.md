# 🏪 Hybrid Inventory Management System

## Architecture Overview

This system implements a **hybrid database architecture** combining the best of both worlds:

- **Local SQLite Database** - For fast, offline-capable POS operations
- **Central PostgreSQL/MySQL Server** - For centralized management, analytics, and multi-store coordination
- **Redis Cache** - For real-time synchronization and pub/sub messaging

## 🌟 Key Features

### ✅ **Offline POS Capability**
- Full point-of-sale operations work without internet connection
- Local SQLite database stores all transactions
- Automatic sync when connection is restored
- No downtime during network outages

### 🔄 **Intelligent Synchronization**
- Automatic background sync between local and central databases
- Conflict resolution with configurable strategies
- Queue-based sync with retry mechanisms
- Real-time status monitoring

### 🏬 **Multi-Store Ready**
- Each store/terminal has its own local database
- Central server aggregates data from all locations
- Store-specific configurations and permissions
- Cross-store inventory visibility

### 📱 **Modern POS Interface**
- Touch-friendly interface optimized for tablets
- Barcode scanning support
- Real-time inventory checking
- Customer management
- Multiple payment methods

## 🗂️ Directory Structure

```
InventorySystem/
├── 📁 assets/
│   ├── 📁 css/
│   │   └── style.css          # Main stylesheet
│   └── 📁 js/
│       └── main.js           # JavaScript functionality
├── 📁 database/
│   ├── inventory.db          # Local SQLite database
│   ├── setup.php            # Database initialization
│   ├── explore.php          # CLI database explorer
│   └── web_explorer.php     # Web database explorer
├── 📁 modules/
│   ├── 📁 stock/
│   │   ├── list.php         # Product listing
│   │   ├── add.php          # Add new products
│   │   ├── view.php         # Product details
│   │   └── delete.php       # Delete products
│   └── 📁 users/
│       └── login.php        # User authentication
├── 📁 data/                 # Sync queue and temp files
├── 📁 logs/                 # System and sync logs
├── index.php               # Main dashboard
├── pos_terminal.php        # Point of Sale interface
├── sync_dashboard.php      # Hybrid database manager
├── hybrid_config.php       # Configuration file
├── hybrid_db.php          # Database abstraction layer
├── sync_manager.php       # Synchronization engine
├── config.php             # Database configuration
├── db.php                 # Database connection
└── functions.php          # Helper functions
```

## ⚙️ Configuration

### Environment Detection
The system automatically detects the environment:
```php
define('ENVIRONMENT', 'development'); // development, staging, production
define('CURRENT_MODE', 'hybrid');     // local, central, hybrid
```

### Store Configuration
```php
define('STORE_ID', 'STORE_001');           // Unique store identifier
define('CENTRAL_AVAILABLE', true);         // Central server availability
define('SYNC_ENABLED', true);             // Enable sync functionality
```

### Sync Settings
```php
define('AUTO_SYNC_ENABLED', true);         // Auto sync enabled
define('AUTO_SYNC_INTERVAL', 300);         // Sync every 5 minutes
define('SYNC_BATCH_SIZE', 100);           // Records per batch
define('SYNC_QUEUE_MAX_SIZE', 1000);      // Maximum queue size
```

## 🚀 Getting Started

### 1. Installation
```bash
# Clone or download the project
cd InventorySystem

# Install dependencies (if any)
# composer install  # (if using Composer)

# Make directories writable
chmod 755 database/ data/ logs/
```

### 2. Database Setup
```bash
# Initialize local SQLite database
php database/setup.php

# Or use the web interface
http://localhost:8000/database/setup.php
```

### 3. Start Development Server
```bash
php -S localhost:8000
```

### 4. Access the System
- **Main Dashboard**: http://localhost:8000/
- **POS Terminal**: http://localhost:8000/pos_terminal.php
- **Sync Manager**: http://localhost:8000/sync_dashboard.php
- **Database Explorer**: http://localhost:8000/database/web_explorer.php

### 5. Default Login
- **Username**: admin
- **Password**: admin123

## 🔧 Database Architecture

### Local SQLite Database
```sql
-- Core tables for local operations
users           -- User accounts and roles
stores          -- Store information
categories      -- Product categories
products        -- Inventory items
stock_movements -- Inventory transactions
transactions    -- POS sales
transaction_items -- Sale line items
```

### Central Database Schema
The central PostgreSQL/MySQL database mirrors the local structure with additional fields:
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

**Built with ❤️ for modern retail operations**#   I n v e n t o r y M a n a g e m e n t  
 