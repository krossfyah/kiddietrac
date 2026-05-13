@include('emails.layout', ['title' => 'Reset your password', 'preheader' => 'Reset your Kiddietrac password — link expires in ' . $expiresInMinutes . ' minutes.', 'appUrl' => $appUrl, 'content' => '
<h1>Reset your password</h1>

<p>Hi ' . e($recipientName) . ',</p>

<p>We received a request to reset your Kiddietrac password. Click the button below to set a new one.</p>

<p><a href="' . e($resetUrl) . '" class="btn">Reset password</a></p>

<p style="font-size: 13px; color: #6B7280;">This link expires in ' . e($expiresInMinutes) . ' minutes. If you didn\'t request a password reset, you can safely ignore this email — your password won\'t change.</p>

<p style="font-size: 12px; color: #9CA3AF; margin-top: 24px;">If the button doesn\'t work, copy and paste this URL into your browser:<br>' . e($resetUrl) . '</p>
'])
