package com.kiddietrac.data.sync

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.*
import com.kiddietrac.data.api.KiddietracApi
import com.kiddietrac.data.db.PendingEventDao
import com.kiddietrac.data.model.BatchEventRequest
import com.kiddietrac.data.model.PendingEventDto
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.*
import java.util.concurrent.TimeUnit

/**
 * Background worker that pushes pending events from the local Room database
 * to the server. Scheduled with WorkManager to run:
 *
 *  - Every 15 minutes while the app is foregrounded
 *  - Immediately when the device transitions from offline → online
 *  - On a long-press of the "sync now" button
 *
 * Uses an exponential backoff retry policy. A failure after 5 attempts moves
 * the event to a manual review queue (shown in app as "Needs attention").
 */
@HiltWorker
class EventSyncWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted params: WorkerParameters,
    private val pendingDao: PendingEventDao,
    private val api: KiddietracApi,
) : CoroutineWorker(appContext, params) {

    private val iso = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    override suspend fun doWork(): Result {
        val pending = pendingDao.pendingBatch(limit = BATCH_SIZE)
        if (pending.isEmpty()) return Result.success()

        return try {
            val request = BatchEventRequest(
                events = pending.map { p ->
                    PendingEventDto(
                        clientId = p.clientId,
                        childId = p.childId,
                        roomId = p.roomId,
                        eventType = p.eventType,
                        occurredAt = iso.format(Date(p.occurredAt)),
                        payload = JSONObject(p.payloadJson).toMap(),
                        notes = p.notes,
                        voiceLogged = p.voiceLogged,
                    )
                }
            )

            val response = api.batchLogEvents(request)
            val now = System.currentTimeMillis()
            response.events.forEach { (clientId, result) ->
                if (result.status == "created" || result.status == "duplicate") {
                    pendingDao.markSynced(clientId, result.id, now)
                } else {
                    pendingDao.recordFailure(clientId, "Server rejected: ${result.status}")
                }
            }

            // Prune events synced more than 7 days ago (keep recent ones for audit)
            pendingDao.pruneSyncedBefore(now - TimeUnit.DAYS.toMillis(7))

            // If more pending exist, retry to drain the queue
            if (pendingDao.pendingBatch(1).isNotEmpty()) Result.retry() else Result.success()
        } catch (e: Exception) {
            pending.forEach { p -> pendingDao.recordFailure(p.clientId, e.message ?: "unknown") }
            // Retry with exponential backoff
            if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
        }
    }

    companion object {
        private const val BATCH_SIZE = 100
        private const val MAX_ATTEMPTS = 5
        const val WORK_NAME = "kiddietrac_event_sync"

        /**
         * Schedule periodic + network-triggered sync.
         * Call this from the Application class or after login.
         */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<EventSyncWorker>(
                15, TimeUnit.MINUTES
            )
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }

        /**
         * Trigger immediate sync — used after every event is logged so it tries
         * to upload right away when online.
         */
        fun syncNow(context: Context) {
            val request = OneTimeWorkRequestBuilder<EventSyncWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                "${WORK_NAME}_immediate",
                ExistingWorkPolicy.REPLACE,
                request
            )
        }
    }
}

// Tiny helper to convert JSONObject to Map
private fun JSONObject.toMap(): Map<String, Any?> = keys().asSequence().associateWith { k ->
    when (val v = get(k)) {
        is JSONObject -> v.toMap()
        JSONObject.NULL -> null
        else -> v
    }
}
