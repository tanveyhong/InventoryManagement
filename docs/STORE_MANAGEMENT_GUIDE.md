# Enhanced Store Management System

## Overview
The inventory system includes a comprehensive store management module with advanced OpenStreetMap integration for mapping, analytics, and inventory management.

## Available Modules

### 1. Store List (`list.php`)
- Comprehensive store directory with search and pagination
- Product count and stock totals for each store
- Quick actions for editing and viewing store details

### 2. Interactive Store Map (`enhanced_map.php`)
- Professional OpenStreetMap integration with Leaflet.js
- Custom markers with automatic clustering
- Interactive popups with store information
- Multiple map layers (street view, satellite imagery)
- Real-time filtering synchronized with store listings
- **Zero Configuration** - works immediately without API keys

### 3. Store Inventory Viewer (`inventory_viewer.php`)
- Real-time inventory tracking per store
- Advanced filtering and search capabilities
- Stock status indicators (In Stock, Low Stock, Out of Stock)
- Expiry date tracking and alerts
- Export functionality for reports
- Product quantity adjustments

### 4. Store Profile Manager (`profile.php`)
- Comprehensive store analytics dashboard
- Staff management and shift logging
- Performance metrics and charts
- Low stock alerts and notifications
- Store settings and configuration
- Financial performance tracking

### 5. Regional Dashboard (`regional_dashboard.php`)
- Multi-region comparative analytics
- Performance benchmarking across stores
- Regional sales and inventory trends
- Interactive charts and visualizations
- Export capabilities for regional reports

## Key Features

### Interactive Mapping
- **Two mapping options** to suit different needs and preferences
- Real-time store location visualization
- Custom markers indicating store status and type
- Synchronized filtering between map and store listings
- Responsive design for mobile and desktop

### Advanced Analytics
- Store performance metrics with trend analysis
- Inventory turnover and reorder point calculations
- Regional comparative analytics
- Financial performance tracking
- Low stock alert system

### Real-Time Data
- Live inventory status updates
- Real-time filtering and search
- Dynamic chart updates
- Instant notification system

### Professional UI/UX
- Modern CSS Grid layouts
- Responsive design for all devices
- Interactive elements with smooth transitions
- Professional color schemes and typography
- Accessible design principles

## Database Schema

The system includes enhanced database tables:

- `stores` - Store information and settings
- `regions` - Regional organization
- `products` - Product inventory per store
- `store_performance` - Performance metrics
- `store_staff` - Staff management
- `store_alerts` - Notification system
- `shift_logs` - Staff shift tracking

## API Endpoints

- `api/get_stores_with_location.php` - Store data with coordinates
- `api/get_inventory.php` - Real-time inventory data
- `api/get_performance.php` - Store performance metrics
- `api/get_regional_analytics.php` - Regional comparison data

## Setup Instructions

### Quick Start (OpenStreetMap)
1. Navigate to `modules/stores/openstreetmap.php`
2. System works immediately without configuration
3. Enjoy free, privacy-friendly mapping

### Google Maps Setup
1. Follow instructions in `docs/GOOGLE_MAPS_SETUP.md`
2. Obtain Google Maps API key
3. Replace `YOUR_API_KEY` in `enhanced_map.php`
4. Enable required APIs in Google Cloud Console

### Database Initialization
The system automatically creates and upgrades database tables using the `upgradeDatabase()` method in `sql_db.php`.

## Technical Stack

- **Backend:** PHP 7.4+ with PDO
- **Database:** SQLite with automatic schema upgrades
- **Frontend:** Modern HTML5, CSS3, JavaScript ES6+
- **Mapping:** Google Maps API / Leaflet.js + OpenStreetMap
- **Charts:** Chart.js for analytics visualization
- **UI Framework:** Custom responsive CSS Grid system

## Browser Compatibility

- Chrome 70+
- Firefox 65+
- Safari 12+
- Edge 79+
- Mobile browsers (iOS Safari, Chrome Mobile)

## Security Features

- Session-based authentication
- SQL injection prevention with PDO prepared statements
- XSS protection with proper HTML escaping
- CSRF protection on forms
- Secure file upload handling
- Input validation and sanitization

## Performance Optimizations

- Efficient database queries with proper indexing
- Lazy loading for large datasets
- Debounced search functionality
- Optimized marker clustering for maps
- Compressed CSS and JavaScript assets
- Proper caching headers

## Future Enhancements

- Multi-language support
- Advanced role-based permissions
- Email notification system
- Mobile app integration
- Advanced reporting with PDF export
- Barcode scanning integration
- Advanced forecasting algorithms

## Support

For setup assistance or technical questions:
1. Check `docs/GOOGLE_MAPS_SETUP.md` for mapping setup
2. Review database logs in `storage/logs/errors.log`
3. Test with demo data using the built-in sample stores
4. Verify PHP version and required extensions

## License

This enhanced store management system is part of the inventory management solution and follows the same licensing terms as the main application.