<?php

use App\Http\Controllers\Admin\AssistanceController as AdminAssistanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BadgeCountController;
use App\Http\Controllers\Admin\ChatReportController;
use App\Http\Controllers\Admin\CommitteeController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\ContributionController as AdminContributionController;
use App\Http\Controllers\Admin\ExpertiseCardController;
use App\Http\Controllers\Admin\FeaturedProgrammerController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FundController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\HeroOverlayController;
use App\Http\Controllers\Admin\HeroSlideController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\Admin\PaymentReminderController as AdminPaymentReminderController;
use App\Http\Controllers\Admin\ProfileListController;
use App\Http\Controllers\Admin\RegistrationTermsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SiteContentController;
use App\Http\Controllers\Admin\SiteImageController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\AssistanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatSafetyController;
use App\Http\Controllers\CommitteeChatController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FundReportController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicGalleryController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\PublicProgrammerController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\PushController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = 'connected';
    } catch (Throwable $e) {
        $database = 'error';
    }

    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'database' => $database,
        'time' => now('Asia/Dhaka')->toIso8601String(),
    ]);
});

// Public pages (no login needed)
Route::post('/contact', [PublicSiteController::class, 'contact'])->middleware('throttle:contact');
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/hero-slides', [PublicSiteController::class, 'heroSlides']);
    Route::get('/site-stats', [PublicSiteController::class, 'siteStats']);
    Route::get('/districts', [PublicSiteController::class, 'districts']);
    Route::get('/registration-terms', [RegistrationTermsController::class, 'publicShow']);
    Route::get('/profile-options', [PublicSiteController::class, 'profileOptions']);
});

// Public Programmers directory + profile (read-only, 60/min per IP). "filters" and "home" must stay before {id}.
Route::middleware('throttle:directory')->prefix('public/programmers')->group(function () {
    Route::get('/', [PublicProgrammerController::class, 'index']);
    Route::get('/filters', [PublicProgrammerController::class, 'filters']);
    Route::get('/home', [PublicProgrammerController::class, 'home']);
    Route::get('/{id}', [PublicProgrammerController::class, 'show']);
});

// Public gallery (read-only, published images only, 60/min per IP). "categories" must stay before {id}.
Route::middleware('throttle:gallery')->prefix('public/gallery')->group(function () {
    Route::get('/', [PublicGalleryController::class, 'index']);
    Route::get('/categories', [PublicGalleryController::class, 'categories']);
    Route::get('/{id}', [PublicGalleryController::class, 'show']);
});

// Public notices (read-only, published "public" notices only, 60/min per IP)
Route::middleware('throttle:notices')->prefix('public/notices')->group(function () {
    Route::get('/', [NoticeController::class, 'publicIndex']);
    Route::get('/{id}', [NoticeController::class, 'publicShow']);
});

// Public Home data: Expertise cards + automatic stats (read-only, 60/min per IP)
Route::middleware('throttle:home')->prefix('public')->group(function () {
    Route::get('/expertise-cards', [PublicHomeController::class, 'expertiseCards']);
    Route::get('/stats', [PublicHomeController::class, 'stats']);
    // Site contact info + About text, About committee list (Phase 11c)
    Route::get('/site-info', [PublicSiteController::class, 'siteInfo']);
    Route::get('/committee', [PublicSiteController::class, 'committee']);
    // Editable public-page text (Phase 14a)
    Route::get('/site-content', [PublicSiteController::class, 'siteContent']);
    // Admin-uploaded site images (Site Content Manager part 2)
    Route::get('/site-images', [PublicSiteController::class, 'siteImages']);
});

