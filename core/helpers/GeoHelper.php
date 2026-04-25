<?php

/**
 * GeoHelper - Geospatial utilities for distance calculation
 */
class GeoHelper {
    
    /**
     * Calculate distance between two points using Haversine formula
     * 
     * @param float $lat1 Latitude of point 1
     * @param float $lon1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lon2 Longitude of point 2
     * @param string $unit Unit of distance (km, m, mi)
     * @return float Distance in specified unit
     */
    public static function calculateDistance($lat1, $lon1, $lat2, $lon2, $unit = 'km') {
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        }
        
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == "KM") {
            return ($miles * 1.609344);
        } else if ($unit == "M") {
            return ($miles * 1609.344);
        } else if ($unit == "NMI") {
            return ($miles * 0.8684);
        } else {
            return $miles;
        }
    }
}
