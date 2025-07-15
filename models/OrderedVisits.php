<?php

declare(strict_types=1);

namespace Api\Models;

/**
 * OrderedVisit Model
 * This model extends Visit and uses the ordered_visits view
 * to provide pre-sorted visit results
 */
class OrderedVisits extends Visit {
    // Additional property from the view
    public ?int $sort_order = null;

    /**
     * Initialize model
     */
    public function initialize(): void {
        // Set source to the view
        $this->setSource('ordered_visits');

        // Inherit parent relationships
        parent::initialize();

        // Mark as read-only since it's based on a view
        $this->keepSnapshots(false);
    }

    /**
     * Override save to prevent updates to the view
     */
    public function save(): bool {
        throw new \Exception('Cannot save to a view. Use Visit model for write operations.');
    }

    /**
     * Override delete to prevent deletions from the view
     */
    public function delete(): bool {
        throw new \Exception('Cannot delete from a view. Use Visit model for write operations.');
    }
}