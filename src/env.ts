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

  WOO_BASE_URL: string;
  WOO_CONSUMER_KEY?: string;
  WOO_CONSUMER_SECRET?: string;

  WORKER_API_KEY?: string;

  REBUILD_INTERVAL_HOURS?: string;
  DRAIN_BATCH_SIZE?: string;
  REQUEST_TIMEOUT_MS?: string;
  LOG_LEVEL?: string;

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
}

export type AppEnv = {
  Bindings: Env;
  Variables: {
    requestId: string;
    requestStart: number;
  };
};
