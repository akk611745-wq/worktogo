<?php
/**
 * Body Engine Mappings
 */

return [
    'shopping' => getenv('ENGINE_SHOPPING_URL') ?: '',
    'service'  => getenv('ENGINE_SERVICE_URL')  ?: '',
    'delivery' => getenv('ENGINE_DELIVERY_URL') ?: '',
    'video'    => getenv('ENGINE_VIDEO_URL')    ?: '',
];
