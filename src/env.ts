export interface SyncQueueMessage {
  stage: "rebuild" | "scrape" | "erp" | "merge" | "media" | "push";
  sku?: string | null;
  slug?: string | null;
  url?: string | null;
  queue_row_id?: number | null;
  attempt?: number;
}

export interface Env {
  DB: D1Database;
  SYNC_QUEUE: Queue<SyncQueueMessage>;
  SYNC_DLQ: Queue<SyncQueueMessage>;
  /** R2 bucket for mirroring product images and PDFs. Optional — when absent
   *  the media stage no-ops with a warning, and downstream still works using
   *  the original URLs. */
  MEDIA?: R2Bucket;
  /** Public base URL for the MEDIA bucket, e.g. "https://media.odontoapi.wpatomic.com.br".
   *  Required to rewrite mirrored URLs. */
  MEDIA_PUBLIC_BASE_URL?: string;

  SCRAPE_BASE_URL: string;
  SCRAPE_SITEMAP_PATH: string;
  SCRAPE_USER_AGENT?: string;

  ERP_BASE_URL: string;
  /** Pre-issued token. When set, takes precedence over ERP_LOGIN/ERP_SENHA. */
  ERP_API_TOKEN?: string;
  /** Login user for POST /autenticacao/entrar (ERP "SPACE" platform). */
  ERP_LOGIN?: string;
  /** Login password for POST /autenticacao/entrar. */
  ERP_SENHA?: string;
  /** filialCodigo sent on the auth body (matches the storefront plugin). */
  ERP_FILIAL_CODIGO?: string;

  WOO_BASE_URL: string;
  WOO_CONSUMER_KEY?: string;
  WOO_CONSUMER_SECRET?: string;

  /** Bearer token for the OdontoJF Woo Bridge plugin (its `ojf_api_secret`
   *  option). This is NOT the WooCommerce consumer key/secret pair — the push
   *  stage talks to the plugin's own namespace, not to core WC REST. */
  WOO_PLUGIN_API_KEY?: string;
  /** REST namespace exposed by the bridge plugin. Default: "odontojf/v1". */
  WOO_PLUGIN_NAMESPACE?: string;
  /** "plugin" (default) → push through the Woo Bridge queue.
   *  "wcrest" → legacy path straight at core WooCommerce REST. */
  WOO_PUSH_MODE?: string;
  /** By default a product whose ERP lookup failed is NOT pushed (price/stock
   *  would be wrong). Set to "1" to push anyway. */
  WOO_PUSH_INCLUDE_ERP_FAILED?: string;

  /** Where price and stock come from on push.
   *  "erp" (default) — the ERP is the source; a product without ERP data is skipped.
   *  "store" — omit price and stock from the payload entirely, so WooCommerce
   *  keeps whatever it already has. Lets content (title, description, gallery)
   *  be published while the ERP is unreachable, without touching money. */
  WOO_PUSH_PRICING?: string;
  /** How many times the push stage polls /queue-status before giving up and
   *  leaving the row as `processing`. 0 (default) = fire-and-forget. */
  WOO_PLUGIN_POLL_MAX?: string;

  WORKER_API_KEY?: string;

  REBUILD_INTERVAL_HOURS?: string;
  DRAIN_BATCH_SIZE?: string;
  REQUEST_TIMEOUT_MS?: string;
  LOG_LEVEL?: string;

  /** Retention for finished sync_queue rows (default 24h) and sync_events
   *  (default 14 days). Both purges run from the cron, chunk-bounded. */
  SYNC_QUEUE_TTL_HOURS?: string;
  SYNC_EVENTS_TTL_DAYS?: string;

  /** When "1" / "true", runMergeStage enqueues the push stage automatically.
   *  Otherwise products stop at `merged` until /sync/sku/<sku>?stage=push is
   *  invoked manually. Default: disabled (mirror loja → ERP → painel first). */
  WOO_PUSH_ENABLED?: string;

  /** Stage gates: when "1" / "true", the previous stage automatically
   *  enqueues the named stage. When false, the previous stage stops and
   *  the operator must trigger the next batch via /admin/advance.
   *  Default for all: false — phased rollout (scrape everything first,
   *  then advance to ERP, then merge, etc.).  */
  AUTO_ENQUEUE_ERP?: string;
  AUTO_ENQUEUE_MERGE?: string;
  AUTO_ENQUEUE_MEDIA?: string;

  /** When unset OR "1" / "true": runScrapeStage mirrors every external image
   *  / PDF to R2 immediately after the scrape and rewrites the URLs inside
   *  scrape_json. Set to "0" to keep the original storefront URLs. */
  SCRAPE_AUTO_MIRROR?: string;
}

export type AppEnv = {
  Bindings: Env;
  Variables: {
    requestId: string;
    requestStart: number;
  };
};
