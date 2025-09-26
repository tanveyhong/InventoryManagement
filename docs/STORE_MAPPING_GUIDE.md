# Store Mapping Setup Guide

## OpenStreetMap Integration

This inventory system uses **OpenStreetMap** with Leaflet.js for displaying store locations interactively. The mapping system is completely free and requires no API keys or external accounts.

## Features

✅ **Zero Configuration** - Works immediately after installation
✅ **Completely Free** - No API keys, no usage limits
✅ **Privacy Friendly** - No third-party tracking
✅ **Professional Features** - Interactive markers, clustering, multiple map layers
✅ **Mobile Responsive** - Works perfectly on all devices
✅ **Custom Styling** - Fully customizable markers and interface

## Quick Start

1. Navigate to `modules/stores/enhanced_map.php`
2. The system works immediately - no setup required!
3. Add store locations through the store management interface
4. View interactive maps with clustering and filtering

## Map Features

### Interactive Elements
- **Custom Markers** - Color-coded by store status (Active: Green, Inactive: Red, Warehouse: Yellow)
- **Info Popups** - Click markers to see store details, manager info, and quick actions
- **Real-time Filtering** - Filter by region, status, type, or search terms
- **Synchronized Display** - Map markers and store cards update together

### Map Controls
- **Multiple Layers** - Switch between street view and satellite imagery
- **Fit All Markers** - Auto-zoom to show all store locations
- **Zoom Controls** - Standard map navigation
- **Scale Display** - Distance reference for geographic context

### Advanced Features
- **Marker Clustering** - Automatic grouping for better performance with many stores
- **Responsive Design** - Optimized for desktop, tablet, and mobile
- **Real-time Search** - Instant filtering as you type
- **Export Ready** - Easy integration with reporting systems

## Technical Details

### Technology Stack
- **Mapping Library:** Leaflet.js (Open Source)
- **Map Tiles:** OpenStreetMap (Free)
- **Satellite Imagery:** Esri World Imagery
- **Database:** SQLite with coordinate storage
- **API:** RESTful endpoints for real-time data

### Performance
- Optimized for fast loading with large datasets
- Efficient marker clustering for hundreds of stores
- Debounced search to prevent excessive API calls
- Cached map tiles for better performance

### Step 1: Get a Google Maps API Key

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the Google Maps JavaScript API
4. Go to "Credentials" and create an API key
5. Restrict the API key to your domain for security

### Step 2: Configure the API Key

1. Open `modules/stores/enhanced_map.php`
2. Find this line:
   ```html
   <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=geometry,places" async defer></script>
   ```
3. Replace `YOUR_API_KEY` with your actual Google Maps API key

### Step 3: Features Included

✅ **Interactive Map Display**
- Multiple map types (Hybrid, Roadmap, Satellite)
- Custom markers based on store status and type
- Responsive map controls

✅ **Store Markers**
- Color-coded by status (Active=Green, Inactive=Red, Warehouse=Yellow)
- Custom info windows with store details
- Click to view store information

✅ **Advanced Features**
- Marker clustering for better performance
- Auto-fit bounds to show all stores
- Real-time filtering updates map markers
- Synchronized with store card highlighting

✅ **Interactive Elements**
- Click markers to see store details
- Links to store profile and inventory pages
- Map controls for different views
- Clustering toggle for large datasets

### Alternative: OpenStreetMap Integration

If you prefer not to use Google Maps, you can integrate OpenStreetMap with Leaflet:

1. Replace the Google Maps script with:
   ```html
   <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
   <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
   ```

2. The system will automatically fallback to a simplified display if Google Maps is not available.

### Security Notes

- Always restrict your API key to your domain
- Monitor your API usage in Google Cloud Console
- Consider setting up billing alerts
- Use environment variables for API keys in production

### Troubleshooting

**Map not loading?**
- Check browser console for API errors
- Verify API key is correct
- Ensure Google Maps JavaScript API is enabled
- Check if there are any billing issues

**Markers not appearing?**
- Verify store data includes latitude/longitude
- Check console for JavaScript errors
- Ensure coordinates are valid numbers

**Performance issues?**
- Enable marker clustering for large datasets
- Consider implementing pagination for very large store lists
- Optimize image assets and reduce marker complexity

### Sample Usage

The map will automatically:
1. Load all active stores from your database
2. Display them as colored markers based on status
3. Show info windows when markers are clicked
4. Update in real-time as you apply filters
5. Highlight corresponding store cards when markers are clicked

### Support

If you need help setting up the Google Maps integration:
1. Check the browser console for errors
2. Verify your API key configuration
3. Test with the demo data first
4. Review Google Maps API documentation

The enhanced mapping system provides a professional, interactive way to visualize your store network with real-time filtering and detailed store information.