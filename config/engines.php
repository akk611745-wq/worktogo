<?php
/**
 * Body Engine Mappings
 */

return [
    'shopping' => getenv('ENGINE_SHOPPING_URL') ?: __DIR__ . '/../body/shopping-engine/index.php',
    'service'  => getenv('ENGINE_SERVICE_URL')  ?: __DIR__ . '/../body/service-engine/index.php',
    'delivery' => getenv('ENGINE_DELIVERY_URL') ?: '',
    'video'    => getenv('ENGINE_VIDEO_URL')    ?: '',
];
