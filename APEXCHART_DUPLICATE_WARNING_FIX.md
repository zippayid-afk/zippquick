# Fix: Vue Apexchart Duplicate Registration Warning

## Issue
```
[Vue warn]: Component "apexchart" has already been registered in target app.
```

## Root Cause
The `apexchart` component was being registered **twice** in `/admin/resources/js/app.js`:

1. **Line 68**: `app.use(VueApexCharts)` - Plugin registration (automatically registers the component globally)
2. **Line 77**: `app.component('apexchart', VueApexCharts)` - Redundant manual registration

## Solution
Removed the duplicate `app.component('apexchart', VueApexCharts)` line since `app.use(VueApexCharts)` already registers the component globally.

### Before:
```javascript
app.use(VueApexCharts);

app.use(VueGoogleMaps, {
    load: {
        key: window.GoogleMapApiKey || window.MapApiKey || decryptedMapKey || decryptedKey,
        libraries: 'places,drawing',
    },
});

app.component('apexchart', VueApexCharts);  // ❌ Duplicate registration
app.component('AppSelect', AppSelect);
```

### After:
```javascript
app.use(VueApexCharts);

app.use(VueGoogleMaps, {
    load: {
        key: window.GoogleMapApiKey || window.MapApiKey || decryptedMapKey || decryptedKey,
        libraries: 'places,drawing',
    },
});

// Note: VueApexCharts is already registered via app.use() above, no need to register again
app.component('AppSelect', AppSelect);
```

## How Vue Plugins Work
When you call `app.use(VueApexCharts)`, the vue3-apexcharts plugin:
1. Automatically registers the `<apexchart>` component globally
2. Makes it available in all Vue components without manual import

Calling `app.component('apexchart', VueApexCharts)` again causes Vue to warn about duplicate registration.

## Files Modified
- `/admin/resources/js/app.js` - Removed duplicate component registration

## Next Steps
Rebuild your frontend assets:
```bash
cd /Volumes/Projects/Snap/Snap/admin
npm run dev
```

The warning should no longer appear in the browser console.

## Note
All existing chart components will continue to work without any changes:
- Dashboard charts
- DeliveryBoy Dashboard charts  
- Customer analytics charts
- Report charts
