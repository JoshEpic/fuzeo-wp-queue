<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

/**
 * Capability names for future REST/dashboard. Phase 7 defines the model only.
 */
final class Capabilities
{
    public const VIEW = 'fuzeo_queue_view';
    public const MANAGE = 'fuzeo_queue_manage';
    public const RETRY = 'fuzeo_queue_retry';
    public const VIEW_PAYLOAD = 'fuzeo_queue_view_payload';
    public const NETWORK_VIEW = 'fuzeo_queue_view_network';
    public const NETWORK_MANAGE = 'fuzeo_queue_manage_network';

    public const FALLBACK_SITE = 'manage_options';
    public const FALLBACK_NETWORK = 'manage_network_options';
}
