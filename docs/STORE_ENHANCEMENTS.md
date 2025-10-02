# Store Management Module Enhancements

## Overview
The Store Management module has been significantly enhanced with advanced features for adding stores and visualizing store locations on an interactive map.

## Enhanced Features

### 1. Add Store Enhancements (`add.php`)

#### New Fields Added:
- **Store Type**: Select from Retail, Warehouse, Distribution Center, Flagship, or Outlet
- **Region Assignment**: Link stores to regions for better organization and reporting
- **Location Coordinates**: Latitude and longitude for precise mapping
- **Operating Hours**: Opening and closing times
- **Store Metrics**:
  - Square Footage: Store area in square feet
  - Maximum Capacity: Customer capacity limits

#### Geocoding Integration:
- **Automatic Address Geocoding**: Convert addresses to GPS coordinates using OpenStreetMap's Nominatim API
- **Interactive Map Preview**: Visual confirmation of store location before saving
- **Manual Coordinate Entry**: Option to manually input coordinates
- **Click-to-Place**: Click anywhere on the preview map to set coordinates

#### How to Use Geocoding:
1. Fill in the address, city, state, and zip code fields
2. Click the "Find Coordinates" button
3. The system will automatically populate latitude and longitude
4. A map preview will appear showing the exact location
5. Optionally click on the map to adjust the marker position
6. Submit the form to save the store with location data

### 2. Enhanced Interactive Map (`map.php`)

#### Key Features:

##### Real-Time Map Visualization:
- **OpenStreetMap Integration**: Uses Leaflet.js for interactive mapping
- **Color-Coded Markers**: Different colors for each store type
  - Blue: Retail Store
  - Green: Warehouse
  - Orange: Distribution Center
  - Pink: Flagship Store
  - Purple: Outlet
- **Custom Icons**: Font Awesome store icons on each marker
- **Popup Information**: Click markers to view detailed store information

##### Marker Clustering:
- **Smart Clustering**: Groups nearby stores when zoomed out
- **Toggle On/Off**: Enable or disable clustering with a button
- **Performance Optimized**: Handles hundreds of stores efficiently
- **Spiderfy Effect**: Automatically spreads clustered markers when clicked

##### Advanced Filtering:
- **Search Bar**: Search stores by name, city, state, or region
- **Region Filter**: Filter by specific regions
- **Store Type Filter**: Show only specific store types
- **Real-Time Updates**: Map and list update instantly when filters are applied
- **Auto-Zoom**: Map automatically zooms to show filtered results

##### Statistics Dashboard:
- **Total Stores**: Count of all stores in the system
- **Active Stores**: Currently operational stores
- **Mapped Stores**: Stores with GPS coordinates
- **Region Count**: Number of regions configured

##### Store Directory:
- **Grid View**: Card-based layout showing all stores
- **Click-to-Navigate**: Click any store card to view its location on the map
- **Quick Actions**: Direct links to view profile, edit, or view inventory
- **Store Type Badges**: Visual indicators for store types

#### User Interface Improvements:
- **Modern Design**: Gradient backgrounds and smooth animations
- **Responsive Layout**: Works on desktop, tablet, and mobile devices
- **Hover Effects**: Interactive elements with visual feedback
- **Color-Coded Legend**: Clear visual guide for store types

### 3. New API Endpoints

#### `/modules/stores/api/geocode.php`
**Purpose**: Convert addresses to GPS coordinates

**Method**: POST

**Request Body**:
```json
{
  "address": "123 Main Street",
  "city": "New York",
  "state": "NY",
  "zip_code": "10001"
}
```

**Response**:
```json
{
  "success": true,
  "latitude": "40.7128",
  "longitude": "-74.0060",
  "display_name": "123 Main Street, New York, NY 10001, USA"
}
```

#### `/modules/stores/api/nearby_stores.php`
**Purpose**: Find stores within a radius of a location

**Method**: GET

**Parameters**:
- `latitude`: Center latitude (required)
- `longitude`: Center longitude (required)
- `radius`: Search radius in kilometers (default: 50)

**Example**:
```
GET /modules/stores/api/nearby_stores.php?latitude=40.7128&longitude=-74.0060&radius=25
```

**Response**:
```json
{
  "success": true,
  "stores": [
    {
      "id": "store123",
      "name": "Downtown Store",
      "latitude": "40.7100",
      "longitude": "-74.0050",
      "distance": 2.34
    }
  ],
  "count": 1,
  "radius": 25,
  "center": {
    "latitude": 40.7128,
    "longitude": -74.0060
  }
}
```

