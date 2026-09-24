<?php

return [
    'documents' => [
        'max_size_mb' => (int) env('SLAD_DOCUMENTS_MAX_SIZE_MB', 20),
        'compression_min_size_bytes' => 524288,
        'ghostscript_binary' => env('SLAD_GHOSTSCRIPT_BINARY'),
    ],
];
