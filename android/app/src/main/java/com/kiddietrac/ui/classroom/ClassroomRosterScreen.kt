package com.kiddietrac.ui.classroom

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import com.kiddietrac.ui.theme.KtColors

/**
 * The primary view of the educator tablet — a grid showing every child in the
 * room with their current status (present / not yet arrived / picked up),
 * key allergens/medications as red dots, and quick-log buttons.
 *
 * Designed for landscape 10" tablet, optimized for taps not text.
 */

@Composable
fun ClassroomRosterScreen(
    onLogEvent: (childId: Long) -> Unit,
    onTakePhoto: () -> Unit,
    vm: ClassroomViewModel = hiltViewModel(),
) {
    val state by vm.state.collectAsState()
    val pendingSync by vm.pendingSyncCount.collectAsState()
    val ratio by vm.currentRatio.collectAsState()

    Column(modifier = Modifier.fillMaxSize().background(KtColors.bg)) {

        // ─── Top bar with ratio status ─────────────────────────
        RatioBar(
            roomName = state.roomName,
            currentChildren = ratio?.childrenPresent ?: 0,
            currentEducators = ratio?.educatorsPresent ?: 0,
            requiredEducators = ratio?.requiredEducators ?: 0,
            isCompliant = ratio?.compliant ?: true,
            pendingSync = pendingSync,
            onSyncNowClick = { vm.syncNow() },
        )

        // ─── Bulk action bar ──────────────────────────────────
        BulkActionBar(
            onBulkMeal = { vm.openBulkLog("meal") },
            onBulkNap = { vm.openBulkLog("nap_start") },
            onBulkSnack = { vm.openBulkLog("snack") },
            onPhotoMoment = onTakePhoto,
        )

        // ─── Children grid ────────────────────────────────────
        LazyVerticalGrid(
            columns = GridCells.Fixed(4),  // 4 cols on a 10" tablet feels right
            modifier = Modifier.fillMaxSize().padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            items(state.children) { child ->
                ChildCard(
                    child = child,
                    onClick = { onLogEvent(child.id) },
                    onCheckIn = { vm.checkIn(child.id) },
                    onCheckOut = { vm.checkOut(child.id) },
                )
            }
        }
    }
}

@Composable
private fun RatioBar(
    roomName: String,
    currentChildren: Int,
    currentEducators: Int,
    requiredEducators: Int,
    isCompliant: Boolean,
    pendingSync: Int,
    onSyncNowClick: () -> Unit,
) {
    val ratioColor = when {
        !isCompliant -> KtColors.danger
        currentChildren > 0 && currentEducators - requiredEducators <= 0 -> KtColors.warn
        else -> KtColors.success
    }

    Row(
        modifier = Modifier.fillMaxWidth().background(KtColors.surface).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = roomName,
            fontSize = 22.sp, fontWeight = FontWeight.Bold, color = KtColors.text,
        )
        Spacer(Modifier.width(20.dp))

        Surface(
            shape = RoundedCornerShape(100),
            color = ratioColor.copy(alpha = 0.12f),
        ) {
            Row(
                modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    imageVector = if (isCompliant) Icons.Default.CheckCircle else Icons.Default.Warning,
                    contentDescription = null,
                    tint = ratioColor,
                    modifier = Modifier.size(16.dp),
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    text = "$currentEducators educators · $currentChildren children · Need $requiredEducators",
                    fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = ratioColor,
                )
            }
        }

        Spacer(Modifier.weight(1f))

        // Pending sync indicator
        if (pendingSync > 0) {
            Surface(
                shape = RoundedCornerShape(100),
                color = KtColors.warn.copy(alpha = 0.15f),
                onClick = onSyncNowClick,
            ) {
                Row(
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Default.CloudOff, null, tint = KtColors.warn, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("$pendingSync waiting", fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = KtColors.warn)
                }
            }
        }
    }
}

@Composable
private fun BulkActionBar(
    onBulkMeal: () -> Unit,
    onBulkNap: () -> Unit,
    onBulkSnack: () -> Unit,
    onPhotoMoment: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(16.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        BulkButton("🍽️ Log Lunch", onBulkMeal, Modifier.weight(1f))
        BulkButton("😴 Start Nap", onBulkNap, Modifier.weight(1f))
        BulkButton("🍎 Snack", onBulkSnack, Modifier.weight(1f))
        BulkButton("📸 Photo Moment", onPhotoMoment, Modifier.weight(1f))
    }
}

@Composable
private fun BulkButton(label: String, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier.height(60.dp),
        shape = RoundedCornerShape(14.dp),
        color = KtColors.surface,
        border = androidx.compose.foundation.BorderStroke(1.5.dp, KtColors.border),
        onClick = onClick,
    ) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(label, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = KtColors.text)
        }
    }
}

@Composable
private fun ChildCard(
    child: ChildUiModel,
    onClick: () -> Unit,
    onCheckIn: () -> Unit,
    onCheckOut: () -> Unit,
) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        color = KtColors.surface,
        border = androidx.compose.foundation.BorderStroke(
            1.dp,
            if (child.isAtCentre) KtColors.success.copy(alpha = 0.4f) else KtColors.border
        ),
        onClick = onClick,
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ChildAvatar(child)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(child.displayName, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = KtColors.text)
                    Text(child.ageHuman, fontSize = 12.sp, color = KtColors.textMuted)
                }
            }

            // Allergy & medication flags
            if (child.urgentFlags.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                Row {
                    child.urgentFlags.take(3).forEach { flag ->
                        Surface(
                            shape = RoundedCornerShape(6.dp),
                            color = KtColors.danger.copy(alpha = 0.12f),
                            modifier = Modifier.padding(end = 4.dp),
                        ) {
                            Text(
                                text = flag.shortLabel,
                                fontSize = 10.sp,
                                fontWeight = FontWeight.Bold,
                                color = KtColors.danger,
                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp),
                            )
                        }
                    }
                }
            }

            Spacer(Modifier.height(10.dp))

            // Status / action
            if (child.isAtCentre) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(8.dp).clip(CircleShape).background(KtColors.success))
                    Spacer(Modifier.width(6.dp))
                    Text("Here since ${child.arrivedAt ?: "—"}", fontSize = 11.sp, color = KtColors.textMuted)
                }
                if (child.lastEvent != null) {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text = "Last: ${child.lastEvent}",
                        fontSize = 11.sp,
                        color = KtColors.text,
                        maxLines = 1,
                    )
                }
            } else {
                Button(
                    onClick = onCheckIn,
                    modifier = Modifier.fillMaxWidth().height(36.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = KtColors.blue),
                    shape = RoundedCornerShape(8.dp),
                ) {
                    Text("Check in", fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun ChildAvatar(child: ChildUiModel) {
    Box(
        modifier = Modifier.size(44.dp).clip(CircleShape).background(KtColors.blue),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text = child.initials,
            color = Color.White,
            fontSize = 16.sp,
            fontWeight = FontWeight.Bold,
        )
    }
}

// UI model — what the screen needs (mapped from db entities by ViewModel)
data class ChildUiModel(
    val id: Long,
    val displayName: String,
    val initials: String,
    val ageHuman: String,
    val isAtCentre: Boolean,
    val arrivedAt: String? = null,
    val lastEvent: String? = null,
    val urgentFlags: List<HealthFlag> = emptyList(),
)

data class HealthFlag(
    val shortLabel: String, // e.g. "PEANUT" or "EPIPEN"
    val severity: String,
)
