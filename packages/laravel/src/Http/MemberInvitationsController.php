<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Mail\StudioMail;
use UseFlex\MediaStudio\Members\MemberAccess;

final class MemberInvitationsController
{
    public function __construct(private MemberAccess $members) {}

    public function index(Request $request, string $workspace): JsonResponse
    {
        $w = $this->members->resolve($request, $workspace, 'invite');
        $data = $request->validate(['after' => ['nullable', 'ulid']]);
        $rows = DB::table('fms_invitations')->where('workspace_id', $w->id)->when(isset($data['after']), fn ($q) => $q->where('id', '>', $data['after']))->orderBy('id')->limit(26)->get(['id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at']);

        return response()->json(['items' => $rows->take(25)->values(), 'nextCursor' => $rows->count() > 25 ? $rows[24]->id : null]);
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'role' => ['required', 'in:admin,viewer,editor']]);
        $principal = Principal::fromUser($request->user());
        abort_unless(class_exists(Mail::class), 503);
        $token = bin2hex(random_bytes(32));
        $email = mb_strtolower(trim($data['email']));
        $result = DB::transaction(function () use ($request, $workspace, $data, $principal, $token, $email): array {
            $w = $this->members->resolve($request, $workspace, 'invite', lock: true);
            $this->members->authorizeRole($w, $data['role']);
            $existing = DB::table('fms_invitations')->where('workspace_id', $w->id)->where('email_key', hash('sha256', $email))->first();
            $id = $existing?->id ?? (string) Str::ulid();
            $values = ['id' => $id, 'role' => $data['role'], 'email' => $email, 'token_hash' => hash('sha256', $token), 'inviter_type' => $principal->type, 'inviter_id' => $principal->id, 'expires_at' => now()->addDays(max(1, min(30, (int) config('media-studio.members.invitation_days', 7)))), 'accepted_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()];
            DB::table('fms_invitations')->updateOrInsert(['workspace_id' => $w->id, 'email_key' => hash('sha256', $email)], $values);

            return ['id' => $id, 'workspace' => $w->name];
        }, 3);
        $url = url(trim((string) config('media-studio.routes.prefix'), '/').'/invitations/'.$token);
        StudioMail::raw('You have been invited to '.$result['workspace'].'. Sign in with '.$email.' and accept your invitation: '.$url, fn ($mail) => $mail->to($email)->subject('Invitation to '.$result['workspace']));

        return response()->json(['id' => $result['id']], 201);
    }

    public function revoke(Request $request, string $workspace, string $invitation): JsonResponse
    {
        DB::transaction(function () use ($request, $workspace, $invitation): void {
            $w = $this->members->resolve($request, $workspace, 'invite', lock: true);
            $row = DB::table('fms_invitations')->where('workspace_id', $w->id)->where('id', $invitation)->first();
            abort_if($row === null, 404);
            $this->members->authorizeRole($w, $row->role);
            DB::table('fms_invitations')->where('id', $row->id)->whereNull('accepted_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        }, 3);

        return response()->json(['revoked' => true]);
    }

    public function show(Request $request, string $token): Response
    {
        $row = $this->invitation($request, $token);

        return response()->view('media-studio::invitation', ['token' => $token, 'role' => $row->role])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token, WorkspaceAccess $access): JsonResponse|RedirectResponse
    {
        $principal = Principal::fromUser($request->user());
        DB::transaction(function () use ($request, $token, $access, $principal): void {
            $row = $this->invitation($request, $token);
            $w = DB::table('fms_workspaces')->where('id', $row->workspace_id)->lockForUpdate()->first();
            abort_if($w === null, 404);
            abort_if(($w->kind ?? 'team') === 'personal', 403);
            $row = $this->invitation($request, $token, lock: true);
            $tenant = $access->tenant($principal);
            abort_unless(($tenant?->key()) === ($w->tenant_key ?? null), 404);
            $inviter = new Principal($row->inviter_type, $row->inviter_id);
            $inviterWorkspace = $access->resolveForWorker($inviter, $w->public_id, TenantIdentity::fromWorkspace($w), operation: 'members.invite');
            $this->members->authorizeRole($inviterWorkspace, $row->role);
            DB::table('fms_memberships')->insertOrIgnore(['workspace_id' => $w->id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => $row->role]);
            DB::table('fms_invitations')->where('id', $row->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        }, 3);

        return $request->expectsJson()
            ? response()->json(['accepted' => true])
            : redirect((string) config('media-studio.members.accept_redirect', '/'))->with('status', 'Invitation accepted.');
    }

    private function invitation(Request $request, string $token, bool $lock = false): object
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($user instanceof MustVerifyEmail && $user->hasVerifiedEmail(), 403, 'Verify your email before accepting an invitation.');
        $email = data_get($user, (string) config('media-studio.members.email_attribute', 'email'));
        abort_unless(is_string($email), 403);
        $row = DB::table('fms_invitations')->where('token_hash', hash('sha256', $token))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_if($row === null || $row->accepted_at !== null || $row->revoked_at !== null || strtotime($row->expires_at) <= time(), 404);
        abort_unless(hash_equals($row->email_key, hash('sha256', mb_strtolower(trim($email)))), 403);

        return $row;
    }
}
