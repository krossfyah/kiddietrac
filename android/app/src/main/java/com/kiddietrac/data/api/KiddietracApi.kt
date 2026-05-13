package com.kiddietrac.data.api

import com.kiddietrac.data.model.*
import retrofit2.http.*

/**
 * Retrofit interface mirroring the Kiddietrac REST API.
 * Implemented automatically by Retrofit at runtime.
 */
interface KiddietracApi {

    // ─── Auth ─────────────────────────────────────────────────────
    @POST("auth/login")
    suspend fun login(@Body req: LoginRequest): LoginResponse

    @GET("me")
    suspend fun me(): UserDto

    @POST("me/device-token")
    suspend fun registerDeviceToken(@Body req: DeviceTokenRequest)

    @POST("auth/logout")
    suspend fun logout()

    // ─── Provider / Educator ─────────────────────────────────────
    /**
     * Single call to load everything a tablet needs to start a shift.
     * Centre, rooms, today's roster, today's events, active medications.
     */
    @GET("provider/bootstrap")
    suspend fun bootstrap(): BootstrapResponse

    @GET("provider/rooms/{roomId}/roster")
    suspend fun roomRoster(@Path("roomId") roomId: Long): RoomRosterResponse

    @GET("provider/rooms/{roomId}/ratio")
    suspend fun roomRatio(@Path("roomId") roomId: Long): RatioStatusDto

    // ─── Check-in / out ──────────────────────────────────────────
    @POST("provider/check-in")
    suspend fun checkIn(@Body req: CheckEventRequest): CheckEventDto

    @POST("provider/check-out")
    suspend fun checkOut(@Body req: CheckEventRequest): CheckEventDto

    // ─── Daily events (single or batch for offline sync) ────────
    @POST("provider/events")
    suspend fun logEvent(@Body req: DailyEventRequest): DailyEventDto

    /**
     * Critical for offline mode. Send queued events from device DB.
     * Returns map of client_id -> server result so we know which to mark synced.
     */
    @POST("provider/events/batch")
    suspend fun batchLogEvents(@Body req: BatchEventRequest): BatchEventResponse

    @POST("provider/events/voice")
    suspend fun voiceLog(@Body req: VoiceLogRequest): VoiceLogResponse

    // ─── Media ───────────────────────────────────────────────────
    @Multipart
    @POST("provider/photos")
    suspend fun uploadPhoto(
        @Part photo: okhttp3.MultipartBody.Part,
        @Part("room_id") roomId: okhttp3.RequestBody,
        @Part("caption") caption: okhttp3.RequestBody,
        @Part("child_ids") childIds: okhttp3.RequestBody,
    ): MediaDto

    // ─── Observations & incidents ────────────────────────────────
    @POST("provider/observations")
    suspend fun logObservation(@Body req: ObservationRequest): ObservationDto

    @POST("provider/incidents")
    suspend fun logIncident(@Body req: IncidentRequest): IncidentDto

    // ─── Medications ─────────────────────────────────────────────
    @GET("provider/medications/due")
    suspend fun medicationsDue(): List<MedicationDto>

    @POST("provider/medications/{medId}/administer")
    suspend fun administerMedication(
        @Path("medId") medicationId: Long,
        @Body req: MedicationAdministrationRequest
    ): MedicationAdministrationDto

    // ─── Messaging ───────────────────────────────────────────────
    @GET("provider/conversations")
    suspend fun conversations(): List<ConversationDto>

    @POST("provider/conversations/{id}/messages")
    suspend fun sendMessage(
        @Path("id") conversationId: Long,
        @Body req: MessageRequest
    ): MessageDto

    // ─── Time clock ──────────────────────────────────────────────
    @POST("provider/clock-in")
    suspend fun clockIn(@Body req: ClockEventRequest): TimeEntryDto

    @POST("provider/clock-out")
    suspend fun clockOut(@Body req: ClockEventRequest): TimeEntryDto
}
