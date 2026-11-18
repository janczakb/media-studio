<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilesController;
use UseFlex\MediaStudio\Http\AiController;
use UseFlex\MediaStudio\Http\AiLabelsController;
use UseFlex\MediaStudio\Http\ArchiveController;
use UseFlex\MediaStudio\Http\CommentsController;
use UseFlex\MediaStudio\Http\MemberInvitationsController;
use UseFlex\MediaStudio\Http\NotesController;
use UseFlex\MediaStudio\Http\SettingsController;
use UseFlex\MediaStudio\Http\SourceImportsController;
use UseFlex\MediaStudio\Http\StudioController;
use UseFlex\MediaStudio\Http\VideoEditsController;
use UseFlex\MediaStudio\Http\WorkspaceMembersController;
use UseFlex\MediaStudio\Integrations\IntegrationsController;
use UseFlex\MediaStudio\Integrations\Slack\SlackController;
use UseFlex\MediaStudio\Integrations\Slack\SlackOAuthController;
use UseFlex\MediaStudio\Search\SearchController;
use UseFlex\MediaStudio\Workspaces\WorkspaceOperationsController;

Route::get('workspaces/{workspace}/ai/labels', [AiLabelsController::class, 'index'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}/ai/labels/{label}', [AiLabelsController::class, 'update'])->whereUlid('workspace')->whereNumber('label');
Route::get('workspaces/{workspace}/integrations', [IntegrationsController::class, 'show'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/integrations/slack/oauth', [SlackOAuthController::class, 'start'])->whereUlid('workspace')->middleware('throttle:10,1');
Route::put('workspaces/{workspace}/integrations/slack', [SlackController::class, 'save'])->whereUlid('workspace');
Route::delete('workspaces/{workspace}/integrations/slack', [SlackController::class, 'disconnect'])->whereUlid('workspace');
Route::get('ai', [AiController::class, 'show']);
Route::get('application-files', [ApplicationFilesController::class, 'sources']);
Route::post('application-files/catalog/thumbnail-status', [ApplicationFilesController::class, 'thumbnailStatus']);
Route::get('application-files/catalog', [ApplicationFilesController::class, 'catalog']);
Route::get('application-files/catalog/entries/{entry}', [ApplicationFilesController::class, 'catalogDetails'])->whereUlid('entry');
Route::get('application-files/catalog/entries/{entry}/neighbors', [ApplicationFilesController::class, 'neighbors'])->whereUlid('entry');
Route::get('application-files/catalog/entries/{entry}/{variant}', [ApplicationFilesController::class, 'catalogContent'])->whereUlid('entry')->where('variant', 'original|thumbnail|preview|generated-thumbnail');
Route::get('application-files/{source}', [ApplicationFilesController::class, 'index'])->where('source', '[a-z0-9_-]{1,64}');
Route::get('application-files/{source}/entries/{entry}', [ApplicationFilesController::class, 'details'])->where('source', '[a-z0-9_-]{1,64}')->whereUlid('entry');
Route::get('application-files/{source}/entries/{entry}/{variant}', [ApplicationFilesController::class, 'content'])->where('source', '[a-z0-9_-]{1,64}')->whereUlid('entry')->where('variant', 'original|thumbnail|preview|generated-thumbnail');

Route::get('workspaces/{workspace}/settings', [SettingsController::class, 'show'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}/settings', [SettingsController::class, 'update'])->whereUlid('workspace');
Route::get('workspaces', [StudioController::class, 'workspaces'])->name('workspaces');
Route::get('workspaces/{workspace}/members', [WorkspaceMembersController::class, 'index'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/members/{member}/remove', [WorkspaceMembersController::class, 'remove'])->whereUlid('workspace')->whereNumber('member');
Route::get('workspaces/{workspace}', [StudioController::class, 'workspace'])->whereUlid('workspace');
Route::post('workspaces', [StudioController::class, 'createWorkspace']);
Route::post('workspaces/{workspace}/references/resolve', [StudioController::class, 'references'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/sources/spatie/{mount}/sync', [StudioController::class, 'syncSpatie'])->whereUlid('workspace')->where('mount', '[A-Za-z0-9_-]{1,64}');
Route::get('workspaces/{workspace}/entries/{entry}/versions/{version}/content', [StudioController::class, 'content'])->whereUlid(['workspace', 'entry', 'version']);
Route::post('workspaces/{workspace}/entries/bulk', [StudioController::class, 'bulk'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/download', [ArchiveController::class, 'download'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/archive-jobs', [ArchiveController::class, 'create'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/archive-jobs', [ArchiveController::class, 'index'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/archive-jobs/{job}', [ArchiveController::class, 'status'])->whereUlid(['workspace', 'job']);
Route::get('workspaces/{workspace}/archive-jobs/{job}/result', [ArchiveController::class, 'result'])->whereUlid(['workspace', 'job']);
Route::put('workspaces/{workspace}/entries/{entry}/star', [StudioController::class, 'star'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/entries/{entry}/visit', [StudioController::class, 'visit'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/entries/status', [StudioController::class, 'status'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/tags', [StudioController::class, 'tagDirectory'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/library-search', [StudioController::class, 'librarySearch'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/filter-options', [StudioController::class, 'filterOptions'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}/entries/{entry}/tags', [StudioController::class, 'replaceTags'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries', [StudioController::class, 'browse'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/folders/resolve', [StudioController::class, 'resolveFolders'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/entries/{entry}/neighbors', [StudioController::class, 'neighbors'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/folders', [StudioController::class, 'createFolder'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/uploads', [StudioController::class, 'upload'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/entries/{entry}/optimize', [StudioController::class, 'optimize'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries/{entry}', [StudioController::class, 'details'])->whereUlid(['workspace', 'entry']);
Route::patch('workspaces/{workspace}/entries/{entry}', [StudioController::class, 'update'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/entries/{entry}/trash', [StudioController::class, 'trash'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/entries/{entry}/restore', [StudioController::class, 'restore'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries/{entry}/versions/{version}/renditions/{preset}', [StudioController::class, 'rendition'])->whereUlid(['workspace', 'entry', 'version'])->where('preset', '[a-zA-Z][a-zA-Z0-9_-]{0,63}');
Route::get('workspaces/{workspace}/entries/{entry}/preview', [StudioController::class, 'preview'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries/{entry}/thumbnail', [StudioController::class, 'thumbnail'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries/{entry}/content', [StudioController::class, 'content'])->whereUlid(['workspace', 'entry']);

Route::get('workspaces/{workspace}/member-candidates', [WorkspaceMembersController::class, 'candidates'])->whereUlid('workspace')->middleware('throttle:60,1');
Route::post('workspaces/{workspace}/members', [WorkspaceMembersController::class, 'assign'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}/members/{member}', [WorkspaceMembersController::class, 'update'])->whereUlid('workspace')->whereNumber('member');

Route::get('workspaces/{workspace}/invitations', [MemberInvitationsController::class, 'index'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/invitations', [MemberInvitationsController::class, 'store'])->whereUlid('workspace')->middleware('throttle:10,1');
Route::post('workspaces/{workspace}/invitations/{invitation}/revoke', [MemberInvitationsController::class, 'revoke'])->whereUlid(['workspace', 'invitation']);

Route::delete('workspaces/{workspace}', [StudioController::class, 'deleteWorkspace'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}', [StudioController::class, 'updateWorkspace'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/make-default', [StudioController::class, 'makeDefaultWorkspace'])->whereUlid('workspace');

Route::get('workspaces/{workspace}/notes/{entry}', [NotesController::class, 'show'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/notes', [NotesController::class, 'store'])->whereUlid('workspace')->middleware('throttle:60,1');
Route::patch('workspaces/{workspace}/notes/{entry}', [NotesController::class, 'update'])->whereUlid(['workspace', 'entry']);

Route::get('workspaces/{workspace}/entries/{entry}/video-edit', [VideoEditsController::class, 'show'])->whereUlid(['workspace', 'entry']);
Route::post('workspaces/{workspace}/entries/{entry}/video-edit', [VideoEditsController::class, 'store'])->whereUlid(['workspace', 'entry'])->middleware('throttle:10,1');

Route::get('workspaces/{workspace}/entries/{entry}/comments', [CommentsController::class, 'index'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/entries/{entry}/comment-mentions', [CommentsController::class, 'mentions'])->whereUlid(['workspace', 'entry'])->middleware('throttle:60,1');
Route::post('workspaces/{workspace}/entries/{entry}/comments', [CommentsController::class, 'store'])->whereUlid(['workspace', 'entry'])->middleware('throttle:30,1');
Route::patch('workspaces/{workspace}/entries/{entry}/comments/{comment}', [CommentsController::class, 'update'])->whereUlid(['workspace', 'entry', 'comment'])->middleware('throttle:60,1');

Route::get('workspaces/{workspace}/source-imports', [SourceImportsController::class, 'index'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/source-imports', [SourceImportsController::class, 'store'])->whereUlid('workspace')->middleware('throttle:20,1');
Route::post('workspaces/{workspace}/source-imports/{run}/step', [SourceImportsController::class, 'step'])->whereUlid(['workspace', 'run'])->middleware('throttle:120,1');
Route::patch('workspaces/{workspace}/source-imports/{run}', [SourceImportsController::class, 'update'])->whereUlid(['workspace', 'run']);

Route::get('workspaces/{workspace}/search', [SearchController::class, 'index'])->whereUlid('workspace');

Route::post('workspaces/{workspace}/retirement', [WorkspaceOperationsController::class, 'store'])->whereUlid('workspace');
Route::get('workspace-operations/{operation}', [WorkspaceOperationsController::class, 'show'])->whereUlid('operation');
Route::post('workspace-operations/{operation}/retry', [WorkspaceOperationsController::class, 'retry'])->whereUlid('operation');

Route::get('workspaces/{workspace}/workspace-creation-candidates', [WorkspaceMembersController::class, 'creationCandidates'])->whereUlid('workspace')->middleware('throttle:60,1');

Route::get('workspaces/{workspace}/folders/{entry}/context', [StudioController::class, 'folderContext'])->whereUlid(['workspace', 'entry']);
Route::get('workspaces/{workspace}/folders/{entry}/ancestors', [StudioController::class, 'folderAncestors'])->whereUlid(['workspace', 'entry']);

Route::post('workspaces/{workspace}/navigation/operations/{operation}/undo', [StudioController::class, 'undoNavigation'])->whereUlid(['workspace', 'operation']);

Route::get('workspaces/{workspace}/navigation/changes', [StudioController::class, 'navigationChanges'])->whereUlid('workspace');

Route::get('workspaces/{workspace}/tree-operations/{operation}', [StudioController::class, 'treeOperation']);
Route::post('workspaces/{workspace}/tree-operations/{operation}/advance', [StudioController::class, 'advanceTreeOperation']);
Route::post('workspaces/{workspace}/tree-operations/{operation}/cancel', [StudioController::class, 'cancelTreeOperation']);
