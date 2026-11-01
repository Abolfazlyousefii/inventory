<?php

return [
    'low_stock_threshold' => 5,
    // Explicit warehouses that may contribute to storefront stock.
    // [] means central warehouse ONLY; returns/personnel/other warehouses
    // require deliberate configuration and must be active.
    'site_sellable_warehouse_ids' => [],
];
