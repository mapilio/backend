<?php

use App\Support\Queue\QueueRuntimeConfiguration;

return [
    'defaults' => [
        'sleep' => 1,
        'timeout' => QueueRuntimeConfiguration::LONGEST_JOB_TIMEOUT_SECONDS,
        'max_time' => 3600,
        'max_jobs' => 1000,
        'memory' => 512,
        'stop_wait_seconds' => QueueRuntimeConfiguration::MINIMUM_GRACEFUL_STOP_SECONDS,
    ],

    'pools' => [
        'callbacks-results' => [
            'queue_config_keys' => [
                'mapilio.ai_callback.queue',
                'mapilio.ai_result_persistence.queue',
            ],
        ],
        'projections-publication' => [
            'queue_config_keys' => [
                'mapilio.ai_status_projection.queue',
                'mapilio.geo_publication.queue',
                'mapilio.geo_publication.preparation_queue',
            ],
        ],
        'outbound-enrichment' => [
            'queue_config_keys' => [
                'mapilio.ai_prediction.queue',
                'mapilio.address_enrichment.queue',
            ],
        ],
        'ukm-scoring' => [
            'queue_config_keys' => [
                'mapilio.ukm_scoring.queue',
            ],
            'memory' => 1024,
        ],
    ],
];
