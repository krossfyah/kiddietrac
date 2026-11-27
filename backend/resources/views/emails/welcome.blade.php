@include('emails.layout', ['title' => 'Welcome to Kiddietrac', 'preheader' => 'You\'ve been invited to ' . $centreName . ' on Kiddietrac. ' . (! empty($setPasswordUrl) ? 'Set your password to get started.' : 'Sign in to get started.'), 'appUrl' => $appUrl, 'content' => '
<h1>Hi ' . e($recipientName) . ',</h1>

' . ($role === 'parent'
    ? '<p>You\'ve been invited by <strong>' . e($centreName) . '</strong> to use Kiddietrac — a modern way to stay connected to your child\'s day.</p>'
    . ($childNames ? '<p>Your account is linked to: <strong>' . e($childNames) . '</strong></p>' : '')
    . '<p>Through the parent app, you can see daily updates, get photos, chat with your educators, and view your billing.</p>'
    : '<p>You\'ve been added to the <strong>' . e($centreName) . '</strong> team on Kiddietrac.</p>'
    . '<p>You can now log into the educator tablet view to track children, log meals and activities, and communicate with parents.</p>'
)
. '

<h2>Your login</h2>
<div class="creds">
<strong>Email:</strong> ' . e($recipientEmail) . '
</div>

' . (! empty($setPasswordUrl)
    ? '<p><a href="' . e($setPasswordUrl) . '" class="btn">Set your password</a></p>
<p style="font-size: 13px; color: #6B7280;">This link works once and expires in ' . \App\Support\SetPasswordLink::DAYS . ' days. After that, sign in with your email and the password you chose. If it has expired, use "Forgot password" on the sign-in page. If you didn\'t expect this email, you can safely ignore it.</p>'
    : '<p><a href="' . e($appUrl) . '" class="btn">Log in to Kiddietrac</a></p>
<p style="font-size: 13px; color: #6B7280;">Sign in with your email and the password you chose. If you didn\'t expect this email, you can safely ignore it.</p>') . '
'])
