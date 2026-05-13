package com.kiddietrac.ui.auth

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.compose.runtime.collectAsState
import com.kiddietrac.ui.theme.KtColors

@Composable
fun LoginScreen(
    onLoginSuccess: () -> Unit,
    vm: LoginViewModel = hiltViewModel(),
) {
    val state by vm.state.collectAsState()
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    LaunchedEffect(state.success) {
        if (state.success) onLoginSuccess()
    }

    Box(
        modifier = Modifier.fillMaxSize().background(
            androidx.compose.ui.graphics.Brush.linearGradient(
                colors = listOf(KtColors.blueDark, KtColors.blue),
            )
        ),
        contentAlignment = Alignment.Center,
    ) {
        Surface(
            modifier = Modifier.width(420.dp).padding(24.dp),
            shape = RoundedCornerShape(22.dp),
            color = KtColors.surface,
        ) {
            Column(
                modifier = Modifier.padding(40.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                // Logo
                Text(
                    text = "KIDDIETRAC",
                    fontSize = 32.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = KtColors.blue,
                )
                Text(
                    text = "EDUCATOR APP",
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Bold,
                    color = KtColors.textMuted,
                    modifier = Modifier.padding(top = 4.dp),
                )

                Spacer(Modifier.height(32.dp))

                Text(
                    text = "Sign in to your shift",
                    fontSize = 22.sp,
                    fontWeight = FontWeight.Bold,
                    color = KtColors.text,
                )

                Spacer(Modifier.height(24.dp))

                OutlinedTextField(
                    value = email,
                    onValueChange = { email = it },
                    label = { Text("Email") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !state.isLoading,
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Password") },
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !state.isLoading,
                )

                if (state.errorMessage != null) {
                    Spacer(Modifier.height(12.dp))
                    Surface(
                        shape = RoundedCornerShape(8.dp),
                        color = KtColors.dangerBg,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(
                            text = state.errorMessage ?: "",
                            color = KtColors.danger,
                            fontSize = 13.sp,
                            fontWeight = FontWeight.Medium,
                            modifier = Modifier.padding(12.dp),
                        )
                    }
                }

                Spacer(Modifier.height(24.dp))

                Button(
                    onClick = { vm.login(email, password) },
                    enabled = !state.isLoading && email.isNotEmpty() && password.isNotEmpty(),
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = KtColors.blue,
                        contentColor = Color.White,
                    ),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    if (state.isLoading) {
                        CircularProgressIndicator(
                            color = Color.White,
                            strokeWidth = 2.dp,
                            modifier = Modifier.size(22.dp),
                        )
                    } else {
                        Text("Start shift", fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                    }
                }

                Spacer(Modifier.height(16.dp))

                Text(
                    text = "Trouble signing in? Speak to your director.",
                    fontSize = 12.sp,
                    color = KtColors.textMuted,
                )
            }
        }
    }
}
