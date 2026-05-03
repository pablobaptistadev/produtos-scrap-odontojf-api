import type { Env } from "../env";
import { runMigrations } from "../db/schema";
import { drainPendingToQueue, enqueueRebuild, shouldRebuild } from "../sync/orchestrator";
import { recordSyncEvent } from "../db/repo";
import { parseIntEnv } from "../core";

export async function handleScheduled(_event: ScheduledController, env: Env, _ctx: ExecutionContext): Promise<void> {
  try {
    await runMigrations(env.DB);
  } catch (err) {
    await recordSyncEvent(env, {
      stage: "cron",
      level: "error",
      message: `migration failed: ${err instanceof Error ? err.message : String(err)}`,
    }).catch(() => {});
    return;
  }

  if (await shouldRebuild(env)) {
    await enqueueRebuild(env, { reason: "cron-interval" });
  }

  const drainSize = parseIntEnv(env.DRAIN_BATCH_SIZE, 20);
  await drainPendingToQueue(env, drainSize);
}