// Auth (Sanctum SPA cookie session)
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Allowed while must_change_password is set.
    Route::middleware('active')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:password');
    });

    // Fund Report — members only (MASTER_SPEC §33): any approved account (401 logged out, 403 not approved)
    Route::middleware(['active:password', 'throttle:120,1'])->prefix('fund-report')->group(function () {
        Route::get('/', [FundReportController::class, 'summary']);
        Route::get('/cases', [FundReportController::class, 'cases']);
        Route::get('/expenses', [FundReportController::class, 'expenses']);
    });

    // Dashboard summary cards (role-aware, read-only)
    Route::get('/payment-reminder/status', [PaymentReminderController::class, 'status'])->middleware('active:password');
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->middleware('active:password');

    // Own profile, photo and private documents (approved members)
    Route::middleware('active:password')->prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::post('/photo', [ProfileController::class, 'uploadPhoto']);
        Route::post('/documents', [ProfileController::class, 'uploadDocument']);
        Route::delete('/documents/{document}', [ProfileController::class, 'destroyDocument'])->whereNumber('document');
        // Owner, admin or super_admin (checked in the controller).
        Route::get('/documents/{document}/download', [ProfileController::class, 'downloadDocument']);

        Route::get('/portfolio', [PortfolioController::class, 'index']);
        Route::post('/portfolio', [PortfolioController::class, 'store']);
        Route::put('/portfolio/order', [PortfolioController::class, 'reorder']);
        Route::put('/portfolio/{item}', [PortfolioController::class, 'update'])->whereNumber('item');
        Route::delete('/portfolio/{item}', [PortfolioController::class, 'destroy'])->whereNumber('item');
    });

    // Notices for approved members (incl. "members only" notices)
    Route::middleware('active:password')->prefix('notices')->group(function () {
        Route::get('/', [NoticeController::class, 'memberIndex']);
        Route::get('/{id}', [NoticeController::class, 'memberShow']);
        Route::get('/{id}/attachment', [NoticeController::class, 'attachment']);
    });

    // Own contribution payments + dues (members and committee only — Admin / Super Admin are management-only, owner decision 2026-10-20)
    Route::middleware(['active:password', 'role:member,committee'])->prefix('contributions')->whereNumber('contribution')->group(function () {
        Route::get('/', [ContributionController::class, 'index']);
        Route::get('/dues', [ContributionController::class, 'dues']);
        // The fund's receiving accounts + instructions (shown parts only, Phase 7e)
        Route::get('/accounts', [ContributionController::class, 'accounts']);
        Route::post('/', [ContributionController::class, 'store'])->middleware('throttle:contributions');
    });
    // The member themself, admin or super_admin (checked in the controller) — admins review other people's proofs.
    Route::get('/contributions/{contribution}/proof', [ContributionController::class, 'proof'])
        ->whereNumber('contribution')->middleware('active:password');

    // Admin: sidebar badge numbers for all admin queues in one request (admin + super_admin).
    Route::get('/admin/badge-counts', [BadgeCountController::class, 'show'])
        ->middleware(['active:password', 'role:admin,super_admin']);

    // Admin: payment verification (admin + super_admin — owner instruction for Phase 7a)
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/contributions')
        ->whereNumber('contribution')
        ->group(function () {
            Route::get('/', [AdminContributionController::class, 'index']);
            Route::get('/{contribution}', [AdminContributionController::class, 'show']);
            Route::post('/{contribution}/approve', [AdminContributionController::class, 'approve']);
            Route::post('/{contribution}/reject', [AdminContributionController::class, 'reject']);
        });

    // Aid requests (Phase 8a, MASTER_SPEC §29–§30): own requests + the open list with advisory votes (any approved account;
    // applying / voting rules checked in the controller). "open" must stay before {assistance}.
    Route::middleware('active:password')->prefix('assistance')->whereNumber(['assistance', 'document'])->group(function () {
        Route::get('/', [AssistanceController::class, 'index']);
        Route::post('/', [AssistanceController::class, 'store'])->middleware('throttle:assistance');
        Route::get('/open', [AssistanceController::class, 'open']);
        Route::get('/{assistance}', [AssistanceController::class, 'show']);
        Route::post('/{assistance}/cancel', [AssistanceController::class, 'cancel']);
        Route::post('/{assistance}/vote', [AssistanceController::class, 'vote']);
        // The applicant, admin or super_admin (checked in the controller).
        Route::get('/{assistance}/documents/{document}', [AssistanceController::class, 'document']);
        Route::get('/{assistance}/payout-proof', [AssistanceController::class, 'payoutProof']);
    });

    // In-app notifications (Phase 10a, MASTER_SPEC §36): own notifications only.
    Route::middleware('active:password')->prefix('notifications')->whereNumber('notification')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::post('/read-all', [NotificationController::class, 'readAll']);
        Route::post('/{notification}/read', [NotificationController::class, 'read']);
    });

    // Phone / desktop Web Push (Phase 10c-1, MASTER_SPEC §36): same access as the bell; demo accounts refused.
    Route::middleware(['active:password', 'throttle:60,1'])->prefix('push')->group(function () {
        Route::get('/public-key', [PushController::class, 'publicKey']);
        Route::post('/subscribe', [PushController::class, 'subscribe']);
        Route::post('/unsubscribe', [PushController::class, 'unsubscribe']);
        Route::get('/settings', [PushController::class, 'show']);
        Route::put('/settings', [PushController::class, 'update']);
    });

    // 1-to-1 chat (Phase 9a, MASTER_SPEC §35): any approved account; only the two participants (checked in the controller).
    Route::middleware('active:password')->prefix('chat')->whereNumber('conversation')->group(function () {
        Route::get('/conversations', [ChatController::class, 'index']);
        Route::post('/conversations', [ChatController::class, 'store']);
        Route::get('/unread', [ChatController::class, 'unread']);
        Route::get('/conversations/{conversation}', [ChatController::class, 'show']);
        Route::get('/conversations/{conversation}/messages', [ChatController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:chat');
        Route::post('/conversations/{conversation}/read', [ChatController::class, 'read']);
        // Block + report (Phase 9d-1): blocks stop messages both ways; a report = one received message (10 per day).
        Route::get('/blocks', [ChatSafetyController::class, 'blocks']);
        Route::post('/blocks', [ChatSafetyController::class, 'block']);
        Route::delete('/blocks/{user}', [ChatSafetyController::class, 'unblock'])->whereNumber('user');
        Route::post('/messages/{message}/report', [ChatSafetyController::class, 'report'])->whereNumber('message');
    });

    // Private Committee Chat: any approved login reaches the route, but only active committee panel members +
    // admin / super_admin get through (CommitteeChat::canAccess, checked in the controller → everyone else 403).
    Route::middleware('active:password')->prefix('committee-chat')->group(function () {
        Route::get('/unread', [CommitteeChatController::class, 'unread']);
        Route::get('/messages', [CommitteeChatController::class, 'messages']);
        Route::post('/messages', [CommitteeChatController::class, 'send'])->middleware('throttle:chat');
        Route::post('/read', [CommitteeChatController::class, 'read']);
        Route::get('/messages/{message}/attachment', [CommitteeChatController::class, 'attachment'])->whereNumber('message');
    });

    // Admin: chat reports (Phase 9d-1) — admin + super_admin; only the reported message's text copy, never the chat.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/chat-reports')
        ->whereNumber('report')
        ->group(function () {
            Route::get('/', [ChatReportController::class, 'index']);
            Route::get('/{report}', [ChatReportController::class, 'show']);
            Route::post('/{report}/review', [ChatReportController::class, 'review']);
            Route::post('/{report}/dismiss', [ChatReportController::class, 'dismiss']);
        });

    // Admin: aid decisions + payouts (admin + super_admin — owner, Phase 8a)
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/assistance')
        ->whereNumber('assistance')
        ->group(function () {
            Route::get('/', [AdminAssistanceController::class, 'index']);
            Route::get('/{assistance}', [AdminAssistanceController::class, 'show']);
            Route::post('/{assistance}/approve', [AdminAssistanceController::class, 'approve']);
            Route::post('/{assistance}/reject', [AdminAssistanceController::class, 'reject']);
            Route::post('/{assistance}/pay', [AdminAssistanceController::class, 'pay']);
        });

    // Programmers List + member profile: read-only for committee (MASTER_SPEC §10, §37a)
    Route::middleware(['active:password', 'role:committee,admin,super_admin'])
        ->prefix('admin/members')
        ->group(function () {
            Route::get('/', [MemberController::class, 'index']);
            Route::get('/{member}/profile', [MemberController::class, 'profile']);
            // Month-by-month payment history (Phase 7d)
            Route::get('/{member}/payments', [FundController::class, 'memberHistory']);
        });

    // Fund-wide monthly collection summary — read-only for committee too (MASTER_SPEC §10, §37a)
    Route::get('/admin/fund/monthly', [FundController::class, 'monthly'])
        ->middleware(['active:password', 'role:committee,admin,super_admin']);

    // Fund Settings (Phase 7e) — Super Admin only (MASTER_SPEC §10 "Change settings")
    Route::middleware(['active:password', 'role:super_admin'])
        ->prefix('admin/settings')
        ->group(function () {
            Route::get('/', [SettingsController::class, 'show']);
            Route::post('/rates', [SettingsController::class, 'storeRate']);
            Route::delete('/rates/{rate}', [SettingsController::class, 'destroyRate'])->whereNumber('rate');
            Route::put('/accounts', [SettingsController::class, 'updateAccounts']);
            Route::put('/start-month', [SettingsController::class, 'updateStartMonth']);
        });

    // Expenses (খরচের হিসাব) — admin + super_admin; delete = super_admin only. Categories managed by admins.
    Route::middleware(['active:password', 'role:admin,super_admin'])->prefix('admin')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('throttle:60,1');
        Route::post('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->middleware('throttle:60,1');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->middleware('throttle:60,1');
        Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->whereNumber('expense');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->middleware('role:super_admin');
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store']);
        Route::patch('/expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('/expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->whereNumber('category')->middleware('role:super_admin');
    });

    // Fund ledger corrections + integrity check (Phase 12b, MASTER_SPEC §34) — Super Admin only.
    Route::middleware(['active:password', 'role:super_admin'])
        ->prefix('admin/ledger')
        ->group(function () {
            Route::get('/', [LedgerController::class, 'index']);
            Route::get('/integrity', [LedgerController::class, 'integrity']);
            Route::post('/adjustments', [LedgerController::class, 'adjust'])->middleware('throttle:30,1');
            Route::post('/{entry}/reverse', [LedgerController::class, 'reverse'])->whereNumber('entry')->middleware('throttle:30,1');
        });

    // Admin: member approval and changes (admin + super_admin)
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/members')
        ->group(function () {
            Route::patch('/{member}', [MemberController::class, 'updateDetails']);
            Route::post('/{member}/approve', [MemberController::class, 'approve']);
            Route::post('/{member}/reject', [MemberController::class, 'reject']);
            Route::post('/{member}/suspend', [MemberController::class, 'suspend']);
            Route::post('/{member}/reset-password', [MemberController::class, 'resetPassword']);
            Route::post('/{member}/role', [MemberController::class, 'changeRole'])->middleware('role:super_admin');
        });

    // Admin: gallery (admin + super_admin; committee has no content powers — MASTER_SPEC §10)
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/gallery')
        ->group(function () {
            Route::get('/images', [GalleryController::class, 'index']);
            Route::post('/images', [GalleryController::class, 'store']);
            Route::put('/images/order', [GalleryController::class, 'reorder']);
            Route::patch('/images/{image}', [GalleryController::class, 'update'])->whereNumber('image');
            Route::delete('/images/{image}', [GalleryController::class, 'destroy'])->whereNumber('image');

            Route::get('/categories', [GalleryController::class, 'categories']);
            Route::post('/categories', [GalleryController::class, 'storeCategory']);
            Route::patch('/categories/{category}', [GalleryController::class, 'updateCategory'])->whereNumber('category');
            Route::delete('/categories/{category}', [GalleryController::class, 'destroyCategory'])->whereNumber('category');
        });

    // Admin: notices (admin + super_admin, same as the gallery)
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/notices')
        ->whereNumber('notice')
        ->group(function () {
            Route::get('/', [AdminNoticeController::class, 'index']);
            Route::post('/', [AdminNoticeController::class, 'store']);
            Route::get('/{notice}', [AdminNoticeController::class, 'show']);
            Route::patch('/{notice}', [AdminNoticeController::class, 'update']);
            Route::delete('/{notice}', [AdminNoticeController::class, 'destroy']);
            Route::post('/{notice}/attachment', [AdminNoticeController::class, 'replaceAttachment']);
            Route::delete('/{notice}/attachment', [AdminNoticeController::class, 'removeAttachment']);
            Route::post('/{notice}/publish', [AdminNoticeController::class, 'publish']);
            Route::post('/{notice}/unpublish', [AdminNoticeController::class, 'unpublish']);
            Route::post('/{notice}/pin', [AdminNoticeController::class, 'pin']);
            Route::post('/{notice}/unpin', [AdminNoticeController::class, 'unpin']);
        });

    // Admin: Home Expertise cards (admin + super_admin, same as the gallery). "order" must stay before {card}.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/expertise-cards')
        ->whereNumber('card')
        ->group(function () {
            Route::get('/', [ExpertiseCardController::class, 'index']);
            Route::post('/', [ExpertiseCardController::class, 'store']);
            Route::put('/order', [ExpertiseCardController::class, 'reorder']);
            Route::patch('/{card}', [ExpertiseCardController::class, 'update']);
            Route::delete('/{card}', [ExpertiseCardController::class, 'destroy']);
            Route::post('/{card}/publish', [ExpertiseCardController::class, 'publish']);
            Route::post('/{card}/unpublish', [ExpertiseCardController::class, 'unpublish']);
            Route::post('/{card}/image', [ExpertiseCardController::class, 'replaceImage']);
            Route::delete('/{card}/image', [ExpertiseCardController::class, 'removeImage']);
        });

    // Admin: Home hero slides (Phase 11b-1) — admin + super_admin, same as the gallery. "order" must stay before {slide}.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/hero-slides')
        ->whereNumber('slide')
        ->group(function () {
            Route::get('/', [HeroSlideController::class, 'index']);
            Route::post('/', [HeroSlideController::class, 'store']);
            Route::put('/order', [HeroSlideController::class, 'reorder']);
            Route::patch('/{slide}', [HeroSlideController::class, 'update']);
            Route::post('/{slide}/active', [HeroSlideController::class, 'setActive']);
            Route::delete('/{slide}', [HeroSlideController::class, 'destroy']);
        });

    // Admin: featured programmers (Phase 11b-2) — admin + super_admin. "order" / "candidates" must stay before {member}.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/featured-programmers')
        ->whereNumber('member')
        ->group(function () {
            Route::get('/', [FeaturedProgrammerController::class, 'index']);
            Route::get('/candidates', [FeaturedProgrammerController::class, 'candidates']);
            Route::post('/', [FeaturedProgrammerController::class, 'store']);
            Route::put('/order', [FeaturedProgrammerController::class, 'reorder']);
            Route::delete('/{member}', [FeaturedProgrammerController::class, 'destroy']);
        });

    // Admin: site contact info + About text (Phase 11c) — admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/site-settings')
        ->group(function () {
            Route::get('/', [SiteSettingsController::class, 'show']);
            Route::put('/', [SiteSettingsController::class, 'update']);
        });

    // Admin: payment-due reminder (message, on/off, who gets it) - admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/payment-reminder')
        ->group(function () {
            Route::get('/', [AdminPaymentReminderController::class, 'show']);
            Route::put('/', [AdminPaymentReminderController::class, 'update']);
        });

    // Admin: registration terms & conditions - admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/registration-terms')
        ->group(function () {
            Route::get('/', [RegistrationTermsController::class, 'show']);
            Route::put('/', [RegistrationTermsController::class, 'update']);
        });

    // Admin: Site Content — editable public-page text (Phase 14a) — admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/site-content')
        ->group(function () {
            Route::get('/', [SiteContentController::class, 'index']);
            Route::put('/', [SiteContentController::class, 'update']);
            Route::post('/reset', [SiteContentController::class, 'reset']);
        });

    // Admin: hero slider overlay darkness (Site Content → Images) — admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/hero-overlay')
        ->group(function () {
            Route::get('/', [HeroOverlayController::class, 'show']);
            Route::put('/', [HeroOverlayController::class, 'update']);
        });

    // Admin: Site Content images (part 2) — admin + super_admin.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/site-images')
        ->group(function () {
            Route::get('/', [SiteImageController::class, 'index']);
            Route::post('/{key}', [SiteImageController::class, 'store']);
            Route::delete('/{key}', [SiteImageController::class, 'destroy']);
        });

    // Admin: Committee panel — members, positions, order (admin + super_admin; committee / member 403). Fixed paths
    // ("candidates", "order", "positions") stay before {member}.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/committee')
        ->group(function () {
            Route::get('/', [CommitteeController::class, 'index']);
            Route::get('/candidates', [CommitteeController::class, 'candidates']);
            Route::post('/', [CommitteeController::class, 'store']);
            Route::put('/order', [CommitteeController::class, 'reorder']);
            Route::post('/positions', [CommitteeController::class, 'storePosition']);
            Route::patch('/positions/{position}', [CommitteeController::class, 'updatePosition'])->whereNumber('position');
            Route::delete('/positions/{position}', [CommitteeController::class, 'destroyPosition'])->whereNumber('position');
            Route::patch('/{member}', [CommitteeController::class, 'update'])->whereNumber('member');
            Route::delete('/{member}', [CommitteeController::class, 'destroy'])->whereNumber('member');
        });

    // Admin: Machine + Skill lists (Phase 11d-1) — admin + super_admin; only super_admin deletes (unused items only).
    // "order" must stay before {item}.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/profile-lists')
        ->whereNumber('item')
        ->group(function () {
            Route::get('/', [ProfileListController::class, 'index']);
            Route::post('/', [ProfileListController::class, 'store']);
            Route::put('/order', [ProfileListController::class, 'reorder']);
            Route::get('/others', [ProfileListController::class, 'others']);
            Route::post('/others/add', [ProfileListController::class, 'addOther']);
            Route::patch('/{item}', [ProfileListController::class, 'update']);
            Route::post('/{item}/active', [ProfileListController::class, 'setActive']);
            Route::delete('/{item}', [ProfileListController::class, 'destroy'])->middleware('role:super_admin');
        });

    // Admin: contact form messages (Phase 11a) — admin + super_admin read / mark; only super_admin deletes.
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/contact-messages')
        ->whereNumber('message')
        ->group(function () {
            Route::get('/', [ContactMessageController::class, 'index']);
            Route::get('/{message}', [ContactMessageController::class, 'show']);
            Route::post('/{message}/read', [ContactMessageController::class, 'markRead']);
            Route::post('/{message}/unread', [ContactMessageController::class, 'markUnread']);
            Route::delete('/{message}', [ContactMessageController::class, 'destroy'])->middleware('role:super_admin');
        });

    // Admin: audit log (Phase 11a) — admin + super_admin, read-only (no edit / delete endpoints).
    Route::middleware(['active:password', 'role:admin,super_admin'])
        ->prefix('admin/audit-log')
        ->group(function () {
            Route::get('/', [AuditLogController::class, 'index']);
            Route::get('/actions', [AuditLogController::class, 'actions']);
        });
});
