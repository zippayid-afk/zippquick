/**
 * API Tester Utility for Address Dropdown Debugging
 * 
 * Usage in browser console:
 * 1. Paste this entire file into browser console, OR
 * 2. Include as a script in development, OR
 * 3. Call individual test functions
 * 
 * Then call: window.apiTester.runAllTests()
 */

const apiTester = {
  baseUrl: window.baseUrl || 'http://localhost:8000',
  apiUrl: (window.baseUrl || 'http://localhost:8000') + '/api',
  
  log: function(message, data = null, type = 'log') {
    const styles = {
      log: 'color: #0066cc; font-weight: bold;',
      success: 'color: #22863a; font-weight: bold; background: #f0f8f4; padding: 2px 6px;',
      error: 'color: #cb2431; font-weight: bold; background: #ffeef0; padding: 2px 6px;',
      warning: 'color: #e36209; font-weight: bold; background: #fff8e5; padding: 2px 6px;',
      info: 'color: #6f42c1; font-weight: bold;'
    };
    
    console[type === 'error' ? 'error' : 'log'](`%c${message}`, styles[type] || styles.log);
    if (data) {
      console.log(data);
    }
  },
  
  testMapProvider: async function() {
    this.log('Testing Map Provider Detection...', null, 'info');
    
    try {
      const response = await fetch(`${this.apiUrl}/store_settings`);
      const json = await response.json();
      
      if (response.status !== 200) {
        this.log('❌ Failed to fetch store settings', json, 'error');
        return false;
      }
      
      const settings = json.data?.store_settings || [];
      const getVal = (key) => (settings.find(s => s.variable === key) || {}).value || 'NOT_SET';
      
      const mapProvider = getVal('map_provider');
      const googleKey = getVal('google_place_api_key');
      const googleMapKey = getVal('google_map_api_key');
      
      this.log('✅ Store Settings Retrieved:', {
        mapProvider,
        googlePlaceApiKey: googleKey ? `${googleKey.substring(0, 10)}...` : 'NOT_SET',
        googleMapApiKey: googleMapKey ? `${googleMapKey.substring(0, 10)}...` : 'NOT_SET'
      }, 'success');
      
      if (!mapProvider || mapProvider === 'osm') {
        this.log('ℹ️  Using OpenStreetMap provider (no API key needed)', null, 'info');
        return true;
      }
      
      if (mapProvider === 'google') {
        if (!googleKey && !googleMapKey) {
          this.log('❌ Map provider is Google but no API key is configured!', null, 'error');
          return false;
        }
        this.log('✅ Google Maps provider with valid key', null, 'success');
        return true;
      }
      
      return true;
    } catch (error) {
      this.log('❌ Error testing map provider', error.message, 'error');
      return false;
    }
  },
  
  testPlacesAutocomplete: async function(searchTerm = 'New York') {
    this.log(`Testing Places Autocomplete with search term: "${searchTerm}"`, null, 'info');
    
    try {
      const url = `${this.apiUrl}/maps/places_autocomplete?input=${encodeURIComponent(searchTerm)}&source=web`;
      this.log('Request URL:', url);
      
      const response = await fetch(url);
      const json = await response.json();
      
      if (response.status !== 200) {
        this.log(`❌ HTTP Error ${response.status}`, json, 'error');
        return false;
      }
      
      if (json.status === 0) {
        this.log('❌ API returned error:', json.message, 'error');
        return false;
      }
      
      const suggestions = json.data?.suggestions || [];
      
      if (suggestions.length === 0) {
        this.log('⚠️  API returned no suggestions (could be normal for some searches)', {
          status: json.data?.status,
          provider: json.data?.provider
        }, 'warning');
        return true;
      }
      
      this.log(`✅ Autocomplete working! Found ${suggestions.length} suggestions:`, 
        suggestions.slice(0, 3).map(s => ({
          placeId: s.placePrediction?.placeId,
          text: s.placePrediction?.text?.text
        })), 
        'success'
      );
      
      return suggestions.length > 0;
    } catch (error) {
      this.log('❌ Error testing places autocomplete', error.message, 'error');
      return false;
    }
  },
  
  testPlacesDetails: async function(placeId = 'ChIJOwg_06VPwokR4/nsz6q7-z8') {
    this.log(`Testing Places Details with place_id: "${placeId}"`, null, 'info');
    
    // First get a valid place ID from autocomplete
    try {
      const autoResponse = await fetch(
        `${this.apiUrl}/maps/places_autocomplete?input=london&source=web`
      );
      const autoJson = await autoResponse.json();
      const suggestions = autoJson.data?.suggestions || [];
      
      if (suggestions.length > 0) {
        placeId = suggestions[0].placePrediction?.placeId;
        this.log('Using place_id from autocomplete:', placeId);
      }
    } catch (e) {
      this.log('Could not fetch place ID, using default', null, 'warning');
    }
    
    try {
      const url = `${this.apiUrl}/maps/places_details?place_id=${encodeURIComponent(placeId)}&source=web`;
      this.log('Request URL:', url);
      
      const response = await fetch(url);
      const json = await response.json();
      
      if (response.status !== 200) {
        this.log(`❌ HTTP Error ${response.status}`, json, 'error');
        return false;
      }
      
      if (json.status === 0) {
        this.log('❌ API returned error:', json.message, 'error');
        return false;
      }
      
      const data = json.data || {};
      
      this.log('✅ Places Details working!', {
        formattedAddress: data.formattedAddress,
        latitude: data.location?.latitude,
        longitude: data.location?.longitude,
        types: data.types,
        addressComponents: data.addressComponents?.length || 0
      }, 'success');
      
      return true;
    } catch (error) {
      this.log('❌ Error testing places details', error.message, 'error');
      return false;
    }
  },
  
  testGeocoding: async function(latitude = 40.7128, longitude = -74.0060) {
    this.log(`Testing Geocoding with lat: ${latitude}, lng: ${longitude}`, null, 'info');
    
    try {
      const url = `${this.apiUrl}/maps/geocoding?latitude=${latitude}&longitude=${longitude}&source=web`;
      this.log('Request URL:', url);
      
      const response = await fetch(url);
      const json = await response.json();
      
      if (response.status !== 200) {
        this.log(`❌ HTTP Error ${response.status}`, json, 'error');
        return false;
      }
      
      if (json.status === 0) {
        this.log('❌ API returned error:', json.message, 'error');
        return false;
      }
      
      const results = json.data?.results || [];
      
      this.log(`✅ Geocoding working! Found ${results.length} results:`, 
        results.slice(0, 2).map(r => ({
          formatted_address: r.formatted_address,
          types: r.types
        })),
        'success'
      );
      
      return true;
    } catch (error) {
      this.log('❌ Error testing geocoding', error.message, 'error');
      return false;
    }
  },
  
  testBoundaryMapComponent: function() {
    this.log('Testing BoundaryMap Component Setup...', null, 'info');
    
    const checks = {
      window_baseUrl: !!window.baseUrl,
      window_axios: !!window.axios,
      leaflet_available: typeof L !== 'undefined',
      google_maps_global: typeof google !== 'undefined' && typeof google.maps !== 'undefined'
    };
    
    this.log('✅ Component Setup Check:', checks, 'success');
    
    return Object.values(checks).every(v => v) || Object.values(checks).filter(v => v).length >= 2;
  },
  
  runAllTests: async function() {
    this.log('=' . repeat(50), null, 'info');
    this.log('🧪 RUNNING ALL API TESTS', null, 'info');
    this.log('=' . repeat(50), null, 'info');
    
    const results = {
      'Map Provider': await this.testMapProvider(),
      'Component Setup': this.testBoundaryMapComponent(),
      'Places Autocomplete': await this.testPlacesAutocomplete(),
      'Places Details': await this.testPlacesDetails(),
      'Geocoding': await this.testGeocoding()
    };
    
    this.log('=' . repeat(50), null, 'info');
    this.log('📊 TEST SUMMARY:', results, 'info');
    
    const passed = Object.values(results).filter(r => r).length;
    const total = Object.keys(results).length;
    
    if (passed === total) {
      this.log(`✅ ALL TESTS PASSED (${passed}/${total})`, null, 'success');
    } else if (passed > 0) {
      this.log(`⚠️  PARTIAL SUCCESS (${passed}/${total} tests passed)`, null, 'warning');
    } else {
      this.log(`❌ ALL TESTS FAILED`, null, 'error');
    }
    
    this.log('=' . repeat(50), null, 'info');
    return results;
  }
};

// Make available globally
window.apiTester = apiTester;

// Print usage instructions
console.log('%c🧪 API Tester Available', 'color: #6f42c1; font-size: 14px; font-weight: bold;');
console.log('Usage:');
console.log('  window.apiTester.runAllTests()              - Run all tests');
console.log('  window.apiTester.testMapProvider()          - Test map provider config');
console.log('  window.apiTester.testPlacesAutocomplete()   - Test autocomplete API');
console.log('  window.apiTester.testPlacesDetails()        - Test place details API');
console.log('  window.apiTester.testGeocoding()            - Test geocoding API');
console.log('  window.apiTester.testBoundaryMapComponent() - Test component setup');

console.log('%cℹ️ Tip: Run this in the browser console on the Zone form page', 'color: #e36209; font-size: 12px;');