#### `/modules/stores/api/statistics.php`
**Purpose**: Get aggregated store statistics

**Method**: GET

**Response**:
```json
{
  "success": true,
  "statistics": {
    "total_stores": 50,
    "active_stores": 48,
    "inactive_stores": 2,
    "stores_with_location": 45,
    "stores_without_location": 5,
    "total_regions": 5,
    "total_square_footage": 250000,
    "total_capacity": 5000,
    "average_square_footage": 5000,
    "average_capacity": 100
  },
  "by_type": {
    "retail": 30,
    "warehouse": 10,
    "distribution": 5,
    "flagship": 3,
    "outlet": 2
  },
  "by_region": {
    "region1": 10,
    "region2": 15
  },
  "by_state": {
    "NY": 20,
    "CA": 15,
    "TX": 15
  }
}
```

## Database Schema Updates

The following fields have been added to the `stores` collection:

```
- latitude: float (GPS latitude)
- longitude: float (GPS longitude)
- store_type: string (retail, warehouse, distribution, flagship, outlet)
- region_id: string (reference to regions collection)
- opening_hours: string (HH:MM format)
- closing_hours: string (HH:MM format)
- square_footage: integer (store area in sq ft)
- max_capacity: integer (maximum customer capacity)
- status: string (active, inactive)
```

## Technology Stack

### Frontend:
- **Leaflet.js 1.9.4**: Interactive mapping library
- **Leaflet.markercluster**: Marker clustering plugin
- **Font Awesome 6.0**: Icon library
- **Vanilla JavaScript**: No framework dependencies

### Backend:
- **PHP**: Server-side processing
- **Firebase Realtime Database**: Data storage
- **Nominatim API**: OpenStreetMap geocoding service

## Performance Considerations

1. **Efficient Data Loading**: All store data is loaded once and cached in JavaScript
2. **Marker Clustering**: Prevents map slowdown with many markers
3. **Lazy Map Initialization**: Map only loads when the page is accessed
4. **Optimized Filters**: Client-side filtering for instant results

## Security Features

1. **Authentication Required**: All pages require user login
2. **Session Validation**: Server-side session checks
3. **Input Sanitization**: All user inputs are sanitized
4. **API Rate Limiting**: Geocoding respects Nominatim rate limits

## Browser Compatibility

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile browsers (iOS Safari, Chrome Mobile)

## Future Enhancements

Potential improvements for future versions:

1. **Heatmaps**: Visualize store density or performance metrics
2. **Routing**: Calculate distances and routes between stores
3. **Geofencing**: Alert-based location monitoring
4. **Store Images**: Add photo galleries to store profiles
5. **Advanced Analytics**: Store performance overlays on map
6. **Offline Support**: Progressive Web App with offline map caching
7. **Real-Time Updates**: WebSocket integration for live store updates
8. **Export Features**: Export map as PDF or image
9. **Custom Map Styles**: Theme customization
10. **Integration**: Connect with Google Maps, Mapbox, or other mapping services

## Troubleshooting

### Common Issues:

**Map not loading:**
- Check internet connection (requires external CDN access)
- Verify Leaflet.js CDN is accessible
- Check browser console for JavaScript errors

**Geocoding not working:**
- Verify address format is correct
- Check Nominatim API rate limits (max 1 request per second)
- Ensure User-Agent header is set

**Markers not appearing:**
- Verify stores have valid latitude/longitude values
- Check data format (must be numbers, not strings)
- Ensure stores are marked as active

**Clustering issues:**
- Refresh the page
- Try toggling clustering off and on
- Check that MarkerCluster plugin is loaded

## Usage Tips

1. **Add coordinates to existing stores**: Edit each store and use the geocoding feature
2. **Organize by regions**: Create regions first, then assign stores
3. **Use store types**: Properly categorize stores for better filtering
4. **Regular updates**: Keep operating hours and contact info current
5. **Test locations**: Always verify geocoded locations on the preview map

## Support

For issues or questions, please refer to:
- Main documentation: `/docs/README.md`
- Store management guide: `/docs/STORE_MANAGEMENT_GUIDE.md`
- Store mapping guide: `/docs/STORE_MAPPING_GUIDE.md`

---

**Version**: 2.0  
**Last Updated**: October 2, 2025  
**Author**: Inventory System Development Team
