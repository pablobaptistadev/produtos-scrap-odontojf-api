import type { Env } from "../env";
import { runMigrations } from "../db/schema";
import { drainPendingToQueue, enqueueRebuild, shouldRebuild, reconcileWooQueue } from "../sync/orchestrator";
import { recordSyncEvent, purgeTerminalSyncRows, purgeOldSyncEvents } from "../db/repo";
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

  // Retention. Both purges are chunk-bounded, so a backlog drains over several
  // ticks instead of blowing the budget of a single one. Never fatal — a failed
  // purge must not stop the queue from draining.
  try {
    const queueTtlH = parseIntEnv(env.SYNC_QUEUE_TTL_HOURS, 24);
    const eventsTtlD = parseIntEnv(env.SYNC_EVENTS_TTL_DAYS, 14);
    const purgedQueue = await purgeTerminalSyncRows(env, { olderThanHours: queueTtlH });
    const purgedEvents = await purgeOldSyncEvents(env, { olderThanDays: eventsTtlD });
    if (purgedQueue + purgedEvents > 0) {
      await recordSyncEvent(env, {
        stage: "cron",
        level: "info",
        message: `retention purge: queue=${purgedQueue} events=${purgedEvents}`,
      }).catch(() => {});
    }
  } catch (err) {
    await recordSyncEvent(env, {
      stage: "cron",
      level: "warn",
      message: `retention purge failed: ${err instanceof Error ? err.message : String(err)}`,
    }).catch(() => {});
  }

  if (await shouldRebuild(env)) {
    await enqueueRebuild(env, { reason: "cron-interval" });
  }

  const drainSize = parseIntEnv(env.DRAIN_BATCH_SIZE, 20);
  await drainPendingToQueue(env, drainSize);

  // Settle products whose WP-side job finished after we handed it over.
  try {
    // A reconciliação é um GET barato no /queue-status por linha, nada a ver com
    // o custo de um push. Amarrada ao DRAIN_BATCH_SIZE (5), ela levava horas para
    // alcançar a realidade: o WordPress já tinha terminado 1.648 produtos e o D1
    // ainda os mostrava "na fila" — e foi esse atraso que eu reportei como
    // trabalho faltando.
    const settled = await reconcileWooQueue(env, parseIntEnv(env.RECONCILE_BATCH_SIZE, 200));
    if (settled > 0) {
      await recordSyncEvent(env, {
        stage: "cron",
        level: "info",
        message: `woo reconcile: ${settled} settled`,
      }).catch(() => {});
    }
  } catch (err) {
    await recordSyncEvent(env, {
      stage: "cron",
      level: "warn",
      message: `woo reconcile failed: ${err instanceof Error ? err.message : String(err)}`,
    }).catch(() => {});
  }
}
