<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Api\ApiAccessController;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilesController;
use UseFlex\MediaStudio\Assistant\AssistantActionsController;
use UseFlex\MediaStudio\Assistant\AssistantController;
use UseFlex\MediaStudio\Assistant\AssistantProfileController;
use UseFlex\MediaStudio\Integrations\BrowserExtension\DeviceController;
use UseFlex\MediaStudio\Integrations\IntegrationsController;
use UseFlex\MediaStudio\Integrations\Slack\SlackController;
use UseFlex\MediaStudio\Integrations\Slack\SlackOAuthController;
use UseFlex\MediaStudio\Search\SearchController;
use UseFlex\MediaStudio\Uploads\UploadController;
use UseFlex\MediaStudio\Workspaces\WorkspaceOperationsController;

final class AuthorizeStudioOperation
{
    public const ACTIONS = [
        WorkspaceOperationsController::class => ['store' => 'workspaces.manage', 'show' => 'view', 'retry' => 'workspaces.manage'],
        AssistantActionsController::class => ['undo' => 'ai.view'],
        AssistantProfileController::class => ['show' => 'ai.view', 'update' => 'ai.view'],
        AssistantController::class => ['index' => 'ai.view', 'store' => 'ai.view', 'show' => 'ai.view', 'send' => 'ai.view', 'cancel' => 'ai.view', 'events' => 'ai.view', 'upload' => 'ai.view', 'discardAttachment' => 'ai.view', 'rename' => 'ai.view', 'remove' => 'ai.view', 'restoreConversation' => 'ai.view'],
        DeviceController::class => ['approve' => 'extension.use', 'devices' => 'extension.use', 'revoke' => 'extension.use'],
        IntegrationsController::class => ['show' => 'integrations.view'],
        SlackOAuthController::class => ['start' => 'integrations.manage'],
        SlackController::class => ['save' => 'integrations.manage', 'disconnect' => 'integrations.manage'],
        ApiAccessController::class => ['show' => 'api.use', 'store' => 'api.manage', 'revoke' => 'api.manage'],
        AiLabelsController::class => ['index' => 'ai.view', 'update' => 'ai.review'],
        AiController::class => ['show' => 'ai.view'],
        ApplicationFilesController::class => ['sources' => 'application-files.view', 'index' => 'application-files.view', 'content' => 'application-files.view', 'details' => 'application-files.view', 'neighbors' => 'application-files.view', 'catalog' => 'application-files.view', 'catalogDetails' => 'application-files.view', 'catalogContent' => 'application-files.view', 'thumbnailStatus' => 'application-files.view'],
        SearchController::class => ['index' => 'view'],
        SourceImportsController::class => ['index' => 'sources.sync', 'store' => 'sources.sync', 'step' => 'sources.sync', 'update' => 'sources.sync'],
        CommentsController::class => ['index' => 'comments.view', 'mentions' => 'comments.create', 'store' => 'comments.create', 'update' => 'comments.view'],
        VideoEditsController::class => ['show' => 'view', 'store' => 'files.process'],
        NotesController::class => ['show' => 'view', 'store' => 'notes.create', 'update' => 'notes.update'],
        MemberInvitationsController::class => ['index' => 'members.invite', 'store' => 'members.invite', 'revoke' => 'members.invite'],
        StudioController::class => ['workspaces' => 'view', 'workspace' => 'view', 'createWorkspace' => 'workspaces.create', 'updateWorkspace' => 'workspaces.manage', 'deleteWorkspace' => 'workspaces.manage', 'makeDefaultWorkspace' => 'workspaces.manage', 'treeOperation' => 'view', 'advanceTreeOperation' => 'view', 'cancelTreeOperation' => 'view', 'undoNavigation' => 'files.update', 'navigationChanges' => 'view', 'folderContext' => 'view', 'folderAncestors' => 'view', 'browse' => 'view', 'librarySearch' => 'view', 'neighbors' => 'view', 'details' => 'view', 'status' => 'view', 'thumbnail' => 'view', 'preview' => 'view', 'rendition' => 'view', 'content' => 'view', 'star' => 'view', 'visit' => 'view', 'references' => 'view', 'tagDirectory' => 'view', 'filterOptions' => 'view', 'createFolder' => 'folders.create', 'resolveFolders' => 'folders.create', 'upload' => 'uploads.create', 'update' => 'files.update', 'trash' => 'files.trash', 'restore' => 'files.restore', 'optimize' => 'files.optimize', 'replaceTags' => 'tags.manage', 'syncSpatie' => 'sources.sync', 'bulk' => 'files.update'],
        SettingsController::class => ['show' => 'settings.view', 'update' => 'settings.manage'],
        ArchiveController::class => ['index' => 'files.download', 'create' => 'files.download', 'status' => 'files.download', 'result' => 'files.download', 'download' => 'files.download'],
        BrowserPushController::class => ['show' => 'notifications.view', 'preferences' => 'notifications.view', 'subscribe' => 'notifications.view', 'unsubscribe' => 'notifications.view'],
        ActivityController::class => ['trash' => 'view', 'notifications' => 'notifications.view', 'read' => 'notifications.manage', 'readAll' => 'notifications.manage', 'readVisible' => 'notifications.manage'],
        SharingController::class => ['index' => 'shares.view', 'roots' => 'shares.view', 'store' => 'shares.manage', 'update' => 'shares.manage', 'rotate' => 'shares.manage', 'link' => 'shares.manage', 'analytics' => 'shares.view', 'destroy' => 'shares.manage', 'invite' => 'shares.manage'],
        WorkspaceMembersController::class => ['creationCandidates' => 'workspaces.create', 'index' => 'members.view', 'remove' => 'members.manage', 'candidates' => 'members.invite', 'assign' => 'members.invite', 'update' => 'members.manage'],
        UploadController::class => ['policy' => 'uploads.create', 'create' => 'uploads.create', 'status' => 'uploads.create', 'part' => 'uploads.create', 'signPart' => 'uploads.create', 'recordPart' => 'uploads.create', 'complete' => 'uploads.create', 'cancel' => 'uploads.create'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $action = $request->route()->getActionName();
        [$class, $method] = array_pad(explode('@', $action, 2), 2, '');
        $operation = self::ACTIONS[$class][$method] ?? null;
        abort_unless(is_string($operation), 403);
        if ($method === 'bulk') {
            $operation = match ($request->input('operation')) {
                'trash' => 'files.trash', 'restore' => 'files.restore', 'move' => 'files.update', default => 'files.update'
            };
        }
        if ($class === StudioController::class && $method === 'content' && $request->boolean('download')) {
            $operation = 'files.download';
        }
        $workspaceId = $request->route('workspace');
        if (is_string($workspaceId)) {
            $request->attributes->set('fms.required-operation', ['workspace' => $workspaceId, 'operation' => $operation]);
        } else {
            app(StudioPermissions::class)->authorize($actor, $operation);
        }

        return $next($request);
    }
}
