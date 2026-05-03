import { app } from "./app";
import { handleScheduled } from "./cron/handler";
import { processSyncBatch, processDeadLetterBatch } from "./sync/queue";
import type { Env, SyncQueueMessage } from "./env";

export type { Env, SyncQueueMessage } from "./env";

export default {
  fetch(request: Request, env: Env, ctx: ExecutionContext) {
    return app.fetch(request, env, ctx);
  },
  scheduled(event: ScheduledController, env: Env, ctx: ExecutionContext) {
    return handleScheduled(event, env, ctx);
  },
  async queue(batch: MessageBatch<SyncQueueMessage>, env: Env) {
    if (batch.queue.endsWith("-dlq")) {
      await processDeadLetterBatch(env, batch);
    } else {
      await processSyncBatch(env, batch);
    }
  },
};
