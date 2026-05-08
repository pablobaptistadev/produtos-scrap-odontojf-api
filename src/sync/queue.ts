import type { Env, SyncQueueMessage } from "../env";
import {
  getSyncQueueRow,
  markSyncRowProcessing,
  markSyncRowDone,
  setNextRetryAt,
  recordSyncEvent,
} from "../db/repo";
import { computeRetryDelaySeconds } from "../core";
import {
  runRebuildStage,
  runScrapeStage,
  runErpStage,
  runMergeStage,
  runMediaStage,
  runPushStage,
} from "./orchestrator";

export async function processSyncBatch(env: Env, batch: MessageBatch<SyncQueueMessage>): Promise<void> {
  for (const msg of batch.messages) {
    await processOne(env, msg);
  }
}

async function processOne(env: Env, msg: Message<SyncQueueMessage>): Promise<void> {
  const body = msg.body;
  const queueRowId = body.queue_row_id ?? null;
  let attempts = 0;
  if (queueRowId) {
    const row = await getSyncQueueRow(env, queueRowId);
    if (!row) {
      msg.ack();
      return;
    }
    if (row.status === "done" || row.status === "dead") {
      msg.ack();
      return;
    }
    attempts = row.attempts + 1;
    await markSyncRowProcessing(env, queueRowId);
  }

  try {
    switch (body.stage) {
      case "rebuild":
        await runRebuildStage(env);
        break;
      case "scrape":
        if (!body.sku || !body.url) throw new Error("scrape stage requires sku and url");
        await runScrapeStage(env, body.sku, body.url);
        break;
      case "erp":
        if (!body.sku) throw new Error("erp stage requires sku");
        await runErpStage(env, body.sku);
        break;
      case "merge":
        if (!body.sku) throw new Error("merge stage requires sku");
        await runMergeStage(env, body.sku);
        break;
      case "media":
        if (!body.sku) throw new Error("media stage requires sku");
        await runMediaStage(env, body.sku);
        break;
      case "push":
        if (!body.sku) throw new Error("push stage requires sku");
        await runPushStage(env, body.sku);
        break;
      default:
        throw new Error(`unknown stage: ${body.stage}`);
    }
    if (queueRowId) await markSyncRowDone(env, queueRowId);
    msg.ack();
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    await recordSyncEvent(env, {
      sku: body.sku ?? null,
      stage: body.stage,
      level: "error",
      message,
      context: { attempts, queue_row_id: queueRowId },
    });
    if (queueRowId) {
      const delaySeconds = computeRetryDelaySeconds(attempts);
      const nextRetryAt = new Date(Date.now() + delaySeconds * 1000).toISOString();
      await setNextRetryAt(env, queueRowId, nextRetryAt, message);
    }
    msg.retry({ delaySeconds: computeRetryDelaySeconds(attempts) });
  }
}

export async function processDeadLetterBatch(env: Env, batch: MessageBatch<SyncQueueMessage>): Promise<void> {
  for (const msg of batch.messages) {
    await recordSyncEvent(env, {
      sku: msg.body?.sku ?? null,
      stage: msg.body?.stage ?? "unknown",
      level: "error",
      message: "moved to DLQ after max retries",
      context: { queue_row_id: msg.body?.queue_row_id ?? null },
    });
    msg.ack();
  }
}
