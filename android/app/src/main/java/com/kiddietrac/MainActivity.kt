package com.kiddietrac

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.kiddietrac.data.sync.EventSyncWorker
import com.kiddietrac.ui.auth.LoginScreen
import com.kiddietrac.ui.classroom.ClassroomRosterScreen
import com.kiddietrac.ui.child.ChildDetailScreen
import com.kiddietrac.ui.theme.KiddietracTheme
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject lateinit var authStore: com.kiddietrac.data.auth.AuthStore

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Schedule background sync as soon as the app starts
        EventSyncWorker.schedule(applicationContext)

        setContent {
            KiddietracTheme {
                Surface(modifier = Modifier, color = MaterialTheme.colorScheme.background) {
                    KiddietracApp(isAuthenticated = authStore.hasToken())
                }
            }
        }
    }
}

@Composable
fun KiddietracApp(isAuthenticated: Boolean) {
    val navController = rememberNavController()
    val startDestination = if (isAuthenticated) "classroom" else "login"

    NavHost(navController = navController, startDestination = startDestination) {
        composable("login") {
            LoginScreen(onLoginSuccess = {
                navController.navigate("classroom") {
                    popUpTo("login") { inclusive = true }
                }
            })
        }
        composable("classroom") {
            ClassroomRosterScreen(
                onLogEvent = { childId -> navController.navigate("child/$childId") },
                onTakePhoto = { /* navigate to photo flow */ },
            )
        }
        composable("child/{childId}") { backStackEntry ->
            val childId = backStackEntry.arguments?.getString("childId")?.toLongOrNull() ?: return@composable
            ChildDetailScreen(
                childId = childId,
                onBack = { navController.popBackStack() },
            )
        }
    }
}
