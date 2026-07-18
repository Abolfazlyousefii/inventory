# Ariya Site ↔ Inventory Integration Contract v1

Base path: `/api/integrations/ariya/v1`. All requests are JSON and must include `X-Ariya-Event-Id`, `X-Ariya-Timestamp`, `X-Ariya-Signature`, `X-Ariya-Source`, and `Content-Type: application/json`.

Signature is `hash_hmac('sha256', timestamp + "." + rawRequestBody, sharedSecret)`. Inventory verifies with `hash_equals`, rejects timestamps older/newer than 5 minutes, and can optionally restrict IPs with `ARIYA_SITE_ALLOWED_IPS`. Secrets live only in env.

## Endpoints
- `POST /orders`: accepts `order.created`; paid orders create exactly one `PreinvoiceOrder` in `pending_finance`, never an invoice.
- `GET /events/{eventId}`: returns inbound processing status.
- `GET /catalog/variants?limit=100`: snapshot for reconciliation.
- `GET /catalog/variants/{externalId}`: one variant.

## Idempotency
Delivery uniqueness uses `event_id`; business document uniqueness uses `external_order_id` stored on `preinvoice_orders.external_order_id`. Replays return `already_processed`; changed replay payloads return conflict.

## Mapping
Variant matching order is stable external id (`variety_id`/external id), then `variant_code`, then SKU/variant code. Product names are never used.

## Stock and price semantics
`variant.stock` is `product_variants.stock` and is already sellable/free stock; do not subtract `reserved` again. `variant.sell_price` is `product_variants.sell_price`.

## Retry
Outgoing events are written to `integration_outbox_events`, delivered by queued jobs on the `integrations` queue, and retried with configured exponential-ish backoff. Only 2xx is success; 429/5xx retry; permanent 4xx fails visibly.

## cURL
```bash
body='{"event_id":"uuid","event_type":"order.created","occurred_at":"2026-07-18T00:00:00Z","order":{"external_order_id":"10001","order_number":"AJ-10001","status":"paid","currency":"IRR","customer":{"name":"Test","mobile":"09120000000"},"shipping":{"amount":0},"discount_amount":0,"total_amount":100000,"payment":{"status":"paid"},"items":[{"external_variant_id":"123","quantity":1,"unit_price":100000,"line_discount_amount":0}]}}'
ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
sig=$(printf "%s.%s" "$ts" "$body" | openssl dgst -sha256 -hmac "$ARIYA_SITE_SHARED_SECRET" -binary | xxd -p -c 256)
curl -X POST "$INVENTORY_URL/api/integrations/ariya/v1/orders" -H 'Content-Type: application/json' -H 'X-Ariya-Source: ariya_site' -H 'X-Ariya-Event-Id: uuid' -H "X-Ariya-Timestamp: $ts" -H "X-Ariya-Signature: $sig" --data "$body"
```

See `ariya-site-example-payloads.json` for examples and `ariya-site-v1.openapi.yaml` for schemas.
