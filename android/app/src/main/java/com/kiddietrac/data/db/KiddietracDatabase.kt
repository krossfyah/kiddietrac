package com.kiddietrac.data.db

import androidx.room.*
import kotlinx.coroutines.flow.Flow
import java.util.UUID

/**
 * Offline event queue.
 *
 * Every event logged by the educator is INSERTED here first, then synced.
 * If sync succeeds, syncedAt is set. If it fails, we retry.
 *
 * This is the core of the tablet app's reliability: the educator can keep
 * logging meals, naps, diapers all day with no internet, and everything
 * uploads automatically when Wi-Fi returns.
 */

@Entity(tableName = "pending_events")
data class PendingEventEntity(
    @PrimaryKey val clientId: String = UUID.randomUUID().toString(),
    val childId: Long,
    val roomId: Long,
    val eventType: String,
    val occurredAt: Long, // epoch millis
    val payloadJson: String, // JSON-encoded
    val notes: String? = null,
    val voiceLogged: Boolean = false,
    val photoLocalPath: String? = null, // pending photo upload
    val createdAt: Long = System.currentTimeMillis(),
    val syncedAt: Long? = null,
    val syncAttempts: Int = 0,
    val lastSyncError: String? = null,
    val serverId: Long? = null, // populated after successful sync
)

@Dao
interface PendingEventDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(event: PendingEventEntity)

    @Query("SELECT * FROM pending_events WHERE syncedAt IS NULL ORDER BY createdAt ASC LIMIT :limit")
    suspend fun pendingBatch(limit: Int = 100): List<PendingEventEntity>

    @Query("SELECT * FROM pending_events WHERE syncedAt IS NULL")
    fun observePending(): Flow<List<PendingEventEntity>>

    @Query("UPDATE pending_events SET syncedAt = :now, serverId = :serverId WHERE clientId = :clientId")
    suspend fun markSynced(clientId: String, serverId: Long, now: Long = System.currentTimeMillis())

    @Query("UPDATE pending_events SET syncAttempts = syncAttempts + 1, lastSyncError = :error WHERE clientId = :clientId")
    suspend fun recordFailure(clientId: String, error: String)

    @Query("DELETE FROM pending_events WHERE syncedAt IS NOT NULL AND syncedAt < :before")
    suspend fun pruneSyncedBefore(before: Long)

    @Query("SELECT COUNT(*) FROM pending_events WHERE syncedAt IS NULL")
    fun unsyncedCount(): Flow<Int>
}

@Database(
    entities = [PendingEventEntity::class, CachedChildEntity::class, CachedRoomEntity::class],
    version = 1,
    exportSchema = true
)
abstract class KiddietracDatabase : RoomDatabase() {
    abstract fun pendingEventDao(): PendingEventDao
    abstract fun cacheDao(): CacheDao
}

// Cached entities for offline read access
@Entity(tableName = "cached_children")
data class CachedChildEntity(
    @PrimaryKey val id: Long,
    val displayName: String,
    val fullName: String,
    val dateOfBirth: String,
    val photoUrl: String?,
    val roomId: Long,
    val allergiesJson: String, // JSON array
    val medicalNotes: String?,
    val isAtCentre: Boolean,
    val cachedAt: Long = System.currentTimeMillis(),
)

@Entity(tableName = "cached_rooms")
data class CachedRoomEntity(
    @PrimaryKey val id: Long,
    val name: String,
    val ageGroup: String,
    val ratioEducators: Int,
    val ratioChildren: Int,
    val colorHex: String,
    val capacity: Int,
    val cachedAt: Long = System.currentTimeMillis(),
)

@Dao
interface CacheDao {
    @Query("SELECT * FROM cached_children WHERE roomId = :roomId ORDER BY displayName")
    fun childrenInRoom(roomId: Long): Flow<List<CachedChildEntity>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertChildren(children: List<CachedChildEntity>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertRooms(rooms: List<CachedRoomEntity>)

    @Query("SELECT * FROM cached_rooms WHERE id = :id")
    suspend fun room(id: Long): CachedRoomEntity?

    @Query("UPDATE cached_children SET isAtCentre = :present WHERE id = :childId")
    suspend fun updatePresence(childId: Long, present: Boolean)
}
