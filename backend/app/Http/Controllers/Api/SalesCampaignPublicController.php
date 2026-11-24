<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SalesCampaigns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The links inside a sales campaign email (2026-10-26). No sign-in: each recipient has a random 40-character token.
 *  - /c/o/{token}.gif       the open pixel
 *  - /c/l/{token}/{key}     a tracked link; key is one of a fixed few, each going to a fixed KiddieTrac page
 *  - /c/i/{token}           "Yes, send me more information": a confirm page, recorded only when the button is pressed
 *                           (mail scanners follow links, they do not press buttons)
 *  - /c/u/{token}           unsubscribe: a confirm page (GET) and the change (POST, also the one-click header)
 */
class SalesCampaignPublicController extends Controller
{
    private function recipient(string $token): ?object
    {
        return preg_match('/^[a-z0-9]{40}$/', $token) ? DB::table('sales_campaign_recipients')->where('token', $token)->first() : null;
    }

    public function open(Request $request, string $token)
    {
        $r = $this->recipient($token);
        if ($r) { try { SalesCampaigns::opened($r, $request); } catch (\Throwable $e) {} }
        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token, string $key)
    {
        $r = $this->recipient($token);
        $url = $r ? SalesCampaigns::clicked($r, $key, $request) : null;
        return redirect()->away($url ?: 'https://www.kiddietrac.com/');
    }

    public function interested(Request $request, string $token)
    {
        $r = $this->recipient($token);
        if (! $r) return response(SalesCampaigns::page('Link expired', '<h1>This link has expired</h1><p>Please reply to our email or call ' . SalesCampaigns::PHONE . ', and we will be glad to help.</p>'), 404);
        $first = trim((string) strtok((string) $r->name, ' '));
        $hi = $first !== '' ? 'Thanks, ' . e($first) . '!' : 'Thank you!';
        if ($request->isMethod('post')) {
            SalesCampaigns::respond($r, 'interested');
            \App\Support\Audit::write(['user_id' => null, 'action' => 'sales.campaign_interested', 'entity_type' => 'sales_campaign', 'entity_id' => (int) $r->campaign_id,
                'payload' => json_encode(['summary' => $r->email . ' asked for more information from a sales campaign']), 'created_at' => now()]);
            return response(SalesCampaigns::page('Thank you', '<h1>' . $hi . '</h1><p>Someone from our team will be in touch within one business day with more about KiddieTrac.</p>'
                . '<p>Rather pick a time now? A short demo is the quickest way to see it with your own centre in mind.</p>'
                . '<p style="margin-top:22px"><a class="btn" href="https://www.kiddietrac.com/?book=1">📅 Book a 20-minute demo</a></p>'));
        }
        return response(SalesCampaigns::page('More information', '<h1>Would you like to hear more?</h1><p>Press the button and someone from our team will be in touch within one business day.</p>'
            . '<form method="post" style="margin-top:22px"><button class="btn" type="submit">Yes, send me more information</button>'
            . '<a class="alt" href="https://www.kiddietrac.com/?book=1">or book a demo</a></form>'));
    }

    public function unsubscribe(Request $request, string $token)
    {
        $r = $this->recipient($token);
        if (! $r) return response(SalesCampaigns::page('Unsubscribe', '<h1>This link has expired</h1><p>To stop KiddieTrac sales emails, reply to any of them with “unsubscribe” or write to ' . SalesCampaigns::FROM . '.</p>'), 404);
        $tpl = (bool) DB::table('sales_campaigns')->where('id', $r->campaign_id)->value('template');   // a subscriber email (2026-10-27)
        if ($tpl) {
            if ($request->isMethod('post')) {
                SalesCampaigns::respond($r, 'unsubscribed');
                \App\Support\Audit::write(['user_id' => null, 'action' => 'sales.campaign_unsubscribed', 'entity_type' => 'sales_campaign', 'entity_id' => (int) $r->campaign_id,
                    'payload' => json_encode(['summary' => $r->email . ' unsubscribed from KiddieTrac emails']), 'created_at' => now()]);
                return response(SalesCampaigns::page('Unsubscribed', '<h1>You’re unsubscribed</h1><p><b>' . e($r->email) . '</b> will not receive KiddieTrac news and updates again.</p><p>If that was a mistake, write to info@kiddietrac.com and we will put it right.</p>'));
            }
            return response(SalesCampaigns::page('Unsubscribe', '<h1>Stop KiddieTrac emails?</h1><p>We will not send news and updates to <b>' . e($r->email) . '</b> again.</p>'
                . '<form method="post" style="margin-top:22px"><button class="btn" type="submit">Unsubscribe</button></form>'));
        }
        if ($request->isMethod('post')) {
            SalesCampaigns::respond($r, 'unsubscribed');
            \App\Support\Audit::write(['user_id' => null, 'action' => 'sales.campaign_unsubscribed', 'entity_type' => 'sales_campaign', 'entity_id' => (int) $r->campaign_id,
                'payload' => json_encode(['summary' => $r->email . ' unsubscribed from KiddieTrac sales emails']), 'created_at' => now()]);
            return response(SalesCampaigns::page('Unsubscribed', '<h1>You’re unsubscribed</h1><p><b>' . e($r->email) . '</b> will not receive KiddieTrac sales emails again.</p><p>If that was a mistake, just reply to our email and we will put it right.</p>'));
        }
        return response(SalesCampaigns::page('Unsubscribe', '<h1>Stop KiddieTrac sales emails?</h1><p>We will not send sales emails to <b>' . e($r->email) . '</b> again.</p>'
            . '<form method="post" style="margin-top:22px"><button class="btn" type="submit">Unsubscribe</button></form>'));
    }
}
