<?php

return [
    'scheduler_stale_after_seconds' => (int) env('HEALTH_SCHEDULER_STALE_SECONDS', 120),
    'queue_worker_stale_after_seconds' => (int) env('HEALTH_QUEUE_WORKER_STALE_SECONDS', 180),
];
