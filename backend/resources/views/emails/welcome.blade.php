@include('emails.layout', ['title' => 'Welcome to Kiddietrac', 'preheader' => 'You\'ve been invited to ' . $centreName . ' on Kiddietrac. Your login details are inside.', 'appUrl' => $appUrl, 'content' => '
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
<strong>Email:</strong> ' . e($recipientEmail) . '<br>
<strong>Temporary password:</strong> ' . e($tempPassword) . '
</div>

<p><a href="' . e($appUrl) . '" class="btn">Log in to Kiddietrac</a></p>

<p style="font-size: 13px; color: #6B7280;">Please change your password after your first login. If you didn\'t expect this email, you can safely ignore it.</p>
'])
