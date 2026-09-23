package com.kiddietrac.app

/*
 * KiddieTrac — urgent push handling for Android.
 *
 * The server sends TWO shapes of message (see App\Services\FcmService::sendToTokens):
 *
 *   • Routine   — a real `notification` block. Android renders it itself, this class is
 *                 not called when the app is backgrounded, and that is fine.
 *   • Urgent    — DATA ONLY, carrying title/body/link/kt_urgent/kt_fullscreen. Data-only
 *                 is what guarantees onMessageReceived runs even when backgrounded, which
 *                 is the only way app code can set FLAG_INSISTENT (repeating sound) and
 *                 attach a full-screen intent.
 *
 * WHAT IS NEW HERE (2026-09-09): the full-screen intent. Everything else matches the
 * behaviour the web layer already assumes.
 *
 * Android 14 (API 34) reality, and the reason this is not simply "on":
 * ────────────────────────────────────────────────────────────────────
 * USE_FULL_SCREEN_INTENT stopped being freely granted in Android 14. It is auto-granted
 * only to apps whose core function is calling or alarms. Everything else must ask the
 * user, in Settings, and can be refused. When it is refused the notification is NOT lost —
 * Android downgrades it to a heads-up banner, which with the insistent sound below is
 * still hard to miss. So:
 *
 *   - never assume the takeover happened; the notification must stand on its own
 *   - never nag for the permission on launch; ask once, in context, from Settings
 *   - the server can withdraw the behaviour at any time by dropping kt_fullscreen,
 *     without a new APK
 */

import android.app.KeyguardManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class KtMessagingService : FirebaseMessagingService() {

    companion object {
        /** Bumping a channel id is the ONLY way to change a channel's sound after install. */
        const val CHANNEL_URGENT = "kt_urgent_v1"
        const val CHANNEL_DEFAULT = "kt_alerts"
        private const val NOTIF_ID_URGENT = 4711
    }

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        // The web layer POSTs the token to /push/device once it has an auth token; nothing
        // to do here. Kept as an override so the reason is written down rather than
        // rediscovered.
    }

    override fun onMessageReceived(msg: RemoteMessage) {
        super.onMessageReceived(msg)

        val data = msg.data
        val urgent = data["kt_urgent"] == "1"
        if (!urgent) return          // routine messages are drawn by Android itself

        val title = data["title"] ?: msg.notification?.title ?: "KiddieTrac"
        val body = data["body"] ?: msg.notification?.body ?: ""
        val link = data["link"] ?: ""
        val wantsFullScreen = data["kt_fullscreen"] == "1"

        ensureUrgentChannel()

        /* The tap target. The link travels as an extra AND on the data Uri: MainActivity
           reads the extra, and the Uri keeps the two PendingIntents distinct when several
           messages arrive — without it, FLAG_UPDATE_CURRENT would make the second one
           reuse the first one's link and every notification would open the same thread. */
        val open = Intent(this, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra("kt_link", link)
            if (link.isNotEmpty()) this.data = Uri.parse("kiddietrac://open$link")
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val contentIntent = PendingIntent.getActivity(this, link.hashCode(), open, flags)

        val n = NotificationCompat.Builder(this, CHANNEL_URGENT)
            .setSmallIcon(R.drawable.ic_stat_kt)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)   // readable on the lock screen
            .setAutoCancel(true)
            .setContentIntent(contentIntent)
            .setVibrate(longArrayOf(0, 700, 250, 700, 250, 900))

        /* THE TAKEOVER. Only when the screen is actually locked or off: firing a
           full-screen intent at somebody already looking at their phone is obnoxious and
           Android will show a heads-up anyway. canUseFullScreenIntent() is the API 34+
           gate; below that the permission is granted at install. */
        if (wantsFullScreen && screenIsLocked() && canUseFullScreenIntent()) {
            n.setFullScreenIntent(contentIntent, true)
        }

        val built = n.build()
        // Keep the sound and vibration going until it is acknowledged. This is the whole
        // point of the urgent path — an educator on the room floor is not watching a badge.
        built.flags = built.flags or Notification.FLAG_INSISTENT

        try {
            NotificationManagerCompat.from(this).notify(NOTIF_ID_URGENT, built)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS refused (API 33+). Nothing to do; the in-app takeover in
            // kt-urgent-alert.js still covers the case where the app is open.
        }
    }

    private fun screenIsLocked(): Boolean = try {
        val km = getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        km.isKeyguardLocked || !km.isDeviceLocked.not()
    } catch (e: Exception) {
        false
    }

    private fun canUseFullScreenIntent(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true  // < API 34
        return try {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            nm.canUseFullScreenIntent()
        } catch (e: Exception) {
            false
        }
    }

    /** Created here as well as in MainActivity, because a push can arrive first. */
    private fun ensureUrgentChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (nm.getNotificationChannel(CHANNEL_URGENT) != null) return

        val ch = NotificationChannel(
            CHANNEL_URGENT,
            "Urgent messages",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "New chat messages and alerts that need attention now."
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 700, 250, 700, 250, 900)
            enableLights(true)
            lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            setBypassDnd(true)          // honoured only if the user allows it for the app
            setSound(
                Uri.parse("android.resource://$packageName/raw/kt_notify"),
                AudioAttributes.Builder()
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)   // rings like a call
                    .build()
            )
        }
        nm.createNotificationChannel(ch)
    }
}
