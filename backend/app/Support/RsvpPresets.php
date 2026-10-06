<?php

namespace App\Support;

/**
 * Built-in RSVP templates (2026-10-06).
 *
 * Anthony: "create a set number of templates to choose from and don't require a template to
 * be created first have a drop down of default templates."
 *
 * Defined in code, not stored per agency, so every agency has them from day one and a fix
 * to one reaches everybody. They are READ-ONLY: an event made from one copies its fields,
 * email and banner into its own snapshot, and "Customize" saves an agency copy to edit.
 * Banners are shared images under /storage/rsvp/presets/{key}.jpg — text and shapes only.
 */
final class RsvpPresets
{
    public static function f(string $key, string $label, string $type = 'text', bool $required = false, array $o = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $o['options'] ?? [],
            'placeholder' => $o['placeholder'] ?? '', 'default' => $o['default'] ?? '', 'help' => $o['help'] ?? ''];
    }

    public static function imageUrl(string $key): string
    {
        return rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/') . '/storage/rsvp/presets/' . $key . '.jpg';
    }

    /** @return array<string, array> keyed by preset key, in display order */
    public static function all(): array
    {
        $f = [self::class, 'f'];
        $list = [
            'town_hall' => [
                'name' => 'Community town hall (Engaged Citizens)',
                'branding' => 'none',
                'description' => 'A public meeting where residents register with their address and the topics they want covered.',
                'fields' => [
                    $f('full_name', 'Full name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('street_address', 'Street address', 'text', true),
                    $f('town_city', 'Town/City', 'text', false),
                    $f('phone', 'Phone', 'tel', false, ['help' => 'Optional — only used if we need to reach you about the event.']),
                    $f('topics', 'Topic(s) you’d like covered at the event', 'textarea', false, ['placeholder' => 'e.g. roads, parks, recreation programs']),
                ],
                'email_subject' => 'You’re invited: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nYou’re invited to {{event}} — a chance to hear what’s happening in our community and to have your say.\n\nPlease RSVP below and tell us which topics matter most to you, so we can make sure they’re covered.\n\nWe hope to see you there.",
                'confirmation_message' => "Thank you for registering for {{event}}. We’ve noted the topics you’d like covered and look forward to seeing you.",
            ],
            'parent_info_night' => [
                'name' => 'Parent information night',
                'description' => 'An evening for current and prospective families to meet staff and learn about the program.',
                'fields' => [
                    $f('full_name', 'Your name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', false),
                    $f('child_names', 'Child’s name(s)', 'text', false),
                    $f('age_group', 'Child’s age group', 'select', false, ['options' => ['Infant', 'Toddler', 'Preschool', 'School age', 'Not yet born']]),
                    $f('attending', 'How many adults are coming?', 'select', true, ['options' => ['1', '2', '3', '4']]),
                    $f('questions', 'Anything you’d like us to cover?', 'textarea', false),
                ],
                'email_subject' => 'Join us for {{event}}',
                'email_body' => "Hi {{first_name}},\n\nYou’re invited to {{event}}. Meet our educators, see how a day in the program works, and bring your questions.\n\nPlease RSVP so we can plan for the right number of people.",
                'confirmation_message' => "Thank you for registering for {{event}}. We’re looking forward to meeting you.",
            ],
            'open_house' => [
                'name' => 'Open house & centre tour',
                'description' => 'Prospective families book a visit and tell you about their child.',
                'fields' => [
                    $f('full_name', 'Your name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', false),
                    $f('child_age', 'Child’s age', 'select', false, ['options' => ['Under 18 months', '18 months – 2½ years', '2½ – 4 years', '4 – 6 years', '6 years and older']]),
                    $f('start_date', 'When would you like care to start?', 'text', false, ['placeholder' => 'e.g. September 2027']),
                    $f('heard_about', 'How did you hear about us?', 'select', false, ['options' => ['Friend or family', 'Online search', 'Social media', 'Drove by', 'Other']]),
                    $f('questions', 'Questions for us', 'textarea', false),
                ],
                'email_subject' => 'Come visit us: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nWe’d love to show you around. Join us for {{event}} to see our rooms, meet the team and ask anything about enrolment.\n\nPlease RSVP so we know to expect you.",
                'confirmation_message' => "Thank you — your visit for {{event}} is booked. We look forward to showing you around.",
            ],
            'fundraiser' => [
                'name' => 'Fundraiser / gala',
                'description' => 'Ticketed or free fundraising evening with guest numbers and dietary needs.',
                'fields' => [
                    $f('full_name', 'Full name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', false),
                    $f('guests', 'Number of guests (including you)', 'select', true, ['options' => ['1', '2', '3', '4', '5', '6']]),
                    $f('dietary', 'Dietary needs or allergies', 'text', false),
                    $f('volunteer', 'I’d like to help on the night', 'checkbox', false),
                ],
                'email_subject' => 'You’re invited: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nPlease join us for {{event}}, an evening to celebrate our community and raise support for the children and families we serve.\n\nRSVP below to save your place.",
                'confirmation_message' => "Thank you for registering for {{event}}. Your support means a great deal to us.",
            ],
            'workshop' => [
                'name' => 'Workshop / training session',
                'description' => 'A learning session with organisation, role and accessibility needs.',
                'fields' => [
                    $f('full_name', 'Full name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('organisation', 'Organisation / role', 'text', false),
                    $f('phone', 'Phone', 'tel', false),
                    $f('accessibility', 'Accessibility needs', 'textarea', false, ['help' => 'Let us know anything that would help you take part fully.']),
                    $f('goals', 'What do you hope to learn?', 'textarea', false),
                ],
                'email_subject' => 'Register for {{event}}',
                'email_body' => "Hi {{first_name}},\n\nYou’re invited to {{event}}. Spaces are limited, so please register below to hold your seat.",
                'confirmation_message' => "Thank you for registering for {{event}}. We’ll see you there.",
            ],
            'family_event' => [
                'name' => 'Holiday celebration / family event',
                'description' => 'A family gathering with adult and child counts and allergy information.',
                'fields' => [
                    $f('full_name', 'Your name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', false),
                    $f('adults', 'Adults attending', 'select', true, ['options' => ['1', '2', '3', '4']]),
                    $f('children', 'Children attending', 'select', true, ['options' => ['0', '1', '2', '3', '4', '5']]),
                    $f('allergies', 'Allergies or dietary needs', 'textarea', false),
                    $f('photo_ok', 'Photos of our family may be shared with other families', 'checkbox', false),
                ],
                'email_subject' => 'Celebrate with us: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nYou and your family are invited to {{event}}. Come for the food, the fun and the company.\n\nPlease RSVP so we can plan food and activities for everyone.",
                'confirmation_message' => "Thank you for registering for {{event}}. We can’t wait to celebrate with you.",
            ],
            'volunteer' => [
                'name' => 'Volunteer sign-up',
                'description' => 'Collect volunteers with their availability and how they want to help.',
                'fields' => [
                    $f('full_name', 'Full name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', true),
                    $f('availability', 'When are you available?', 'select', true, ['options' => ['Morning', 'Afternoon', 'Evening', 'Any time']]),
                    $f('help_with', 'How would you like to help?', 'select', false, ['options' => ['Set-up', 'Welcome desk', 'Activities with children', 'Food and drinks', 'Clean-up', 'Wherever needed']]),
                    $f('police_check', 'I have a current police / vulnerable sector check', 'checkbox', false),
                    $f('notes', 'Anything else we should know?', 'textarea', false),
                ],
                'email_subject' => 'Volunteers needed: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nWe’re looking for volunteers for {{event}}. Even an hour or two makes a real difference.\n\nIf you can help, please sign up below and tell us when you’re available.",
                'confirmation_message' => "Thank you for volunteering for {{event}}! We’ll be in touch with the details.",
            ],
            'general' => [
                'name' => 'General event',
                'description' => 'A simple RSVP: name, email, guests and comments.',
                'fields' => [
                    $f('full_name', 'Full name', 'text', true),
                    $f('email', 'Email', 'email', true),
                    $f('phone', 'Phone', 'tel', false),
                    $f('guests', 'Number attending (including you)', 'select', false, ['options' => ['1', '2', '3', '4', '5']]),
                    $f('comments', 'Comments', 'textarea', false),
                ],
                'email_subject' => 'You’re invited: {{event}}',
                'email_body' => "Hi {{first_name}},\n\nYou’re invited to {{event}}. Please let us know you’re coming by pressing RSVP below.\n\nWe hope to see you there.",
                'confirmation_message' => "Thank you for registering for {{event}}. We look forward to seeing you.",
            ],
        ];
        foreach ($list as $k => &$p) {
            $p['key'] = $k;
            $p['branding'] = $p['branding'] ?? 'agency';
            $p['image_url'] = self::imageUrl($k);
        }
        unset($p);

        return $list;
    }

    public static function get(?string $key): ?array
    {
        if ($key === null || $key === '') return null;
        $all = self::all();

        return $all[$key] ?? null;
    }

    public static function isPresetImage(string $url): bool
    {
        foreach (array_keys(self::all()) as $k) {
            if ($url === self::imageUrl($k)) return true;
        }

        return false;
    }
}
