/**
 * MapModule - In-app Google Maps Integration
 */
const MapModule = (() => {
    let _map;
    let _directionsService;
    let _directionsRenderer;
    let _apiKey = null;

    /**
     * Load Google Maps SDK
     */
    async function loadSDK() {
        if (window.google && window.google.maps) return;

        // Fetch API key from settings if not provided
        if (!_apiKey) {
            try {
                const response = await API.get('/admin/settings'); // Or a public settings endpoint
                const settings = response.settings || [];
                const mapSetting = settings.find(s => s.key === 'google_maps_api_key');
                _apiKey = mapSetting ? mapSetting.value : '';
            } catch (e) {
                console.error("Failed to load Maps API key", e);
            }
        }

        if (!_apiKey) {
            console.error("Google Maps API Key missing");
            return;
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${_apiKey}&libraries=places`;
            script.async = true;
            script.defer = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    /**
     * Initialize map in container
     */
    async function initMap(containerId, options = {}) {
        await loadSDK();
        
        const defaultOptions = {
            zoom: 14,
            center: { lat: 20.5937, lng: 78.9629 }, // India default
            disableDefaultUI: true,
            zoomControl: true,
        };

        _map = new google.maps.Map(document.getElementById(containerId), { ...defaultOptions, ...options });
        _directionsService = new google.maps.DirectionsService();
        _directionsRenderer = new google.maps.DirectionsRenderer({
            map: _map,
            suppressMarkers: false
        });

        return _map;
    }

    /**
     * Show route between two points
     */
    function showRoute(origin, destination) {
        if (!_directionsService) return;

        _directionsService.route({
            origin: origin,
            destination: destination,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status === google.maps.DirectionsStatus.OK) {
                _directionsRenderer.setDirections(result);
            } else {
                console.error("Directions request failed due to " + status);
            }
        });
    }

    /**
     * Add marker to map
     */
    function addMarker(position, title, icon = null) {
        return new google.maps.Marker({
            position: position,
            map: _map,
            title: title,
            icon: icon
        });
    }

    return {
        initMap,
        showRoute,
        addMarker,
        setApiKey: (key) => { _apiKey = key; }
    };
})();
