<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['lat']) && isset($input['lng'])) {
        // Reverse geocoding
        $lat = floatval($input['lat']);
        $lng = floatval($input['lng']);
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lng&addressdetails=1";
    } elseif (isset($input['address'])) {
        // Forward geocoding
        $address = urlencode($input['address']);
        $url = "https://nominatim.openstreetmap.org/search?format=json&q=$address&limit=1";
    } else {
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }
    
    // Set user agent to comply with Nominatim usage policy
    $options = [
        'http' => [
            'header' => "User-Agent: ArtisanLink/1.0\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        echo json_encode(['error' => 'Geocoding service unavailable']);
    } else {
        echo $response;
    }
} else {
    echo json_encode(['error' => 'Method not allowed']);
}
?>