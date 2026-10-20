<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistanceRequest;
use App\Models\ChatReport;
use App\Models\ContactMessage;
use App\Models\Contribution;
use App\Models\User;
use App\Support\CommitteeChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sidebar red-badge numbers for the admin queues (admin + super_admin by route middleware — the same roles that
 * see these menu items). Read-only; one request instead of one per menu item.
 */
class BadgeCountController extends Controller
{
    /** GET /api/admin/badge-counts */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            // New sign-ups waiting for approval (menu: Members).
            'pending_members' => User::where('status', User::STATUS_PENDING)->count(),
            // Payments waiting for a check (menu: Payment Review).
            'pending_payments' => Contribution::where('status', Contribution::STATUS_PENDING)->count(),
            // Aid requests that need an admin: to decide (submitted) or to pay out (approved) (menu: Aid Applications).
            'pending_aid' => AssistanceRequest::whereIn('status', AssistanceRequest::OPEN_STATUSES)->count(),
            // Reported chat messages not yet looked at (menu: Chat Reports).
            'open_chat_reports' => ChatReport::where('status', ChatReport::STATUS_OPEN)->count(),
            // Contact form messages not yet read (menu: Contact Messages).
            'unread_contact_messages' => ContactMessage::where('is_read', false)->count(),
            // Unread messages in the private Committee Chat (menu: Committee Chat).
            'committee_chat_unread' => CommitteeChat::unreadFor($request->user()),
        ]]);
    }
}
